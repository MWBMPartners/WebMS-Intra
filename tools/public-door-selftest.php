<?php
// Path: tools/public-door-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The public door, over real HTTP 🚪🧪 (#493 P4a, P4b and P4c; D-056, D-057, D-082, D-084,
 * D-087, D-094, D-095, D-097, D-098)
 * -----------------------------------------------------------------------------
 * Drives the REAL door — `web/public_html/index.php` → `Site::preDetect()` →
 * `Portal\Core\PublicDoor` — in a copy of the portal served by PHP's own web
 * server, and the REAL card on Admin → Public website that turns it on,
 * replaces its name and switches it off. Every result is read back from the
 * database or from the answer itself, never from a message.
 *
 * WHAT IT COVERS
 *   K  the card: the door starts OFF (D-097); turning it on, replacing its
 *      name (the old name stops at once) and switching it off, each recorded;
 *      replacing or switching off refused without the "I understand" tick;
 *      who may (the page's own gate) and what the card shows.
 *   G  the gate: door off, an unknown, replaced or removed name, a name in
 *      capitals, an inactive organisation (by name and by public address),
 *      Calendar off (events refused, posters served) and Noticeboard off (the
 *      reverse, media included), maintenance (503), a wrong method, every bad
 *      `limit`, an unknown address after the name, and the portal's own
 *      address not stored (the /w/ form refuses, a public address still works).
 *   R  every refusal byte-identical: status, every header but Date (and Host,
 *      which PHP's own test server adds and Apache does not), and the body.
 *   X  organisations never cross: each name sees only its own; a session for
 *      organisation B changes nothing when asking A's name; A's public address
 *      with B's name in the path; B's name on A's members' address in "own
 *      address each" mode; the other organisation's media; multi-organisation
 *      switched off.
 *   C  content: nothing unticked, outside its dates, deleted, draft,
 *      cancelled, members-only, finished or another organisation's; a
 *      copied-in event under each rule (and its details withheld when its
 *      calendar says so); an event on its own clock; no database number,
 *      creator, telephone, e-mail, map position or what3words anywhere in
 *      the answer; posters with their media pointed at the door, an outside
 *      https picture passed through, a plain-http link dropped, another
 *      organisation's upload never named; media served only while its poster
 *      is on the public website, and stopping the moment it is unticked.
 *   N  (#882, D-129; #898) an event with NO end time: by default kept until
 *      the end of its day on its OWN clock (the same reading still today on
 *      one clock and a past day on another), with listedUntil saying so; the
 *      card's fixed time (refusals with the reason and on record, the bounds,
 *      a hand edit ignored when read), exact real minutes; an event WITH an
 *      end time and an all-day event unaffected by either choice. The REAL
 *      order: two events on clocks 25 hours apart whose readings and real
 *      moments disagree, with `limit` set so reading order drops the sooner.
 *      N11-N15 run the REAL PublicDoor::events() in-process at fixed moments
 *      on London's 25-hour and 23-hour days, because the real "now" cannot be
 *      chosen. N17-N18 read listedUntil where D-129 says nothing changes (an
 *      end time; all-day events of one day and of three) under both settings;
 *      N20 needs the window to reach a zone's HISTORIC highest offset
 *      (London 1941, +2, beside New York, whose whole history is at or below
 *      -4), whatever the season; N21 needs it to read the WHOLE history (the
 *      Azores' +1 of 1993, late in its list). PN1-PN18 put the old or a wrong
 *      code back piece by piece.
 *   J  (#493 P4b) the script a website pastes in, /w/{name}/door.js and
 *      /door.js on a public address: the file kept outside the web root,
 *      byte for byte, as text/javascript with its headers; the same gate
 *      (Calendar OR Noticeboard needed, both off refused), the same 404 and
 *      503; no session file; the card's three lines to paste, as text; the
 *      script refused after a replaced name and with the door off — on the
 *      approved public address too, where "is the door on?" is the only
 *      guard (J09, PJ9); and on the morning a clock goes forward the feed's
 *      startsOnClock keeps the stored reading while startsAt moves (J10,
 *      PJ10). What
 *      the script DRAWS is driven in a real browser by
 *      tools/public-door-script-selftest.mjs, not here.
 *   V  (#493 P4c; D-056, D-082, D-094) the page a website shows in a FRAME,
 *      /w/{name}/page.html and /page.html on a public address, read with
 *      PHP's own HTML5 parser: what it shows (events by default, posters,
 *      both; limit), the organisations never crossing, pictures and links
 *      only over https, each event's time as STORED on its own clock (the
 *      missing hour), hostile text in every field the database can carry
 *      appearing as text with nothing becoming markup, no <script> or
 *      javascript: anywhere; its headers pinned in each state —
 *      frame-ancestors exactly what frameAncestorsFor() gives (nothing
 *      listed, two listed, a hand-edited list, the installation's switch off
 *      or unreadable, another organisation), X-Frame-Options DENY only with
 *      'none', the one stylesheet allowed by the hash of what was SENT,
 *      sandboxed, no cookie; the card's frame lines as text and its three
 *      "where it works" sentences; every refusal (bad show or limit, an app
 *      off, replaced, switched off on BOTH forms, inactive) the door's 404.
 *      VP01-VP16 drive the RENDERER from the copy in its own process with
 *      every field hostile — the zone's name and a poster's time included,
 *      which the database cannot carry — and take each escape and each
 *      https check out on its own. Whether a browser REFUSES the frame from a
 *      website not listed is driven in a real browser by
 *      tools/public-door-script-selftest.mjs (section F there).
 *   W  (#493 P4b, D-084) the old widget folder is gone: /widget, its old
 *      script, feed and embed page each the portal's own 404 on the members'
 *      address and the door's 404 on a public address; no route row; the
 *      files gone; migration 264 in the install script too. PJ6-PJ8 put the
 *      deleted things back into the copy and require W01 to notice.
 *   H  headers on the live portal: CORS, CORP, the content rule
 *      (frame-ancestors 'none' on data even when the organisation lists
 *      websites that may frame its page), Referrer-Policy, caching,
 *      X-Robots-Tag, no X-Frame-Options or COOP left over from start-up, NO
 *      Set-Cookie, and no session file made by any door request. H06 (#882
 *      point 2) MEASURES that no organisation can add its own row for a
 *      setting start-up turns into a header on the door's answers.
 *   S  every captured feed answer satisfies its schema
 *      (`tools/audit-checks/check_public_door_schema.py --answers`).
 *   E  error.php and the API description, which start the portal WITHOUT
 *      index.php: on a public address both give the door's 404 (D-098).
 *   F  EVERY file in the web root the server could run as PHP, found by
 *      walking the folder and run DIRECTLY (as a server ignoring .htaccess
 *      would) in eight shapes — GET, HEAD, POST, a query string, each with and
 *      without a stale session cookie — on A's and switched-off C's public
 *      address and in the failure state (FS01): no cookie, no session file,
 *      no organisation named; each file's statuses recorded (F02). Plants for
 *      new entry points that start a session first, above start-up, only on
 *      POST, only with a query string, on any method but GET, or as .phtml.
 *      It cannot reach a branch keyed on a particular parameter VALUE, a
 *      method other than GET/HEAD/POST, or a header it does not send.
 *   T  a test copy (PORTAL_ENV=alpha, D-087): no sign-in, a member who may
 *      not use the test copy, a made-up cookie, a tester whose session has
 *      outlived PHP's session lifetime — all refused; a tester
 *      served with `private, no-store` and `Vary: Cookie`; every answer
 *      `noindex, nofollow, noarchive`; "open to anybody" only when BOTH the
 *      installation's limit and the organisation's choice say so (written
 *      straight into the database here: the general settings editor refuses
 *      both, PublicWebsite::LOCKED_KEYS; section U drives the card); no
 *      session file made, even for a made-up cookie.
 *   U  (#493 P4d; D-087, D-101) the test-copy card and the reach check. The
 *      card is drawn only on a test copy; the installation's ceiling is set
 *      by its global administrator alone and the organisation's choice by its
 *      own global administrator alone (the "Set up" holder sees, cannot
 *      choose), each posted DIRECTLY to the handler: "open to anybody" under
 *      a ceiling of testers refused with the reason, every refusal recorded,
 *      nothing written, both writers refusing on the live portal. Open to
 *      anybody, every surface on BOTH forms — a poster's picture included,
 *      with its whole header set — answers with no sign-in and noindex,
 *      nofollow, noarchive; the ceiling lowered, every surface on both forms
 *      is the identical 404 again, and a tester is served at the /w/ address
 *      (the only form a browser carries the sign-in to: the card and the
 *      check say a public address answers nobody then, and name the /w/
 *      one). The test copy's own gate switched off, and what saving says
 *      while it is. The door's check address (/door-check.txt, public
 *      address only): its proof, whole header set and every refusal. And
 *      "Check my set-up"'s reach row in each state — waiting, a members'
 *      address, the door off, testers only, not reachable — over HTTP, then
 *      the REAL check in-process through a real TLS server in front of this
 *      portal: reached ("ok"), another organisation's door, another
 *      installation's, a 404, a plain 200, a redirect, and no curl.
 *   Q  (#895, D-121) several organisations asking for one public address:
 *      each is accepted while it waits, the installation's list shows the
 *      clash, one is approved and the others read "taken" (on their card,
 *      never naming the winner, and on the list, with no Approve button), a
 *      later request for an approved name is refused, a losing request is
 *      kept and can be approved once the winner's approval is withdrawn, the
 *      door answers only for the approved one, the database's own key
 *      refuses a second approval, a second approval made while the first is
 *      open waits and is refused by the ask (Q11), a real race on two web
 *      servers leaves exactly one approved five times, each refusal from the
 *      ask and never the backstop (Q12), the approver HOLDS its lock from
 *      its read to its write even with the key in place (Q13), and neither
 *      organisation's own administrator reads the other's name, number or
 *      address in its own activity log or its export, a third organisation
 *      holding the address included (Q14), and a losing organisation saving
 *      its address again is told it is taken (Q15).
 *   P  the plants: each gate and each header taken out of a COPY of the
 *      portal, and the scenario that should catch it re-run. The expected
 *      value of a plant check is the value UNDER the fault, as
 *      tools/public-website-tick-selftest.php's P-checks do, and every plant
 *      must find its text exactly once or it fails loudly.
 *
 * WHAT IT CANNOT DO, said plainly
 *   - Data answers say `frame-ancestors 'none'` whatever the organisation
 *     lists; only the framed PAGE (section V) carries frameAncestorsFor().
 *     What a browser DOES with that header is not seen here (see V above).
 *   - No real outside website, real browser, DNS name or TLS certificate is
 *     involved: a public address is imitated by sending its name in the Host
 *     header to the test server. What browsers do with CORS, CORP and the
 *     sign-in cookie is not tested.
 *   - One web worker, so two administrators pressing the card at once is not
 *     exercised — EXCEPT approving a public address (section Q), which
 *     starts a second web server for Q12 and holds a lock on this test's own
 *     connection for Q11 and Q13. Q12 does not measure how far the two
 *     requests really overlapped, so it catches a lock released too early
 *     only when they happen to overlap; Q11 shows a second approval waits
 *     and is refused by the ask, but CANNOT see a lock taken outside the
 *     transaction (round 1 of #895's check); Q13 is the deterministic proof
 *     that the lock is held from the read to the write.
 *     exercised; the writers' answers to that race are read, not run.
 *   - Caches in between, and the minute `max-age=60` allows, are not tested.
 *   - With PHP's "files" session store only: the guarantee that a made-up
 *     cookie makes no session file is the door's own guard, which cannot see
 *     any other store.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_… SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         [SELFTEST_WEB_PORT=9561] php tools/public-door-selftest.php
 *
 *         The database must already hold `web/_sql/full_schema.sql` (with
 *         migration 263). Needs PHP's curl extension, `proc_open()` and
 *         python3. It REFUSES rather than skipping when anything is missing.
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
const PDS_EXPECTED_CHECKS = 387; // #880 adds C14b-C14f and P23b to the 381 below; #882 (D-129) and #898 add N01-N21, H06, PN1-PN18 to the 341 below; #895 (D-121) adds Q01-Q15 and PQ1-PQ17 to the 309 below; 170 in P4a; #493 P4b adds J01-J11, W01-W04, T10 and PJ1-PJ11; P4c adds V01-V19, VP01-VP16, T11, PV01-PV14, (round 1) T12 and PV15-PV18, and (round 2) PV19-PV20; P4d adds U01-U25 and PU01-PU18, and (round 1) U16b, U19b, U21b, U24b and PU19-PU23, U20 replaced; (round 2) U26 and U27, every sentence from doorReachWords(); (round 3) U28; (round 4) U28 with seven states

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);

$passed = 0;
$failed = 0;
$state  = ['proc' => null, 'child' => null, 'restore' => []];

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
foreach (['curl_init', 'proc_open'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this test needs {$needed}(), which this PHP does not have");
    }
}
$python = trim((string) shell_exec('command -v python3'));
if ($python === '') {
    refuse('this test needs python3 to run the schema check over the answers it captures');
}
$dbHost = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
$dbUser = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
$dbPass = getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '';
$dbPort = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    refuse("could not reach {$dbName} on {$dbHost}:{$dbPort}");
}
$hasTable = static fn (string $t): bool => (int) ($db->query(
    "SELECT COUNT(*) AS `n` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = '" . $db->real_escape_string($t) . "'"
)->fetch_assoc()['n'] ?? 0) === 1;
if ($hasTable('tblPublicDoorNames') === false || $hasTable('tblPublicHosts') === false) {
    refuse('this database was not built from the current install script (migration 263 is missing)');
}
if ((int) ($db->query('SELECT MIN(`siteID`) AS `s` FROM `tblSites`')->fetch_assoc()['s'] ?? 0) !== 1) {
    refuse('organisation 1 must exist: the door is tested against the default organisation');
}

// ── Settings this test changes, put back at the end whatever happens ────────
$installationRow = static function (string $key) use ($db): ?string {
    $s = $db->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL');
    $s->bind_param('s', $key);
    $s->execute();
    $r = $s->get_result()->fetch_assoc();
    $s->close();
    return $r === null ? null : (string) $r['settingValue'];
};
// An existing row is UPDATED rather than replaced, so its seeded defaultValue
// survives the run; null deletes it (and putting back an absent row deletes).
$setInstallation = static function (string $key, ?string $value) use ($db): void {
    if ($value === null) {
        $d = $db->prepare('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL');
        $d->bind_param('s', $key);
        $d->execute();
        $d->close();
        return;
    }
    $u = $db->prepare('UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingKey` = ? AND `siteID` IS NULL');
    $u->bind_param('ss', $value, $key);
    $u->execute();
    $u->close();
    $exists = $db->prepare('SELECT COUNT(*) AS `n` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL');
    $exists->bind_param('s', $key);
    $exists->execute();
    $n = (int) ($exists->get_result()->fetch_assoc()['n'] ?? 0);
    $exists->close();
    if ($n === 0) {
        $i = $db->prepare("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, ?, ?, '', 0)");
        $i->bind_param('ss', $key, $value);
        $i->execute();
        $i->close();
    }
};
$setOwn = static function (int $site, string $key, ?string $value) use ($db): void {
    $d = $db->prepare('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ?');
    $d->bind_param('si', $key, $site);
    $d->execute();
    $d->close();
    if ($value !== null) {
        $i = $db->prepare("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, ?, ?, '', 0)");
        $i->bind_param('iss', $site, $key, $value);
        $i->execute();
        $i->close();
    }
};
$touched = ['multisite.enabled', 'multisite.detectionMode', 'site.url', 'public.door.testCopies.permitted', 'portal.gatekeeper.enabled',
    'public.events.copiedRule', 'portal.maintenance.active', 'public.frame.permitted', 'calendar.enabled', 'noticeboard.enabled'];
foreach ($touched as $key) {
    $state['restore'][$key] = $installationRow($key);
}

// ── A copy of web/, so a planted fault never touches the repository ─────────
$child = sys_get_temp_dir() . $sep . 'pds-http-' . bin2hex(random_bytes(6));
$state['child'] = $child;
$cleanUp = static function () use (&$state, $setInstallation, $db): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
        $state['proc'] = null;
    }
    foreach ($state['restore'] as $key => $value) {
        try {
            $setInstallation($key, $value);
        } catch (Throwable $e) {
            echo "  ! could not put the setting {$key} back; set it by hand.\n";
        }
    }
    $state['restore'] = [];
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
// Ctrl-C, a kill or a closed terminal run the SAME shutdown functions (they
// put the settings, organisation 1's name and the test accounts back): exit()
// inside the handler is what makes PHP run them. Without the pcntl extension
// only the shutdown functions are there (the #649 and #667 tests do the same).
if (function_exists('pcntl_async_signals') === true && function_exists('pcntl_signal') === true) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
        pcntl_signal($signal, static function (int $which): void {
            echo "\nStopped by a signal ({$which}); putting things back.\n";
            exit(3);
        });
    }
}
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
@mkdir($child . $sep . 'web' . $sep . '_uploads' . $sep . 'noticeboard', 0700, true);
$sessions = $child . $sep . 'sessions';
mkdir($sessions, 0700);
$answers = $child . $sep . 'answers';
mkdir($answers, 0700);
file_put_contents(
    $child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
    "<?php\n// Written by tools/public-door-selftest.php for ONE run.\nreturn "
    . var_export(['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort], true)
    . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// The router imitates web/public_html/.htaccess, as the other page tests do.
file_put_contents($child . $sep . 'router.php', <<<'ROUTER'
<?php
// Written by tools/public-door-selftest.php for ONE run. Test only.
declare(strict_types=1);
$root = (string) realpath(__DIR__ . '/web/public_html');
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#(^|/)\.(?!well-known)#', $uri) === 1) { http_response_code(403); exit('403'); }
// Section F's "direct" requests run ANY .php file, as a web server that does
// not honour .htaccess (nginx, IIS, a misconfigured host) would.
$direct = ($_SERVER['HTTP_X_SELFTEST_DIRECT'] ?? '') === '1';
if ($direct === false && preg_match('#\.(php\d?|phtml|phar)(/|$)#i', $uri) === 1 && in_array($uri, ['/index.php', '/api-docs/index.php', '/error.php'], true) === false) { http_response_code(404); exit('404'); }
$target = $root . '/index.php';
$real   = ($uri === '/' || preg_match('#^/assets/?$#', $uri) === 1) ? false : realpath($root . $uri);
if ($real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) === true && is_file($real) === true) {
    // Every ending a server may run as PHP (section F plants a .phtml); the
    // refusal above still turns them all away unless the request is "direct".
    if (in_array(strtolower(pathinfo($real, PATHINFO_EXTENSION)), ['php', 'php5', 'phtml', 'phar'], true) === true) { $target = $real; } else { readfile($real); return true; }
}
$_SERVER['SCRIPT_NAME']     = substr($target, strlen($root));
$_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];
$_SERVER['SCRIPT_FILENAME'] = $target;
chdir(dirname($target));
require $target;
return true;
ROUTER);

$port = getenv('SELFTEST_WEB_PORT') !== false ? (int) getenv('SELFTEST_WEB_PORT') : 9560 + random_int(0, 30);
$base = 'http://127.0.0.1:' . $port;
$channel = 'prod';

$startServer = static function () use (&$state, &$channel, $child, $sep, $port, $sessions): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
        $state['proc'] = null;
        usleep(400000);
    }
    $env = getenv();
    unset($env['PHP_CLI_SERVER_WORKERS']);
    $env['PORTAL_ENV'] = $channel;
    $state['proc'] = proc_open(
        [
            PHP_BINARY,
            '-d', 'session.save_path=' . $sessions,
            '-d', 'session.save_handler=files',
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

/**
 * One request. Options: host (the Host header: a public address is imitated
 * this way), jar (a cookie file to send and keep), cookie (a raw Cookie
 * header), post (an array), head (true for HEAD). Redirects are NOT followed.
 *
 * @return array{status:int, headers:array<string,string>, lines:list<string>, body:string}
 */
$http = static function (string $method, string $path, array $opt = []) use ($port): array {
    $handle  = curl_init('http://127.0.0.1:' . $port . $path);
    $headers = [];
    if (isset($opt['host']) === true) {
        $headers[] = 'Host: ' . $opt['host'];
    }
    if (isset($opt['cookie']) === true) {
        $headers[] = 'Cookie: ' . $opt['cookie'];
    }
    if (($opt['direct'] ?? false) === true) {
        $headers[] = 'X-Selftest-Direct: 1';
    }
    // #880: a byte-range request, as a browser playing a poster video sends.
    if (isset($opt['range']) === true) {
        $headers[] = 'Range: ' . $opt['range'];
    }
    $curl = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_HTTPHEADER => $headers, CURLOPT_CUSTOMREQUEST => $method,
    ];
    if (isset($opt['jar']) === true) {
        $curl[CURLOPT_COOKIEJAR]  = $opt['jar'];
        $curl[CURLOPT_COOKIEFILE] = $opt['jar'];
    }
    if ($method === 'HEAD') {
        $curl[CURLOPT_NOBODY] = true;
    }
    if (isset($opt['post']) === true) {
        $curl[CURLOPT_POSTFIELDS] = http_build_query($opt['post']);
    }
    curl_setopt_array($handle, $curl);
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'headers' => [], 'lines' => [], 'body' => 'no answer: ' . curl_error($handle)];
    }
    $size  = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head  = substr((string) $raw, 0, $size);
    $lines = [];
    $named = [];
    foreach (preg_split('/\r?\n/', trim($head)) ?: [] as $i => $line) {
        if ($i === 0 || $line === '') {
            continue;
        }
        $lines[] = $line;
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $key = strtolower(trim($name));
        $named[$key] = isset($named[$key]) === true ? $named[$key] . "\n" . trim($value) : trim($value);
    }

    return ['status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE), 'headers' => $named, 'lines' => $lines,
        'body' => substr((string) $raw, $size)];
};

$waitForServer = static function () use ($http): void {
    for ($i = 0; $i < 80; $i++) {
        if ($http('GET', '/login')['status'] > 0) {
            return;
        }
        usleep(250000);
    }
    refuse('the test server never answered');
};

/** A refusal's whole answer as one text: status, every header but Date and Host, and the body. */
$signature = static function (array $r): string {
    $keep = array_values(array_filter($r['lines'], static fn (string $l): bool
        => preg_match('/^(date|host):/i', $l) !== 1));
    sort($keep);
    return $r['status'] . "\n" . implode("\n", $keep) . "\n\n" . $r['body'];
};

/** How many session files exist. */
$sessionFiles = static function () use ($sessions): int {
    return count(glob($sessions . DIRECTORY_SEPARATOR . 'sess_*') ?: []);
};

// ── The organisations ───────────────────────────────────────────────────────
// A = organisation 1 (UTC, whatever its zone is). B = a second one on a clock
// far from UTC. C = an INACTIVE one with a door and a public address, so
// "inactive" is the only thing standing in its way.
foreach (['selftest-door-b', 'selftest-door-c'] as $key) {
    $old = $db->query("SELECT `siteID` FROM `tblSites` WHERE `siteKey` = '{$key}'")->fetch_assoc();
    if ($old !== null) {
        $sid = (int) $old['siteID'];
        foreach (['tblNoticeboardUploads', 'tblNoticeboardPosters', 'tblEvents', 'tblSettings', 'tblUserSites', 'tblRoles'] as $t) {
            $db->query("DELETE FROM `{$t}` WHERE `siteID` = {$sid}");
        }
        $db->query("DELETE FROM `tblSites` WHERE `siteID` = {$sid}");
    }
}
$db->query("INSERT INTO `tblSites` (`siteKey`, `siteName`, `timezone`, `isActive`) VALUES ('selftest-door-b', 'Selftest Door B', 'Pacific/Auckland', 1)");
$siteB = (int) $db->insert_id;
$db->query("INSERT INTO `tblSites` (`siteKey`, `siteName`, `timezone`, `isActive`) VALUES ('selftest-door-c', 'Selftest Door C', 'UTC', 0)");
$siteC = (int) $db->insert_id;
$siteA = 1;
// Organisation 1 is given a DISTINCTIVE name for the run, put back afterwards:
// section F looks for every organisation's name in what each web-root file
// answers, and the seeded name "Portal" is a word the offline page uses on
// its own (round 4's first run of section F found exactly that).
//
// THE ORIGINAL NAME IS WRITTEN TO A FILE FIRST, so a run that dies before
// putting it back (a crash, a kill -9 that no handler can catch) leaves the
// next run something to trust: it restores from that file, never from
// "whatever is there now", which would by then be the test's own name (round 5
// of P4a's check). The file is removed only once the name is back.
$nameTest = 'Selftest Door A Organisation';
$nameFile = sys_get_temp_dir() . $sep . 'public-door-selftest-' . $dbName . '-site1-name.txt';
if (is_file($nameFile) === true) {
    $state['siteOneName'] = (string) file_get_contents($nameFile);
    echo "  ! a previous run did not put organisation 1's name back; it is restored from {$nameFile} at the end.\n";
} else {
    $state['siteOneName'] = (string) ($db->query('SELECT `siteName` FROM `tblSites` WHERE `siteID` = 1')->fetch_assoc()['siteName'] ?? '');
    if ($state['siteOneName'] === $nameTest) {
        refuse("organisation 1 is called '{$nameTest}', this test's own name, and no record of its real name exists. Set its real name by hand");
    }
    if (file_put_contents($nameFile, $state['siteOneName']) === false) {
        refuse("could not record organisation 1's real name in {$nameFile} before changing it");
    }
}
register_shutdown_function(static function () use (&$state, $db, $nameFile): void {
    if (($state['siteOneName'] ?? null) === null) {
        return;
    }
    // try/catch: a failed put-back must not stop the shutdown functions after
    // this one (removing the test accounts) from running.
    try {
        $u = $db->prepare('UPDATE `tblSites` SET `siteName` = ? WHERE `siteID` = 1');
        $u->bind_param('s', $state['siteOneName']);
        $u->execute();
        $u->close();
        @unlink($nameFile);
        $state['siteOneName'] = null;
    } catch (Throwable $e) {
        echo "  ! could not put organisation 1's name back; its real name is kept in {$nameFile} for the next run.\n";
    }
});
$db->query("UPDATE `tblSites` SET `siteName` = '{$nameTest}' WHERE `siteID` = 1");
$zoneA = (string) ($db->query('SELECT `timezone` FROM `tblSites` WHERE `siteID` = 1')->fetch_assoc()['timezone'] ?? 'UTC');
try {
    $clockA = new DateTimeZone($zoneA);
} catch (Throwable $e) {
    $clockA = new DateTimeZone('UTC');
}
$clockB  = new DateTimeZone('Pacific/Auckland');
$nowA    = new DateTimeImmutable('now', $clockA);
$todayA  = $nowA->format('Y-m-d');
$yesterA = $nowA->modify('-1 day')->format('Y-m-d');
$tomorA  = $nowA->modify('+1 day')->format('Y-m-d');

$db->query("DELETE FROM `tblPublicDoorNames` WHERE `siteID` IN (1, {$siteB}, {$siteC})");
$db->query("DELETE FROM `tblPublicHosts` WHERE `siteID` IN (1, {$siteB}, {$siteC}) OR `hostName` LIKE '%.selftest.test'");
$db->query("UPDATE `tblSites` SET `hostPattern` = NULL WHERE `siteID` = 1");
foreach (['calendar.enabled', 'noticeboard.enabled', 'public.door.testCopies.open', 'public.frame.origins'] as $k) {
    $db->query("DELETE FROM `tblSettings` WHERE `siteID` IN (1, {$siteB}, {$siteC}) AND `settingKey` = '{$k}'");
}
$setInstallation('multisite.enabled', 'true');
$setInstallation('multisite.detectionMode', 'session');
$setInstallation('site.url', $base);
$setInstallation('public.door.testCopies.permitted', 'testers');
$setInstallation('public.events.copiedRule', 'event');
$setInstallation('portal.maintenance.active', '0');
$setInstallation('public.frame.permitted', 'yes');
// Both apps on for everybody, installation-wide (each test that switches one off does it per organisation).
$setInstallation('calendar.enabled', $installationRow('calendar.enabled') ?? 'true');
$setInstallation('noticeboard.enabled', $installationRow('noticeboard.enabled') ?? 'true');

// ── The people ──────────────────────────────────────────────────────────────
//    root     A's OWN global administrator (with the portal-wide Admin flag, so
//             the test-copy gate lets them in): drives the card. NOT the
//             installation's global administrator, who belongs to every
//             organisation and is sent to the organisation chooser first.
//    tester   the portal-wide Admin flag — the test-copy gate lets them in
//    member   a plain member of A — the test-copy gate does not
//    memberB  a plain member of B only — for "a session for B asking A's name"
$removeMember = static function (int $who) use ($db): void {
    $rs = $db->query(
        "SELECT `TABLE_NAME` FROM `information_schema`.`KEY_COLUMN_USAGE` WHERE `TABLE_SCHEMA` = DATABASE() "
        . "AND `REFERENCED_TABLE_NAME` = 'tblUsers' AND `COLUMN_NAME` = 'userID'"
    );
    while ($row = $rs->fetch_assoc()) {
        try {
            $db->query('DELETE FROM `' . $row['TABLE_NAME'] . "` WHERE `userID` = {$who}");
        } catch (Throwable $e) {
            // A table of a different shape.
        }
    }
    try {
        $db->query("DELETE FROM `tblUsers` WHERE `userID` = {$who}");
    } catch (Throwable $e) {
        echo "  ! could not remove test person {$who}; remove it by hand.\n";
    }
};
$people = [];
foreach ([
    'root'    => ['isAdmin' => 1, 'isRoot' => 0, 'site' => $siteA, 'siteRoot' => 1],
    'tester'  => ['isAdmin' => 1, 'isRoot' => 0, 'site' => $siteA],
    'member'  => ['isAdmin' => 0, 'isRoot' => 0, 'site' => $siteA],
    'memberB' => ['isAdmin' => 0, 'isRoot' => 0, 'site' => $siteB],
    // #895 (round 1 of its check): B's own ordinary administrator, who reads
    // B's activity log in Q14. Not a global administrator of anything.
    'adminB'  => ['isAdmin' => 1, 'isRoot' => 0, 'site' => $siteB],
    // D-101: the installation's global administrator (the ONLY one who may
    // approve a public address), and somebody given "Set up the public
    // website" in A (who may save one, and may not approve it).
    'instroot' => ['isAdmin' => 0, 'isRoot' => 1, 'site' => $siteA],
    'setup'    => ['isAdmin' => 0, 'isRoot' => 0, 'site' => $siteA],
] as $role => $flags) {
    $email = "pds.{$role}@selftest.invalid";
    $old   = $db->prepare('SELECT `userID` FROM `tblUsers` WHERE `emailAddress` = ?');
    $old->bind_param('s', $email);
    $old->execute();
    foreach ($old->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $removeMember((int) $row['userID']);
    }
    $old->close();
    $ins  = $db->prepare('INSERT INTO `tblUsers` (`fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `createdAt`) VALUES (?, ?, 1, ?, ?, NOW())');
    $name = 'Selftest PDS ' . $role;
    $ins->bind_param('ssii', $name, $email, $flags['isAdmin'], $flags['isRoot']);
    $ins->execute();
    $id = (int) $db->insert_id;
    $ins->close();
    $db->query("INSERT INTO `tblUserSites` (`siteID`, `userID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES ({$flags['site']}, {$id}, 1, 0, " . (int) ($flags['siteRoot'] ?? 0) . ")");
    $password = 'Pds' . bin2hex(random_bytes(8)) . '!9x';
    $login    = 'selftest-pds-' . $role;
    $db->query("DELETE FROM `tblLocalAccounts` WHERE `username` = '{$login}'");
    $acc  = $db->prepare('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)');
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $acc->bind_param('iss', $id, $login, $hash);
    $acc->execute();
    $acc->close();
    $people[$role] = ['id' => $id, 'login' => $login, 'password' => $password, 'jar' => $child . $sep . 'jar-' . $role . '.txt'];
}
$state['people'] = array_column($people, 'id');
register_shutdown_function(static function () use (&$state, $removeMember): void {
    foreach ($state['people'] ?? [] as $who) {
        $removeMember((int) $who);
    }
});

$setupRole = (int) ($db->query("SELECT `roleID` FROM `tblRoles` WHERE `siteID` = 1 AND `roleKey` = 'website_setup'")->fetch_assoc()['roleID'] ?? 0);
if ($setupRole <= 0) {
    refuse('organisation 1 has no Website Set-up role — migration 262 did not seed it');
}
$db->query("INSERT INTO `tblUserRoles` (`userID`, `roleID`, `siteID`) VALUES ({$people['setup']['id']}, {$setupRole}, 1)");
$tokenOn = static function (string $html): string {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) === 1) {
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    return '';
};
/** Sign a person in; their cookie file is then theirs for the rest of the run. */
$signIn = static function (string $role) use ($http, $tokenOn, $people): string {
    $jar = $people[$role]['jar'];
    @unlink($jar);
    $page  = $http('GET', '/login', ['jar' => $jar]);
    $token = $tokenOn($page['body']);
    if ($token === '') {
        refuse('the sign-in page carried no form token');
    }
    $in = $http('POST', '/login', ['jar' => $jar, 'post' => ['csrf_token' => $token, 'username' => $people[$role]['login'],
        'password' => $people[$role]['password']]]);
    if ($in['status'] !== 302 && $in['status'] !== 303) {
        refuse("signing in as {$role} did not answer a redirect (HTTP {$in['status']})");
    }
    return $jar;
};
/** The session cookie a person's file holds, as a Cookie header. */
$cookieOf = static function (string $jar): string {
    foreach (file($jar) ?: [] as $line) {
        $parts = explode("\t", trim($line));
        if (count($parts) === 7 && $parts[5] === 'PHPSESSID') {
            return 'PHPSESSID=' . $parts[6];
        }
    }
    return '';
};
/** Press a button on the card as `$role`. */
$card = static function (string $role, string $action, array $more = []) use ($http, $tokenOn, $people): array {
    $jar   = $people[$role]['jar'];
    $page  = $http('GET', '/admin/public-website', ['jar' => $jar]);
    $token = $tokenOn($page['body']);
    $r     = $http('POST', '/admin/public-website/save', ['jar' => $jar, 'post' => ['csrf_token' => $token, 'action' => $action] + $more]);
    return ['status' => $r['status'], 'page' => $http('GET', '/admin/public-website', ['jar' => $jar])['body']];
};
$doorName = static function (int $site) use ($db): ?string {
    $r = $db->query("SELECT `publicName` FROM `tblPublicDoorNames` WHERE `siteID` = {$site}")->fetch_assoc();
    return $r === null ? null : (string) $r['publicName'];
};
$logged = static function (string $type, int $userId) use ($db): int {
    $s = $db->prepare('SELECT COUNT(*) AS `n` FROM `tblActivityLogs` WHERE `activityType` = ? AND `userID` = ?');
    $s->bind_param('si', $type, $userId);
    $s->execute();
    $n = (int) ($s->get_result()->fetch_assoc()['n'] ?? 0);
    $s->close();
    return $n;
};

// ── The content ─────────────────────────────────────────────────────────────
$db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'PDS %'");
$db->query("DELETE FROM `tblNoticeboardUploads` WHERE `storedName` LIKE '%' AND `siteID` IN (1, {$siteB}) AND `fileSize` = 4242");
$db->query("DELETE FROM `tblNoticeboardPosters` WHERE `title` LIKE 'PDS %'");
$db->query("DELETE FROM `tblExternalFeeds` WHERE `name` = 'PDS outside calendar'");

/** An event straight into the database: published, public, ticked, unless `$set` says otherwise. */
$makeEvent = static function (string $name, int $site, string $zone, string $start, ?string $end, array $set = []) use ($db): int {
    $slug = 'pds-' . bin2hex(random_bytes(5));
    $stmt = $db->prepare("INSERT INTO `tblEvents` (`siteID`, `eventName`, `eventSlug`, `startDateTime`, `endDateTime`, `timezone`, `status`, `isPublic`, `showOnWebsite`) VALUES (?, ?, ?, ?, ?, ?, 'published', 1, 1)");
    $stmt->bind_param('isssss', $site, $name, $slug, $start, $end, $zone);
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
$at = static fn (DateTimeImmutable $d): string => $d->format('Y-m-d H:i:s');

$later = $nowA->modify('+2 days')->setTime(10, 0);
$eShown = $makeEvent('PDS A shown', $siteA, $zoneA, $at($later), $at($later->modify('+2 hours')), [
    'description' => 'Bring a dish.', 'locationName' => 'PDS Hall', 'locationAddress' => '1 PDS Street',
    'locationWebURL' => 'https://hall.selftest.invalid/', 'locationPhone' => '01234 PDS PHONE',
    'locationEmail' => 'pds-hall-secret@selftest.invalid', 'locationGeoLat' => '51.1234567', 'locationGeoLng' => '-1.7654321',
    'locationW3W' => 'pds.secret.words', 'hostOrgName' => 'PDS HOST ORG', 'capacity' => '77',
]);
$eRunning   = $makeEvent('PDS A running now', $siteA, $zoneA, $at($nowA->modify('-1 hour')), $at($nowA->modify('+2 hours')),
    ['description' => '<b>"Tom & Jerry\'s"</b></script>']);
// Two all-day events with no end time, OFF the website until C18 ticks them, so
// they change no other check's list: one today (kept all of today), one
// yesterday (gone).
$eAllDayToday = $makeEvent('PDS A all day today', $siteA, $zoneA, $todayA . ' 00:00:00', null, ['isAllDay' => '1', 'showOnWebsite' => '0']);
$eAllDayPast  = $makeEvent('PDS A all day yesterday', $siteA, $zoneA, $yesterA . ' 00:00:00', null, ['isAllDay' => '1', 'showOnWebsite' => '0']);
$eUnticked  = $makeEvent('PDS A unticked', $siteA, $zoneA, $at($later), null, ['showOnWebsite' => '0']);
$eUntil     = $makeEvent('PDS A until yesterday', $siteA, $zoneA, $at($later), null, ['websiteUntil' => $yesterA]);
$eFrom      = $makeEvent('PDS A from tomorrow', $siteA, $zoneA, $at($later), null, ['websiteFrom' => $tomorA]);
$eDeleted   = $makeEvent('PDS A deleted', $siteA, $zoneA, $at($later), null, ['isDeleted' => '1']);
$eDraft     = $makeEvent('PDS A draft', $siteA, $zoneA, $at($later), null, ['status' => 'draft']);
$eCancelled = $makeEvent('PDS A cancelled', $siteA, $zoneA, $at($later), null, ['status' => 'cancelled']);
$eMembers   = $makeEvent('PDS A members only', $siteA, $zoneA, $at($later), null, ['isPublic' => '0']);
$eFinished  = $makeEvent('PDS A finished', $siteA, $zoneA, $at($nowA->modify('-1 day -3 hours')), $at($nowA->modify('-1 day -1 hour')));
$laterB = (new DateTimeImmutable('now', $clockB))->modify('+3 days')->setTime(18, 0);
$eB     = $makeEvent('PDS B shown', $siteB, 'Pacific/Auckland', $at($laterB), null);
$db->query("INSERT INTO `tblEvents` (`siteID`, `eventName`, `eventSlug`, `startDateTime`, `timezone`, `status`, `isPublic`, `showOnWebsite`) "
    . "VALUES ({$siteC}, 'PDS C shown', 'pds-c-" . bin2hex(random_bytes(4)) . "', '" . $at($later) . "', 'UTC', 'published', 1, 1)");
// A copied-in event: public, its calendar's website box ticked, its details
// withheld (basic detail, and marked private in its own calendar: on a public
// calendar basic detail alone is NOT withheld, see EventVisibility's
// buildFullDetail()), the "yes" coming from the CALENDAR.
$db->query("INSERT INTO `tblExternalFeeds` (`siteID`, `name`, `url`, `audienceLevel`, `websiteOptIn`, `isActive`) "
    . "VALUES ({$siteA}, 'PDS outside calendar', 'https://calendar.selftest.invalid/pds.ics', 'public', 1, 1)");
$feedId = (int) $db->insert_id;
$eCopied = $makeEvent('PDS A copied in', $siteA, $zoneA, $at($later), null, ['description' => 'PDS COPIED DESCRIPTION', 'locationName' => 'PDS copied place']);
$db->query("UPDATE `tblEvents` SET `externalFeedID` = {$feedId}, `importLevel` = 'public', `importDetail` = 'basic', `importWebsite` = 1, "
    . "`externalPrivate` = 1, `importSource` = 'calendar', `importRecheckAt` = NULL, `isPublic` = 0, `showOnWebsite` = 0 WHERE `eventID` = {$eCopied}");

// Posters and uploads. A tiny real PNG, written into the COPY's upload folder.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$upload = static function (int $site) use ($db, $png, $child, $sep): string {
    $stored = bin2hex(random_bytes(16)) . '.png';
    file_put_contents($child . $sep . 'web' . $sep . '_uploads' . $sep . 'noticeboard' . $sep . $stored, $png);
    $db->query("INSERT INTO `tblNoticeboardUploads` (`siteID`, `storedName`, `mimeType`, `fileSize`) VALUES ({$site}, '{$stored}', 'image/png', 4242)");
    return $stored;
};
$upA  = $upload($siteA);
$upA2 = $upload($siteA);
$upB  = $upload($siteB);
$makePoster = static function (string $title, int $site, array $set = []) use ($db): int {
    $db->query("INSERT INTO `tblNoticeboardPosters` (`siteID`, `title`, `showOnWebsite`) VALUES ({$site}, '" . $db->real_escape_string($title) . "', 1)");
    $id = (int) $db->insert_id;
    foreach ($set as $col => $val) {
        $u = $db->prepare('UPDATE `tblNoticeboardPosters` SET `' . $col . '` = ? WHERE `posterID` = ?');
        $u->bind_param('si', $val, $id);
        $u->execute();
        $u->close();
    }
    return $id;
};
$pShown = $makePoster('PDS poster shown', $siteA, ['mediaType' => 'image', 'mediaUrl' => '/noticeboard/media?f=' . $upA,
    'scheduleType' => 'weekly', 'weekday' => '2', 'eventTime' => '10:30:00', 'location' => 'PDS Hall', 'link' => 'https://events.selftest.invalid/toddlers']);
$pUnticked = $makePoster('PDS poster unticked', $siteA, ['mediaType' => 'image', 'mediaUrl' => '/noticeboard/media?f=' . $upA2, 'showOnWebsite' => '0']);
$pOutside  = $makePoster('PDS poster outside picture', $siteA, ['mediaType' => 'image', 'mediaUrl' => 'https://images.selftest.invalid/a.png',
    'link' => 'http://plain.selftest.invalid/', 'scheduleType' => 'once', 'eventDate' => $tomorA]);
$pBorrow   = $makePoster('PDS poster naming B upload', $siteA, ['mediaType' => 'image', 'mediaUrl' => '/noticeboard/media?f=' . $upB]);
$pDeleted  = $makePoster('PDS poster deleted', $siteA, ['isDeleted' => '1']);
$pUntil    = $makePoster('PDS poster until yesterday', $siteA, ['websiteUntil' => $yesterA]);
$pB        = $makePoster('PDS poster B', $siteB, ['mediaType' => 'image', 'mediaUrl' => '/noticeboard/media?f=' . $upB]);

$hostA = 'door-a.selftest.test';
$hostB = 'door-b.selftest.test';
$hostC = 'door-c.selftest.test';

$startServer();
$waitForServer();

/** A feed's titles, or null when it did not answer 200 JSON. */
$titles = static function (array $r, string $list): ?array {
    if ($r['status'] !== 200) {
        return null;
    }
    $j = json_decode($r['body'], true);
    if (is_array($j) === false || isset($j[$list]) === false) {
        return null;
    }
    $out = array_map(static fn (array $one): string => (string) $one['title'], $j[$list]);
    sort($out);
    return $out;
};
$capture = static function (string $kind, string $label, array $r) use ($answers): void {
    if ($r['status'] === 200) {
        file_put_contents($answers . DIRECTORY_SEPARATOR . $kind . '-' . $label . '.json', $r['body']);
    }
};

// =============================================================================
echo "K. The card on Admin → Public website (D-097: the door starts off)\n";
// =============================================================================
$signIn('root');
$k0 = $http('GET', '/admin/public-website', ['jar' => $people['root']['jar']]);
check('K01 every organisation\'s door starts OFF: no name row, and the card says Off with a "Turn on" button',
    [$doorName($siteA), str_contains($k0['body'], 'data-public-door="off"'), str_contains($k0['body'], 'data-door-form="on"')],
    [null, true, true]);
$k1 = $card('root', 'door_on');
$nameA1 = $doorName($siteA);
check('K02 "Turn on the public door" makes a 24-character random name, recorded as PublicDoorTurnedOn, and the card shows its addresses',
    [preg_match('/^[0-9a-f]{24}$/', (string) $nameA1), $logged('PublicDoorTurnedOn', $people['root']['id']),
        str_contains($k1['page'], 'data-public-door="on"'), str_contains($k1['page'], $base . '/w/' . $nameA1 . '/events.json'),
        str_contains($k1['page'], $base . '/w/' . $nameA1 . '/posters.json')],
    [1, 1, true, true, true]);
$k3 = $card('root', 'door_on');
check('K03 turning it on again changes nothing and says so', [$doorName($siteA), str_contains($k3['page'], 'already on')], [$nameA1, true]);
$k4 = $card('root', 'door_replace');
check('K04 "Replace the name" without the "I understand" tick changes nothing', [$doorName($siteA), str_contains($k4['page'], 'tick the box')], [$nameA1, true]);
$k5 = $card('root', 'door_off');
check('K05 "Switch the public door off" without the tick changes nothing, and both refusals are recorded (PublicWebsiteSetupRefused)',
    [$doorName($siteA), $logged('PublicWebsiteSetupRefused', $people['root']['id'])], [$nameA1, 2]);
check('K06 the card warns, before the buttons are pressed, that replacing or switching off stops every pasted copy',
    [str_contains($k1['page'], 'Every copy pasted into a website with the old name stops working at once'),
        // Round 2 of P4a's check: the switch-off words must not offer a new
        // name as protection for a PUBLIC address, which answers again the
        // moment the door is back on.
        str_contains($k1['page'], 'a public address carries no name, so it answers again the moment the door is back on')],
    [true, true]);
$signIn('member');
$kMember = $card('member', 'door_off', ['understood' => '1']);
check('K07 a plain member cannot open the card or switch the door off (the page\'s own gate, maySetUp())', [$kMember['status'], $doorName($siteA)], [403, $nameA1]);

// =============================================================================
echo "G. The gate\n";
// =============================================================================
$feedA = static fn (string $what = 'events.json', array $opt = []) => $http('GET', '/w/' . (string) $doorName($siteA) . '/' . $what, $opt);
$g1 = $feedA();
$capture('events', 'a-first', $g1);
check('G01 with the door on, /w/{name}/events.json answers 200 JSON in the events format',
    [$g1['status'], $g1['headers']['content-type'] ?? '', json_decode($g1['body'], true)['format'] ?? null],
    [200, 'application/json; charset=utf-8', 'webms-public-events']);
$g2 = $feedA('posters.json');
$capture('posters', 'a-first', $g2);
check('G02 posters.json answers 200 JSON in the posters format', [$g2['status'], json_decode($g2['body'], true)['format'] ?? null], [200, 'webms-public-posters']);

$refusals = [];
$refusals['unknown name'] = $http('GET', '/w/' . str_repeat('ab', 12) . '/events.json');
$refusals['name in capitals'] = $http('GET', '/w/' . strtoupper((string) $nameA1) . '/events.json');
$refusals['malformed name'] = $http('GET', '/w/not-a-name/events.json');
$refusals['no name at all'] = $http('GET', '/w/');
$refusals['unknown address after the name'] = $feedA('secrets.json');
$refusals['the bare name'] = $feedA('');
$refusals['a trailing slash'] = $feedA('events.json/');
$refusals['a wrong method'] = $http('POST', '/w/' . $nameA1 . '/events.json', ['post' => ['x' => '1']]);
foreach (['0', '51', '-1', 'abc', '01', '1.5', '999'] as $bad) {
    $refusals['limit=' . $bad] = $feedA('events.json?limit=' . rawurlencode($bad));
}
$refusals['limit as a list'] = $feedA('events.json?limit[]=1');
check('G03 an unknown name, a name in capitals, a malformed or missing name, an unknown address, a trailing slash, a POST and every bad limit are all 404',
    array_values(array_unique(array_map(static fn (array $r): int => $r['status'], $refusals))), [404]);
check('G04 HEAD is answered like GET', $http('HEAD', '/w/' . $nameA1 . '/events.json')['status'], 200);
$one = json_decode($feedA('events.json?limit=1')['body'], true);
$fifty = $feedA('events.json?limit=50');
check('G05 limit=1 gives one event; limit=50 is accepted', [count($one['events'] ?? []), $fifty['status']], [1, 200]);

$card('root', 'door_replace', ['understood' => '1']);
$nameA2 = $doorName($siteA);
$refusals['a replaced name'] = $http('GET', '/w/' . $nameA1 . '/events.json');
check('G06 replacing the name: a new name, the OLD one refused at once, the new one answers, recorded as PublicDoorNameReplaced',
    [$nameA2 !== $nameA1 && preg_match('/^[0-9a-f]{24}$/', (string) $nameA2) === 1, $refusals['a replaced name']['status'],
        $feedA()['status'], $logged('PublicDoorNameReplaced', $people['root']['id'])],
    [true, 404, 200, 1]);
$card('root', 'door_off', ['understood' => '1']);
$refusals['the door switched off'] = $http('GET', '/w/' . $nameA2 . '/events.json');
check('G07 switching the door off deletes the row; the last name is refused; recorded as PublicDoorTurnedOff',
    [$doorName($siteA), $refusals['the door switched off']['status'], $logged('PublicDoorTurnedOff', $people['root']['id'])], [null, 404, 1]);
$card('root', 'door_on');
$nameA = (string) $doorName($siteA);
check('G08 turning it on again gives a NEW name (so a copy pasted before it was switched off stays stopped)',
    [$nameA !== $nameA2, $http('GET', '/w/' . $nameA2 . '/events.json')['status'], $feedA()['status']], [true, 404, 200]);
$checkPage = (static function () use ($http, $tokenOn, $people): string {
    $jar  = $people['root']['jar'];
    $page = $http('GET', '/admin/public-website', ['jar' => $jar]);
    $http('POST', '/admin/public-website/check', ['jar' => $jar, 'post' => ['csrf_token' => $tokenOn($page['body'])]]);
    return $http('GET', '/admin/public-website', ['jar' => $jar])['body'];
})();
check('G08b "Check my set-up" says the door is on and gives its events address — and still never ticks whether a website shows it',
    [str_contains($checkPage, 'Switched on. Its events are read from ' . $base . '/w/' . $nameA . '/events.json'),
        str_contains($checkPage, 'Whether your website really shows it is not tested by this check'),
        preg_match('/data-check-state="ok"[^>]*>\s*<span[^>]*>[^<]*<\/span>\s*<strong>The public door/', $checkPage)],
    [true, true, 0]);

// B and C, written straight into the database, with public addresses.
$nameB = bin2hex(random_bytes(12));
$nameC = bin2hex(random_bytes(12));
$db->query("INSERT INTO `tblPublicDoorNames` (`siteID`, `publicName`, `changedAtUtc`) VALUES ({$siteB}, '{$nameB}', UTC_TIMESTAMP()), ({$siteC}, '{$nameC}', UTC_TIMESTAMP())");
// Written APPROVED (D-101): sections G onwards test the door on an approved
// address; section D tests approval itself.
$db->query("INSERT INTO `tblPublicHosts` (`siteID`, `hostName`, `changedAtUtc`, `approvedAtUtc`) VALUES ({$siteA}, '{$hostA}', UTC_TIMESTAMP(), UTC_TIMESTAMP()), "
    . "({$siteB}, '{$hostB}', UTC_TIMESTAMP(), UTC_TIMESTAMP()), ({$siteC}, '{$hostC}', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
$refusals['an inactive organisation by name'] = $http('GET', '/w/' . $nameC . '/events.json');
$refusals['an inactive organisation by public address'] = $http('GET', '/events.json', ['host' => $hostC]);
check('G09 an INACTIVE organisation answers nothing, by name or by its public address (it has a door, a public address and a ticked event)',
    [$refusals['an inactive organisation by name']['status'], $refusals['an inactive organisation by public address']['status']], [404, 404]);
$pubA = $http('GET', '/events.json', ['host' => $hostA]);
$capture('events', 'a-public-host', $pubA);
check('G10 A\'s public address answers /events.json with A\'s events', [$pubA['status'], in_array('PDS A shown', $titles($pubA, 'events') ?? [], true)], [200, true]);
$refusals['a public address, path not a door address'] = $http('GET', '/login', ['host' => $hostA]);
$refusals['a public address, its root'] = $http('GET', '/', ['host' => $hostA]);
check('G11 a public address answers door requests ONLY (D-098): /login and / there are the door\'s 404, never a portal page',
    [$refusals['a public address, path not a door address']['status'], $refusals['a public address, its root']['status']], [404, 404]);

// Round 1 of P4a's check: "Replace the name" stops nothing on a public address,
// which carries no name. The card and the message must say so.
$kBefore = $http('GET', '/admin/public-website', ['jar' => $people['root']['jar']])['body'];
$k8 = $card('root', 'door_replace', ['understood' => '1']);
$refusals['the old name after a replace with a public address set'] = $http('GET', '/w/' . $nameA . '/events.json');
$nameA = (string) $doorName($siteA);
check('K08 with a public address set, replacing the name stops the old /w/ name but NOT the public address, and the card and the message say exactly that',
    [$refusals['the old name after a replace with a public address set']['status'], $http('GET', '/events.json', ['host' => $hostA])['status'],
        str_contains($kBefore, 'data-door-replace-public="1"'), str_contains($kBefore, 'Every copy pasted into a website with the old name stops working at once'),
        str_contains($k8['page'], 'is NOT affected by the name'), $feedA()['status']],
    [404, 200, true, false, true, 200]);
$k9 = $card('root', 'door_replace');
check('K09 with a public address set, the refusal for a missing tick says what the card\'s tick says: the public address is NOT stopped by a new name',
    [$doorName($siteA), str_contains($k9['page'], 'copies using your public address will not'),
        // The refusal's OWN words, whole: the switch-off tick on the same page
        // rightly says "every copy pasted into a website will stop working".
        str_contains($k9['page'], 'tick the box to say you understand that every copy pasted into a website will stop working')],
    [$nameA, true, false]);
// Round 2 of P4a's check: switching the door off and on again does NOT protect a
// leaked PUBLIC address — it answers again the moment the door is back on.
$card('root', 'door_off', ['understood' => '1']);
$offStatus = $http('GET', '/events.json', ['host' => $hostA])['status'];
$card('root', 'door_on');
$nameA = (string) $doorName($siteA);
check('K10 a public address is refused while the door is off and answers again the moment it is back on — which is why the card offers changing or removing it, not a new name, as the way to stop it',
    [$offStatus, $http('GET', '/events.json', ['host' => $hostA])['status'], $feedA()['status']], [404, 200, 200]);

// Round 1 of P4a's check (D-098): error.php and api-docs start the portal
// WITHOUT index.php, and on a real server .htaccess sends every refused .php,
// dotfile and 403/404/500/503 to error.php. On a public address both must give
// the door's identical 404, with no cookie and no session file.
$eBefore = $sessionFiles();
$refusals['error.php on a public address'] = $http('GET', '/error.php?code=404', ['host' => $hostA]);
$refusals['error.php (500) on a public address'] = $http('GET', '/error.php?code=500', ['host' => $hostA]);
$refusals['api-docs on a public address'] = $http('GET', '/api-docs/index.php', ['host' => $hostA]);
$refusals['error.php on a switched-off organisation\'s public address'] = $http('GET', '/error.php?code=404', ['host' => $hostC]);
check('E01 on a public address error.php (404 and 500) and the API description answer the door\'s 404 — no portal page, no menu, no cookie, no session file — and on the inactive C\'s address too',
    [$refusals['error.php on a public address']['status'], $refusals['error.php (500) on a public address']['status'],
        $refusals['api-docs on a public address']['status'], $refusals['error.php on a switched-off organisation\'s public address']['status'],
        $refusals['api-docs on a public address']['headers']['set-cookie'] ?? '', $sessionFiles() - $eBefore],
    [404, 404, 404, 404, '', 0]);
$eMembers = $http('GET', '/error.php?code=404');
check('E02 on the members\' own address error.php still draws the portal\'s own error page (the hand-off reaches door requests only)',
    [$eMembers['status'], str_contains($eMembers['body'], '<html'), $eMembers['body'] === "Not found.\n"], [404, true, false]);

// =============================================================================
echo "D. D-101: a public address takes effect only once the installation approves it\n";
// =============================================================================
// Round 6 of P4a's check: an organisation's administrator could save an
// address members really sign in at that the portal had no record of (an
// alias of the portal's own address), and every page there — sign-in included
// — became the door's 404, with no way back from that address. Now an address
// does NOTHING until the installation's global administrator approves it.
$instroot = static function () use ($http, $tokenOn, $signIn): string {
    $jar = $signIn('instroot');
    // The installation's global administrator belongs to every organisation,
    // so in this mode they choose one first.
    $page = $http('GET', '/site/choose', ['jar' => $jar]);
    $http('POST', '/site/switch', ['jar' => $jar, 'post' => ['csrf_token' => $tokenOn($page['body']), 'site_id' => '1']]);
    return $jar;
};
$hostRow = static function (int $site) use ($db): ?array {
    return $db->query("SELECT `hostName`, `approvedAtUtc`, `approvedByID` FROM `tblPublicHosts` WHERE `siteID` = {$site}")->fetch_assoc();
};
/** Is this answer the door's (its 404 or a feed)? Only the door sends this content rule; no portal page does. */
/** The approval moment of an organisation's address: null when waiting, 'no row' when it has none (never `??`, which reads null as absent). */
$approvedAt = static function (int $site) use ($hostRow): ?string {
    $row = $hostRow($site);
    return $row === null ? 'no row' : ($row['approvedAtUtc'] === null ? null : (string) $row['approvedAtUtc']);
};
$isDoor = static function (array $r): bool {
    return ($r['headers']['content-security-policy'] ?? '') === "default-src 'none'; frame-ancestors 'none'; sandbox";
};
$alias = 'intranet.selftest.test';
$signIn('setup');
$cardAs = static function (string $role, string $action, array $more = []) use ($http, $tokenOn, $people): array {
    $jar   = $people[$role]['jar'];
    $page  = $http('GET', '/admin/public-website', ['jar' => $jar]);
    $r     = $http('POST', '/admin/public-website/save', ['jar' => $jar, 'post' => ['csrf_token' => $tokenOn($page['body']), 'action' => $action] + $more]);
    return ['status' => $r['status'], 'page' => $http('GET', '/admin/public-website', ['jar' => $jar])['body']];
};
// The takeover scenario: A's "Set up the public website" holder saves an alias
// members sign in at, which the portal has no record of.
$d1 = $cardAs('setup', 'public_host', ['value' => $alias]);
$aliasLogin  = $http('GET', '/login', ['host' => $alias]);
$aliasEvents = $http('GET', '/events.json', ['host' => $alias]);
check('D01 an organisation saving an address the portal has no record of (an alias members sign in at) gets a row that waits — and until approved the alias is NOT the door: its sign-in page still answers, and /events.json there is not the door',
    [$hostRow($siteA)['hostName'] ?? null, $approvedAt($siteA), str_contains($d1['page'], 'data-public-host-approval="waiting"'),
        $aliasLogin['status'], str_contains($aliasLogin['body'], 'name="password"'), $isDoor($aliasEvents)],
    [$alias, null, true, 200, true, false]);
check('D02 the address A used before (approved) stopped being the door the moment it was changed — saving a new address drops approval',
    $isDoor($http('GET', '/events.json', ['host' => $hostA])), false);
$refusedBefore = $logged('PublicWebsiteSetupRefused', $people['setup']['id']) + $logged('PublicWebsiteSetupRefused', $people['root']['id']);
$d3a = $cardAs('setup', 'public_host_approve', ['site' => '1', 'host' => $alias]);
$d3b = $cardAs('root', 'public_host_approve', ['site' => '1', 'host' => $alias]);
$signIn('tester');
$d3c = $cardAs('tester', 'public_host_approve', ['site' => '1', 'host' => $alias]);
check('D03 nobody but the installation\'s global administrator can approve: the "Set up the public website" holder and A\'s own global administrator are refused and it is recorded; an ordinary administrator cannot even open the page',
    [$approvedAt($siteA), $logged('PublicWebsiteSetupRefused', $people['setup']['id']) + $logged('PublicWebsiteSetupRefused', $people['root']['id']) - $refusedBefore,
        str_contains($d3a['page'], 'Only a global administrator of the whole installation can approve'), $d3c['status']],
    [null, 2, true, 403]);
$jarRootI = $instroot();
$instPage = $http('GET', '/admin/public-website', ['jar' => $jarRootI])['body'];
$stale = $cardAs('instroot', 'public_host_approve', ['site' => '1', 'host' => 'something-else.selftest.test']);
check('D04 the installation\'s global administrator sees every organisation\'s address in one list, waiting; approving an address that is no longer the one stored changes nothing',
    [str_contains($instPage, 'data-public-host-approvals="1"'), str_contains($instPage, $alias), $approvedAt($siteA),
        str_contains($stale['page'], 'no longer the one waiting')],
    [true, true, null, true]);
$cardAs('instroot', 'public_host_approve', ['site' => '1', 'host' => $alias]);
$row = $hostRow($siteA);
check('D05 approved by the installation\'s global administrator, the address IS the door — recorded with who approved',
    [$row['approvedAtUtc'] !== null, (int) ($row['approvedByID'] ?? 0), $logged('PublicWebsitePublicAddressApproved', $people['instroot']['id']),
        $http('GET', '/events.json', ['host' => $alias])['status']],
    [true, $people['instroot']['id'], 1, 200]);
$cardAs('instroot', 'public_host_withdraw', ['site' => '1']);
check('D06 withdrawing approval takes effect AT ONCE: the next request is not the door, and the members\' pages answer there again; recorded',
    [$isDoor($http('GET', '/events.json', ['host' => $alias])), $http('GET', '/login', ['host' => $alias])['status'],
        $logged('PublicWebsitePublicAddressApprovalWithdrawn', $people['instroot']['id'])],
    [false, 200, 1]);
// Back to A's own address, approved, for the sections that follow.
$cardAs('setup', 'public_host', ['value' => $hostA]);
check('D07 changing the address again leaves it waiting, whatever was approved before', $approvedAt($siteA), null);
$cardAs('instroot', 'public_host_approve', ['site' => '1', 'host' => $hostA]);
check('D08 approved again, A\'s own address is the door again', $http('GET', '/events.json', ['host' => $hostA])['status'], 200);
$setInstallation('site.url', null);
$d9 = $cardAs('setup', 'public_host', ['value' => 'another.selftest.test']);
$setInstallation('site.url', $base);
check('D09 while the portal\'s own address is not set, no public address can be saved (the portal cannot tell which addresses members sign in at)',
    [$hostRow($siteA)['hostName'] ?? null, str_contains($d9['page'], 'own address is not set, so it cannot tell')], [$hostA, true]);

// ── Round 7 of P4a's check: three D-101 guards were pinned by NOTHING ───────
// (each removable with every check green), so each now has a scenario that
// the check below AND a plant in section P both run. Each scenario puts back
// what it changed: A's address approved, B's approved, site.url set.
/** (a) An organisation's FIRST public address — the INSERT branch — waits too. D01 and P58 only reach the UPDATE. */
$scnFirstAddress = static function () use ($db, $siteA, $hostA, $cardAs, $approvedAt, $isDoor, $http): array {
    $db->query("DELETE FROM `tblPublicHosts` WHERE `siteID` = {$siteA}");
    $cardAs('setup', 'public_host', ['value' => 'first.selftest.test']);
    $out = [$approvedAt($siteA), $isDoor($http('GET', '/events.json', ['host' => 'first.selftest.test']))];
    $db->query("DELETE FROM `tblPublicHosts` WHERE `siteID` = {$siteA}");
    $db->query("INSERT INTO `tblPublicHosts` (`siteID`, `hostName`, `changedAtUtc`, `approvedAtUtc`) VALUES ({$siteA}, '{$hostA}', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    return $out;
};
check('D10 an organisation with NO public address yet saves its first one: it is stored WAITING, and it is not the door',
    $scnFirstAddress(), [null, false]);
/** (b) Withdrawing is the installation's global administrator's alone — D03 and P59 only tried approving. */
$scnWithdrawRefused = static function () use ($db, $siteA, $siteB, $cardAs, $approvedAt, $logged, $people): array {
    $refusals = static fn (): int => $logged('PublicWebsiteSetupRefused', $people['setup']['id']) + $logged('PublicWebsiteSetupRefused', $people['root']['id']);
    $before = $refusals();
    $cardAs('setup', 'public_host_withdraw', ['site' => '1']);
    $cardAs('root', 'public_host_withdraw', ['site' => '1']);
    $tester = $cardAs('tester', 'public_host_withdraw', ['site' => '1']);
    // Another organisation's, by changing the posted `site` field.
    $cross = $cardAs('setup', 'public_host_withdraw', ['site' => (string) $siteB]);
    $out = [$approvedAt($siteA) !== null, $approvedAt($siteB) !== null, $refusals() - $before, $tester['status'],
        str_contains($cross['page'], 'Only a global administrator of the whole installation can withdraw')];
    $db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` IN ({$siteA}, {$siteB}) AND `approvedAtUtc` IS NULL");
    return $out;
};
check('D11 nobody but the installation\'s global administrator can WITHDRAW approval: the "Set up the public website" holder and A\'s own global administrator are refused and it is recorded, an ordinary administrator cannot open the page, and A\'s holder cannot withdraw B\'s by changing the posted organisation',
    $scnWithdrawRefused(), [true, true, 3, 403, true]);
/** (c) An UNAPPROVED address is shown nowhere as the door's: not in the card's addresses, not as the replace advice, not in the refusal's words, and whyNoAddress() still explains. */
$scnUnapprovedNotShown = static function () use ($db, $siteA, $hostA, $cardAs, $http, $people, $setInstallation, $base): array {
    $db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = NULL WHERE `siteID` = {$siteA}");
    $page = $http('GET', '/admin/public-website', ['jar' => $people['root']['jar']])['body'];
    $addr = preg_match('/data-door-address="events">([^<]*)</', $page, $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : '';
    $refusal = $cardAs('root', 'door_replace');
    $setInstallation('site.url', null);
    $noAddr = $http('GET', '/admin/public-website', ['jar' => $people['root']['jar']])['body'];
    $setInstallation('site.url', $base);
    $db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = {$siteA}");
    return [str_contains($addr, $hostA), str_contains($addr, '/w/'), str_contains($page, 'data-door-replace-public="1"'),
        str_contains($refusal['page'], 'tick the box to say you understand that every copy pasted into a website will stop working'),
        str_contains($noAddr, 'own address is not set, so the door answers nothing on it yet')];
};
check('D12 with A\'s address WAITING, the card gives the /w/ address and not the public one, offers no "public address is not affected" advice, the missing-tick refusal says every copy stops, and with no portal address the card still says why there is none',
    $scnUnapprovedNotShown(), [false, true, false, true, true]);
/** An APPROVED address that is also an address members sign in at is not the door (publicHostRow() refuses it), and the card must not claim it is. */
$scnMembersAddress = static function () use ($db, $siteA, $siteB, $hostA, $people, $http, $isDoor): array {
    $db->query("UPDATE `tblSites` SET `hostPattern` = '{$hostA}' WHERE `siteID` = {$siteB}");
    $page = $http('GET', '/admin/public-website', ['jar' => $people['root']['jar']])['body'];
    $door = $isDoor($http('GET', '/events.json', ['host' => $hostA]));
    $list = $http('GET', '/admin/public-website', ['jar' => $people['instroot']['jar']])['body'];
    $db->query("UPDATE `tblSites` SET `hostPattern` = NULL WHERE `siteID` = {$siteB}");
    return [str_contains($page, 'data-public-host-approval="approved-members-address"'), str_contains($page, 'https://' . $hostA . '/events.json'), $door,
        str_contains($list, 'data-public-host-row="' . $siteA . '" data-public-host-row-state="approved-members-address"')];
};
check('D13 an approved address that is ALSO an address members sign in at: the card says the door does not answer on it and does not offer it as the door\'s address, it is not the door, and the installation\'s list says "not in use" (round 8)',
    $scnMembersAddress(), [true, false, false, true]);
/** The installation's list says when it could not be read, never "no organisation has entered one". */
$scnListUnreadable = static function () use ($db, $http, $people): array {
    $db->query('RENAME TABLE `tblPublicHosts` TO `tblPublicHostsPdsAside`');
    try {
        $page = $http('GET', '/admin/public-website', ['jar' => $people['instroot']['jar']])['body'];
    } finally {
        $db->query('RENAME TABLE `tblPublicHostsPdsAside` TO `tblPublicHosts`');
    }
    return [str_contains($page, 'data-public-host-list-unreadable="1"'), str_contains($page, 'No organisation has entered a public address')];
};
// A run killed while the table is renamed would leave it aside: put it back.
register_shutdown_function(static function () use ($db): void {
    try {
        $rs = $db->query("SHOW TABLES LIKE 'tblPublicHostsPdsAside'");
        if ($rs !== false && $rs->num_rows === 1) {
            $db->query('RENAME TABLE `tblPublicHostsPdsAside` TO `tblPublicHosts`');
        }
    } catch (Throwable $e) {
        echo "  ! tblPublicHosts may still be called tblPublicHostsPdsAside; rename it back by hand.\n";
    }
});
check('D14 when the public addresses cannot be read, the installation\'s list says so, and does not claim no organisation has entered one',
    $scnListUnreadable(), [true, false]);
/** Approving is refused while the portal's own address is not set, as saving is. */
$scnApproveNoSiteUrl = static function () use ($db, $siteA, $hostA, $cardAs, $approvedAt, $setInstallation, $base): array {
    $db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = NULL WHERE `siteID` = {$siteA}");
    $setInstallation('site.url', null);
    $r = $cardAs('instroot', 'public_host_approve', ['site' => '1', 'host' => $hostA]);
    $setInstallation('site.url', $base);
    $out = [$approvedAt($siteA) !== null, str_contains($r['page'], 'cannot tell whether this is an address members sign in at')];
    $db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = {$siteA}");
    return $out;
};
check('D15 while the portal\'s own address is not set, the installation\'s global administrator cannot approve a public address either, and is told why',
    $scnApproveNoSiteUrl(), [false, true]);
$db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = NULL WHERE `siteID` = {$siteA}");
$d16 = $cardAs('instroot', 'public_host_approve', ['site' => '1', 'host' => strtoupper($hostA) . ' ']);
$d16Waiting = $approvedAt($siteA);
$cardAs('instroot', 'public_host_approve', ['site' => '1', 'host' => $hostA]);
$d16Said = (string) ($db->query("SELECT `activityDescription` FROM `tblActivityLogs` WHERE `activityType` = 'PublicWebsitePublicAddressApproved' "
    . "AND `userID` = {$people['instroot']['id']} ORDER BY `logID` DESC LIMIT 1")->fetch_assoc()['activityDescription'] ?? '');
check('D16 an approval must name the address exactly as stored (MySQL would match other capitals and trailing spaces): a different spelling changes nothing, and the approval recorded names the STORED address',
    [$d16Waiting, str_contains($d16['page'], 'no longer the one waiting'), $approvedAt($siteA) !== null, str_contains($d16Said, ' ' . $hostA . ' ')],
    [null, true, true, true]);

// =============================================================================
echo "Q. D-121 (#895): several organisations may ask for one address; only one is approved\n";
// =============================================================================
// Until #895 an address was unique the moment it was ASKED for, so one
// organisation could type another's real website address first and block it.
// D-121: any number may ask while it waits; the installation's global
// administrator sees the clash and approves ONE; the others are then shown it
// as taken. Uniqueness is enforced at approval, by a lock inside a transaction
// AND by the database's unique key on the worked-out column `approvedHost`
// (migration 276). Every scenario here puts back what it changed: A's own
// address approved, B's own address approved.
//
// A plays every "other" organisation that asks: it is the one organisation
// with a "Set up the public website" holder, so it is the one that can ask
// through the real page. B's request is written straight into the database.
// "A third organisation asking later" is therefore A asking AFTER B was
// approved — the property is the same (an organisation that is not the
// approved one, asking once the name is approved), and it is driven through
// the real form.
$qName = 'shared-895.selftest.test';
$qBName = 'Selftest Door B';
/** Both organisations asking for the one name, neither approved. B first, so B was "first come". */
$qBothWaiting = static function () use ($db, $siteA, $siteB, $qName): void {
    $db->query("UPDATE `tblPublicHosts` SET `hostName` = '{$qName}', `approvedAtUtc` = NULL, `approvedByID` = NULL WHERE `siteID` IN ({$siteA}, {$siteB})");
};
/** Back to how every later section expects it: A on its own address, B on its own, both approved. */
$qPutBack = static function () use ($db, $siteA, $siteB, $hostA, $hostB): void {
    $db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = NULL WHERE `siteID` IN ({$siteA}, {$siteB})");
    $db->query("UPDATE `tblPublicHosts` SET `hostName` = '{$hostA}', `approvedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = {$siteA}");
    $db->query("UPDATE `tblPublicHosts` SET `hostName` = '{$hostB}', `approvedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = {$siteB}");
};
/** One organisation's row on the installation's list: its state, and whether it offers an Approve button. */
$qListRow = static function (string $page, int $site): array {
    if (preg_match('#<li class="mb-2" data-public-host-row="' . $site . '" data-public-host-row-state="([a-z\-]+)">(.*?)</li>#s', $page, $m) !== 1) {
        return ['missing', false, ''];
    }
    return [$m[1], str_contains($m[2], 'value="public_host_approve"'), trim((string) preg_replace('/\s+/', ' ', strip_tags($m[2])))];
};
/** The organisation card's own word for its address. */
$qCardState = static function (string $page): string {
    return preg_match('/data-public-host-approval="([a-z\-]+)"/', $page, $m) === 1 ? $m[1] : 'missing';
};

/** Q01-Q06: ask, see the clash, approve one, the other is taken, the door answers for the approved one only. */
$scnQClash = static function () use ($db, $siteA, $siteB, $qName, $qBName, $hostB, $cardAs, $http, $isDoor, $titles, $approvedAt,
    $qListRow, $qCardState, $qPutBack, $people): array {
    $out = [];
    // B asks first (written straight in), then A asks for the same name through the real form.
    $db->query("UPDATE `tblPublicHosts` SET `hostName` = '{$qName}', `approvedAtUtc` = NULL, `approvedByID` = NULL WHERE `siteID` = {$siteB}");
    $db->query("UPDATE `tblPublicHosts` SET `hostName` = 'door-a.selftest.test', `approvedAtUtc` = NULL WHERE `siteID` = {$siteA}");
    $ask = $cardAs('setup', 'public_host', ['value' => $qName]);
    $out['asked'] = [$db->query("SELECT `hostName` FROM `tblPublicHosts` WHERE `siteID` = {$siteA}")->fetch_assoc()['hostName'] ?? null,
        $approvedAt($siteA), $approvedAt($siteB), $qCardState($ask['page']),
        str_contains($ask['page'], 'Another organisation on this portal has asked for the same address'), str_contains($ask['page'], $qBName)];
    $list = $http('GET', '/admin/public-website', ['jar' => $people['instroot']['jar']])['body'];
    $rowA = $qListRow($list, $siteA);
    $rowB = $qListRow($list, $siteB);
    $out['list before'] = [$rowA[0], $rowA[1], str_contains($rowA[2], 'Also asked for by ' . $qBName), $rowB[0], $rowB[1]];
    $out['door before'] = $isDoor($http('GET', '/events.json', ['host' => $qName]));
    // The installation's global administrator approves B.
    $ok = $cardAs('instroot', 'public_host_approve', ['site' => (string) $siteB, 'host' => $qName]);
    $cardA = $http('GET', '/admin/public-website', ['jar' => $people['setup']['jar']])['body'];
    $list  = $http('GET', '/admin/public-website', ['jar' => $people['instroot']['jar']])['body'];
    $rowA  = $qListRow($list, $siteA);
    $rowB  = $qListRow($list, $siteB);
    $out['approved'] = [$approvedAt($siteA), $approvedAt($siteB) !== null,
        str_contains($ok['page'], 'The other organisation that asked for it is now told the address is taken')];
    $out['taken on the card'] = [$qCardState($cardA), str_contains($cardA, 'This address is taken'), str_contains($cardA, $qBName)];
    $out['taken on the list'] = [$rowA[0], $rowA[1], str_contains($rowA[2], 'Approved for ' . $qBName), $rowB[0]];
    // The door, on the shared name: B's, not A's (A's own door is on and holds ticked events).
    $onName = $http('GET', '/events.json', ['host' => $qName]);
    $db->query("UPDATE `tblPublicHosts` SET `hostName` = '{$hostB}' WHERE `siteID` = {$siteB}");
    $bOwn = $http('GET', '/events.json', ['host' => $hostB]);
    $db->query("UPDATE `tblPublicHosts` SET `hostName` = '{$qName}' WHERE `siteID` = {$siteB}");
    $out['door after'] = [$onName['status'], $isDoor($onName), $titles($onName, 'events') === $titles($bOwn, 'events'),
        in_array('PDS A shown', $titles($onName, 'events') ?? [], true)];
    // Approving A now is refused, by the LOCKED ASK (not the database's backstop), naming B.
    $again = $cardAs('instroot', 'public_host_approve', ['site' => (string) $siteA, 'host' => $qName]);
    $out['second approval'] = [$approvedAt($siteA), $approvedAt($siteB) !== null,
        str_contains($again['page'], 'That address is already approved for another organisation (' . $qBName . ')'),
        str_contains($again['page'], 'The database itself refused')];
    $qPutBack();
    return $out;
};
$qClash = $scnQClash();
check('Q01 a name another organisation has only ASKED for is accepted: stored waiting beside theirs, the card says another organisation asked as well, and never names it',
    $qClash['asked'], ['shared-895.selftest.test', null, null, 'waiting-clashing', true, false]);
check('Q02 the installation\'s list shows the clash before anybody approves: both rows "waiting, clashes", each with an Approve button, and A\'s row names B',
    $qClash['list before'], ['waiting-clashing', true, true, 'waiting-clashing', true]);
check('Q03 while both only ask, the name is nobody\'s door', $qClash['door before'], false);
check('Q04 approving B: B approved, A not, and the approver is told the other organisation is now shown it as taken',
    $qClash['approved'], [null, true, true]);
check('Q05 A is then shown the address as TAKEN — on its card, without naming B — and on the installation\'s list as taken by B, with NO Approve button',
    [$qClash['taken on the card'], $qClash['taken on the list']], [['taken', true, false], ['taken', false, true, 'approved']]);
check('Q06 the door answers on the shared name for B, the approved one, and never shows A\'s events there',
    $qClash['door after'], [200, true, true, false]);
check('Q07 approving A once B is approved is refused by the locked ask (not the database\'s backstop), naming B to the installation\'s administrator; nothing changes',
    $qClash['second approval'], [null, true, true, false]);

/** Q08: asking LATER for a name another organisation is approved for is refused, and the old row stays. */
$scnQLater = static function () use ($db, $siteA, $siteB, $qName, $qBName, $cardAs, $approvedAt, $logged, $people, $qPutBack): array {
    $db->query("UPDATE `tblPublicHosts` SET `hostName` = '{$qName}', `approvedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = {$siteB}");
    $db->query("UPDATE `tblPublicHosts` SET `hostName` = 'elsewhere-895.selftest.test', `approvedAtUtc` = NULL WHERE `siteID` = {$siteA}");
    $before = $logged('PublicWebsiteSetupRefused', $people['setup']['id']);
    $r = $cardAs('setup', 'public_host', ['value' => strtoupper($qName)]);
    $out = [$db->query("SELECT `hostName` FROM `tblPublicHosts` WHERE `siteID` = {$siteA}")->fetch_assoc()['hostName'] ?? null,
        $approvedAt($siteB) !== null, str_contains($r['page'], 'That public address is taken'), str_contains($r['page'], $qBName),
        $logged('PublicWebsiteSetupRefused', $people['setup']['id']) - $before];
    $qPutBack();
    return $out;
};
check('Q08 an organisation asking for a name ANOTHER organisation is already approved for (in other capitals) is refused as taken, its own address unchanged, without naming the other, and recorded',
    $scnQLater(), ['elsewhere-895.selftest.test', true, true, false, 1]);

/** Q09: a request that lost is KEPT, not cleared: withdraw the winner and it is waiting again, and can be approved. */
$scnQKept = static function () use ($db, $siteA, $siteB, $qName, $cardAs, $http, $approvedAt, $qCardState, $people, $qBothWaiting, $qPutBack): array {
    $qBothWaiting();
    $db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = {$siteB}");
    $taken = $qCardState($http('GET', '/admin/public-website', ['jar' => $people['setup']['jar']])['body']);
    $cardAs('instroot', 'public_host_withdraw', ['site' => (string) $siteB]);
    $after = $qCardState($http('GET', '/admin/public-website', ['jar' => $people['setup']['jar']])['body']);
    $cardAs('instroot', 'public_host_approve', ['site' => (string) $siteA, 'host' => $qName]);
    $out = [$taken, $after, $approvedAt($siteA) !== null, $approvedAt($siteB)];
    $qPutBack();
    return $out;
};
check('Q09 the losing request is kept as it was: withdrawing the winner\'s approval makes it read "waiting, clashes" again, and it can then be approved',
    $scnQKept(), ['taken', 'waiting-clashing', true, null]);

/** Q10: the database's own rule — two requests may share a name, two APPROVALS may not. */
$scnQKey = static function () use ($db, $siteA, $siteB, $qBothWaiting, $qPutBack): array {
    $qBothWaiting();
    $db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = {$siteA}");
    try {
        $db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = {$siteB}");
        $code = 0;
    } catch (mysqli_sql_exception $e) {
        $code = $e->getCode();
    }
    $approved = (int) ($db->query("SELECT COUNT(*) AS `n` FROM `tblPublicHosts` WHERE `hostName` = 'shared-895.selftest.test' AND `approvedAtUtc` IS NOT NULL")->fetch_assoc()['n'] ?? 0);
    $qPutBack();
    return [$code, $approved];
};
check('Q10 the database itself refuses a second APPROVED row for one name (error 1062), whatever the code does', $scnQKey(), [1062, 1]);

/**
 * Q11: the LOCK, made visible. This connection plays the first approver: it
 * opens a transaction, locks every row asking for the name exactly as
 * `approvePublicHost()` does, and approves B — without committing. The real
 * page is then asked to approve A. It must WAIT (the premise, asserted, not
 * hoped for: a sleep is a hope, a still-running request is a fact), and once
 * this connection commits it must be refused by the locked ask. Without the
 * lock the request does not wait on the ask; with the key there behind it the
 * database's backstop refuses it instead, and the words say so — which is how
 * the lock is told apart from the key. With BOTH gone, both are approved.
 */
$scnQInterleave = static function () use ($db, $siteA, $siteB, $qName, $port, $people, $tokenOn, $http, $qBothWaiting, $qPutBack): array {
    $qBothWaiting();
    $jar   = $people['instroot']['jar'];
    $token = $tokenOn($http('GET', '/admin/public-website', ['jar' => $jar])['body']);
    $db->begin_transaction();
    $hold = $db->prepare('SELECT `siteID` FROM `tblPublicHosts` WHERE `hostName` = ? FOR UPDATE');
    $hold->bind_param('s', $qName);
    $hold->execute();
    $hold->get_result()->fetch_all();
    $hold->close();
    $db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = {$siteB}");
    $multi = curl_multi_init();
    $one   = curl_init('http://127.0.0.1:' . $port . '/admin/public-website/save');
    curl_setopt_array($one, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 90,
        CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => $token, 'action' => 'public_host_approve', 'site' => (string) $siteA, 'host' => $qName])]);
    curl_multi_add_handle($multi, $one);
    $until = microtime(true) + 1.5;
    $running = 1;
    while (microtime(true) < $until && $running > 0) {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.1);
    }
    $stillWaiting = $running > 0;
    $db->commit();
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.2);
    } while ($running > 0);
    curl_multi_remove_handle($multi, $one);
    curl_multi_close($multi);
    $page = $http('GET', '/admin/public-website', ['jar' => $jar])['body'];
    $approved = (int) ($db->query("SELECT COUNT(*) AS `n` FROM `tblPublicHosts` WHERE `hostName` = '{$qName}' AND `approvedAtUtc` IS NOT NULL")->fetch_assoc()['n'] ?? 0);
    $out = ['the request waited' => $stillWaiting, 'approved' => $approved,
        'refused by the ask' => str_contains($page, 'That address is already approved for another organisation')
            && str_contains($page, 'The database itself refused') === false];
    $qPutBack();
    return $out;
};
check('Q11 a second approval made while the first is still open WAITS on its lock, and is then refused by the locked ask: exactly one approved',
    $scnQInterleave(), ['the request waited' => true, 'approved' => 1, 'refused by the ask' => true]);

/**
 * Q12: a real race. A SECOND web server on the next port serves the same copy,
 * and the installation's global administrator is signed in twice (two
 * sessions: one session's file lock would make two requests take turns). Both
 * approvals are sent at once, A on one server and B on the other, five times,
 * swapping which server asks for which. Every time exactly one is approved.
 */
$scnQRace = static function () use ($db, $siteA, $siteB, $qName, $port, $child, $sep, $sessions, $people, $tokenOn, $qBothWaiting, $qPutBack): array {
    $port2 = $port + 1;
    $env   = getenv();
    unset($env['PHP_CLI_SERVER_WORKERS']);
    $env['PORTAL_ENV'] = 'prod';
    $pipes = [];
    $proc  = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions, '-d', 'session.save_handler=files',
        '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1', '-d', 'display_errors=0',
        '-d', 'opcache.enable=0', '-S', '127.0.0.1:' . $port2, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server2.log', 'a'], 2 => ['file', $child . $sep . 'server2.log', 'a']],
        $pipes, $child, $env);
    $req = static function (int $onPort, string $method, string $path, string $jar, array $post = []): string {
        $h = curl_init('http://127.0.0.1:' . $onPort . $path);
        $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_CUSTOMREQUEST => $method];
        if ($post !== []) {
            $o[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($h, $o);
        return (string) curl_exec($h);
    };
    try {
        $up = false;
        for ($i = 0; $i < 80 && $up === false; $i++) {
            $up = @fsockopen('127.0.0.1', $port2) !== false;
            if ($up === false) {
                usleep(250000);
            }
        }
        if ($up === false) {
            return ['the second server started' => false];
        }
        // Two sessions of the installation's global administrator, one per server.
        $jars = [];
        foreach ([$port, $port2] as $k => $onPort) {
            $jar = $child . $sep . 'jar-race-' . $k . '.txt';
            @unlink($jar);
            $t = $tokenOn($req($onPort, 'GET', '/login', $jar));
            $req($onPort, 'POST', '/login', $jar, ['csrf_token' => $t, 'username' => $people['instroot']['login'], 'password' => $people['instroot']['password']]);
            $t = $tokenOn($req($onPort, 'GET', '/site/choose', $jar));
            $req($onPort, 'POST', '/site/switch', $jar, ['csrf_token' => $t, 'site_id' => '1']);
            $jars[$k] = $jar;
        }
        $rounds = [];
        for ($round = 0; $round < 5; $round++) {
            $qBothWaiting();
            $targets = $round % 2 === 0 ? [$siteA, $siteB] : [$siteB, $siteA];
            $multi = curl_multi_init();
            $hs = [];
            foreach ([$port, $port2] as $k => $onPort) {
                $t = $tokenOn($req($onPort, 'GET', '/admin/public-website', $jars[$k]));
                $h = curl_init('http://127.0.0.1:' . $onPort . '/admin/public-website/save');
                curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 90,
                    CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_COOKIEFILE => $jars[$k], CURLOPT_COOKIEJAR => $jars[$k],
                    CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => $t, 'action' => 'public_host_approve',
                        'site' => (string) $targets[$k], 'host' => $qName])]);
                curl_multi_add_handle($multi, $h);
                $hs[] = $h;
            }
            do {
                curl_multi_exec($multi, $running);
                curl_multi_select($multi, 0.1);
            } while ($running > 0);
            $codes = [];
            foreach ($hs as $h) {
                $codes[] = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
                curl_multi_remove_handle($multi, $h);
            }
            curl_multi_close($multi);
            // What each administrator was told (the flash waits in each session
            // for its next page). Round 1 of #895's check: with the lock taken
            // OUTSIDE the transaction, exactly one is still approved — the key
            // refuses the second — so the count alone passes on that broken
            // code. The refusal must come from the locked ASK.
            $said = '';
            foreach ([$port, $port2] as $k => $onPort) {
                $said .= $req($onPort, 'GET', '/admin/public-website', $jars[$k]);
            }
            $rounds[] = [(int) ($db->query("SELECT COUNT(*) AS `n` FROM `tblPublicHosts` WHERE `hostName` = '{$qName}' AND `approvedAtUtc` IS NOT NULL")->fetch_assoc()['n'] ?? 0), $codes,
                str_contains($said, 'That address is already approved for another organisation'), str_contains($said, 'The database itself refused')];
        }
        return ['the second server started' => true, 'rounds' => $rounds];
    } finally {
        if (is_resource($proc) === true) {
            proc_terminate($proc);
            proc_close($proc);
        }
        $qPutBack();
    }
};
$qRaceWant = ['the second server started' => true, 'rounds' => array_fill(0, 5, [1, [302, 302], true, false])];
$qRace = $scnQRace();
check('Q12 two administrators approving the two clashing requests at the same moment, on two web servers, five times: exactly one is approved every time, and the other is refused by the locked ask, never by the database\'s backstop',
    $qRace, $qRaceWant);

/**
 * Q13: the approver HOLDS the lock on every row asking for the name until it
 * writes — made visible with the database's key IN PLACE.
 *
 * Round 1 of #895's check found that Q11 and Q12 both passed with the lock
 * taken OUTSIDE the transaction (plant MP1: `begin_transaction()` moved after
 * the locking read, so the read's locks end with its own statement — #697's
 * shape). Q11 cannot see it: the request's read waits on THIS test's lock,
 * and on waking it reads the committed approval anyway. Q12 sees it only when
 * the two requests happen to overlap inside a few milliseconds.
 *
 * So the request is stopped DELIBERATELY between its read and its write: this
 * connection takes a gap lock on the unique key where the approved name would
 * go (`WHERE approvedHost = ? FOR UPDATE`, which finds no row), so the
 * request's locking read (through the ordinary key on hostName) is NOT held
 * up, but its UPDATE — which puts the name into the unique key — waits. While
 * it waits, a SECOND connection asks for B's row with NOWAIT. A request that
 * still holds its read's locks has B's row, so that ask is refused at once;
 * one whose locks ended with the read does not, and the ask succeeds.
 */
$scnQHeld = static function () use ($db, $dbHost, $dbUser, $dbPass, $dbName, $dbPort, $siteA, $siteB, $qName, $port, $people, $tokenOn,
    $http, $qBothWaiting, $qPutBack): array {
    $qBothWaiting();
    $jar   = $people['instroot']['jar'];
    $token = $tokenOn($http('GET', '/admin/public-website', ['jar' => $jar])['body']);
    $db->begin_transaction();
    $gap = $db->prepare('SELECT `siteID` FROM `tblPublicHosts` WHERE `approvedHost` = ? FOR UPDATE');
    $gap->bind_param('s', $qName);
    $gap->execute();
    $gap->get_result()->fetch_all();
    $gap->close();
    $multi = curl_multi_init();
    $one   = curl_init('http://127.0.0.1:' . $port . '/admin/public-website/save');
    curl_setopt_array($one, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 90,
        CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => $token, 'action' => 'public_host_approve', 'site' => (string) $siteA, 'host' => $qName])]);
    curl_multi_add_handle($multi, $one);
    $until = microtime(true) + 1.5;
    $running = 1;
    while (microtime(true) < $until && $running > 0) {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.1);
    }
    $stillWaiting = $running > 0;
    // The second connection: is B's row still locked by the waiting request?
    $held = 'not asked';
    try {
        $other = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
        $other->begin_transaction();
        try {
            $other->query("SELECT `siteID` FROM `tblPublicHosts` WHERE `siteID` = {$siteB} FOR UPDATE NOWAIT")->fetch_all();
            $held = false;
        } catch (mysqli_sql_exception $e) {
            $held = in_array($e->getCode(), [3572, 1205], true) === true ? true : 'error ' . $e->getCode();
        }
        $other->rollback();
        $other->close();
    } catch (Throwable $e) {
        $held = 'could not ask: ' . $e->getMessage();
    }
    $db->commit();
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.2);
    } while ($running > 0);
    curl_multi_remove_handle($multi, $one);
    curl_multi_close($multi);
    $approved = $db->query("SELECT `siteID` FROM `tblPublicHosts` WHERE `hostName` = '{$qName}' AND `approvedAtUtc` IS NOT NULL")->fetch_all();
    $out = ['the request waited at its write' => $stillWaiting, 'B\'s row still locked by it' => $held,
        'approved' => array_map(static fn (array $r): int => (int) $r[0], $approved) === [$siteA] ? 'A only' : 'not A alone'];
    $qPutBack();
    return $out;
};
check('Q13 between its locking read and its write, the approver still holds the lock on the OTHER organisation\'s row asking for the name (a second connection is refused it at once), with the database\'s key in place; then A alone is approved',
    $scnQHeld(), ['the request waited at its write' => true, 'B\'s row still locked by it' => true, 'approved' => 'A only']);

/**
 * Q14: what each organisation's OWN administrator reads in its OWN activity
 * log about approvals (#895, round 1 of its check — measured there: the
 * losing organisation's ordinary administrator read "…already approved for
 * another organisation (Selftest Door B)…", and the approval line named the
 * winner by number with its address). The log is stamped with the
 * organisation the installation's administrator has OPEN, so with A open the
 * decisions about B land in A's log, and with B open the decisions about A
 * land in B's.
 *
 * A fresh name, set straight into the database, so no line any organisation
 * wrote itself mentions it: here the address IS another organisation's
 * information, and any sight of it in A's log is a leak.
 *
 * Round 2 of #895's check added two things, each because a plant got past:
 *   - A THIRD organisation holding the address. With only A and B, a refusal
 *     about B made while A is open can only have A as the holder, so a
 *     recorder that wrote the SHOWN words (naming the holder) whenever the
 *     refusal was about another organisation (plant M2) left everything
 *     green. Here C (the inactive organisation, which has a public-address
 *     row) holds it, B asks for it, and the installation's administrator,
 *     with A open, tries to approve B.
 *   - The EXPORT (/admin/activity/export), which round 2's privacy argument
 *     rests on — its filter `siteID = ? OR siteID IS NULL` — and which no
 *     test read. Each ordinary administrator reads it as well as the page.
 */
$scnQLogs = static function () use ($db, $siteA, $siteB, $siteC, $hostC, $nameTest, $cardAs, $http, $tokenOn, $people, $signIn, $instroot, $qPutBack): array {
    $name = 'privacy-895.selftest.test';
    $db->query("UPDATE `tblPublicHosts` SET `hostName` = '{$name}', `approvedAtUtc` = NULL, `approvedByID` = NULL WHERE `siteID` IN ({$siteA}, {$siteB})");
    $look = static function (string $page, string $pattern): bool {
        return preg_match($pattern, $page) === 1;
    };
    $bNumber = '/organisation #' . $siteB . '(?![0-9])/i';
    $aNumber = '/organisation #' . $siteA . '(?![0-9])/i';
    $out = [];
    // A is open: approve B (the winner), then try A (refused as taken).
    $instroot();
    $cardAs('instroot', 'public_host_approve', ['site' => (string) $siteB, 'host' => $name]);
    $signIn('tester');
    $aLog = $http('GET', '/admin/activity', ['jar' => $people['tester']['jar']])['body'];
    $out['A after the approval'] = [$look($aLog, '/Selftest Door B/'), $look($aLog, $bNumber), str_contains($aLog, $name),
        str_contains($aLog, 'Another organisation&#039;s public address was approved')];
    $cardAs('instroot', 'public_host_approve', ['site' => (string) $siteA, 'host' => $name]);
    $aLog = $http('GET', '/admin/activity', ['jar' => $people['tester']['jar']])['body'];
    $out['A after the refusal'] = [$look($aLog, '/Selftest Door B/'), $look($aLog, $bNumber), str_contains($aLog, $name),
        str_contains($aLog, 'already approved for another organisation. Only one')];
    // B is open: the installation's administrator, working in B, tries A again
    // (refused), withdraws B's approval and approves A.
    $jar  = $people['instroot']['jar'];
    $page = $http('GET', '/site/choose', ['jar' => $jar]);
    $http('POST', '/site/switch', ['jar' => $jar, 'post' => ['csrf_token' => $tokenOn($page['body']), 'site_id' => (string) $siteB]]);
    $cardAs('instroot', 'public_host_approve', ['site' => (string) $siteA, 'host' => $name]);
    $cardAs('instroot', 'public_host_withdraw', ['site' => (string) $siteB]);
    $cardAs('instroot', 'public_host_approve', ['site' => (string) $siteA, 'host' => $name]);
    $signIn('adminB');
    $bLog = $http('GET', '/admin/activity', ['jar' => $people['adminB']['jar']])['body'];
    $out['B after A\'s refusal and approval'] = [$look($bLog, '/' . preg_quote($nameTest, '/') . '/'), $look($bLog, $aNumber),
        str_contains($bLog, 'Another organisation&#039;s public address was approved'), str_contains($bLog, 'another organisation&#039;s public address approval')];
    $out['A approved in the end'] = $db->query("SELECT `approvedAtUtc` FROM `tblPublicHosts` WHERE `siteID` = {$siteA}")->fetch_assoc()['approvedAtUtc'] !== null;
    // The installation's administrator goes back to A for every later section.
    $instroot();
    $qPutBack();

    // A THIRD organisation holds the address: C approved for it, B asking, and
    // the installation's administrator, with A open, tries to approve B.
    $third = 'held-by-c-895.selftest.test';
    $cNumber = '/organisation #' . $siteC . '(?![0-9])/i';
    $db->query("UPDATE `tblPublicHosts` SET `hostName` = '{$third}', `approvedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = {$siteC}");
    $db->query("UPDATE `tblPublicHosts` SET `hostName` = '{$third}', `approvedAtUtc` = NULL, `approvedByID` = NULL WHERE `siteID` = {$siteB}");
    $refused = $cardAs('instroot', 'public_host_approve', ['site' => (string) $siteB, 'host' => $third]);
    $db->query("UPDATE `tblPublicHosts` SET `hostName` = '{$hostC}', `approvedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = {$siteC}");
    $qPutBack();
    /** What an ordinary administrator's EXPORT of their own activity log holds (the link on the page carries its token). */
    $export = static function (string $role) use ($http, $people): string {
        $page = $http('GET', '/admin/activity', ['jar' => $people[$role]['jar']])['body'];
        if (preg_match('#activity/export\?csrf_token=([0-9A-Za-z]+)#', $page, $m) !== 1) {
            return 'no export link';
        }
        $r = $http('GET', '/admin/activity/export?csrf_token=' . $m[1], ['jar' => $people[$role]['jar']]);
        return $r['status'] === 200 ? $r['body'] : 'export answered ' . $r['status'];
    };
    $aLog    = $http('GET', '/admin/activity', ['jar' => $people['tester']['jar']])['body'];
    $aExport = $export('tester');
    $out['the holder named to the installation\'s administrator, on screen'] = str_contains($refused['page'], 'approved for another organisation (Selftest Door C)');
    $out['A after a refusal about B while C holds it: page'] = [$look($aLog, '/Selftest Door C/'), $look($aLog, $cNumber), str_contains($aLog, $third),
        $look($aLog, $bNumber), str_contains($aLog, 'another organisation&#039;s public address approval. That address is already approved for another organisation. Only one')];
    $out['A after a refusal about B while C holds it: export'] = [str_contains($aExport, 'Selftest Door C'), $look($aExport, $cNumber), str_contains($aExport, $third),
        $look($aExport, $bNumber), str_contains($aExport, 'another organisation\'s public address approval. That address is already approved for another organisation. Only one')];
    // The whole of each export: nothing of the other organisations in it.
    $out['A\'s export: B\'s name, number, the shared address'] = [str_contains($aExport, 'Selftest Door B'), $look($aExport, $bNumber), str_contains($aExport, $name)];
    $bExport = $export('adminB');
    $out['B\'s export: A\'s name, number; C\'s name'] = [str_contains($bExport, $nameTest), $look($bExport, $aNumber), str_contains($bExport, 'Selftest Door C'),
        str_contains($bExport, 'public address')];
    return $out;
};

/** Q15: an organisation whose request has LOST, saving the same address again unchanged, is told it is taken. */
$scnQResave = static function () use ($db, $siteA, $siteB, $qName, $cardAs, $qCardState, $qPutBack): array {
    $db->query("UPDATE `tblPublicHosts` SET `hostName` = '{$qName}', `approvedAtUtc` = NULL, `approvedByID` = NULL WHERE `siteID` = {$siteA}");
    $db->query("UPDATE `tblPublicHosts` SET `hostName` = '{$qName}', `approvedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = {$siteB}");
    $r = $cardAs('setup', 'public_host', ['value' => $qName]);
    $out = [$qCardState($r['page']), str_contains($r['page'], 'That public address is taken'), str_contains($r['page'], 'Saved. Nothing was different.'),
        $db->query("SELECT `hostName` FROM `tblPublicHosts` WHERE `siteID` = {$siteA}")->fetch_assoc()['hostName'] ?? null];
    $qPutBack();
    return $out;
};
check('Q14 neither organisation\'s own administrator reads the other\'s name, number or address in its own activity log — after the approval, after the refusal, and from the winning side — while each still sees that a decision was made',
    $scnQLogs(), ['A after the approval' => [false, false, false, true], 'A after the refusal' => [false, false, false, true],
        'B after A\'s refusal and approval' => [false, false, true, true], 'A approved in the end' => true,
        'the holder named to the installation\'s administrator, on screen' => true,
        'A after a refusal about B while C holds it: page' => [false, false, false, false, true],
        'A after a refusal about B while C holds it: export' => [false, false, false, false, true],
        'A\'s export: B\'s name, number, the shared address' => [false, false, false],
        'B\'s export: A\'s name, number; C\'s name' => [false, false, false, true]]);
check('Q15 an organisation whose request has lost, saving the same address again unchanged, is told it is taken — never "Saved. Nothing was different." — and its card says taken',
    $scnQResave(), ['taken', true, false, 'shared-895.selftest.test']);

// ── Round 8 of P4a's check: two publicHostInEffect() callers were pinned by
// nothing (reverting either left 164/164). Each is driven in the three states
// an approved-or-not address can be in, with a plant in section P.
/** The members' address state, for the scenarios below: B's members sign in at A's public address. */
$asMembersAddress = static function (bool $on) use ($db, $siteB, $hostA): void {
    $db->query("UPDATE `tblSites` SET `hostPattern` = " . ($on === true ? "'{$hostA}'" : 'NULL') . " WHERE `siteID` = {$siteB}");
};
/** X7: what "Replace the name" SAYS and RECORDS about the public address, waiting / a members' address / in effect. */
$scnReplaceWords = static function () use ($db, $siteA, $hostA, $card, $doorName, $people, $asMembersAddress): array {
    $was    = (string) $doorName($siteA);
    $record = static function () use ($db, $people): string {
        return (string) ($db->query("SELECT `activityDescription` FROM `tblActivityLogs` WHERE `activityType` = 'PublicDoorNameReplaced' "
            . "AND `userID` = {$people['root']['id']} ORDER BY `logID` DESC LIMIT 1")->fetch_assoc()['activityDescription'] ?? '');
    };
    $press = static function () use ($card, $record, $hostA): array {
        $r = $card('root', 'door_replace', ['understood' => '1']);
        return [str_contains($r['page'], 'is NOT affected by the name'), str_contains($record(), 'copies using the public address ' . $hostA)];
    };
    $out = [];
    $db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = NULL WHERE `siteID` = {$siteA}");
    $out['waiting'] = $press();
    $db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = {$siteA}");
    $asMembersAddress(true);
    $out['a members\' address'] = $press();
    $asMembersAddress(false);
    $out['in effect'] = $press();
    // Put A's name back, so every later section still holds the name it read.
    $db->query("UPDATE `tblPublicDoorNames` SET `publicName` = '{$was}' WHERE `siteID` = {$siteA}");
    return $out;
};
check('D17 "Replace the name" claims a public address is NOT affected — in its message AND its record — only when that address is the door\'s (approved, not a members\' address), never while it waits or is a members\' address',
    $scnReplaceWords(), ['waiting' => [false, false], 'a members\' address' => [false, false], 'in effect' => [true, true]]);
/**
 * X5: the set-up check's row about approval, in the same three states.
 *
 * Round 9 of P4a's check: the first version matched words ANYWHERE on the
 * page, and the organisation's card prints "Waiting for the installation's
 * administrator…" itself, so the check's own waiting row was never tested —
 * replacing it with an "ok" row saying the address was "set and ready" left
 * every check green. So this reads ONLY the set-up check's results block
 * (data-setup-check), takes its "Public address" rows in order — the address
 * is this organisation's alone, its name exists, APPROVAL, not yet tested —
 * and returns the approval row's STATE and WORDS, which the check compares
 * exactly.
 */
$scnCheckRow = static function () use ($db, $siteA, $http, $tokenOn, $people, $asMembersAddress): array {
    $run = static function () use ($http, $tokenOn, $people): ?array {
        $jar  = $people['root']['jar'];
        $page = $http('GET', '/admin/public-website', ['jar' => $jar]);
        $http('POST', '/admin/public-website/check', ['jar' => $jar, 'post' => ['csrf_token' => $tokenOn($page['body'])]]);
        $body  = $http('GET', '/admin/public-website', ['jar' => $jar])['body'];
        $start = strpos($body, 'data-setup-check="1"');
        $end   = $start === false ? false : strpos($body, '</ul>', $start);
        if ($start === false || $end === false) {
            return null;
        }
        preg_match_all('#<li class="mb-2" data-check-state="([^"]*)">(.*?)</li>#s', substr($body, $start, $end - $start), $m, PREG_SET_ORDER);
        $rows = [];
        foreach ($m as $one) {
            $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($one[2]), ENT_QUOTES, 'UTF-8')));
            $at   = strpos($text, 'Public address:');
            if ($at !== false) {
                $rows[] = [$one[1], trim(substr($text, $at + strlen('Public address:')))];
            }
        }
        return $rows[2] ?? null;
    };
    $out = [];
    $db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = NULL WHERE `siteID` = {$siteA}");
    $out['waiting'] = $run();
    $db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = {$siteA}");
    $asMembersAddress(true);
    $out['a members\' address'] = $run();
    $asMembersAddress(false);
    $out['in effect'] = $run();
    return $out;
};
/** What the set-up check's approval row must say in each state: [state, words], compared EXACTLY. */
$checkRowWant = [
    'waiting'            => ['problem', 'Waiting for the installation\'s administrator to approve it. Until then it does nothing.'],
    'a members\' address' => ['problem', 'Approved, but it is also an address members sign in at, so the public door does NOT answer on it.'],
    'in effect'          => ['ok', 'Approved by the installation\'s administrator.'],
];
check('D18 "Check my set-up" gives its OWN approval row — read from its results block alone, state and words exact — as Waiting, "approved but members sign in at it, so the door does NOT answer", or Approved, each in its own state',
    $scnCheckRow(), $checkRowWant);

$setOwn($siteA, 'calendar.enabled', 'false');
$refusals['Calendar switched off'] = $feedA();
check('G12 with Calendar switched off for A, events.json is refused while posters.json still answers',
    [$refusals['Calendar switched off']['status'], $feedA('posters.json')['status']], [404, 200]);
$setOwn($siteA, 'calendar.enabled', null);
$setOwn($siteA, 'noticeboard.enabled', 'false');
$refusals['Noticeboard switched off'] = $feedA('posters.json');
$refusals['Noticeboard off, media'] = $feedA('media/' . $upA);
check('G13 with the Noticeboard switched off for A, posters.json AND its media are refused while events.json still answers',
    [$refusals['Noticeboard switched off']['status'], $refusals['Noticeboard off, media']['status'], $feedA()['status']], [404, 404, 200]);
check('G14 switching A\'s Noticeboard off leaves B\'s alone', $http('GET', '/w/' . $nameB . '/posters.json')['status'], 200);
$setOwn($siteA, 'noticeboard.enabled', null);

$setInstallation('portal.maintenance.active', '1');
$m1 = $feedA();
$m2 = $http('GET', '/w/' . $nameB . '/posters.json');
$m3 = $http('GET', '/w/' . str_repeat('cd', 12) . '/events.json');
check('G15 while closed for maintenance every door answer is a 503 with Retry-After and no-store, the same for every organisation and an unknown name',
    [$m1['status'], $m1['headers']['retry-after'] ?? '', $m1['headers']['cache-control'] ?? '', $signature($m1) === $signature($m2), $signature($m1) === $signature($m3)],
    [503, '60', 'no-store', true, true]);
$setInstallation('portal.maintenance.active', '0');

$setInstallation('site.url', null);
$refusals['the portal\'s own address not stored'] = $feedA();
check('G16 with the portal\'s own address NOT stored, the /w/ form refuses (its links would be built from a host the visitor chose); the public address still answers',
    [$refusals['the portal\'s own address not stored']['status'], $http('GET', '/events.json', ['host' => $hostA])['status']], [404, 200]);
$setInstallation('site.url', $base);

// =============================================================================
echo "X. Organisations never cross\n";
// =============================================================================
$xa = $feedA();
$xb = $http('GET', '/w/' . $nameB . '/events.json');
$capture('events', 'b', $xb);
check('X01 A\'s name gives A\'s events only and B\'s name gives B\'s only',
    [$titles($xa, 'events'), $titles($xb, 'events')], [['PDS A running now', 'PDS A shown'], ['PDS B shown']]);
$jarB = $signIn('memberB');
$withB = $http('GET', '/w/' . $nameA . '/events.json', ['jar' => $jarB]);
check('X02 a signed-in session for B asking A\'s name gets exactly A\'s answer (the door never reads the session)',
    [$titles($withB, 'events'), $titles($withB, 'events') === $titles($xa, 'events')], [['PDS A running now', 'PDS A shown'], true]);
$refusals['A\'s public address with B\'s name'] = $http('GET', '/w/' . $nameB . '/events.json', ['host' => $hostA]);
check('X03 A\'s public address never resolves a name: /w/{B\'s name}/events.json there is refused', $refusals['A\'s public address with B\'s name']['status'], 404);
$refusals['A\'s media through B\'s door'] = $http('GET', '/w/' . $nameB . '/media/' . $upA);
$refusals['B\'s media through A\'s door'] = $feedA('media/' . $upB);
check('X04 one organisation\'s media is never served through the other\'s door (even when one of A\'s ticked posters names B\'s upload)',
    [$refusals['A\'s media through B\'s door']['status'], $refusals['B\'s media through A\'s door']['status'],
        $http('GET', '/w/' . $nameB . '/media/' . $upB)['status']], [404, 404, 200]);
// "Own address each": A and B each have a members' address.
$db->query("UPDATE `tblSites` SET `hostPattern` = 'members-a.selftest.test' WHERE `siteID` = 1");
$db->query("UPDATE `tblSites` SET `hostPattern` = 'members-b.selftest.test' WHERE `siteID` = {$siteB}");
$setInstallation('multisite.detectionMode', 'subdomain');
$refusals['B\'s name on A\'s members\' address'] = $http('GET', '/w/' . $nameB . '/events.json', ['host' => 'members-a.selftest.test']);
$xOwnA = $http('GET', '/w/' . $nameA . '/events.json', ['host' => 'members-a.selftest.test']);
$xOwnB = $http('GET', '/w/' . $nameB . '/events.json', ['host' => 'members-b.selftest.test']);
check('X05 in "own address each" mode B\'s name on A\'s members\' address is refused, while each name answers on its own',
    [$refusals['B\'s name on A\'s members\' address']['status'], $titles($xOwnA, 'events'), $titles($xOwnB, 'events')],
    [404, ['PDS A running now', 'PDS A shown'], ['PDS B shown']]);
$setInstallation('multisite.detectionMode', 'session');
$db->query("UPDATE `tblSites` SET `hostPattern` = NULL WHERE `siteID` IN (1, {$siteB})");
$setInstallation('multisite.enabled', 'false');
$refusals['B\'s name with multi-organisation off'] = $http('GET', '/w/' . $nameB . '/events.json');
check('X06 with multi-organisation switched off only organisation 1\'s name is honoured',
    [$refusals['B\'s name with multi-organisation off']['status'], $feedA()['status']], [404, 200]);
$setInstallation('multisite.enabled', 'true');
$setInstallation('multisite.detectionMode', 'path');
$xBefore = $sessionFiles();
$refusals['a door address behind an organisation prefix'] = $http('GET', '/selftest-door-b/w/' . $nameB . '/events.json');
check('X07 in "own path each" mode, /{B\'s key}/w/{B\'s name}/events.json is the door\'s 404 — decided before the session, so no session file',
    [$refusals['a door address behind an organisation prefix']['status'], $sessionFiles() - $xBefore], [404, 0]);
$xBefore = $sessionFiles();
$refusals['a door address behind a prefix with a doubled slash'] = $http('GET', '/selftest-door-b//w/' . $nameB . '/events.json');
$refusals['a door address behind an INACTIVE organisation\'s prefix'] = $http('GET', '/selftest-door-c/w/' . $nameC . '/events.json');
$doubled = $http('GET', '//w/' . $nameA . '/events.json');
check('X08 repeated slashes count as one (/{key}//w/... refused, //w/{name}/... answered as the door) and an INACTIVE organisation\'s prefix is refused too — all before any session',
    [$refusals['a door address behind a prefix with a doubled slash']['status'], $refusals['a door address behind an INACTIVE organisation\'s prefix']['status'],
        $doubled['status'], $doubled['headers']['set-cookie'] ?? '', $sessionFiles() - $xBefore], [404, 404, 200, '', 0]);
$setInstallation('multisite.detectionMode', 'session');

// =============================================================================
echo "C. What the door gives out\n";
// =============================================================================
$cBody = $feedA('events.json?limit=50')['body'];
$cJson = json_decode($cBody, true);
$shown = null;
foreach ($cJson['events'] ?? [] as $one) {
    if ($one['title'] === 'PDS A shown') {
        $shown = $one;
    }
}
check('C01 never shown: unticked, outside its dates (either side), deleted, draft, cancelled, members-only, finished, or another organisation\'s',
    array_values(array_intersect(['PDS A unticked', 'PDS A until yesterday', 'PDS A from tomorrow', 'PDS A deleted', 'PDS A draft',
        'PDS A cancelled', 'PDS A members only', 'PDS A finished', 'PDS B shown', 'PDS C shown', 'PDS A copied in'],
        array_column($cJson['events'] ?? [], 'title'))), []);
check('C02 an event still running on its own clock IS shown, and they come soonest first',
    array_column($cJson['events'] ?? [], 'title'), ['PDS A running now', 'PDS A shown']);
check('C03 one event carries exactly the published fields, its id is its slug (not a number), and its full details where allowed',
    [array_keys($shown ?? []), is_string($shown['id'] ?? null) && str_starts_with((string) $shown['id'], 'pds-'), $shown['description'] ?? null,
        $shown['location'] ?? null, $shown['detailsLimited'] ?? null],
    [['id', 'title', 'startsAt', 'endsAt', 'startsOnClock', 'endsOnClock', 'listedUntil', 'allDay', 'timezone', 'detailsLimited', 'description', 'location'], true, 'Bring a dish.',
        ['name' => 'PDS Hall', 'address' => '1 PDS Street', 'url' => 'https://hall.selftest.invalid/'], false]);
$leaks = [];
foreach (['01234 PDS PHONE', 'pds-hall-secret', '51.1234567', 'pds.secret.words', 'PDS HOST ORG', '"eventID"', '"siteID"', 'createdBy', '"capacity"'] as $needle) {
    if (str_contains($cBody, $needle) === true) {
        $leaks[] = $needle;
    }
}
check('C04 the answer holds no telephone, e-mail, map position, what3words, host organisation, database number, creator or capacity', $leaks, []);
$startA = (new DateTimeImmutable($at($later), $clockA))->format('Y-m-d\TH:i:sP');
$bEvent = json_decode($xb['body'], true)['events'][0] ?? [];
check('C05 times are on the event\'s own clock with its offset: A\'s on A\'s, and B\'s 18:00 in Pacific/Auckland with Auckland\'s offset',
    [$shown['startsAt'] ?? null, $bEvent['startsAt'] ?? null, $bEvent['timezone'] ?? null, json_decode($xb['body'], true)['organisation']['timezone'] ?? null],
    [$startA, $laterB->format('Y-m-d\TH:i:sP'), 'Pacific/Auckland', 'Pacific/Auckland']);
$setInstallation('public.events.copiedRule', 'calendar');
$copiedUnder = static function () use ($feedA): ?array {
    foreach (json_decode($feedA('events.json?limit=50')['body'], true)['events'] ?? [] as $one) {
        if ($one['title'] === 'PDS A copied in') {
            return $one;
        }
    }
    return null;
};
$cc = $copiedUnder();
check('C06 under "one box per calendar" the calendar-ticked copied-in event IS shown — title, date and time only, as its calendar withholds details',
    [$cc !== null, $cc['detailsLimited'] ?? null, array_key_exists('description', $cc ?? []) === true ? $cc['description'] : 'missing',
        array_key_exists('location', $cc ?? []) === true ? $cc['location'] : 'missing'], [true, true, null, null]);
$capture('events', 'a-calendar-rule', $feedA('events.json?limit=50'));
$setInstallation('public.events.copiedRule', 'event');
check('C07 under "one tick per event" the same event is NOT shown', $copiedUnder(), null);
$setEv($eCopied, ['importSource' => 'date']);
check('C08 under "one tick per event" a copied-in event whose "yes" came from a choice about that date IS shown', $copiedUnder() !== null, true);
$setEv($eCopied, ['importSource' => 'calendar']);
$setOwn($siteA, 'public.events.copiedRule', 'calendar');
check('C09 an organisation row looser than the installation is held down when READ (copiedRuleFor caps it)', $copiedUnder(), null);
$setOwn($siteA, 'public.events.copiedRule', null);

$pr   = $feedA('posters.json');
$pj   = json_decode($pr['body'], true);
$byT  = [];
foreach ($pj['posters'] ?? [] as $one) {
    $byT[$one['title']] = $one;
}
$capture('posters', 'a', $pr);
check('C10 posters: only ticked, in-date, undeleted posters of A',
    $titles($pr, 'posters'), ['PDS poster naming B upload', 'PDS poster outside picture', 'PDS poster shown']);
check('C11 a poster\'s upload is pointed at the DOOR\'s own media address; its day, time, place and https link are given',
    [$byT['PDS poster shown']['media'] ?? null, $byT['PDS poster shown']['when'] ?? null, $byT['PDS poster shown']['time'] ?? null,
        $byT['PDS poster shown']['location'] ?? null, $byT['PDS poster shown']['link'] ?? null],
    [['type' => 'image', 'url' => 'https://' . $hostA . '/media/' . $upA], ['type' => 'weekly', 'weekday' => 2], '10:30', 'PDS Hall',
        'https://events.selftest.invalid/toddlers']);
check('C12 an outside https picture is passed through; a plain-http link is dropped; a poster naming ANOTHER organisation\'s upload gets no media',
    // array_key_exists, never `?? 'missing'`: `??` reads a key holding null
    // as absent, which is exactly the value being pinned here.
    [$byT['PDS poster outside picture']['media']['url'] ?? null,
        array_key_exists('link', $byT['PDS poster outside picture'] ?? []) === true ? $byT['PDS poster outside picture']['link'] : 'missing',
        $byT['PDS poster outside picture']['when'] ?? null,
        array_key_exists('media', $byT['PDS poster naming B upload'] ?? []) === true ? $byT['PDS poster naming B upload']['media'] : 'missing'],
    ['https://images.selftest.invalid/a.png', null, ['type' => 'once', 'date' => $tomorA], null]);
check('C13 nothing in the posters answer names a database number or the poster\'s sort order',
    [str_contains($pr['body'], 'posterID'), str_contains($pr['body'], 'sortOrder'), str_contains($pr['body'], '"siteID"')], [false, false, false]);
$md = $feedA('media/' . $upA);
check('C14 the door serves a ticked poster\'s upload: the file\'s own bytes, as image/png',
    [$md['status'], $md['body'] === $png, $md['headers']['content-type'] ?? ''], [200, true, 'image/png']);
// #880: the door's media answers a byte-range request, which Safari needs
// before it will play a poster VIDEO. The bytes come from
// `Portal\Core\ByteRange`, the one place every media route asks; the full
// matrix (chunk boundaries, If-Range, several pieces) is in
// tools/uploads-and-media-selftest.php. Here: that the DOOR uses it, and that
// every question the door asks still comes first.
$pngLen = strlen($png);
$piece  = static fn (array $r): array => [$r['status'], $r['headers']['accept-ranges'] ?? '', $r['headers']['content-range'] ?? '',
    $r['headers']['content-length'] ?? '', $r['body']];
check('C14b a whole answer says Accept-Ranges: bytes, so a browser knows it may ask for pieces',
    $md['headers']['accept-ranges'] ?? '', 'bytes');
check('C14c bytes=0-1 (Safari\'s first question), an open-ended piece and the last ten bytes: 206, Content-Range, and exactly those bytes',
    [$piece($feedA('media/' . $upA, ['range' => 'bytes=0-1'])), $piece($feedA('media/' . $upA, ['range' => 'bytes=5-'])),
        $piece($feedA('media/' . $upA, ['range' => 'bytes=-10']))],
    [[206, 'bytes', 'bytes 0-1/' . $pngLen, '2', substr($png, 0, 2)],
        [206, 'bytes', 'bytes 5-' . ($pngLen - 1) . '/' . $pngLen, (string) ($pngLen - 5), substr($png, 5)],
        [206, 'bytes', 'bytes ' . ($pngLen - 10) . '-' . ($pngLen - 1) . '/' . $pngLen, '10', substr($png, -10)]]);
$past = $feedA('media/' . $upA, ['range' => 'bytes=' . $pngLen . '-']);
check('C14d a piece starting past the end: 416, Content-Range: bytes */size, no body, and never kept by a cache',
    [$past['status'], $past['headers']['content-range'] ?? '', $past['body'], $past['headers']['cache-control'] ?? ''],
    [416, 'bytes */' . $pngLen, '', 'no-store']);
check('C14e a piece keeps the door\'s own headers (loadable from any website, data only, a minute\'s caching)',
    array_map(static fn (string $k): string => $feedA('media/' . $upA, ['range' => 'bytes=0-1'])['headers'][$k] ?? '',
        ['access-control-allow-origin', 'cross-origin-resource-policy', 'cache-control', 'x-content-type-options']),
    ['*', 'cross-origin', 'public, max-age=60', 'nosniff']);
$rangedRefusals = [$feedA('media/' . $upA2, ['range' => 'bytes=0-1']), $http('GET', '/w/' . $nameB . '/media/' . $upA, ['range' => 'bytes=0-1']),
    $feedA('media/' . str_repeat('e', 32) . '.png', ['range' => 'bytes=0-1'])];
check('C14f asked WITH a Range header, an unticked poster\'s upload, another organisation\'s, and a name that is no upload are still the door\'s one 404 — no Accept-Ranges, no Content-Range, no byte',
    array_map(static fn (array $r): array => [$r['status'], $r['headers']['accept-ranges'] ?? '', $r['headers']['content-range'] ?? '', $r['body']], $rangedRefusals),
    array_fill(0, 3, [404, '', '', "Not found.\n"]));
$refusals['an unticked poster\'s media'] = $feedA('media/' . $upA2);
$refusals['media that is not an upload'] = $feedA('media/' . str_repeat('e', 32) . '.png');
check('C15 an unticked poster\'s upload, and a name that is no upload, are refused', [$refusals['an unticked poster\'s media']['status'],
    $refusals['media that is not an upload']['status']], [404, 404]);
$db->query("UPDATE `tblNoticeboardPosters` SET `showOnWebsite` = 0 WHERE `posterID` = {$pShown}");
$refusals['media the moment its poster is unticked'] = $feedA('media/' . $upA);
check('C16 the moment its poster is unticked, the media stops answering and the poster leaves the feed',
    [$refusals['media the moment its poster is unticked']['status'], in_array('PDS poster shown', $titles($feedA('posters.json'), 'posters') ?? [], true)], [404, false]);
$db->query("UPDATE `tblNoticeboardPosters` SET `showOnWebsite` = 1 WHERE `posterID` = {$pShown}");
check('C17 ticked again, it answers again', $feedA('media/' . $upA)['status'], 200);
$allDayShown = static function () use ($setEv, $eAllDayToday, $eAllDayPast, $titles, $feedA): array {
    $setEv($eAllDayToday, ['showOnWebsite' => '1']);
    $setEv($eAllDayPast, ['showOnWebsite' => '1']);
    $t = array_values(array_intersect(['PDS A all day today', 'PDS A all day yesterday'], $titles($feedA('events.json?limit=50'), 'events') ?? []));
    $setEv($eAllDayToday, ['showOnWebsite' => '0']);
    $setEv($eAllDayPast, ['showOnWebsite' => '0']);
    return $t;
};
check('C18 an all-day event with no end time is kept through the whole of its own day, and gone the day after', $allDayShown(), ['PDS A all day today']);
$hexBody = $feedA('events.json?limit=50')['body'];
check('C19 markup and quotes inside a value are written as \\u escapes: no live <b>, </script>, & or \' in the answer, and the value still reads back exactly',
    [str_contains($hexBody, '<b>'), str_contains($hexBody, '</script>'), str_contains($hexBody, '&'), str_contains($hexBody, "'"),
        (static function () use ($hexBody): ?string {
            foreach (json_decode($hexBody, true)['events'] ?? [] as $one) {
                if ($one['title'] === 'PDS A running now') {
                    return $one['description'];
                }
            }
            return null;
        })()],
    [false, false, false, false, '<b>"Tom & Jerry\'s"</b></script>']);

// =============================================================================
echo "N. An event with no end time (#882, D-129), and the real order (#898)\n";
// =============================================================================
// Every N event is made OFF the website and ticked only inside the scenario
// that needs it ($nShow), so no other section's list changes. The no-end
// cases use TWO clocks chosen now: a clock where it is between 03:00 and
// 22:59 (so its day began well before and does not end during the run), and
// Pacific/Kiritimati, which is ALWAYS on a later date than that clock (it is
// the furthest ahead of UTC there is). The same stored reading is then still
// "today" on one clock and already a past day on the other: "on its OWN clock".
$nAheadZone = 'Pacific/Kiritimati';
$nAheadDate = (new DateTimeImmutable('now', new DateTimeZone($nAheadZone)))->format('Y-m-d');
$nZone = null;
foreach (array_merge(['Pacific/Pago_Pago', 'Europe/London', 'Pacific/Honolulu', 'America/Los_Angeles'], DateTimeZone::listIdentifiers()) as $z) {
    $here = new DateTimeImmutable('now', new DateTimeZone($z));
    $hour = (int) $here->format('G');
    if ($hour >= 3 && $hour <= 22 && $here->format('Y-m-d') < $nAheadDate) {
        $nZone = $z;
        break;
    }
}
if ($nZone === null) {
    refuse('section N found no clock that is between 03:00 and 22:59 and on an earlier date than Pacific/Kiritimati');
}
$nClock   = new DateTimeZone($nZone);
$nNow     = new DateTimeImmutable('now', $nClock);
$nToday   = $nNow->format('Y-m-d');
// "N minutes ago" in REAL minutes, read on that clock (a change of the clocks in
// between must not make it an hour out).
$nAgo     = static fn (int $minutes): string => (new DateTimeImmutable('@' . (time() - $minutes * 60)))->setTimezone($nClock)->format('Y-m-d H:i:s');
$nIds     = [];
$nMake    = static function (string $name, string $zone, string $start, ?string $end) use ($makeEvent, $siteA, &$nIds): int {
    $id = $makeEvent($name, $siteA, $zone, $start, $end, ['showOnWebsite' => '0']);
    $nIds[$name] = $id;
    return $id;
};
$nMake('PDS N no end today', $nZone, $nToday . ' 00:30:00', null);
$nMake('PDS N no end today, ahead clock', $nAheadZone, $nToday . ' 00:30:00', null);
$nMake('PDS N no end yesterday', $nZone, $nNow->modify('-1 day')->format('Y-m-d') . ' 23:59:00', null);
$nMake('PDS N no end 90 minutes ago', $nZone, $nAgo(90), null);
$nMake('PDS N no end 150 minutes ago', $nZone, $nAgo(150), null);
$nMake('PDS N ended a minute ago', $nZone, $nAgo(30), $nAgo(1));
// #898: the same day forty days on, two clocks 25 hours apart. "Later reading,
// sooner moment": 23:00 in Kiritimati (+14) is 09:00 UTC; 08:00 in Pago Pago
// (-11) is 19:00 UTC. Far from every other event of A, so nothing sits between.
$nOrderDay = $nowA->modify('+40 days')->format('Y-m-d');
$nMake('PDS N sooner, later reading', 'Pacific/Kiritimati', $nOrderDay . ' 23:00:00', null);
$nMake('PDS N later, earlier reading', 'Pacific/Pago_Pago', $nOrderDay . ' 08:00:00', null);
// The two nights London's clocks change, for the fixed-moment checks run in
// the portal's own classes (the real "now" cannot be chosen).
$nMake('PDS N clock 25-hour day', 'Europe/London', '2026-10-25 00:30:00', null);
$nMake('PDS N clock 23-hour day', 'Europe/London', '2027-03-28 00:30:00', null);
// Round 1 of #882's check: listedUntil for an event WITH an end time and for
// all-day events of one day and of three, read under both settings.
$nLondonTomorrow = (new DateTimeImmutable('now', new DateTimeZone('Europe/London')))->modify('+1 day')->format('Y-m-d');
$nMake('PDS N with an end time', 'Europe/London', $nLondonTomorrow . ' 10:00:00', $nLondonTomorrow . ' 12:00:00');
$nAllDayOne   = $nMake('PDS N all day, one day', 'Europe/London', $nLondonTomorrow . ' 00:00:00', null);
$nAllDayThree = $nMake('PDS N all day, three days', 'Europe/London', $nLondonTomorrow . ' 00:00:00',
    (new DateTimeImmutable($nLondonTomorrow))->modify('+2 days')->format('Y-m-d') . ' 00:00:00');
$setEv($nAllDayOne, ['isAllDay' => '1']);
$setEv($nAllDayThree, ['isAllDay' => '1']);
// Rounds 1 and 2 of #882's check: the second read's window must reach a
// zone's HIGHEST offset in its history, not only today's. Organisation C
// (UTC, and its only other event in UTC) gets two events running since June
// 1941, when London was on DOUBLE summer time (+2): 15:00 in New York (EDT,
// 19:00 UTC) and 20:30 in London (18:30 UTC, sooner, though its reading sorts
// later). With `limit=1` the first read takes New York's, T = 19:00 UTC, and
// London's reading is between T + 1 hour and T + 2 hours — inside the window
// only when it reaches London's historic +2. Both end in 2099, so they are
// still listed.
// ⚠️ ROUND 2 FOUND THE FIRST VERSION HELD UP BY THE WRONG ZONE: its partner
//    was Pacific/Pago_Pago, whose history begins with local mean time
//    +12:37:12, so C's window was always 45,432 seconds and London's history
//    decided nothing — three wrong windows stayed green. New York's whole
//    history (local mean time -4:56:02 included) is at or below -4 hours, so
//    with UTC as C's only other clock, London's +2 is the ONLY thing that can
//    widen the window enough. N19 checks that premise.
$nHistoricLondon = $makeEvent('PDS N historic London', $siteC, 'Europe/London', '1941-06-01 20:30:00', '2099-01-01 00:00:00', ['showOnWebsite' => '0']);
$nHistoricNy     = $makeEvent('PDS N historic New York', $siteC, 'America/New_York', '1941-06-01 15:00:00', '2099-01-01 00:00:00', ['showOnWebsite' => '0']);
$nIds['PDS N historic London']   = $nHistoricLondon;
$nIds['PDS N historic New York'] = $nHistoricNy;
// Round 2: the same shape where the highest offset is LATE in a zone's
// history, so a window that read only part of the history (say its first
// half) cannot reach it either. Organisation C again gets Atlantic/Azores,
// which was +1 only from 28 March to 17 June 1993 (entry 126 of its 217,
// every entry before that at or below 0, and at or below 0 ever since), and
// New York again: 15:00 on 1 May 1993 in New York is 19:00 UTC, 19:30 in the
// Azores is 18:30 UTC. Only the Azores' 1993 +1 reaches it — while N21 runs,
// the London event is put on UTC's clock, so London's +2 is not in C's zones
// (the Azores' +1 is below what N20 needs, so it cannot carry N20 either).
$nLateAzores = $makeEvent('PDS N late Azores', $siteC, 'Atlantic/Azores', '1993-05-01 19:30:00', '2099-01-01 00:00:00', ['showOnWebsite' => '0']);
$nLateNy     = $makeEvent('PDS N late New York', $siteC, 'America/New_York', '1993-05-01 15:00:00', '2099-01-01 00:00:00', ['showOnWebsite' => '0']);
$nIds['PDS N late Azores']   = $nLateAzores;
$nIds['PDS N late New York'] = $nLateNy;
$state['nIds'] = array_values($nIds);
register_shutdown_function(static function () use (&$state, $db): void {
    foreach ($state['nIds'] ?? [] as $id) {
        $db->query('DELETE FROM `tblEvents` WHERE `eventID` = ' . (int) $id);
    }
    $db->query("DELETE FROM `tblSettings` WHERE (`settingKey` = 'public.events.noEndListedFor' AND `siteID` IS NOT NULL) OR `settingKey` = 'selftest.p882.probe'");
});
/** Tick the named N events (or none), run $then, untick them again. */
$nShow = static function (array $names, callable $then) use ($setEv, &$nIds): mixed {
    foreach ($names as $n) {
        $setEv($nIds[$n], ['showOnWebsite' => '1']);
    }
    try {
        return $then();
    } finally {
        foreach ($names as $n) {
            $setEv($nIds[$n], ['showOnWebsite' => '0']);
        }
    }
};
/** The N titles a feed gives, in the feed's OWN order (not sorted). */
$nTitles = static function (array $r): ?array {
    $j = json_decode($r['body'], true);
    if ($r['status'] !== 200 || is_array($j) === false || isset($j['events']) === false) {
        return null;
    }
    return array_values(array_filter(array_map(static fn (array $e): string => (string) $e['title'], $j['events']),
        static fn (string $t): bool => str_starts_with($t, 'PDS N ')));
};
$nNoEnd = ['PDS N no end today', 'PDS N no end today, ahead clock', 'PDS N no end yesterday', 'PDS N no end 90 minutes ago',
    'PDS N no end 150 minutes ago', 'PDS N ended a minute ago'];
$nListed = static fn (): ?array => $nShow($nNoEnd, static function () use ($feedA, $nTitles): ?array {
    $t = $nTitles($feedA('events.json?limit=50'));
    if ($t !== null) {
        sort($t);
    }
    return $t;
});
$setOwn($siteA, 'public.events.noEndListedFor', null);
check('N01 (D-129, the default) an event with no end time that started today on its own clock (' . $nZone . ') is STILL listed; the same reading on a clock already past that day (' . $nAheadZone . '), and one that started late yesterday, are gone; one that ended a minute ago is gone, end time and all',
    $nListed(), ['PDS N no end 150 minutes ago', 'PDS N no end 90 minutes ago', 'PDS N no end today']);
$nFeedToday = $nShow(['PDS N no end today'], static fn (): array => json_decode($feedA('events.json?limit=50')['body'], true)['events'] ?? []);
$nRow = null;
foreach ($nFeedToday as $one) {
    if ($one['title'] === 'PDS N no end today') {
        $nRow = $one;
    }
}
check('N02 its listedUntil is midnight at the end of its own day, on its own clock, with that clock\'s offset; endsAt stays null',
    [$nRow['listedUntil'] ?? 'missing', array_key_exists('endsAt', $nRow ?? []) === true ? $nRow['endsAt'] : 'missing'],
    [(new DateTimeImmutable($nNow->modify('+1 day')->format('Y-m-d') . ' 00:00:00', $nClock))->format('Y-m-d\TH:i:sP'), null]);

// The card (D-129: the organisation chooses; D-085: whoever may open the page).
$nRefusedBefore = $logged('PublicWebsiteSetupRefused', $people['root']['id']);
$nFlash = static function (string $page): string {
    if (preg_match('#<div class="alert alert-[a-z]+" role="alert">(.*?)</div>#s', $page, $m) !== 1) {
        return '';
    }
    return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
};
$nOwnRow = static function () use ($db, $siteA): ?string {
    $r = $db->query("SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'public.events.noEndListedFor' AND `siteID` = {$siteA}")->fetch_assoc();
    return $r === null ? null : (string) $r['settingValue'];
};
$n03a = $card('root', 'no_end_listing', ['mode' => 'fixed', 'hours' => '0', 'minutes' => '10']);
$n03b = $card('root', 'no_end_listing', ['mode' => 'fixed', 'hours' => '24', 'minutes' => '1']);
$n03c = $card('root', 'no_end_listing', ['mode' => 'fixed', 'minutes' => '30']);
$n03d = $card('root', 'no_end_listing', ['mode' => 'sometimes']);
$n03e = $card('root', 'no_end_listing', ['hours' => '1', 'minutes' => '0']);
check('N03 the card refuses, with the reason and on record, a time under 15 minutes, one over 24 hours, a fixed time whose hours box did not arrive, an unknown choice, and a post with no choice at all (round 1: that one was refused but not recorded) — and writes nothing',
    [$nFlash($n03a['page']), $nFlash($n03b['page']), $nFlash($n03c['page']), $nFlash($n03d['page']), $nFlash($n03e['page']),
        $logged('PublicWebsiteSetupRefused', $people['root']['id']) - $nRefusedBefore, $nOwnRow()],
    ['The time must be at least 15 minutes and at most 24 hours. Nothing was saved.', 'The time must be at least 15 minutes and at most 24 hours. Nothing was saved.',
        'That form did not arrive whole. Nothing was saved.', 'That choice was not recognised. Nothing was saved.', 'That form did not arrive whole. Nothing was saved.', 5, null]);
$nSavedBefore = $logged('PublicWebsiteNoEndListingSaved', $people['root']['id']);
$n04 = $card('root', 'no_end_listing', ['mode' => 'fixed', 'hours' => '2', 'minutes' => '0']);
check('N04 a fixed time of 2 hours is saved as A\'s own row (120 minutes), recorded, and the card says so and pre-selects it',
    [$nOwnRow(), $logged('PublicWebsiteNoEndListingSaved', $people['root']['id']) - $nSavedBefore,
        str_contains($n04['page'], 'data-no-end-listing="120"'), str_contains($n04['page'], 'id="pw-noend-fixed" checked')],
    ['120', 1, true, true]);
check('N05 with 2 hours chosen: started 90 minutes ago, listed; 150 minutes ago, gone; an event with an end time that has passed is STILL gone (the setting changes nothing for it)',
    array_values(array_intersect(['PDS N ended a minute ago', 'PDS N no end 150 minutes ago', 'PDS N no end 90 minutes ago'], $nListed() ?? [])),
    ['PDS N no end 90 minutes ago']);
check('N06 with 2 hours chosen, an all-day event with no end time is still kept through the whole of its own day (unaffected), and gone the day after',
    $allDayShown(), ['PDS A all day today']);
$nFeedFixed = $nShow(['PDS N no end 90 minutes ago'], static fn (): array => json_decode($feedA('events.json?limit=50')['body'], true)['events'] ?? []);
$nRowFixed = null;
foreach ($nFeedFixed as $one) {
    if ($one['title'] === 'PDS N no end 90 minutes ago') {
        $nRowFixed = $one;
    }
}
check('N07 with 2 hours chosen, listedUntil is exactly 7,200 seconds after startsAt',
    $nRowFixed === null ? null : strtotime((string) $nRowFixed['listedUntil']) - strtotime((string) $nRowFixed['startsAt']), 7200);
$n08 = $card('root', 'no_end_listing', ['mode' => 'day']);
check('N08 choosing "until the end of the day" again stores \'day\' and the list is the default\'s again',
    [$nOwnRow(), str_contains($n08['page'], 'data-no-end-listing="day"'), $nListed()],
    ['day', true, ['PDS N no end 150 minutes ago', 'PDS N no end 90 minutes ago', 'PDS N no end today']]);
$setOwn($siteA, 'public.events.noEndListedFor', null);

// #898: the order is the real moment's, and `limit` never drops the sooner one.
$nOrder = ['PDS N sooner, later reading', 'PDS N later, earlier reading'];
$nAll = $nShow($nOrder, static fn (): array => json_decode($feedA('events.json?limit=50')['body'], true)['events'] ?? []);
$nAt  = array_search('PDS N sooner, later reading', array_column($nAll, 'title'), true);
check('N09 (#898) the full list puts 23:00 in Pacific/Kiritimati (09:00 UTC) BEFORE 08:00 in Pacific/Pago_Pago (19:00 UTC) on the same date, and every event in the list is in order of its real start',
    [array_values(array_filter(array_column($nAll, 'title'), static fn (string $t): bool => in_array($t, $nOrder, true))),
        (static function () use ($nAll): bool {
            $m = array_map(static fn (array $e): int => (int) strtotime((string) $e['startsAt']), $nAll);
            $sorted = $m;
            sort($sorted);
            return $m === $sorted;
        })()],
    [$nOrder, true]);
$nLimit = $nAt === false ? 1 : $nAt + 1;
check('N10 (#898) with `limit` set so the list ends at the sooner of the two (limit=' . $nLimit . '), the sooner one is the last given — reading order would have given the later one instead and dropped the sooner',
    $nShow($nOrder, static fn (): ?array => $nTitles($feedA('events.json?limit=' . $nLimit))), ['PDS N sooner, later reading']);

// The nights the clocks change, at fixed moments, in the portal's OWN classes:
// PublicDoor::events() takes the moment it judges at, so the real reader runs
// on 25 October 2026 (a 25-hour day in London) and 28 March 2027 (23 hours).
file_put_contents($child . $sep . 'n-ask.php', <<<'ASK'
<?php
// Written by tools/public-door-selftest.php for ONE run. Test only. Runs the
// REAL PublicDoor::events() from the copy of web/, at the moment given.
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/web/_core/' . str_replace('Portal\\Core\\', '', $class) . '.php';
    if (is_file($file) === true) {
        require_once $file;
    }
});
define('PORTAL_CORE', __DIR__ . '/web/_core');
define('PORTAL_ENV', 'prod');
define('PORTAL_ENV_SOURCE', 'environment');
$c = require __DIR__ . '/web/_auth_keys/auth_creds.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli($c['db_host'], $c['db_user'], $c['db_pass'], $c['db_name'], (int) $c['db_port']);
$mysqli->set_charset('utf8mb4');
$site = (int) $argv[1];
\Portal\Core\Site::init($mysqli, ['site' => ['timezoneKeptAt' => '']], $site);
try {
    $read = \Portal\Core\PublicDoor::events($mysqli, $site, (int) ($argv[3] ?? 50), (int) $argv[2]);
} catch (\Throwable $e) {
    echo json_encode(['error' => get_class($e) . ': ' . $e->getMessage()]);
    exit;
}
$prefix = (string) ($argv[4] ?? 'PDS N clock');
echo json_encode(array_values(array_filter(array_column($read['events'], 'title'), static fn (string $t): bool => str_starts_with($t, $prefix))));
ASK);
/** The real reader for organisation `$site` at `$utc`, `$limit` events, the titles starting `$prefix`. */
$nAskFor = static function (int $site, string $utc, int $limit, string $prefix) use ($child, $sep): mixed {
    $raw = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d ' . escapeshellarg('error_log=' . $child . $sep . 'n-ask.log') . ' '
        . escapeshellarg($child . $sep . 'n-ask.php') . ' ' . $site . ' ' . (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp()
        . ' ' . $limit . ' ' . escapeshellarg($prefix) . ' 2>&1');
    $got = json_decode($raw, true);
    return is_array($got) === true ? $got : ['could not ask' => $raw];
};
$nAsk = static fn (string $utc): mixed => $nAskFor($siteA, $utc, 50, 'PDS N clock');
$nClocks = ['PDS N clock 25-hour day', 'PDS N clock 23-hour day'];
/** At each moment, is the named clock event listed? */
$nClockAt = static function (string $name, array $moments) use ($nShow, $nClocks, $nAsk): array {
    return $nShow($nClocks, static function () use ($name, $moments, $nAsk): array {
        $out = [];
        foreach ($moments as $m) {
            $got = $nAsk($m);
            $out[$m] = isset($got['could not ask']) === true || isset($got['error']) === true ? $got : in_array($name, $got, true);
        }
        return $out;
    });
};
$london = new DateTimeZone('Europe/London');
$dayLength = static fn (string $d): float => ((new DateTimeImmutable($d . ' 00:00:00', $london))->modify('+1 day')->getTimestamp()
    - (new DateTimeImmutable($d . ' 00:00:00', $london))->getTimestamp()) / 3600;
check('N11 the premise: in Europe/London 25 October 2026 lasts 25 hours and 28 March 2027 lasts 23',
    [$dayLength('2026-10-25'), $dayLength('2027-03-28')], [25.0, 23.0]);
check('N12 (the default) the 25-hour day: an event with no end time starting at 00:30 is still listed at 23:45 that night (24 h 15 min after it began — more than a day of real time) and gone at 00:05 the next day',
    $nClockAt('PDS N clock 25-hour day', ['2026-10-25 23:45:00', '2026-10-26 00:05:00']), ['2026-10-25 23:45:00' => true, '2026-10-26 00:05:00' => false]);
check('N13 (the default) the 23-hour day: starting at 00:30, still listed at 23:45 (22 h 15 min later) and gone at 00:15 the next day — less than a day of real time after it began',
    $nClockAt('PDS N clock 23-hour day', ['2027-03-28 22:45:00', '2027-03-28 23:15:00']), ['2027-03-28 22:45:00' => true, '2027-03-28 23:15:00' => false]);
$setOwn($siteA, 'public.events.noEndListedFor', '120');
check('N14 (2 hours chosen) across the clocks going FORWARD: starting at 00:30, listed at 03:15 on the clock (1 h 45 min of real time later) and gone at 03:45 — exact hours, not the clock\'s hands',
    $nClockAt('PDS N clock 23-hour day', ['2027-03-28 02:15:00', '2027-03-28 02:45:00']), ['2027-03-28 02:15:00' => true, '2027-03-28 02:45:00' => false]);
check('N15 (2 hours chosen) across the clocks going BACK: starting at 00:30, listed at the SECOND 01:15 (1 h 45 min later) and gone at the second 01:45',
    $nClockAt('PDS N clock 25-hour day', ['2026-10-25 01:15:00', '2026-10-25 01:45:00']), ['2026-10-25 01:15:00' => true, '2026-10-25 01:45:00' => false]);
$setOwn($siteA, 'public.events.noEndListedFor', '5');
/** Lines of the web server's PHP error log, so a check can see whether a request wrote one. */
$nErrorLines = static fn (): int => is_file($child . $sep . 'php-errors.log') === true ? count(file($child . $sep . 'php-errors.log') ?: []) : 0;
$nScenario16 = static function () use ($nListed, $nErrorLines, $http, $people): array {
    $before = $nErrorLines();
    $listed = in_array('PDS N no end 90 minutes ago', $nListed() ?? [], true);
    $logged = $nErrorLines() - $before;
    $page   = $http('GET', '/admin/public-website', ['jar' => $people['root']['jar']])['body'];
    return [$listed, $logged, str_contains($page, 'data-no-end-ignored="1"')];
};
check('N16 a stored time the card would refuse (5 minutes, a hand edit) is IGNORED and the default applies (the event that started 90 minutes ago is still listed); the public request writes NOTHING to the error log (round 1: one line per anonymous request); the card says it is being ignored',
    $nScenario16(), [true, 0, true]);
$setOwn($siteA, 'public.events.noEndListedFor', null);

// Round 1 of #882's check: listedUntil for the cases D-129 says must NOT change.
$nUntil = static function () use ($nShow, $feedA): array {
    return $nShow(['PDS N with an end time', 'PDS N all day, one day', 'PDS N all day, three days'], static function () use ($feedA): array {
        $out = [];
        foreach (json_decode($feedA('events.json?limit=50')['body'], true)['events'] ?? [] as $one) {
            if (str_starts_with((string) $one['title'], 'PDS N ') === true) {
                $out[$one['title']] = [$one['listedUntil'] ?? 'missing', $one['endsAt'] ?? null];
            }
        }
        ksort($out);
        return $out;
    });
};
$nLondon = new DateTimeZone('Europe/London');
$nMidnight = static fn (string $day): string => (new DateTimeImmutable($day . ' 00:00:00', $nLondon))->format('Y-m-d\TH:i:sP');
$nEndAt = (new DateTimeImmutable($nLondonTomorrow . ' 12:00:00', $nLondon))->format('Y-m-d\TH:i:sP');
$nUntilWant = [
    'PDS N all day, one day'    => [$nMidnight((new DateTimeImmutable($nLondonTomorrow))->modify('+1 day')->format('Y-m-d')), null],
    'PDS N all day, three days' => [$nMidnight((new DateTimeImmutable($nLondonTomorrow))->modify('+3 days')->format('Y-m-d')),
        (new DateTimeImmutable((new DateTimeImmutable($nLondonTomorrow))->modify('+2 days')->format('Y-m-d') . ' 00:00:00', $nLondon))->format('Y-m-d\TH:i:sP')],
    'PDS N with an end time'    => [$nEndAt, $nEndAt],
];
check('N17 (the default) listedUntil is the END for an event with an end time; midnight after its day for a one-day all-day event; midnight after its LAST day for a three-day all-day event',
    $nUntil(), $nUntilWant);
$setOwn($siteA, 'public.events.noEndListedFor', '120');
check('N18 (2 hours chosen) exactly the same: the choice changes listedUntil for none of the three (D-129)', $nUntil(), $nUntilWant);
$setOwn($siteA, 'public.events.noEndListedFor', null);
$nHistoric = static fn (): mixed => $nShow(['PDS N historic London', 'PDS N historic New York'],
    static fn (): mixed => $nAskFor($siteC, gmdate('Y-m-d H:i:s'), 1, 'PDS N historic'));
// N21: London's event is put on UTC's clock while it runs (see the fixture's
// comment), so the Azores' late +1 is the only thing that can carry it.
$nLate = static function () use ($nShow, $nAskFor, $siteC, $setEv, $nHistoricLondon): mixed {
    $setEv($nHistoricLondon, ['timezone' => 'UTC']);
    try {
        return $nShow(['PDS N late Azores', 'PDS N late New York'],
            static fn (): mixed => $nAskFor($siteC, gmdate('Y-m-d H:i:s'), 1, 'PDS N late'));
    } finally {
        $setEv($nHistoricLondon, ['timezone' => 'Europe/London']);
    }
};
$nWindows = static fn (): array => [$nHistoric(), $nLate()];
/** The highest offset a zone has EVER had, from PHP's whole list (local mean time included). */
$nEverMax = static fn (string $z): int => max(array_column((new DateTimeZone($z))->getTransitions() ?: [['offset' => 0]], 'offset'));
$nAzores = array_column((new DateTimeZone('Atlantic/Azores'))->getTransitions(), 'offset');
$nAzoresHalf = intdiv(count($nAzores), 2);
$nAzoresYear = array_column((new DateTimeZone('Atlantic/Azores'))->getTransitions(time(), time() + 366 * 86400) ?: [], 'offset');
check('N19 the premises: in June 1941 London was +2 and in May 1993 the Azores +1; EXCLUDING London, the highest offset any of C\'s clocks (UTC, New York, Azores) has EVER had is at most +1 hour, so only London\'s history can carry N20; excluding London AND the Azores it is at most 0, so only the Azores\' history can carry N21; and the Azores\' +1 is in the LATER half of its history and above every offset it has in the coming year',
    [(new DateTimeImmutable('1941-06-01 20:30:00', $nLondon))->format('P'), (new DateTimeImmutable('1993-05-01 19:30:00', new DateTimeZone('Atlantic/Azores')))->format('P'),
        max($nEverMax('UTC'), $nEverMax('America/New_York'), $nEverMax('Atlantic/Azores')) <= 3600,
        max($nEverMax('UTC'), $nEverMax('America/New_York')) <= 0,
        max(array_slice($nAzores, 0, $nAzoresHalf)) <= 0, max($nAzores) === 3600, max($nAzoresYear !== [] ? $nAzoresYear : [0]) <= 0],
    ['+02:00', '+01:00', true, true, true, true, true]);
check('N20 (#898) with limit=1, the sooner of two events (London 20:30 and New York 15:00 on 1 June 1941) is the one given — London\'s reading lies between one and two hours past the first read\'s latest moment, so the window must reach London\'s HISTORIC +2',
    $nHistoric(), ['PDS N historic London']);
check('N21 (#898) the same where the highest offset is LATE in a zone\'s history: the Azores at 19:30 on 1 May 1993 (+1, entry 126 of its history) is given ahead of New York\'s 15:00 — the window must read the WHOLE history, not its first part',
    $nLate(), ['PDS N late Azores']);

// =============================================================================
echo "J. The script a website pastes in (#493 P4b; D-056, D-084)\n";
// =============================================================================
// What is SERVED is pinned here, over HTTP: the same gate, the same identical
// 404, the headers, no cookie and no session file. What the script DRAWS is
// behaviour in a browser, so it is driven in a real one by
// tools/public-door-script-selftest.mjs (no claim about drawing is made here).
$scriptFile   = $child . $sep . 'web' . $sep . '_core' . $sep . 'data' . $sep . 'public-door.js';
$scriptOnDisk = (string) file_get_contents($scriptFile);
$j1 = $feedA('door.js');
check('J01 /w/{name}/door.js answers 200 as text/javascript, byte for byte the file kept OUTSIDE the web root, with its length stated',
    [$j1['status'], $j1['headers']['content-type'] ?? '', $j1['body'] === $scriptOnDisk && $scriptOnDisk !== '',
        $j1['headers']['content-length'] ?? '', is_file($child . $sep . 'web' . $sep . 'public_html' . $sep . 'door.js')],
    [200, 'text/javascript; charset=utf-8', true, (string) strlen($scriptOnDisk), false]);
$scriptWant = [
    'access-control-allow-origin' => '*', 'cross-origin-resource-policy' => 'cross-origin',
    'content-security-policy' => "default-src 'none'; frame-ancestors 'none'; sandbox", 'referrer-policy' => 'no-referrer',
    'cache-control' => 'public, max-age=60', 'x-robots-tag' => 'noindex', 'x-content-type-options' => 'nosniff',
];
$scriptHeaders = static function (array $r) use ($scriptWant): array {
    $out = [];
    foreach (array_keys($scriptWant) as $k) {
        $out[$k] = $r['headers'][$k] ?? '';
    }
    foreach (['set-cookie', 'x-frame-options', 'cross-origin-opener-policy', 'access-control-allow-credentials', 'vary'] as $k) {
        $out['no ' . $k] = $r['headers'][$k] ?? '';
    }
    return $out;
};
$scriptHeadersWant = $scriptWant + ['no set-cookie' => '', 'no x-frame-options' => '', 'no cross-origin-opener-policy' => '',
    'no access-control-allow-credentials' => '', 'no vary' => ''];
check('J02 its headers: loadable by any website (CORS *, CORP cross-origin), nosniff, no Referer, one minute\'s caching, noindex — and no Set-Cookie, X-Frame-Options, COOP, credentials header or Vary',
    $scriptHeaders($j1), $scriptHeadersWant);
$j3  = $http('GET', '/door.js', ['host' => $hostA]);
$j3q = $feedA('door.js?v=2');
check('J03 the same bytes on A\'s public address (/door.js), and a query string such as ?v=2 (which websites add to fetch a fresh copy) is ignored',
    [$j3['status'], $j3['body'] === $scriptOnDisk, $j3q['status'], $j3q['body'] === $scriptOnDisk], [200, true, 200, true]);
$jBefore = $sessionFiles();
$feedA('door.js', ['cookie' => 'PHPSESSID=' . str_repeat('y', 32)]);
$http('HEAD', '/w/' . $nameA . '/door.js');
$http('GET', '/door.js', ['host' => $hostA, 'cookie' => 'PHPSESSID=' . str_repeat('x', 32)]);
check('J04 no request for the script makes a session file — a made-up cookie and HEAD included', $sessionFiles() - $jBefore, 0);
$refusals['the script, unknown name']    = $http('GET', '/w/' . str_repeat('ef', 12) . '/door.js');
$refusals['the script, in capitals']     = $feedA('DOOR.JS');
$refusals['the script, a trailing slash'] = $feedA('door.js/');
$refusals['the script, a POST']          = $http('POST', '/w/' . $nameA . '/door.js', ['post' => ['x' => '1']]);
$refusals['the script, an inactive organisation\'s public address'] = $http('GET', '/door.js', ['host' => $hostC]);
$refusals['the script, an inactive organisation by name']           = $http('GET', '/w/' . $nameC . '/door.js');
$setOwn($siteA, 'calendar.enabled', 'false');
$jCalOff = $feedA('door.js');
$setOwn($siteA, 'noticeboard.enabled', 'false');
$refusals['the script with Calendar AND Noticeboard off'] = $feedA('door.js');
$setOwn($siteA, 'calendar.enabled', null);
$jNbOff = $feedA('door.js');
$setOwn($siteA, 'noticeboard.enabled', null);
check('J05 the script needs only ONE of the two apps: Calendar off, served; Noticeboard off, served; BOTH off, the door\'s 404',
    [$jCalOff['status'], $jNbOff['status'], $refusals['the script with Calendar AND Noticeboard off']['status']], [200, 200, 404]);
check('J06 an unknown name, capitals, a trailing slash, a POST and an inactive organisation (by name and by public address) are all the door\'s 404 for the script too',
    array_values(array_unique(array_map(static fn (string $k): int => $refusals[$k]['status'],
        ['the script, unknown name', 'the script, in capitals', 'the script, a trailing slash', 'the script, a POST',
            'the script, an inactive organisation\'s public address', 'the script, an inactive organisation by name']))), [404]);
$setInstallation('portal.maintenance.active', '1');
$jClosed        = $feedA('door.js');
$jClosedUnknown = $http('GET', '/w/' . str_repeat('ef', 12) . '/door.js');
$setInstallation('portal.maintenance.active', '0');
check('J07 while closed for maintenance the script is the same 503 as every other door answer, a real name and an unknown one alike',
    [$jClosed['status'], $signature($jClosed) === $signature($jClosedUnknown)], [503, true]);
// The card. A's public address is approved from section G on, so the ONE
// helper (PublicDoor::addressFor()) builds the script's address on it.
$jCard  = $http('GET', '/admin/public-website', ['jar' => $people['root']['jar']])['body'];
$jPaste = static function (string $show) use ($hostA): string {
    return htmlspecialchars('<script src="https://' . $hostA . '/door.js" data-show="' . $show . '" defer></script>', ENT_QUOTES, 'UTF-8');
};
check('J08 with the door on, the card prints the line to paste for each of the three modes — events, posters, and "next" (the countdown, D-084) — on the address the ONE helper builds (A\'s approved public address here), as TEXT, never as a live script on the page',
    [str_contains($jCard, $jPaste('events')), str_contains($jCard, $jPaste('posters')), str_contains($jCard, $jPaste('next')),
        str_contains($jCard, '<script src="https://' . $hostA . '/door.js"')],
    [true, true, true, false]);

// Round 1 of P4b's check: the script was never asked for on a switched-off
// door's APPROVED PUBLIC ADDRESS. On /w/{name}/ switching off deletes the name
// row, so the name stops resolving and the "door on?" question is never the
// one that refuses; on a public address it is the ONLY guard. So every way the
// script can be asked for after a replace and a switch-off joins $refusals,
// which R01 compares byte for byte, and PJ9 takes the question out.
$jOldName = $nameA;
$card('root', 'door_replace', ['understood' => '1']);
$refusals['the script on a replaced name'] = $http('GET', '/w/' . $jOldName . '/door.js');
$jLastName = (string) $doorName($siteA);
$card('root', 'door_off', ['understood' => '1']);
$refusals['the script on a switched-off door\'s public address'] = $http('GET', '/door.js', ['host' => $hostA]);
$refusals['the script on a switched-off door\'s last name'] = $http('GET', '/w/' . $jLastName . '/door.js');
$card('root', 'door_on');
$nameA = (string) $doorName($siteA);
check('J09 the script after a replaced name (the old name), and with the door switched off (on A\'s approved public address AND on the last name), is the door\'s 404 — and served again once the door is back on',
    [$refusals['the script on a replaced name']['status'], $refusals['the script on a switched-off door\'s public address']['status'],
        $refusals['the script on a switched-off door\'s last name']['status'], $feedA('door.js')['status'], $http('GET', '/door.js', ['host' => $hostA])['status']],
    [404, 404, 404, 200, 200]);

// Round 1 of P4b's check (#777): on the morning a clock goes forward, a stored
// reading inside the missing hour must reach a website AS WRITTEN — the portal's
// own pages show it so (I18n::formatClockDateTime()) — while the instant moves
// past the gap. The next such morning in London is worked out, not typed, so
// the test does not expire.
$jLondon = new DateTimeZone('Europe/London');
$jYear   = (int) (new DateTimeImmutable('now', $jLondon))->format('Y');
$jGapDay = '';
for ($y = $jYear; $y <= $jYear + 1 && $jGapDay === ''; $y++) {
    $candidate = (new DateTimeImmutable('last sunday of march ' . $y, $jLondon))->format('Y-m-d');
    if ($candidate > (new DateTimeImmutable('now', $jLondon))->modify('+1 day')->format('Y-m-d')) {
        $jGapDay = $candidate;
    }
}
$jGapEvent = $makeEvent('PDS A in the missing hour', $siteA, 'Europe/London', $jGapDay . ' 01:30:00', $jGapDay . ' 03:00:00');
$jGapFeed  = $feedA('events.json?limit=50');
$capture('events', 'a-missing-hour', $jGapFeed);
$jGapRow = null;
foreach (json_decode($jGapFeed['body'], true)['events'] ?? [] as $one) {
    if ($one['title'] === 'PDS A in the missing hour') {
        $jGapRow = $one;
    }
}
$db->query("DELETE FROM `tblEvents` WHERE `eventID` = {$jGapEvent}");
check('J10 on the morning London\'s clocks go forward (' . $jGapDay . '), an event stored at 01:30 reaches a website as 01:30 to SHOW (startsOnClock, as the portal\'s own pages show it) while its instant has moved past the gap (startsAt 02:30+01:00, for counting down) — the premise first: PHP really moves that reading',
    [(new DateTimeImmutable($jGapDay . ' 01:30:00', $jLondon))->format('H:i'), $jGapRow['startsOnClock'] ?? null, $jGapRow['startsAt'] ?? null,
        $jGapRow['endsOnClock'] ?? null],
    ['02:30', $jGapDay . 'T01:30:00', $jGapDay . 'T02:30:00+01:00', $jGapDay . 'T03:00:00']);

// Round 2 of P4b's check: "startsOnClock is null exactly when startsAt is" was
// held by construction only — clockText() made to answer null for any reading
// at hour 00 left every check green. So every event in a FRESH read of the
// feeds is checked against the rule: each *OnClock is null exactly when its
// instant is, and otherwise equals the instant's first 19 characters — except
// the one event in the missing hour, whose reading is the STORED one. The read
// includes an event starting at 00:00 and the missing-hour event, each made
// for the read and removed after it, and the premise says they were really
// there. ⚠️ The first version borrowed C18's all-day event "today" for its
// 00:00 reading — and a run that crossed midnight lost it: by the time PJ11
// ran, "today" was yesterday, the event had finished and left the feed, the
// plant had nothing to bite on and stayed green (measured). Its own event,
// three days ahead, cannot expire mid-run; and PJ11 asks the premise too.
$scnClockFields = static function () use ($feedA, $http, $hostA, $nameB, $makeEvent, $siteA, $zoneA, $nowA, $jGapDay, $db): array {
    $midnightDay = $nowA->modify('+3 days')->format('Y-m-d');
    $midnight = $makeEvent('PDS A at midnight', $siteA, $zoneA, $midnightDay . ' 00:00:00', $midnightDay . ' 01:00:00');
    $gap      = $makeEvent('PDS A in the missing hour', $siteA, 'Europe/London', $jGapDay . ' 01:30:00', $jGapDay . ' 03:00:00');
    $reads = [$feedA('events.json?limit=50'), $http('GET', '/events.json?limit=50', ['host' => $hostA]), $http('GET', '/w/' . $nameB . '/events.json?limit=50')];
    $db->query("DELETE FROM `tblEvents` WHERE `eventID` IN ({$midnight}, {$gap})");
    $problems = [];
    $seen     = ['events' => 0, 'midnight' => 0, 'withEnd' => 0, 'gap' => 0];
    foreach ($reads as $i => $r) {
        $events = json_decode($r['body'], true)['events'] ?? null;
        if ($r['status'] !== 200 || is_array($events) === false) {
            $problems[] = "read {$i} answered {$r['status']}";
            continue;
        }
        foreach ($events as $one) {
            $seen['events']++;
            $isGap = $one['title'] === 'PDS A in the missing hour';
            foreach (['startsAt' => 'startsOnClock', 'endsAt' => 'endsOnClock'] as $instantKey => $clockKey) {
                if (array_key_exists($clockKey, $one) === false) {
                    $problems[] = "{$one['title']}: no {$clockKey}";
                    continue;
                }
                $instant = $one[$instantKey];
                $clock   = $one[$clockKey];
                // What was READ is counted from the instant, before any rule is
                // judged, so a fault in the reading to show cannot also empty
                // the premise (it did, in the first version of PJ11).
                if ($instantKey === 'startsAt' && $instant !== null && substr((string) $instant, 11, 2) === '00') {
                    $seen['midnight']++;
                }
                if ($instantKey === 'endsAt' && $instant !== null) {
                    $seen['withEnd']++;
                }
                if (($instant === null) !== ($clock === null)) {
                    $problems[] = "{$one['title']}: {$clockKey} " . var_export($clock, true) . " beside {$instantKey} " . var_export($instant, true);
                    continue;
                }
                if ($instant === null) {
                    continue;
                }
                $want = $isGap === true
                    ? ($instantKey === 'startsAt' ? $jGapDay . 'T01:30:00' : $jGapDay . 'T03:00:00')
                    : substr((string) $instant, 0, 19);
                if ($clock !== $want) {
                    $problems[] = "{$one['title']}: {$clockKey} {$clock}, wanted {$want}";
                }
            }
            if ($isGap === true) {
                $seen['gap']++;
            }
        }
    }
    return ['problems' => $problems, 'premise' => $seen['events'] >= 5 && $seen['midnight'] >= 1 && $seen['withEnd'] >= 1 && $seen['gap'] >= 1, 'seen' => $seen];
};
$jClock = $scnClockFields();
check('J11 in a fresh read of three feeds (A by name, A by public address, B), every event\'s startsOnClock and endsOnClock are null exactly when its instant is, and otherwise the instant\'s own reading — the missing-hour event excepted, which keeps the STORED reading; the premise: an event at 00:00, one with an end and the missing-hour one were really read',
    [$jClock['premise'], $jClock['problems']], [true, []]);

// =============================================================================
echo "V. The page a website shows in a frame (#493 P4c; D-056, D-082, D-094)\n";
// =============================================================================
// The page is read here as a browser reads it — PHP's own HTML5 parser
// (Dom\HTMLDocument), never a search of the markup — so "this shows as text"
// means the parsed text node holds the very characters sent, and "nothing
// runs" means no <script> element and no on… attribute exists in the parsed
// document. Whether a browser REFUSES the frame from a website not listed is
// behaviour in a browser: tools/public-door-script-selftest.mjs drives it.
/**
 * The facts of one page, from the PARSED document.
 *
 * @return array<string, mixed>
 */
$pageFacts = static function (string $html): array {
    if (str_contains($html, '<html') === false) {
        return ['parsed' => false];
    }
    $doc   = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $texts = static function (string $selector) use ($doc): array {
        $out = [];
        foreach ($doc->querySelectorAll($selector) as $node) {
            $out[] = $node->textContent;
        }
        return $out;
    };
    $tags = [];
    $handlers = [];
    foreach ($doc->querySelectorAll('*') as $el) {
        $tags[strtolower($el->tagName)] = true;
        foreach ($el->attributes as $attr) {
            if (str_starts_with(strtolower($attr->name), 'on') === true) {
                $handlers[] = strtolower($el->tagName) . '[' . $attr->name . ']';
            }
        }
    }
    ksort($tags);
    $links = [];
    foreach ($doc->querySelectorAll('a') as $a) {
        $links[] = ['href' => $a->getAttribute('href'), 'target' => $a->getAttribute('target'), 'rel' => $a->getAttribute('rel'),
            'ref' => $a->getAttribute('referrerpolicy'), 'text' => $a->textContent];
    }
    $pics = [];
    foreach ($doc->querySelectorAll('img') as $img) {
        $pics[] = ['src' => $img->getAttribute('src'), 'ref' => $img->getAttribute('referrerpolicy'), 'alt' => $img->getAttribute('alt')];
    }
    $style = $doc->querySelector('head > style');
    return [
        'parsed' => true, 'title' => $doc->title, 'parts' => array_map(static fn ($n) => $n->getAttribute('data-door-part'),
            iterator_to_array($doc->querySelectorAll('[data-door-part]'))),
        'headings' => $texts('h2'), 'titles' => $texts('.door__title'), 'whens' => $texts('.door__when'), 'wheres' => $texts('.door__where'),
        'abouts' => $texts('.door__about'), 'kickers' => $texts('.door__kicker'), 'empties' => $texts('.door__empty'),
        'links' => $links, 'pics' => $pics, 'tags' => array_keys($tags), 'handlers' => $handlers,
        'scripts' => count($doc->querySelectorAll('script')), 'styles' => count($doc->querySelectorAll('style')),
        'styleText' => $style === null ? null : $style->textContent,
    ];
};
/** The content rule a page must carry: its ONE stylesheet allowed by the hash of what was really SENT, and `$fa` for frame-ancestors. */
$pageCspFor = static function (array $facts, string $fa): string {
    return "default-src 'none'; img-src https:; style-src 'sha256-" . base64_encode(hash('sha256', (string) ($facts['styleText'] ?? ''), true)) . "'; "
        . "base-uri 'none'; form-action 'none'; frame-ancestors " . $fa . '; sandbox allow-popups allow-popups-to-escape-sandbox';
};
/** The headers a page answer is pinned on, and the ones it must NOT carry. */
$pageHeaders = static function (array $r): array {
    $out = [];
    foreach (['content-type', 'content-security-policy', 'x-frame-options', 'cross-origin-resource-policy', 'referrer-policy',
        'x-content-type-options', 'cache-control', 'vary', 'x-robots-tag', 'set-cookie', 'access-control-allow-origin',
        'access-control-allow-credentials', 'cross-origin-opener-policy'] as $k) {
        $out[$k] = $r['headers'][$k] ?? '';
    }
    return $out;
};
$pageHeadersWant = static function (string $csp, string $xfo, string $cache = 'public, max-age=60', string $vary = '', string $robots = 'noindex'): array {
    return ['content-type' => 'text/html; charset=utf-8', 'content-security-policy' => $csp, 'x-frame-options' => $xfo,
        'cross-origin-resource-policy' => 'cross-origin', 'referrer-policy' => 'no-referrer', 'x-content-type-options' => 'nosniff',
        'cache-control' => $cache, 'vary' => $vary, 'x-robots-tag' => $robots, 'set-cookie' => '', 'access-control-allow-origin' => '',
        'access-control-allow-credentials' => '', 'cross-origin-opener-policy' => ''];
};
// The name is asked of the database at every call (as $feedA does): V19 replaces it.
$pageA = static fn (string $query = '', array $opt = []) => $http('GET', '/w/' . (string) $doorName($siteA) . '/page.html' . $query, $opt);
$pagePublicA = static fn (string $query = '', array $opt = []) => $http('GET', '/page.html' . $query, ['host' => $hostA] + $opt);

$v1 = $pageA();
$v1f = $pageFacts($v1['body']);
check('V01 /w/{name}/page.html answers 200 HTML: by default A\'s events only, soonest first — the same events the feed gives — with no posters part',
    [$v1['status'], $v1f['parts'] ?? null, $v1f['titles'] ?? null, $v1f['headings'] ?? null],
    [200, ['events'], ['PDS A running now', 'PDS A shown'], []]);
$v2a = $pageFacts($pagePublicA()['body']);
$v2b = $pageFacts($http('GET', '/w/' . $nameB . '/page.html')['body']);
$v2c = $pageFacts($http('GET', '/page.html', ['host' => $hostB])['body']);
check('V02 the same page on A\'s approved public address (/page.html); and B\'s name and B\'s public address give B\'s events only — the organisations never cross',
    [$v2a['titles'] ?? null, $v2b['titles'] ?? null, $v2c['titles'] ?? null], [['PDS A running now', 'PDS A shown'], ['PDS B shown'], ['PDS B shown']]);
$v3w = $pageFacts($pageA('?show=posters')['body']);
$v3p = $pageFacts($pagePublicA('?show=posters')['body']);
// A has an APPROVED public address, so addressFor() points the door's media at
// it whichever form the page is asked on (C11): both forms draw the same
// pictures. A plain-http picture or link is dropped (VP01 drives that).
check('V03 show=posters gives A\'s posters in the board\'s order; a picture only over https — the door\'s own media address, and an outside https picture — the same on both forms of address; a link only to https (the plain-http one dropped)',
    [$v3w['parts'] ?? null, $v3w['titles'] ?? null, $v3p['pics'] ?? null, $v3w['pics'] ?? null, $v3p['links'] ?? null],
    [['posters'], ['PDS poster shown', 'PDS poster outside picture', 'PDS poster naming B upload'],
        [['src' => 'https://' . $hostA . '/media/' . $upA, 'ref' => 'no-referrer', 'alt' => ''], ['src' => 'https://images.selftest.invalid/a.png', 'ref' => 'no-referrer', 'alt' => '']],
        [['src' => 'https://' . $hostA . '/media/' . $upA, 'ref' => 'no-referrer', 'alt' => ''], ['src' => 'https://images.selftest.invalid/a.png', 'ref' => 'no-referrer', 'alt' => '']],
        [['href' => 'https://events.selftest.invalid/toddlers', 'target' => '_blank', 'rel' => 'noopener noreferrer nofollow', 'ref' => 'no-referrer', 'text' => 'Find out more']]]);
$v4 = $pageFacts($pageA('?show=both')['body']);
$v5 = $pageFacts($pageA('?show=both&limit=1')['body']);
check('V04 show=both gives events then posters, each headed; limit=1 gives one of EACH',
    [$v4['parts'] ?? null, $v4['headings'] ?? null, count($v4['titles'] ?? []), $v5['titles'] ?? null],
    [['events', 'posters'], ['Events', 'Noticeboard'], 5, ['PDS A running now', 'PDS poster shown']]);
// Times: on the event's own clock, from the STORED reading (#777). The next
// morning London's clocks go forward was worked out in J10 ($jGapDay).
$vGap = $makeEvent('PDS A in the missing hour', $siteA, 'Europe/London', $jGapDay . ' 01:30:00', $jGapDay . ' 03:00:00');
$vClock = $pageFacts($pageA('?limit=50')['body']);
$db->query("DELETE FROM `tblEvents` WHERE `eventID` = {$vGap}");
$vWhen = static function (array $f, string $title): ?string {
    foreach ($f['titles'] ?? [] as $i => $t) {
        if ($t === $title) {
            return $f['whens'][$i] ?? null;
        }
    }
    return null;
};
$vLondon = $zoneA === 'Europe/London' ? '' : ' (London time)';
check('V05 each event\'s time is on its own clock, as STORED: the running event at its stored reading; on the morning London\'s clocks go forward (' . $jGapDay . ') an event stored at 01:30 SHOWS 01:30, not the 02:30 its instant moved to — the premise first: PHP really moves that reading — and an event on another clock than the organisation\'s names it',
    [(new DateTimeImmutable($jGapDay . ' 01:30:00', new DateTimeZone('Europe/London')))->format('H:i'),
        $vWhen($vClock, 'PDS A running now'), $vWhen($vClock, 'PDS A in the missing hour')],
    ['02:30', $nowA->modify('-1 hour')->format('D j M Y, H:i'),
        (new DateTimeImmutable($jGapDay . ' 00:00:00', new DateTimeZone('UTC')))->format('D j M Y') . ', 01:30' . $vLondon]);
// Hostile text in EVERY field the database can carry (P4b round 1's lesson:
// one field with markup proves nothing about the others). The fields the
// database cannot carry hostile text in (a zone name, a TIME column) are
// driven through the renderer itself in VP01, with every field hostile.
$vEvil = [
    'title' => 'PDS V <img src=x onerror="window.pwned=1">title & "q"',
    'about' => "PDS V <script>window.pwned=2</script>about\nline two",
    'place' => 'PDS V <b onclick="x()">place</b>',
    'street' => 'PDS V <svg onload="x()"></svg>street',
    'poster' => 'PDS V <iframe src="//evil"></iframe>poster',
    'kicker' => 'PDS V <u onmouseover="x()">kicker</u>',
    'where' => 'PDS V <style>*{display:none}</style>where',
    // `</title>` because inside <title> a parser reads tags as TEXT: only a
    // closing tag can break out of it, so only a value carrying one can tell
    // an escaped <title> from an unescaped one (VP02 was green without it).
    'org' => 'PDS V </title><img src=x onerror=alert(1)> org & \'q\'',
];
$vEvent = $makeEvent($vEvil['title'], $siteA, $zoneA, $at($later->modify('+1 day')), null,
    ['description' => $vEvil['about'], 'locationName' => $vEvil['place'], 'locationAddress' => $vEvil['street']]);
$vPoster = $makePoster($vEvil['poster'], $siteA, ['kicker' => $vEvil['kicker'], 'location' => $vEvil['where']]);
$db->query("UPDATE `tblSites` SET `siteName` = '" . $db->real_escape_string($vEvil['org']) . "' WHERE `siteID` = 1");
$vHostileBodies = [$pageA('?show=both&limit=50')['body'], $pagePublicA('?show=both&limit=50')['body']];
$db->query("UPDATE `tblSites` SET `siteName` = '" . $db->real_escape_string($nameTest) . "' WHERE `siteID` = 1");
$db->query("DELETE FROM `tblEvents` WHERE `eventID` = {$vEvent}");
$db->query("DELETE FROM `tblNoticeboardPosters` WHERE `posterID` = {$vPoster}");
$vHostile = array_map($pageFacts, $vHostileBodies);
$vMissing = [];
foreach ($vHostile as $i => $f) {
    $all = array_merge([$f['title'] ?? ''], $f['titles'] ?? [], $f['abouts'] ?? [], $f['wheres'] ?? [], $f['kickers'] ?? []);
    foreach (['title', 'poster'] as $k) {
        if (in_array($vEvil[$k], $all, true) === false) {
            $vMissing[] = "{$i}:{$k}";
        }
    }
    foreach (['about' => 'abouts', 'kicker' => 'kickers', 'where' => 'wheres'] as $k => $list) {
        if (in_array($vEvil[$k], $f[$list] ?? [], true) === false) {
            $vMissing[] = "{$i}:{$k}";
        }
    }
    if (in_array($vEvil['place'] . ', ' . $vEvil['street'], $f['wheres'] ?? [], true) === false) {
        $vMissing[] = "{$i}:place+street";
    }
    if (($f['title'] ?? '') !== $vEvil['org'] . ' — events and noticeboard') {
        $vMissing[] = "{$i}:organisation name";
    }
    if (in_array('<b>"Tom & Jerry\'s"</b></script>', $f['abouts'] ?? [], true) === false) {
        $vMissing[] = "{$i}:running event's description";
    }
}
check('V06 hostile text in EVERY field the database can carry — an event\'s title, description, place and address, a poster\'s title, kicker and place, and the organisation\'s name — appears character for character as TEXT in the parsed page, on both forms of address',
    $vMissing, []);
$vElements = ['a', 'body', 'h2', 'head', 'html', 'img', 'li', 'main', 'meta', 'p', 'section', 'style', 'title', 'ul'];
check('V07 and nothing in those values became markup: no <script>, no on… attribute, exactly ONE <style> (the page\'s own), and no element the page does not draw itself',
    array_map(static fn (array $f): array => [$f['scripts'] ?? null, $f['handlers'] ?? null, $f['styles'] ?? null, $f['tags'] ?? null], $vHostile),
    [[0, [], 1, $vElements], [0, [], 1, $vElements]]);
$vAllBodies = array_merge([$v1['body'], $pageA('?show=posters')['body'], $pageA('?show=both')['body'], $pagePublicA('?show=both')['body']], $vHostileBodies);
check('V08 no page answer contains a <script> tag or "javascript:" anywhere in its bytes (the page is the no-script fallback)',
    array_values(array_filter($vAllBodies, static fn (string $b): bool => stripos($b, '<script') !== false || stripos($b, 'javascript:') !== false)), []);

// Headers, in each state of D-082 and D-094.
$setOwn($siteA, 'public.frame.origins', null);
$vNone  = $pageA();
$vNoneP = $pagePublicA();
check('V09 with nothing listed: frame-ancestors \'none\' (no website may frame it) and X-Frame-Options DENY; the ONE stylesheet allowed by the hash of what was really sent; no script source at all; sandboxed; one minute\'s caching; noindex; no cookie, no CORS header, no COOP — on both forms of address',
    [$pageHeaders($vNone), $pageHeaders($vNoneP)],
    [$pageHeadersWant($pageCspFor($pageFacts($vNone['body']), "'none'"), 'DENY'), $pageHeadersWant($pageCspFor($pageFacts($vNoneP['body']), "'none'"), 'DENY')]);
$setOwn($siteA, 'public.frame.origins', "https://www.selftest.invalid\nhttps://second.selftest.invalid:8443");
$vListed  = $pageA();
$vListedP = $pagePublicA();
$vListedB = $http('GET', '/w/' . $nameB . '/page.html');
check('V10 with two websites listed: frame-ancestors names exactly those two (as frameAncestorsFor() gives them), with NO X-Frame-Options (it cannot name a website, and DENY would refuse the listed ones) — on both forms of address; and B, which lists nothing, still says \'none\'',
    [$pageHeaders($vListed), $pageHeaders($vListedP), $vListedB['headers']['content-security-policy'] ?? '', $vListedB['headers']['x-frame-options'] ?? ''],
    [$pageHeadersWant($pageCspFor($pageFacts($vListed['body']), 'https://www.selftest.invalid https://second.selftest.invalid:8443'), ''),
        $pageHeadersWant($pageCspFor($pageFacts($vListedP['body']), 'https://www.selftest.invalid https://second.selftest.invalid:8443'), ''),
        $pageCspFor($pageFacts($vListedB['body']), "'none'"), 'DENY']);
// A HAND-EDITED list (lines the page would refuse) is filtered by the ONE
// reader: the header carries only what frameAncestorsFor() accepts.
$setOwn($siteA, 'public.frame.origins', "https://www.selftest.invalid\nhttp://plain.selftest.invalid\nhttps://*.selftest.invalid\n*\nhttps://x.selftest.invalid; script-src *");
$vEdited = $pageA();
check('V11 a hand-edited list holding lines the page would refuse (plain http, a wildcard, "*", an injected directive) gives frame-ancestors the one good website only',
    $vEdited['headers']['content-security-policy'] ?? '', $pageCspFor($pageFacts($vEdited['body']), 'https://www.selftest.invalid'));
$setOwn($siteA, 'public.frame.origins', 'https://www.selftest.invalid');
$setInstallation('public.frame.permitted', 'no');
$vOff  = $pageA();
$vOffP = $pagePublicA();
$setInstallation('public.frame.permitted', 'maybe');
$vOdd  = $pageA();
$vOddP = $pagePublicA();
$setInstallation('public.frame.permitted', 'yes');
check('V12 with the installation\'s switch saying no (D-094), and with a value that is neither yes nor no, a listed website counts for nothing: \'none\' and DENY — on both forms of address',
    array_map($pageHeaders, [$vOff, $vOffP, $vOdd, $vOddP]),
    array_map(static fn (array $r): array => $pageHeadersWant($pageCspFor($pageFacts($r['body']), "'none'"), 'DENY'), [$vOff, $vOffP, $vOdd, $vOddP]));
$vBefore = $sessionFiles();
$vHead  = $http('HEAD', '/w/' . $nameA . '/page.html');
$vHeadP = $http('HEAD', '/page.html', ['host' => $hostA]);
$pageA('', ['cookie' => 'PHPSESSID=' . str_repeat('p', 32)]);
$pagePublicA('?show=both', ['cookie' => 'PHPSESSID=' . str_repeat('o', 32)]);
check('V13 HEAD is answered like GET, with no body, on both forms of address; and no page request makes a session file — a made-up cookie included',
    [$vHead['status'], $vHead['body'], $vHeadP['status'], $vHeadP['body'], $sessionFiles() - $vBefore], [200, '', 200, '', 0]);

// The card.
$vCard = static function () use ($http, $people): string {
    return $http('GET', '/admin/public-website', ['jar' => $people['root']['jar']])['body'];
};
$vFrameLine = static function (string $show, string $title) use ($hostA): string {
    return htmlspecialchars('<iframe src="https://' . $hostA . '/page.html?show=' . $show . '" title="' . $title . '" width="100%" height="600" style="border:0" '
        . 'loading="lazy" referrerpolicy="no-referrer" sandbox="allow-popups allow-popups-to-escape-sandbox"></iframe>', ENT_QUOTES, 'UTF-8');
};
$vCardListed = $vCard();
$setOwn($siteA, 'public.frame.origins', null);
$vCardNone = $vCard();
$setOwn($siteA, 'public.frame.origins', 'https://www.selftest.invalid');
$setInstallation('public.frame.permitted', 'no');
$vCardOff = $vCard();
$setInstallation('public.frame.permitted', 'yes');
$setOwn($siteA, 'public.frame.origins', null);
check('V14 the card prints the frame to paste for events, posters and both, on the address the ONE helper builds (A\'s public address here), as TEXT — never a live frame on the card',
    [str_contains($vCardListed, $vFrameLine('events', 'Our events')), str_contains($vCardListed, $vFrameLine('posters', 'Our noticeboard')),
        str_contains($vCardListed, $vFrameLine('both', 'Our events and noticeboard')), stripos($vCardListed, '<iframe') !== false],
    [true, true, true, false]);
check('V15 and says when a frame works: only on the website listed (named, with the cost in a very old browser said); on NO website while none is listed; not at all while the installation forbids framing — each in its own words; and (round 1 of P4c\'s check) that the frames and the script lines show nothing while their app is switched off',
    [str_contains($vCardListed, 'data-frame-works="listed"') && str_contains($vCardListed, 'below: https://www.selftest.invalid.'),
        str_contains($vCardListed, 'such as Internet Explorer 11, would show it on any website'),
        str_contains($vCardNone, 'data-frame-works="none-listed"'), str_contains($vCardOff, 'data-frame-works="installation-off"'),
        str_contains($vCardOff, 'data-frame-works="listed"'), str_contains($vCardNone, 'data-frame-works="listed"'),
        // WHOLE sentences (round 2 of P4c's check: pinning only their openings
        // let the posters halves go untrue unnoticed).
        str_contains($vCardListed, 'data-frame-apps="1">The events frame, and the one with both, show nothing while Calendar and Events is switched off '
            . 'for your organisation; the posters frame, and the one with both, show nothing while the Noticeboard is switched off.</p>'),
        str_contains($vCardListed, 'data-script-apps="1">The events and countdown lines show nothing while Calendar and Events is switched off for your '
            . 'organisation, and the posters line shows nothing while the Noticeboard is switched off.</p>')],
    [true, true, true, true, false, false, true, true]);

// Refusals: every one joins $refusals, which R01 compares byte for byte.
// Round 1 of P4c's check: every one of these was asked on /w/ ONLY, so a
// public address could answer them differently (a bare 404 without the door's
// headers, measured) with everything green. BOTH forms, every one.
foreach (['by name' => $pageA, 'public address' => $pagePublicA] as $form => $ask) {
    foreach (['Events', 'all', '', ' events', 'next'] as $bad) {
        $refusals["the page, {$form}, show={$bad}"] = $ask('?show=' . rawurlencode($bad));
    }
    $refusals["the page, {$form}, show as a list"] = $ask('?show[]=events');
    $refusals["the page, {$form}, limit=0"] = $ask('?limit=0');
    $refusals["the page, {$form}, limit=51"] = $ask('?limit=51');
}
$refusals['the page, by name, a POST'] = $http('POST', '/w/' . $nameA . '/page.html', ['post' => ['x' => '1']]);
$refusals['the page, public address, a POST'] = $http('POST', '/page.html', ['host' => $hostA, 'post' => ['x' => '1']]);
$refusals['the page, by name, in capitals'] = $http('GET', '/w/' . $nameA . '/PAGE.HTML');
$refusals['the page, public address, in capitals'] = $http('GET', '/PAGE.HTML', ['host' => $hostA]);
$refusals['the page, by name, a trailing slash'] = $http('GET', '/w/' . $nameA . '/page.html/');
$refusals['the page, public address, a trailing slash'] = $http('GET', '/page.html/', ['host' => $hostA]);
$refusals['the page, unknown name'] = $http('GET', '/w/' . str_repeat('cd', 12) . '/page.html');
$refusals['the page, an inactive organisation by name'] = $http('GET', '/w/' . $nameC . '/page.html');
$refusals['the page, an inactive organisation\'s public address'] = $http('GET', '/page.html', ['host' => $hostC]);
$vApps = [];
foreach (['by name' => $pageA, 'public address' => $pagePublicA] as $form => $ask) {
    $setOwn($siteA, 'calendar.enabled', 'false');
    $refusals["the page, {$form}, events with Calendar off"] = $ask('?show=events');
    $refusals["the page, {$form}, both with Calendar off"] = $ask('?show=both');
    $vApps[] = [$refusals["the page, {$form}, events with Calendar off"]['status'], $refusals["the page, {$form}, both with Calendar off"]['status'],
        $ask('?show=posters')['status']];
    $setOwn($siteA, 'calendar.enabled', null);
    $setOwn($siteA, 'noticeboard.enabled', 'false');
    $refusals["the page, {$form}, posters with the Noticeboard off"] = $ask('?show=posters');
    $refusals["the page, {$form}, both with the Noticeboard off"] = $ask('?show=both');
    $vApps[] = [$refusals["the page, {$form}, posters with the Noticeboard off"]['status'], $refusals["the page, {$form}, both with the Noticeboard off"]['status'],
        $ask('?show=events')['status']];
    $setOwn($siteA, 'noticeboard.enabled', null);
}
check('V16 every part asked for needs its own app, on both forms of address: Calendar off refuses events and both but serves posters; the Noticeboard off refuses posters and both but serves events',
    $vApps, [[404, 404, 200], [404, 404, 200], [404, 404, 200], [404, 404, 200]]);
check('V17 on BOTH forms of address, a show that is not exactly events, posters or both (capitals, "all", empty, a space, the script\'s "next", a list), a bad limit, a POST, capitals and a trailing slash — and an unknown name and an inactive organisation (by name and by public address) — are all the door\'s 404 (R01 compares every one byte for byte)',
    array_values(array_unique(array_map(static fn (string $k): int => $refusals[$k]['status'], array_values(array_filter(array_keys($refusals),
        static fn (string $k): bool => str_starts_with($k, 'the page, ') === true))))), [404]);
$setInstallation('portal.maintenance.active', '1');
$vClosed        = $pageA();
$vClosedPublic  = $pagePublicA();
$vClosedUnknown = $http('GET', '/w/' . str_repeat('cd', 12) . '/page.html');
$setInstallation('portal.maintenance.active', '0');
check('V18 while closed for maintenance the page is the same 503 as every other door answer — by name, on A\'s public address, and for an unknown name alike',
    [$vClosed['status'], $vClosedPublic['status'], $signature($vClosed) === $signature($vClosedUnknown), $signature($vClosedPublic) === $signature($vClosedUnknown)],
    [503, 503, true, true]);
// Replaced, and switched off — on BOTH forms of address (P4b round 1's
// lesson: on a public address "is the door on?" is the only guard).
$vOldName = $nameA;
$card('root', 'door_replace', ['understood' => '1']);
$refusals['the page on a replaced name'] = $http('GET', '/w/' . $vOldName . '/page.html');
$vLastName = (string) $doorName($siteA);
$card('root', 'door_off', ['understood' => '1']);
$refusals['the page on a switched-off door\'s public address'] = $http('GET', '/page.html', ['host' => $hostA]);
$refusals['the page on a switched-off door\'s last name'] = $http('GET', '/w/' . $vLastName . '/page.html');
$card('root', 'door_on');
$nameA = (string) $doorName($siteA);
check('V19 the page after a replaced name, and with the door switched off (on A\'s approved public address AND on the last name), is the door\'s 404 — and served again once the door is back on',
    [$refusals['the page on a replaced name']['status'], $refusals['the page on a switched-off door\'s public address']['status'],
        $refusals['the page on a switched-off door\'s last name']['status'], $pageA()['status'], $pagePublicA()['status']],
    [404, 404, 404, 200, 200]);

// The renderer itself, with EVERY field hostile — including the two the
// database cannot carry hostile text in (a zone's name, a poster's time) — run
// from the COPY in its own PHP process, so a plant made in the copy is what
// answers (VP plants below).
$vpFixture = [
    'events' => ['organisation' => ['name' => 'VP </title><img src=x onerror=1> org', 'timezone' => 'Europe/London'], 'events' => [
        ['id' => 'a', 'title' => 'VP <script>window.pwned=1</script>title', 'startsAt' => '2030-06-01T10:30:00+01:00', 'endsAt' => null,
            'startsOnClock' => '2030-06-01T10:30:00', 'endsOnClock' => null, 'allDay' => false, 'timezone' => 'Atlantic/<i onclick=x>Nowhere',
            'detailsLimited' => false, 'description' => 'VP <iframe src=//evil></iframe>about',
            'location' => ['name' => 'VP <u>place</u>', 'address' => 'VP <svg onload=x></svg>street', 'url' => 'javascript:window.pwned=2']],
        ['id' => 'b', 'title' => 'VP plain', 'startsAt' => '2030-06-02T09:00:00+01:00', 'endsAt' => null, 'startsOnClock' => '2030-06-02T09:00:00',
            'endsOnClock' => null, 'allDay' => false, 'timezone' => 'Europe/London', 'detailsLimited' => false, 'description' => null,
            'location' => ['name' => 'Venue', 'address' => null, 'url' => 'https://venue.example.invalid/?a=1&lt;b']],
        ['id' => 'c', 'title' => 'VP all day', 'startsAt' => '2030-07-04T00:00:00+01:00', 'endsAt' => null, 'startsOnClock' => '2030-07-04T00:00:00',
            'endsOnClock' => null, 'allDay' => true, 'timezone' => 'Europe/London', 'detailsLimited' => true, 'description' => null, 'location' => null],
    ]],
    'posters' => ['organisation' => ['name' => 'VP </title><img src=x onerror=1> org', 'timezone' => 'Europe/London'], 'posters' => [
        ['title' => 'VP <img src=x onerror=3>poster', 'kicker' => 'VP <i>kicker</i>', 'category' => null, 'when' => ['type' => 'weekly', 'weekday' => 2],
            'time' => 'VP <b>10:30</b>', 'location' => 'VP <s>where</s>', 'link' => 'http://plain.example.invalid/',
            'media' => ['type' => 'image', 'url' => 'http://pictures.example.invalid/a.png'], 'thumbUrl' => null, 'canvaUrl' => null],
        ['title' => 'VP poster two', 'kicker' => null, 'category' => null, 'when' => ['type' => 'once', 'date' => '2030-07-04'], 'time' => null,
            'location' => null, 'link' => 'https://more.example.invalid/x?a=1&lt;b', 'media' => null,
            'thumbUrl' => 'https://pictures.example.invalid/t.png?a=1&lt;b', 'canvaUrl' => null],
    ]],
];
file_put_contents($child . $sep . 'vp-fixture.json', json_encode($vpFixture));
file_put_contents($child . $sep . 'vp-render.php', '<?php' . "\n" . 'declare(strict_types=1);' . "\n"
    . '$core = $argv[1];' . "\n"
    . 'spl_autoload_register(static function (string $c) use ($core): void { if (str_starts_with($c, "Portal\\\\Core\\\\") === true) { $f = $core . "/" . substr($c, 12) . ".php"; if (is_file($f) === true) { require $f; } } });' . "\n"
    . '$fx = json_decode((string) file_get_contents($argv[2]), true);' . "\n"
    . 'echo \Portal\Core\PublicDoor::renderPage($fx["events"], $fx["posters"]);' . "\n");
$pureRender = static function () use ($child, $sep): string {
    return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($child . $sep . 'vp-render.php') . ' '
        . escapeshellarg($child . $sep . 'web' . $sep . '_core') . ' ' . escapeshellarg($child . $sep . 'vp-fixture.json') . ' 2>&1');
};
$vpWant = [
    'parsed' => true, 'title' => 'VP </title><img src=x onerror=1> org — events and noticeboard', 'parts' => ['events', 'posters'],
    'headings' => ['Events', 'Noticeboard'],
    'titles' => ['VP <script>window.pwned=1</script>title', 'VP plain', 'VP all day', 'VP <img src=x onerror=3>poster', 'VP poster two'],
    'whens' => ['Sat 1 Jun 2030, 10:30 (<i onclick=x>Nowhere time)', 'Sun 2 Jun 2030, 09:00', 'Thu 4 Jul 2030', 'Every Tuesday, VP <b>10:30</b>', 'Thu 4 Jul 2030'],
    'wheres' => ['VP <u>place</u>, VP <svg onload=x></svg>street', 'Venue', 'VP <s>where</s>'],
    'abouts' => ['VP <iframe src=//evil></iframe>about'], 'kickers' => ['VP <i>kicker</i>'], 'empties' => [],
    'links' => [['href' => 'https://venue.example.invalid/?a=1&lt;b', 'target' => '_blank', 'rel' => 'noopener noreferrer nofollow', 'ref' => 'no-referrer', 'text' => 'Venue details'],
        ['href' => 'https://more.example.invalid/x?a=1&lt;b', 'target' => '_blank', 'rel' => 'noopener noreferrer nofollow', 'ref' => 'no-referrer', 'text' => 'Find out more']],
    'pics' => [['src' => 'https://pictures.example.invalid/t.png?a=1&lt;b', 'ref' => 'no-referrer', 'alt' => '']],
    'tags' => $vElements, 'handlers' => [], 'scripts' => 0, 'styles' => 1,
];
$vpFacts = static function (string $html) use ($pageFacts): array {
    $f = $pageFacts($html);
    unset($f['styleText']);
    return $f;
};
check('VP01 the renderer, with every field hostile (the zone\'s name and the poster\'s time included): each value is TEXT, character for character; a link and a picture only to https, written so "&lt;" in an address stays those four characters; the zone named only where it is not the organisation\'s; an all-day event by its day alone; nothing that runs',
    $vpFacts($pureRender()), $vpWant);
/** A plant in the COPY's renderer only — no web server is involved, so none is restarted. */
$plantPure = static function (string $from, string $to, callable $read) use ($child, $sep, $pureRender, $vpFacts): array {
    $path   = $child . $sep . 'web' . $sep . '_core' . $sep . 'PublicDoor.php';
    $before = (string) file_get_contents($path);
    if (substr_count($before, $from) !== 1) {
        echo '      ! PLANT NOT APPLIED: the text was found ' . substr_count($before, $from) . " times, not once: " . substr($from, 0, 90) . "\n";
        return ['applied' => false, 'result' => null];
    }
    file_put_contents($path, str_replace($from, $to, $before));
    $facts = $vpFacts($pureRender());
    file_put_contents($path, $before);
    return ['applied' => true, 'result' => $read($facts)];
};
foreach ([
    ['VP02', "'<title>' . self::text(\$title)", "'<title>' . \$title", 'the organisation\'s name in the <title>', static fn (array $f): bool => ($f['title'] ?? null) !== $vpWant['title']],
    ['VP03', "<p class=\\\"door__title\\\">\" . self::text(\$ev['title'] ?? '')", "<p class=\\\"door__title\\\">\" . (\$ev['title'] ?? '')", 'an event\'s title', static fn (array $f): bool => ($f['titles'][0] ?? null) !== $vpWant['titles'][0]],
    ['VP04', "'<p class=\"door__when\">' . self::text(\$when)", "'<p class=\"door__when\">' . \$when", 'an event\'s time and its zone label', static fn (array $f): bool => ($f['whens'][0] ?? null) !== $vpWant['whens'][0]],
    ['VP05', "'<p class=\"door__where\">' . self::text(\$where)", "'<p class=\"door__where\">' . \$where", 'an event\'s place and address', static fn (array $f): bool => ($f['wheres'][0] ?? null) !== $vpWant['wheres'][0]],
    ['VP06', "'<p class=\"door__about\">' . self::text(\$about)", "'<p class=\"door__about\">' . \$about", 'an event\'s description', static fn (array $f): bool => ($f['abouts'] ?? null) !== $vpWant['abouts']],
    ['VP07', "'<p class=\"door__kicker\">' . self::text(\$kicker)", "'<p class=\"door__kicker\">' . \$kicker", 'a poster\'s kicker', static fn (array $f): bool => ($f['kickers'] ?? null) !== $vpWant['kickers']],
    ['VP08', "'<p class=\"door__title\">' . self::text(\$p['title'] ?? '')", "'<p class=\"door__title\">' . (\$p['title'] ?? '')", 'a poster\'s title', static fn (array $f): bool => ($f['titles'][3] ?? null) !== $vpWant['titles'][3]],
    ['VP09', "'<p class=\"door__when\">' . self::text(\$posterWhen)", "'<p class=\"door__when\">' . \$posterWhen", 'a poster\'s day and time', static fn (array $f): bool => ($f['whens'][3] ?? null) !== $vpWant['whens'][3]],
    ['VP10', "'<p class=\"door__where\">' . self::text(\$place)", "'<p class=\"door__where\">' . \$place", 'a poster\'s place', static fn (array $f): bool => ($f['wheres'][2] ?? null) !== $vpWant['wheres'][2]],
    ['VP11', "'<p class=\"door__link\"><a href=\"' . self::text(\$safe)", "'<p class=\"door__link\"><a href=\"' . \$safe", 'a link\'s address', static fn (array $f): bool => ($f['links'] ?? null) !== $vpWant['links']],
    ['VP12', "'<img class=\"door__picture\" src=\"' . self::text(\$src)", "'<img class=\"door__picture\" src=\"' . \$src", 'a picture\'s address', static fn (array $f): bool => ($f['pics'] ?? null) !== $vpWant['pics']],
    ['VP13', "\$safe = is_string(\$address) === true ? self::httpsOrNull(\$address) : null;", "\$safe = is_string(\$address) === true ? \$address : null;", 'https only, for links', static fn (array $f): bool => ($f['links'] ?? null) !== $vpWant['links']],
    ['VP14', "\$src     = is_string(\$picture) === true ? self::httpsOrNull(\$picture) : null;", "\$src     = is_string(\$picture) === true ? \$picture : null;", 'https only, for pictures', static fn (array $f): bool => ($f['pics'] ?? null) !== $vpWant['pics']],
    ['VP15', "if (\$zone !== '' && \$orgClock !== '' && \$zone !== \$orgClock) {", "if (\$zone !== '') {", 'the zone label only where the clock differs', static fn (array $f): bool => ($f['whens'][1] ?? null) !== $vpWant['whens'][1]],
    ['VP16', "\$words = I18n::formatClockDateTime(\$reading, (\$ev['allDay'] ?? false) === true ? 'D j M Y' : 'D j M Y, H:i');", "\$words = I18n::formatClockDateTime(\$reading, 'D j M Y, H:i');", 'an all-day event by its day alone', static fn (array $f): bool => ($f['whens'][2] ?? null) !== $vpWant['whens'][2]],
] as [$label, $from, $to, $what, $caught]) {
    $p = $plantPure($from, $to, $caught);
    check("{$label} with {$what} taken out of the renderer, VP01's facts change", [$p['applied'], $p['result']], [true, true]);
}

// =============================================================================
echo "W. The old widget folder is gone, and its addresses answer nothing (D-084)\n";
// =============================================================================
// D-084: web/public_html/widget/ (countdown.js) is deleted with its two
// routes (migration 264), and so are the countdown's handlers and the old
// #336 embeddable page the folder hid. With the folder gone /widget reaches
// the router — so what is pinned is that the router has NOTHING there: no
// route row, no file, and the portal's own "page not found". On a public
// address every one of them is a door request, so the door's identical 404.
$scnWidgetGone = static function () use ($http, $db): array {
    $out = [];
    foreach (['/widget', '/widget/', '/widget/countdown.js', '/widget/countdown.json', '/widget/countdown'] as $path) {
        $r = $http('GET', $path);
        $out[$path] = $r['status'] . (($r['status'] === 404 && str_contains($r['body'], '<html') === true
            && str_contains($r['body'], 'nextEvent') === false && str_contains($r['body'], 'webms-countdown') === false)
            ? ' portal page' : ' ' . substr($r['body'], 0, 40));
    }
    $out['route rows'] = (int) ($db->query("SELECT COUNT(*) AS `n` FROM `tblRoutes` WHERE `routeKey` = 'widget' OR `routeKey` LIKE 'widget/%'")->fetch_assoc()['n'] ?? -1);
    return $out;
};
$widgetGoneWant = ['/widget' => '404 portal page', '/widget/' => '404 portal page', '/widget/countdown.js' => '404 portal page',
    '/widget/countdown.json' => '404 portal page', '/widget/countdown' => '404 portal page', 'route rows' => 0];
check('W01 /widget, /widget/, /widget/countdown.js, /widget/countdown.json and /widget/countdown each answer the portal\'s own 404 page (no script, no feed, no embed page), and no route row starts "widget"',
    $scnWidgetGone(), $widgetGoneWant);
check('W02 the files are gone from the copy served here: the web root\'s widget folder, the countdown\'s handlers, and the old embeddable page (calendar/widget.php) and its loader (assets/js/widget.js)',
    [is_dir($child . $sep . 'web' . $sep . 'public_html' . $sep . 'widget'), is_dir($child . $sep . 'web' . $sep . '_apps' . $sep . 'widget'),
        is_file($child . $sep . 'web' . $sep . '_apps' . $sep . 'calendar' . $sep . 'widget.php'),
        is_file($child . $sep . 'web' . $sep . 'public_html' . $sep . 'assets' . $sep . 'js' . $sep . 'widget.js')],
    [false, false, false, false]);
$refusals['the old widget script on a public address'] = $http('GET', '/widget/countdown.js', ['host' => $hostA]);
$refusals['the old countdown feed on a public address'] = $http('GET', '/widget/countdown.json', ['host' => $hostA]);
check('W03 on a public address the old widget addresses are door requests, so the door\'s 404 (compared byte for byte in R01)',
    [$refusals['the old widget script on a public address']['status'], $refusals['the old countdown feed on a public address']['status']], [404, 404]);
$wMig    = (string) file_get_contents($repo . $sep . 'web' . $sep . '_sql' . $sep . '264_old_widget_removed.sql');
$wSchema = (string) file_get_contents($repo . $sep . 'web' . $sep . '_sql' . $sep . 'full_schema.sql');
check('W04 migration 264 is in the install script too (D-003): its record in both, and no seed of either countdown address left in the install script',
    [str_contains($wSchema, "VALUES ('264_old_widget_removed.sql')"), str_contains($wMig, "VALUES ('264_old_widget_removed.sql')"),
        preg_match("/\\('widget\\/countdown(\\.json)?',\\s*'widget\\//", $wSchema)],
    [true, true, 0]);

// =============================================================================
echo "H. Headers on the live portal\n";
// =============================================================================
$setOwn($siteA, 'public.frame.origins', 'https://www.selftest.invalid');
$hf = $feedA();
$hm = $feedA('media/' . $upA);
$want = [
    'access-control-allow-origin' => '*', 'cross-origin-resource-policy' => 'cross-origin',
    'content-security-policy' => "default-src 'none'; frame-ancestors 'none'; sandbox", 'referrer-policy' => 'no-referrer',
    'cache-control' => 'public, max-age=60', 'x-robots-tag' => 'noindex', 'x-content-type-options' => 'nosniff',
];
$pick = static function (array $r) use ($want): array {
    $out = [];
    foreach (array_keys($want) as $k) {
        $out[$k] = $r['headers'][$k] ?? '';
    }
    return $out;
};
check('H01 a feed answer: readable and loadable by any website, data never a page (frame-ancestors \'none\' although A lists a framing website), no Referer, one minute\'s caching, noindex',
    $pick($hf), $want);
check('H02 a media answer carries the same headers', $pick($hm), $want);
$absent = static fn (array $r): array => [
    'set-cookie' => $r['headers']['set-cookie'] ?? '', 'x-frame-options' => $r['headers']['x-frame-options'] ?? '',
    'cross-origin-opener-policy' => $r['headers']['cross-origin-opener-policy'] ?? '',
    'access-control-allow-credentials' => $r['headers']['access-control-allow-credentials'] ?? '', 'vary' => $r['headers']['vary'] ?? '',
];
$none = ['set-cookie' => '', 'x-frame-options' => '', 'cross-origin-opener-policy' => '', 'access-control-allow-credentials' => '', 'vary' => ''];
check('H03 no Set-Cookie, X-Frame-Options, COOP, credentials header or Vary on a feed, a media answer or a refusal',
    [$absent($hf), $absent($hm), $absent($refusals['unknown name'])], [$none, $none, $none]);
$r404 = $refusals['unknown name'];
check('H04 the refusal: 404, plain text "Not found.", no-store, readable by any website, noindex',
    [$r404['status'], $r404['body'], $r404['headers']['content-type'] ?? '', $r404['headers']['cache-control'] ?? '',
        $r404['headers']['access-control-allow-origin'] ?? '', $r404['headers']['x-robots-tag'] ?? ''],
    [404, "Not found.\n", 'text/plain; charset=utf-8', 'no-store', '*', 'noindex, nofollow, noarchive']);
$before = $sessionFiles();
$feedA();
$feedA('posters.json');
$feedA('media/' . $upA);
$http('GET', '/events.json', ['host' => $hostA]);
$http('GET', '/w/' . str_repeat('ab', 12) . '/events.json');
$http('GET', '/w/' . $nameA . '/events.json', ['cookie' => 'PHPSESSID=' . str_repeat('z', 32)]);
check('H05 no door request makes a session file — a made-up cookie included', $sessionFiles() - $before, 0);
$setOwn($siteA, 'public.frame.origins', null);

// #882 point 2, MEASURED: start-up sends some headers from settings it merges
// for the request's organisation — for a door request, the door's
// organisation — and the door leaves three of them in place on every answer,
// its refusals included (Strict-Transport-Security, Permissions-Policy and
// X-Powered-By, from `portal.headers.*`, `branding.hidePoweredBy` and
// `product.name`). If an ORGANISATION could hold its own row for one of
// those, a known organisation's refusal could differ from an unknown name's.
// The general settings editor is the only page that could add one (Admin,
// Settings' header group writes the installation's row only); asked here, as
// that organisation's own global administrator, for every such key, it adds
// none — every key is seeded installation-wide, and the editor refuses to add
// an organisation's row for a key the installation holds. A probe key the
// installation does NOT hold IS added, so the refusal is the editor's rule and
// not a page that refuses everything.
$hKeys = ['portal.headers.strict_transport_security', 'portal.headers.permissions_policy', 'portal.headers.coop', 'portal.headers.corp',
    'portal.headers.referrer_policy', 'portal.headers.x_frame_options', 'branding.hidePoweredBy', 'product.name'];
$hAdd = static function (string $key) use ($http, $tokenOn, $people): string {
    $jar   = $people['root']['jar'];
    $token = $tokenOn($http('GET', '/settings', ['jar' => $jar])['body']);
    $http('POST', '/settings/save', ['jar' => $jar, 'post' => ['csrf_token' => $token, 'settingID' => '0', 'settingKey' => $key,
        'settingValue' => 'selftest-882']]);
    $page = $http('GET', '/settings', ['jar' => $jar])['body'];
    return preg_match('#<div class="alert alert-[a-z]+[^"]*"[^>]*>(.*?)</div>#s', $page, $m) === 1
        ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')) : '';
};
$hOwnRows = static function (array $keys) use ($db): int {
    $in = "'" . implode("', '", array_map([$db, 'real_escape_string'], $keys)) . "'";
    return (int) ($db->query("SELECT COUNT(*) AS `n` FROM `tblSettings` WHERE `siteID` IS NOT NULL AND `settingKey` IN ({$in})")->fetch_assoc()['n'] ?? 0);
};
$hSaid = [];
foreach ($hKeys as $k) {
    $hSaid[$k] = $hAdd($k);
}
$hProbe = $hAdd('selftest.p882.probe');
check('H06 (#882) no organisation can hold its own row for a setting start-up turns into a header on the door\'s answers: adding each of the eight as A\'s global administrator is refused ("already exists") and stores nothing, while a key the installation does not hold IS added — so the door\'s refusals need no fixed headers of their own',
    [array_values(array_unique(array_map(static fn (string $s): bool => str_contains($s, 'already exists'), $hSaid))), $hOwnRows($hKeys),
        $hOwnRows(['selftest.p882.probe']) === 1, $hProbe !== ''],
    [[true], 0, true, true]);
$db->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'selftest.p882.probe'");

// =============================================================================
echo "R. Every refusal is byte-identical\n";
// =============================================================================
$ref = $signature($refusals['unknown name']);
$differ = [];
foreach ($refusals as $kind => $r) {
    if ($signature($r) !== $ref) {
        $differ[] = $kind;
    }
}
check('R01 all ' . count($refusals) . ' refusals give the same status, headers (Date and the test server\'s Host aside) and body', $differ, []);

/**
 * One request sent over a raw socket with an ABSOLUTE-FORM request line
 * (`GET http://host/path HTTP/1.1`), which HTTP servers must accept and curl
 * will not send. Same answer shape as $http. Round 3 of P4a's check: the door
 * step had stopped recognising these, so a valid name got a portal 404 and a
 * session file.
 */
$rawAbsolute = static function (string $host, string $path) use ($port): array {
    $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 5);
    if ($sock === false) {
        return ['status' => 0, 'headers' => [], 'lines' => [], 'body' => 'no answer'];
    }
    fwrite($sock, 'GET http://' . $host . $path . " HTTP/1.1\r\nHost: " . $host . "\r\nConnection: close\r\n\r\n");
    $raw = (string) stream_get_contents($sock);
    fclose($sock);
    [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    $rows  = explode("\r\n", $head);
    $first = array_shift($rows);
    $named = [];
    $lines = [];
    foreach ($rows as $line) {
        if ($line === '') {
            continue;
        }
        $lines[] = $line;
        [$n, $v] = array_pad(explode(':', $line, 2), 2, '');
        $named[strtolower(trim($n))] = trim($v);
    }
    // PHP's test server answers HTTP/1.1 with a chunked body only when it has
    // no length; the door always knows its length, so the body is plain.
    return ['status' => (int) (explode(' ', (string) $first)[1] ?? 0), 'headers' => $named, 'lines' => $lines, 'body' => $body];
};
$absBefore = $sessionFiles();
$absValid   = $rawAbsolute('127.0.0.1:' . $port, '/w/' . $nameA . '/events.json');
$absUnknown = $rawAbsolute('127.0.0.1:' . $port, '/w/' . str_repeat('ab', 12) . '/events.json');
$absPublic  = $rawAbsolute($hostA, '/events.json');
$absLogin   = $rawAbsolute($hostA, '/login');
check('R02 an ABSOLUTE-FORM request line (GET http://host/…) is the door too: a valid name 200 JSON, an unknown name the identical 404, a public address 200, a portal page on a public address the identical 404 — and no session file',
    [$absValid['status'], json_decode($absValid['body'], true)['format'] ?? null, $signature($absUnknown) === $ref, $absPublic['status'],
        $signature($absLogin) === $ref, $sessionFiles() - $absBefore],
    [200, 'webms-public-events', true, 200, true, 0]);

// =============================================================================
echo "F. Every PHP file in the web root, driven (rounds 4 and 5 of P4a's check)\n";
// =============================================================================
// "Does anything run before the hand-off?" is BEHAVIOURAL (D-065): a static
// gate sharpened round after round kept losing to the next spelling. So EVERY
// file under the copy's web root that the server could run as PHP (.php,
// .php5, .phtml, .phar — the endings this test's router treats as PHP;
// .htaccess refuses only `\.php$`) is found here by walking the folder — no
// hand-kept list — and run DIRECTLY, as a web server that does not honour
// .htaccess would, in EIGHT shapes: GET, HEAD, POST with a body and GET with a
// query string, each with and without a stale session cookie (round 5: a GET
// alone let an entry point that starts a session only on POST, only with a
// query string, or on any method but GET walk past). On A's public address,
// on switched-off C's, and — FS01 in section P — in the failure state. None
// may set a cookie, make a session file, or show any organisation's name.
//
// WHAT IT CANNOT REACH, said plainly: a branch keyed on a PARTICULAR value
// (`if (($_GET['debug'] ?? '') === 'letmein') { session_start(); }`) — no test
// can guess the value; methods other than GET, HEAD and POST; cookies other
// than PHP's session cookie; and request headers it does not send. It also
// cannot tell "behaved" from "crashed" by status alone, which is why F02
// records each file's statuses: only the three entry points start the portal;
// the other files are reached through index.php (where the door has already
// answered), and run DIRECTLY most of them crash (500) because the classes
// they use are not loaded. This test runs PHP with display_errors OFF, so
// nothing is printed; with display_errors ON the fatal error is printed and
// names the server's file path (round 6 measured it on real Apache through
// /robots.php/x, which .htaccess now refuses — check_htaccess_php_denied.py).
// They leak no organisation's data, but they are not "behaving", they fail.
$orgNames = [];
foreach ($db->query("SELECT `siteName` FROM `tblSites` WHERE `siteID` IN (1, {$siteB}, {$siteC})")->fetch_all(MYSQLI_ASSOC) as $row) {
    $orgNames[] = (string) $row['siteName'];
}
$webRootFiles = static function () use ($child, $sep): array {
    $root = $child . $sep . 'web' . $sep . 'public_html';
    $out  = [];
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($walk as $file) {
        if ($file->isFile() === true && in_array(strtolower($file->getExtension()), ['php', 'php5', 'phtml', 'phar'], true) === true) {
            $out[] = '/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }
    sort($out);
    return $out;
};
/** The eight request shapes: [label, method, query, post body, cookie]. */
$shapes = [];
foreach ([null, 'PHPSESSID=' . str_repeat('s', 32)] as $cookie) {
    $tag = $cookie === null ? '' : ' + stale cookie';
    $shapes[] = ['GET' . $tag, 'GET', '', null, $cookie];
    $shapes[] = ['HEAD' . $tag, 'HEAD', '', null, $cookie];
    $shapes[] = ['POST' . $tag, 'POST', '', ['a' => '1', 'action' => 'save', 'csrf_token' => 'x', 'value' => 'y'], $cookie];
    $shapes[] = ['GET ?query' . $tag, 'GET', '?a=1&action=x&code=404&lang=cy&page=2', null, $cookie];
}
/**
 * Every web-root PHP file, run directly on $host in every shape. Answers the
 * files that misbehaved (and why), and each file's statuses per shape.
 *
 * @return array{bad:list<string>, statuses:array<string,array<string,int>>}
 */
$sweepWebRoot = static function (string $host) use ($http, $webRootFiles, $sessionFiles, $orgNames, $shapes): array {
    $bad      = [];
    $statuses = [];
    foreach ($webRootFiles() as $path) {
        foreach ($shapes as [$label, $method, $query, $post, $cookie]) {
            $before = $sessionFiles();
            $opt    = ['host' => $host, 'direct' => true];
            if ($cookie !== null) {
                $opt['cookie'] = $cookie;
            }
            if ($post !== null) {
                $opt['post'] = $post;
            }
            $r   = $http($method, $path . $query, $opt);
            $statuses[$path][$label] = $r['status'];
            $why = [];
            if (($r['headers']['set-cookie'] ?? '') !== '') {
                $why[] = 'set a cookie';
            }
            if ($sessionFiles() !== $before) {
                $why[] = 'made a session file';
            }
            foreach ($orgNames as $name) {
                if ($name !== '' && str_contains($r['body'], $name) === true) {
                    $why[] = 'named ' . $name;
                }
            }
            if ($why !== []) {
                $bad[] = $path . ' (' . $label . '): ' . implode(', ', $why);
            }
        }
    }
    return ['bad' => $bad, 'statuses' => $statuses];
};
/** One status per file when every shape agreed, else the list (HEAD carries no body, but the same status). */
$statusLine = static function (array $byShape): string {
    $unique = array_values(array_unique(array_values($byShape)));
    return count($unique) === 1 ? (string) $unique[0] : implode('/', array_map('strval', $byShape));
};
$found = $webRootFiles();
$sweepA = $sweepWebRoot($hostA);
$sweepC = $sweepWebRoot($hostC);
check('F01 every file the server could run as PHP in the web root (' . count($found) . ', found by walking the folder), run DIRECTLY in 8 shapes (GET, HEAD, POST, a query string; with and without a stale cookie) on A\'s AND switched-off C\'s public address: none sets a cookie, makes a session file or names any organisation',
    [count($found) >= 8, in_array('/index.php', $found, true), $sweepA['bad'], $sweepC['bad']], [true, true, [], []]);
echo "      statuses when run directly on A's public address (every shape alike unless shown per shape):\n";
foreach ($sweepA['statuses'] as $path => $byShape) {
    echo '        ' . $path . ': ' . $statusLine($byShape) . "\n";
}
$entryPoints = ['/api-docs/index.php', '/error.php', '/index.php'];
$entryStatuses = [];
foreach ([$sweepA, $sweepC] as $sweep) {
    foreach ($entryPoints as $path) {
        $entryStatuses[] = array_values(array_unique(array_values($sweep['statuses'][$path] ?? [])));
    }
}
$others = array_values(array_diff($found, $entryPoints));
$otherStatuses = [];
foreach ($others as $path) {
    $otherStatuses[$path] = array_values(array_unique(array_values($sweepA['statuses'][$path] ?? [])));
}
check('F02 the three entry points answer only the door\'s 404 in every shape on both addresses; every OTHER file run directly either fails (500 — its classes are not loaded; nothing printed only because display_errors is off here) or is a page that starts nothing — recorded, not assumed: ' . json_encode($otherStatuses),
    [$entryStatuses, array_values(array_filter($otherStatuses, static fn (array $st): bool => array_diff($st, [200, 500]) !== []))],
    [array_fill(0, 6, [404]), []]);
// The plants: a NEW entry point must be caught by what it DOES, in every
// shape the sweep sends.
$plantFile = static function (string $code, string $name = 'zz-planted-entry.php') use ($child, $sep, $sweepWebRoot): array {
    $file = $child . $sep . 'web' . $sep . 'public_html' . $sep . $name;
    file_put_contents($file, $code);
    $bad = $sweepWebRoot('door-a.selftest.test')['bad'];
    @unlink($file);
    return array_values(array_filter($bad, static fn (string $l): bool => str_starts_with($l, '/' . $name)));
};
$bootLine = "require dirname(__DIR__) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';\n";
check('P50 a NEW web-root file that loads start-up and starts a session first is caught by behaviour',
    count($plantFile("<?php\ndefine('PORTAL_WEBROOT', __DIR__);\n" . $bootLine . "session_start();\necho 'x';\n")) > 0, true);
check('P51 a NEW web-root file that starts a session ABOVE the require of start-up is caught by behaviour',
    count($plantFile("<?php\nsession_start();\ndefine('PORTAL_WEBROOT', __DIR__);\n" . $bootLine . "echo 'x';\n")) > 0, true);
check('P52 a NEW web-root file that starts the portal and prints the organisation\'s name, with no session at all, is caught by behaviour',
    count($plantFile("<?php\ndefine('PORTAL_WEBROOT', __DIR__);\n" . $bootLine . "echo \\Portal\\Core\\Site::branding('name');\n")) > 0, true);
check('P53 a NEW web-root file that starts a session ONLY ON POST is caught (round 5: a GET-only sweep passed it)',
    count($plantFile("<?php\nif ((\$_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {\n    session_start();\n}\necho 'x';\n")) > 0, true);
check('P54 a NEW web-root file that starts a session ONLY WITH A QUERY STRING is caught',
    count($plantFile("<?php\nif (\$_GET !== []) {\n    session_start();\n}\necho 'x';\n")) > 0, true);
check('P55 a NEW web-root file that starts a session on ANY METHOD BUT GET is caught (HEAD and POST both reach it)',
    count($plantFile("<?php\nif ((\$_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {\n    session_start();\n}\necho 'x';\n")) > 0, true);
check('P56 a NEW web-root file ending .phtml (which .htaccess does not refuse) that starts a session is found and caught',
    count($plantFile("<?php\nsession_start();\necho 'x';\n", 'zz-planted-entry.phtml')) > 0, true);

// =============================================================================
echo "S. Every captured answer satisfies its schema\n";
// =============================================================================
$schemaRun = static function () use ($python, $repo, $answers): array {
    $out = [];
    exec(escapeshellarg($python) . ' -I ' . escapeshellarg($repo . '/tools/audit-checks/check_public_door_schema.py') . ' --answers '
        . escapeshellarg($answers) . ' 2>&1', $out, $code);
    return ['code' => $code, 'out' => implode("\n", $out)];
};
$sr = $schemaRun();
check('S01 check_public_door_schema.py --answers passes over ' . count(glob($answers . '/*.json') ?: []) . ' captured answers', [$sr['code'], str_contains($sr['out'], 'captured answer')], [0, true]);

// =============================================================================
echo "T. A test copy (PORTAL_ENV=alpha, D-087)\n";
// =============================================================================
$channel = 'alpha';
$startServer();
$waitForServer();
$tBefore = $sessionFiles();
$tNone   = $feedA();
$tForged = $http('GET', '/w/' . $nameA . '/events.json', ['cookie' => 'PHPSESSID=' . str_repeat('q', 32)]);
check('T01 with no sign-in, and with a made-up cookie, the test copy\'s door refuses — and makes no session file',
    [$tNone['status'], $tForged['status'], $sessionFiles() - $tBefore], [404, 404, 0]);
$alphaRefusal = $http('GET', '/w/' . str_repeat('ab', 12) . '/events.json');
check('T02 a D-087 refusal is the same answer as an unknown name on the test copy, and says noindex, nofollow, noarchive',
    [$signature($tNone) === $signature($alphaRefusal), $tNone['headers']['x-robots-tag'] ?? ''], [true, 'noindex, nofollow, noarchive']);
$jarM = $signIn('member');
$jarT = $signIn('tester');
$tMember = $http('GET', '/w/' . $nameA . '/events.json', ['cookie' => $cookieOf($jarM)]);
$tTester = $http('GET', '/w/' . $nameA . '/events.json', ['cookie' => $cookieOf($jarT)]);
$capture('events', 'alpha-tester', $tTester);
check('T03 a signed-in member the test-copy gate does not let in is refused; a tester is served',
    [$tMember['status'], $tTester['status'], $titles($tTester, 'events')], [404, 200, ['PDS A running now', 'PDS A shown']]);
check('T04 the tester\'s answer depends on who asked: private, no-store, Vary: Cookie, noindex, nofollow, noarchive, and NO Set-Cookie',
    [$tTester['headers']['cache-control'] ?? '', $tTester['headers']['vary'] ?? '', $tTester['headers']['x-robots-tag'] ?? '', $tTester['headers']['set-cookie'] ?? ''],
    ['private, no-store', 'Cookie', 'noindex, nofollow, noarchive', '']);
$setOwn($siteA, 'public.door.testCopies.open', 'anybody');
check('T05 an organisation choosing "anybody" under an installation limit of "testers" is still refused (the stricter wins)', $feedA()['status'], 404);
$setInstallation('public.door.testCopies.permitted', 'either');
$tOpen = $feedA();
check('T06 only when the installation permits AND the organisation chose "anybody" does the door answer with no sign-in — one minute\'s caching, still noindex, nofollow, noarchive',
    [$tOpen['status'], $tOpen['headers']['cache-control'] ?? '', $tOpen['headers']['x-robots-tag'] ?? ''], [200, 'public, max-age=60', 'noindex, nofollow, noarchive']);
$setOwn($siteB, 'public.door.testCopies.open', null);
check('T07 that choice is A\'s alone: B, with no choice of its own, is still refused with no sign-in', $http('GET', '/w/' . $nameB . '/events.json')['status'], 404);
$setInstallation('public.door.testCopies.permitted', 'testers');
$setOwn($siteA, 'public.door.testCopies.open', null);
$jarRoot = $signIn('root');
$tCard = $http('GET', '/admin/public-website', ['jar' => $jarRoot])['body'];
check('T08 on the test copy the card says the door answers signed-in testers only, and why a real website shows nothing', str_contains($tCard, 'data-door-testers-only="1"'), true);
$testerFile = $sessions . DIRECTORY_SEPARATOR . 'sess_' . substr($cookieOf($people['tester']['jar']), strlen('PHPSESSID='));
$ageOut = static function (bool $stale) use ($testerFile): void {
    clearstatcache();
    touch($testerFile, $stale === true ? time() - ((int) ini_get('session.gc_maxlifetime') + 600) : time());
};
$ageOut(true);
$tStale = $feedA('events.json', ['cookie' => $cookieOf($people['tester']['jar'])]);
$ageOut(false);
check('T09 a tester whose session file is older than PHP\'s session lifetime is nobody: refused (the file is still there, as it is until PHP\'s clear-out runs)',
    [is_file($testerFile), $tStale['status'], $feedA('events.json', ['cookie' => $cookieOf($people['tester']['jar'])])['status']], [true, 404, 200]);
$tScriptNone   = $feedA('door.js');
$tScriptTester = $feedA('door.js', ['cookie' => $cookieOf($people['tester']['jar'])]);
check('T10 on a test copy the script follows D-087 exactly as the feeds do: with no sign-in the door\'s 404; for a tester served, private, no-store, Vary: Cookie, noindex, nofollow, noarchive, and no Set-Cookie',
    [$tScriptNone['status'], $signature($tScriptNone) === $signature($alphaRefusal), $tScriptTester['status'], $tScriptTester['headers']['cache-control'] ?? '',
        $tScriptTester['headers']['vary'] ?? '', $tScriptTester['headers']['x-robots-tag'] ?? '', $tScriptTester['headers']['set-cookie'] ?? ''],
    [404, true, 200, 'private, no-store', 'Cookie', 'noindex, nofollow, noarchive', '']);
// #493 P4c: the page follows D-087 exactly as the feeds do — and its
// frame-ancestors is still the organisation's list on a test copy.
$setOwn($siteA, 'public.frame.origins', 'https://www.selftest.invalid');
$tPageNone   = $pageA();
$tPageTester = $pageA('', ['cookie' => $cookieOf($people['tester']['jar'])]);
$setOwn($siteA, 'public.frame.origins', null);
// Round 1 of P4c's check: DENY on the testers-only 'none' answer was asserted
// nowhere, and dropping it there passed.
$tPageTesterNone = $pageA('', ['cookie' => $cookieOf($people['tester']['jar'])]);
check('T11 on a test copy the page follows D-087 as the feeds do: with no sign-in the door\'s 404; for a tester served with private, no-store, Vary: Cookie, noindex, nofollow, noarchive and no Set-Cookie — frame-ancestors still the organisation\'s listed website with no X-Frame-Options, and with nothing listed \'none\' WITH DENY',
    [$tPageNone['status'], $signature($tPageNone) === $signature($alphaRefusal), $tPageTester['status'], $pageHeaders($tPageTester), $pageHeaders($tPageTesterNone)],
    [404, true, 200, $pageHeadersWant($pageCspFor($pageFacts($tPageTester['body']), 'https://www.selftest.invalid'), '', 'private, no-store', 'Cookie',
        'noindex, nofollow, noarchive'), $pageHeadersWant($pageCspFor($pageFacts($tPageTesterNone['body']), "'none'"), 'DENY', 'private, no-store', 'Cookie',
        'noindex, nofollow, noarchive')]);
// Round 1 of P4c's check: no test-copy check had ever asked on the APPROVED
// PUBLIC ADDRESS, for any surface — so skipping D-087's question there passed.
$tTester = $cookieOf($people['tester']['jar']);
$tPub = [];
foreach (['/page.html', '/events.json', '/posters.json', '/door.js'] as $what) {
    $none   = $http('GET', $what, ['host' => $hostA]);
    $tester = $http('GET', $what, ['host' => $hostA, 'cookie' => $tTester]);
    $tPub[$what] = [$none['status'], $signature($none) === $signature($alphaRefusal), $tester['status'], $tester['headers']['cache-control'] ?? '',
        $tester['headers']['vary'] ?? '', $tester['headers']['x-robots-tag'] ?? '', $tester['headers']['set-cookie'] ?? ''];
}
$tPubWant = [404, true, 200, 'private, no-store', 'Cookie', 'noindex, nofollow, noarchive', ''];
// Round 2 of P4c's check: on this combination (a test copy's PUBLIC ADDRESS,
// a tester) only four headers were compared, so the page sent with
// `frame-ancestors *` passed. The page's WHOLE header set is compared here,
// exactly as T11 does on /w/ (A lists nothing at this point: 'none' and DENY).
$tPubPage = $http('GET', '/page.html', ['host' => $hostA, 'cookie' => $tTester]);
check('T12 on A\'s approved PUBLIC ADDRESS a test copy\'s door follows D-087 for every surface — the page, both feeds and the script: with no sign-in the door\'s 404, identical to an unknown name\'s; for a tester served, private, no-store, Vary: Cookie, noindex, nofollow, noarchive, no Set-Cookie — and the tester\'s page carries its WHOLE header set, frame-ancestors \'none\' and DENY included',
    [$tPub, $pageHeaders($tPubPage)],
    [['/page.html' => $tPubWant, '/events.json' => $tPubWant, '/posters.json' => $tPubWant, '/door.js' => $tPubWant],
        $pageHeadersWant($pageCspFor($pageFacts($tPubPage['body']), "'none'"), 'DENY', 'private, no-store', 'Cookie', 'noindex, nofollow, noarchive')]);
$channel = 'prod';
$startServer();
$waitForServer();

/**
 * Round 1 of P4a's check: D-087's two settings must be refused by the general
 * settings editor, or a global administrator could open a test copy's door
 * there with no card and no check. Asked of the REAL lock in the COPY, in a
 * separate PHP process, so a plant made in the copy is what answers. (The
 * settings-locks self-test drives the editor itself, but it finds its keys BY
 * READING this lock, so a key taken out of it is simply no longer tested
 * there — which is why this check exists.)
 */
$askLocks = static function () use ($child, $sep): mixed {
    $file = $child . $sep . 'web' . $sep . '_core' . $sep . 'PublicWebsite.php';
    $code = 'require ' . var_export($file, true) . ';'
        . '$o = [];'
        . 'foreach (["public.door.testCopies.permitted", "public.door.testCopies.open"] as $k) {'
        . '  $pg = \\Portal\\Core\\PublicWebsite::lockedPageFor($k);'
        . '  $o[$k] = [\\Portal\\Core\\PublicWebsite::lockedInGeneralEditor($k), $pg["route"] ?? null, $pg["installationOnly"] ?? null];'
        . '}'
        . 'echo json_encode($o);';
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
    return json_decode((string) $out, true) ?? (string) $out;
};
check('L01 the general settings editor refuses both of D-087\'s settings and sends them to Admin, Public website (the installation\'s limit for the installation\'s global administrator alone)',
    $askLocks(), ['public.door.testCopies.permitted' => [true, 'admin/public-website', true],
        'public.door.testCopies.open' => [true, 'admin/public-website', false]]);

// =============================================================================
echo "U. D-087's card, and \"Check my set-up\" reaching the door (#493 P4d)\n";
// =============================================================================
// The setting D-087 describes is set on a CARD now, by two different people
// (the installation's global administrator sets the ceiling; the
// organisation's global administrator chooses within it), and the set-up check
// FETCHES an approved public address to learn whether it reaches THIS door.
// Every value here is read back from the database or from the answer itself.

/** A door answer's whole header set, as sorted lines, without what PHP's test server adds of its own. */
$uHeaderSet = static function (array $r): array {
    $keep = array_values(array_filter($r['lines'], static fn (string $l): bool
        => preg_match('/^(date|host|connection|x-powered-by):/i', $l) !== 1));
    sort($keep);
    return $keep;
};
/** A fresh random value, as the set-up check makes one. */
$uNonce = static fn (): string => bin2hex(random_bytes(32));
/**
 * The proof, worked out HERE from what D-087's check is defined to hash, so a
 * change to what the door hashes fails U01 rather than being agreed with.
 */
$uProof = static function (int $site, string $host, string $nonce) use ($doorName): string {
    return hash_hmac('sha256', "webms-public-door-check-v1\n" . $site . "\n" . $host . "\n" . $nonce, (string) $doorName($site));
};
/** A's door switched off and back on with the SAME name (later sections and plants hold it). */
$uDoorOff = static function (bool $off) use ($db, $siteA, $doorName): void {
    static $kept = null;
    if ($off === true) {
        $kept = (string) $doorName($siteA);
        $db->query("DELETE FROM `tblPublicDoorNames` WHERE `siteID` = {$siteA}");
        return;
    }
    $db->query("INSERT INTO `tblPublicDoorNames` (`siteID`, `publicName`, `changedAtUtc`) VALUES ({$siteA}, '{$kept}', UTC_TIMESTAMP())");
};
/** The organisation's own row of a setting, exactly as stored, or null. */
$uOwnRow = static function (int $site, string $key) use ($db): ?string {
    $s = $db->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ?');
    $s->bind_param('si', $key, $site);
    $s->execute();
    $r = $s->get_result()->fetch_assoc();
    $s->close();
    return $r === null ? null : (string) $r['settingValue'];
};
/** The flash message the page shows after a press (the first alert in the page). */
$uFlash = static function (string $page): string {
    if (preg_match('#<div class="alert alert-[a-z]+" role="alert">(.*?)</div>#s', $page, $m) !== 1) {
        return '';
    }
    return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
};
/** Run "Check my set-up" as $role over HTTP and give the set-up check's "Public address" rows, [state, words], in order. */
$uCheckRows = static function (string $role) use ($http, $tokenOn, $people, $db): array {
    // The check allows six runs in ten minutes per person (RateLimiter); this
    // section runs it more often than that, so each run starts with none.
    $db->query("DELETE FROM `tblApiRateLimits` WHERE `bucket` LIKE 'pwsetup:check:%'");
    $jar  = $people[$role]['jar'];
    $page = $http('GET', '/admin/public-website', ['jar' => $jar]);
    $http('POST', '/admin/public-website/check', ['jar' => $jar, 'post' => ['csrf_token' => $tokenOn($page['body'])]]);
    $body  = $http('GET', '/admin/public-website', ['jar' => $jar])['body'];
    $start = strpos($body, 'data-setup-check="1"');
    $end   = $start === false ? false : strpos($body, '</ul>', $start);
    if ($start === false || $end === false) {
        return [];
    }
    preg_match_all('#<li class="mb-2" data-check-state="([^"]*)">(.*?)</li>#s', substr($body, $start, $end - $start), $m, PREG_SET_ORDER);
    $rows = [];
    foreach ($m as $one) {
        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($one[2]), ENT_QUOTES, 'UTF-8')));
        $at   = strpos($text, 'Public address:');
        if ($at !== false) {
            $rows[] = [$one[1], trim(substr($text, $at + strlen('Public address:')))];
        }
    }
    return $rows;
};
/**
 * doorReachWords()'s answer for each set of facts, asked of the COPY's class in
 * a PHP of its own (so a plant in the copy is what answers). Pure: no database.
 *
 * @param list<array<string,mixed>> $factsList
 * @return list<array<string,mixed>>
 */
$uWords = static function (array $factsList) use ($child, $sep): array {
    $in = $child . $sep . 'u-words-in.json';
    file_put_contents($in, json_encode($factsList));
    file_put_contents($child . $sep . 'u-words.php', <<<'WORDS'
<?php
// Written by tools/public-door-selftest.php for ONE run. Test only.
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/web/_core/' . str_replace('Portal\\Core\\', '', $class) . '.php';
    if (is_file($file) === true) {
        require_once $file;
    }
});
$out = [];
foreach (json_decode((string) file_get_contents($argv[1]), true) as $facts) {
    $out[] = \Portal\Core\PublicWebsiteSetup::doorReachWords($facts);
}
echo json_encode($out);
WORDS);
    $raw = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($child . $sep . 'u-words.php') . ' ' . escapeshellarg($in) . ' 2>&1');
    $got = json_decode($raw, true);
    if (is_array($got) === false || count($got) !== count($factsList)) {
        refuse('doorReachWords() could not be asked: ' . substr($raw, 0, 300));
    }
    return $got;
};
/** Every "Check my set-up" result row, [state, "Label: words"], in order. */
$uCheckAll = static function (string $role) use ($http, $tokenOn, $people, $db): array {
    $db->query("DELETE FROM `tblApiRateLimits` WHERE `bucket` LIKE 'pwsetup:check:%'");
    $jar  = $people[$role]['jar'];
    $page = $http('GET', '/admin/public-website', ['jar' => $jar]);
    $http('POST', '/admin/public-website/check', ['jar' => $jar, 'post' => ['csrf_token' => $tokenOn($page['body'])]]);
    $body  = $http('GET', '/admin/public-website', ['jar' => $jar])['body'];
    $start = strpos($body, 'data-setup-check="1"');
    $end   = $start === false ? false : strpos($body, '</ul>', $start);
    if ($start === false || $end === false) {
        return [];
    }
    preg_match_all('#<li class="mb-2" data-check-state="([^"]*)">(.*?)</li>#s', substr($body, $start, $end - $start), $m, PREG_SET_ORDER);
    $rows = [];
    foreach ($m as $one) {
        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($one[2]), ENT_QUOTES, 'UTF-8')));
        $rows[] = [$one[1], trim((string) preg_replace('/^[^:]*?(For information|Needs attention|Fine|Not tested)\s*/', '', $text))];
    }
    return $rows;
};
/** The text of the FIRST element on a page matching `$pattern` (its group 1), tags stripped; null when there is none. */
$uElementText = static function (string $page, string $pattern): ?string {
    if (preg_match($pattern, $page, $m) !== 1) {
        return null;
    }
    return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')));
};
/** What the ONE reach row says, exactly as PublicWebsiteSetup words it. */
$uReachWords = [
    'waiting' => 'Whether it reaches this portal\'s public door was not checked: until the installation\'s administrator approves it, the door does not answer there.',
    'not in effect' => 'Whether it reaches this portal\'s public door was not checked: as said above, the door does not answer on it.',
    'door off' => 'Whether it reaches this portal\'s public door was not checked: the door is switched off, so nothing answers there for your organisation.',
    'not reached' => 'This check did not get an answer from this organisation\'s public door at https://' . $hostA . '/. That can happen, for example, when the address points at a different server, something in front of it answers instead or sends visitors on to another address, its security certificate does not cover the name, the portal is closed for maintenance, or this server cannot reach outside addresses or its own public address. Open https://' . $hostA . '/events.json in your own browser to see what the door gives there.',
    'another door' => 'A public door answered at https://' . $hostA . '/, but not this organisation\'s door on this portal. That can happen, for example, when the address leads to another installation of the portal, or to another organisation\'s door. Make sure the address points at this portal.',
    'reached' => 'It answers as your organisation\'s public door: the answer from https://' . $hostA . '/ carried this check\'s one-off proof, which only a door holding your door\'s current name can work out.',
    'no curl' => 'Whether it reaches this portal\'s public door could not be checked on this server, because this server cannot fetch outside addresses (PHP\'s curl extension is not installed or is switched off on this server). Open https://' . $hostA . '/events.json in your own browser to see what the door gives there.',
];
$uCheckHeaders = ['Cache-Control: no-store', 'Content-Length: 91', "Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox",
    'Content-Type: text/plain; charset=utf-8', 'Referrer-Policy: no-referrer', 'X-Content-Type-Options: nosniff', 'X-Robots-Tag: noindex, nofollow, noarchive'];

// ── The door's check address, on the live portal ─────────────────────────────
$uRefusal = $http('GET', '/w/' . str_repeat('ab', 12) . '/events.json');
$n1 = $uNonce();
$u01 = $http('GET', '/door-check.txt?n=' . $n1, ['host' => $hostA]);
// Start-up's Permissions-Policy is left on every door answer (it is not in
// PublicDoor::REMOVED_HEADERS, and asks nothing of a reader); the check's
// answer carries exactly what the feeds on the same address carry.
$uPerm = $http('GET', '/events.json', ['host' => $hostA])['headers']['permissions-policy'] ?? null;
if ($uPerm !== null) {
    $uCheckHeaders[] = 'Permissions-Policy: ' . $uPerm;
    sort($uCheckHeaders);
}
check('U01 on A\'s approved public address, /door-check.txt with a fresh value answers ONE line — the check\'s format and the proof made from A\'s door name, number, address and that value — with its WHOLE header set: plain text, no-store, data only, never framed, no Referer, nosniff, noindex nofollow noarchive, no cookie and no CORS header',
    [$u01['status'], $u01['body'], $uHeaderSet($u01)], [200, 'webms-public-door-check 1 ' . $uProof($siteA, $hostA, $n1) . "\n", $uCheckHeaders]);
$n2 = $uNonce();
$u02a = $http('GET', '/door-check.txt?n=' . $n2, ['host' => $hostA]);
$u02b = $http('GET', '/door-check.txt?n=' . $n2, ['host' => $hostB]);
check('U02 a new value gives a new proof (so an old answer can never be replayed), and B\'s public address answers with B\'s OWN proof for the same value — never A\'s',
    [$u02a['body'] === 'webms-public-door-check 1 ' . $uProof($siteA, $hostA, $n2) . "\n", $u02a['body'] !== $u01['body'],
        $u02b['body'] === 'webms-public-door-check 1 ' . $uProof($siteB, $hostB, $n2) . "\n", $u02a['body'] !== $u02b['body']],
    [true, true, true, true]);
$uBad = [];
$uBad['/w/ form'] = $feedA('door-check.txt?n=' . $uNonce());
$uBad['/w/ form, B'] = $http('GET', '/w/' . $nameB . '/door-check.txt?n=' . $uNonce());
$uBad['no value'] = $http('GET', '/door-check.txt', ['host' => $hostA]);
$uBad['capitals'] = $http('GET', '/door-check.txt?n=' . strtoupper($uNonce()), ['host' => $hostA]);
$uBad['63 characters'] = $http('GET', '/door-check.txt?n=' . substr($uNonce(), 1), ['host' => $hostA]);
$uBad['65 characters'] = $http('GET', '/door-check.txt?n=' . $uNonce() . 'a', ['host' => $hostA]);
$uBad['a list'] = $http('GET', '/door-check.txt?n[]=' . $uNonce(), ['host' => $hostA]);
$uBad['POST'] = $http('POST', '/door-check.txt?n=' . $uNonce(), ['host' => $hostA, 'post' => ['x' => '1']]);
$uBad['inactive C'] = $http('GET', '/door-check.txt?n=' . $uNonce(), ['host' => $hostC]);
$uDoorOff(true);
$uBad['A\'s door off'] = $http('GET', '/door-check.txt?n=' . $uNonce(), ['host' => $hostA]);
$uDoorOff(false);
$uSame = [];
foreach ($uBad as $kind => $r) {
    $uSame[$kind] = $signature($r) === $signature($uRefusal);
}
check('U03 the check address is the door\'s identical 404 on the /w/ form (A\'s name and B\'s), with no value, capitals, 63 or 65 characters, a list, a POST, on the inactive C\'s address and with A\'s door off',
    $uSame, array_fill_keys(array_keys($uBad), true));
$u04 = $http('GET', '/admin/public-website', ['jar' => $people['root']['jar']])['body'];
check('U04 on the LIVE portal the test-copy card is not drawn at all', [str_contains($u04, 'data-door-test-card'), str_contains($u04, 'door_test_choice')], [false, false]);
$uRootRefusedBefore = $logged('PublicWebsiteSetupRefused', $people['root']['id']);
$u05 = $card('root', 'door_test_choice', ['value' => 'anybody']);
$instJar = $instroot();
$uInstRefusedBefore = $logged('PublicWebsiteSetupRefused', $people['instroot']['id']);
$u05b = $card('instroot', 'door_test_limit', ['value' => 'either']);
check('U05 on the LIVE portal both writers refuse — the organisation\'s choice and the installation\'s limit — saying why, each refusal recorded, and nothing written',
    [$uFlash($u05['page']), $uFlash($u05b['page']), $logged('PublicWebsiteSetupRefused', $people['root']['id']) - $uRootRefusedBefore,
        $logged('PublicWebsiteSetupRefused', $people['instroot']['id']) - $uInstRefusedBefore, $uOwnRow($siteA, 'public.door.testCopies.open'),
        $installationRow('public.door.testCopies.permitted')],
    ['This is the live portal. This setting changes only how the public door answers on a test copy (the alpha, beta or dev copy), so it is set on that copy, where it is shown. Nothing was saved.',
        'This is the live portal. This setting changes only how the public door answers on a test copy (the alpha, beta or dev copy), so it is set on that copy, where it is shown. Nothing was saved.',
        1, 1, null, 'testers']);

// ── "Check my set-up": the reach row, in every state the live portal can show ─
// The rows are [this organisation's alone, its name exists, approval, REACH].
$uStates = [];
$db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = NULL WHERE `siteID` = {$siteA}");
$uStates['waiting'] = ($uCheckRows('root'))[3] ?? null;
$db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = {$siteA}");
$asMembersAddress(true);
$uStates['a members\' address'] = ($uCheckRows('root'))[3] ?? null;
$asMembersAddress(false);
$uDoorOff(true);
$uStates['door off'] = ($uCheckRows('root'))[3] ?? null;
$uDoorOff(false);
// In effect, on the live portal: the check FETCHES. This test's portal has no
// way to reach door-a.selftest.test (no such name exists), so it is not reached.
$uStates['in effect, not reachable from here'] = ($uCheckRows('root'))[3] ?? null;
check('U06 "Check my set-up" over HTTP, its reach row read from its results block alone: waiting and a members\' address are "not tested" with the reason, the door off is "not tested", and an approved address this server cannot reach is a problem — never a tick',
    $uStates, ['waiting' => ['untested', $uReachWords['waiting']], 'a members\' address' => ['untested', $uReachWords['not in effect']],
        'door off' => ['untested', $uReachWords['door off']], 'in effect, not reachable from here' => ['problem', $uReachWords['not reached']]]);

// ── The real check, in-process, against a real TLS server in front of the door ─
// SafeFetch reaches only public https addresses, so — exactly as the framing
// test does — a scratch copy of the REAL SafeFetch trusts this test's
// certificate and records its options, its test-only resolver names
// door-a.selftest.test as a TLS server on this machine, and that server
// passes every request on to THIS test's portal (Host header unchanged), or,
// by a mode file, answers as something else would. Nothing in the portal's
// own files is changed for this.
$uTls = $child . $sep . 'u-tls';
@mkdir($uTls . $sep . 'core', 0700, true);
$uCnf = $uTls . $sep . 'tls.cnf';
file_put_contents($uCnf, "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN={$hostA}\n[v3]\nsubjectAltName=DNS:{$hostA}\nbasicConstraints=critical,CA:TRUE\n");
$uCfg = ['config' => $uCnf, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'x509_extensions' => 'v3'];
$uKey = openssl_pkey_new($uCfg);
$uCsr = $uKey === false ? false : openssl_csr_new(['commonName' => $hostA], $uKey, $uCfg);
$uCrt = $uCsr === false ? false : openssl_csr_sign($uCsr, null, $uKey, 2, $uCfg, random_int(1, 2000000000));
if ($uCrt === false || openssl_x509_export_to_file($uCrt, $uTls . $sep . 'cert.pem') === false
    || openssl_pkey_export_to_file($uKey, $uTls . $sep . 'key.pem', null, $uCfg) === false) {
    refuse('could not make the test certificate for section U (PHP\'s openssl extension)');
}
file_put_contents($uTls . $sep . 'mode.txt', 'forward');
file_put_contents($uTls . $sep . 'server.php', <<<'TLS'
<?php
// Written by tools/public-door-selftest.php for ONE run. Test only.
// A TLS server that passes each request on to the test portal, or — by the
// mode file — answers as something else would.
declare(strict_types=1);
[, $tlsPort, $cert, $key, $webPort, $modeFile, $otherHost] = $argv;
$ctx = stream_context_create(['ssl' => ['local_cert' => $cert, 'local_pk' => $key, 'verify_peer' => false]]);
$server = stream_socket_server('tls://127.0.0.1:' . $tlsPort, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if ($server === false) {
    fwrite(STDERR, "could not listen: {$errstr}\n");
    exit(1);
}
$plain = static fn (int $code, string $words, string $body, string $extra = ''): string
    => "HTTP/1.1 {$code} {$words}\r\nContent-Type: text/plain\r\n{$extra}Content-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
while (true) {
    $c = @stream_socket_accept($server, 60);
    if ($c === false) {
        continue;
    }
    $head = '';
    while (($line = fgets($c)) !== false) {
        $head .= $line;
        if (rtrim($line, "\r\n") === '') {
            break;
        }
    }
    $mode = trim((string) @file_get_contents($modeFile));
    if ($mode === 'p404') {
        $out = $plain(404, 'Not Found', "no site here\n");
    } elseif ($mode === 'p200') {
        $out = $plain(200, 'OK', "ok\n");
    } elseif ($mode === 'fake') {
        $out = $plain(200, 'OK', 'webms-public-door-check 1 ' . str_repeat('0', 64) . "\n");
    } elseif ($mode === 'redirect') {
        $out = $plain(301, 'Moved', '', 'Location: /events.json' . "\r\n");
    } else {
        // Pass it on, as a proxy in front of the portal would. 'forward-b'
        // rewrites the Host to another organisation's public address.
        $lines = preg_split('/\r\n/', rtrim($head, "\r\n")) ?: [];
        $out   = [];
        foreach ($lines as $i => $l) {
            if ($i > 0 && preg_match('/^(host|connection):/i', $l) === 1) {
                if (stripos($l, 'host:') === 0) {
                    $out[] = $mode === 'forward-b' ? 'Host: ' . $otherHost : $l;
                }
                continue;
            }
            $out[] = $l;
        }
        $out[] = 'Connection: close';
        $u = @stream_socket_client('tcp://127.0.0.1:' . $webPort, $eno, $estr, 10);
        if ($u === false) {
            $out = $plain(502, 'Bad Gateway', "test portal not reachable\n");
        } else {
            fwrite($u, implode("\r\n", $out) . "\r\n\r\n");
            stream_set_timeout($u, 30);
            $out = (string) stream_get_contents($u);
            fclose($u);
        }
    }
    @fwrite($c, $out);
    @fclose($c);
}
TLS);
$uTlsPort = $port + 41;
$uTlsProc = proc_open([PHP_BINARY, $uTls . $sep . 'server.php', (string) $uTlsPort, $uTls . $sep . 'cert.pem', $uTls . $sep . 'key.pem',
    (string) $port, $uTls . $sep . 'mode.txt', $hostB],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $uTls . $sep . 'server.log', 'a'], 2 => ['file', $uTls . $sep . 'server.log', 'a']], $uTlsPipes);
register_shutdown_function(static function () use (&$uTlsProc): void {
    if (is_resource($uTlsProc) === true) {
        proc_terminate($uTlsProc);
        proc_close($uTlsProc);
    }
});
for ($i = 0; $i < 40; $i++) {
    $probeSocket = @fsockopen('127.0.0.1', $uTlsPort, $eno, $estr, 0.25);
    if ($probeSocket !== false) {
        fclose($probeSocket);
        break;
    }
    usleep(150000);
}
$uRealSafeFetch = (string) file_get_contents($child . $sep . 'web' . $sep . '_core' . $sep . 'SafeFetch.php');
$uTrust  = 'CURLOPT_SSL_VERIFYPEER    => true,';
$uRecord = '$o = self::options($opts);';
if (substr_count($uRealSafeFetch, $uTrust) !== 1 || substr_count($uRealSafeFetch, $uRecord) !== 1) {
    refuse('section U could not find SafeFetch\'s certificate line or the first line of get() to record its options');
}
file_put_contents($uTls . $sep . 'core' . $sep . 'SafeFetch.php', str_replace([$uTrust, $uRecord], [
    $uTrust . ' CURLOPT_CAINFO => ' . var_export($uTls . $sep . 'cert.pem', true) . ',',
    '$GLOBALS[\'pdsSafeFetchCalls\'][] = [\'url\' => $url, \'opts\' => $opts]; ' . $uRecord,
], $uRealSafeFetch));
file_put_contents($child . $sep . 'u-ask-check.php', <<<'ASK'
<?php
// Written by tools/public-door-selftest.php for ONE run. Test only. Runs the
// REAL PublicWebsiteSetup::checkSetUp() — no hook — with SafeFetch taken from
// the scratch copy (it trusts the test certificate and records its options)
// and every other class from the copy of web/.
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    $short = str_replace('Portal\\Core\\', '', $class);
    $file  = $short === 'SafeFetch' ? __DIR__ . '/u-tls/core/SafeFetch.php' : __DIR__ . '/web/_core/' . $short . '.php';
    if (is_file($file) === true) {
        require_once $file;
    }
});
define('PORTAL_CORE', __DIR__ . '/web/_core');
define('PORTAL_ENV', 'prod');
define('PORTAL_ENV_SOURCE', 'environment');
$c = require __DIR__ . '/web/_auth_keys/auth_creds.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli($c['db_host'], $c['db_user'], $c['db_pass'], $c['db_name'], (int) $c['db_port']);
$mysqli->set_charset('utf8mb4');
$site = (int) $argv[1];
\Portal\Core\Site::init($mysqli, ['site' => ['timezoneKeptAt' => '']], $site);
\Portal\Core\SafeFetch::$testResolverOverride = [$argv[3] => ['ips' => ['127.0.0.1'], 'port' => (int) $argv[2]]];
$GLOBALS['pdsSafeFetchCalls'] = [];
$before = microtime(true);
try {
    $rows = \Portal\Core\PublicWebsiteSetup::checkSetUp($mysqli, $site);
} catch (\Throwable $e) {
    echo json_encode(['error' => get_class($e) . ': ' . $e->getMessage()]);
    exit;
}
$door  = array_values(array_filter($GLOBALS['pdsSafeFetchCalls'], static fn (array $one): bool => str_contains($one['url'], '/door-check.txt')));
$first = $door[0]['opts'] ?? [];
$public = array_values(array_filter($rows, static fn (array $r): bool => $r['label'] === 'Public address'));
echo json_encode([
    'reach'   => isset($public[3]) === true ? [$public[3]['state'], $public[3]['words']] : null,
    'fetches' => count($door),
    'url'     => isset($door[0]) === true ? preg_replace('/n=[0-9a-f]{64}$/', 'n=<64 hex>', $door[0]['url']) : null,
    'opts'    => $first === [] ? null : ['deadline ahead' => (int) round((float) $first['deadline'] - $before), 'step' => $first['timeoutSeconds'],
        'connect' => $first['connectTimeoutSeconds'], 'redirects' => $first['maxRedirects'], 'https only' => $first['httpsOnly'],
        'bytes' => $first['maxBytes'], 'accept' => $first['accept']],
]);
ASK);
/** The real check, in-process, with the TLS server in `$mode`; `$php` adds PHP options (for "no curl"). */
$uAsk = static function (string $mode, string $php = '') use ($child, $sep, $uTls, $uTlsPort, $siteA, $hostA): array {
    file_put_contents($uTls . $sep . 'mode.txt', $mode);
    $raw = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . $php . ' -d ' . escapeshellarg('error_log=' . $child . $sep . 'u-ask.log') . ' '
        . escapeshellarg($child . $sep . 'u-ask-check.php') . ' ' . $siteA . ' ' . $uTlsPort . ' ' . escapeshellarg($hostA) . ' 2>&1');
    file_put_contents($uTls . $sep . 'mode.txt', 'forward');
    $got = json_decode($raw, true);
    return is_array($got) === true ? $got : ['could not ask' => $raw];
};
$u07 = $uAsk('forward');
check('U07 the REAL check, through a real TLS server passing the request on to this portal: ONE fetch of https://' . $hostA . '/door-check.txt with a fresh 64-character value, https only, NO redirects followed, at most 1,024 bytes, 5 seconds a step and 15 in all — and it says the address reaches this organisation\'s door ("ok")',
    $u07, ['reach' => ['ok', $uReachWords['reached']], 'fetches' => 1, 'url' => 'https://' . $hostA . '/door-check.txt?n=<64 hex>',
        'opts' => ['deadline ahead' => 15, 'step' => 5, 'connect' => 5, 'redirects' => 0, 'https only' => true, 'bytes' => 1024, 'accept' => 'text/plain']]);
$u08 = [];
foreach (['forward-b' => 'another organisation\'s door here', 'fake' => 'another installation\'s door', 'p404' => 'a server answering 404',
    'p200' => 'a server answering 200 with something else', 'redirect' => 'a server sending it elsewhere'] as $mode => $what) {
    $u08[$what] = $uAsk($mode)['reach'] ?? null;
}
check('U08 a door of the same kind that is not A\'s — another organisation\'s door on this portal (the request passed on with B\'s address) or another installation\'s — is "a public door answered, but not this one"; a 404, a plain 200 or a redirect is "did not get an answer from this door", with the last status as a fact; none is ever "ok"',
    $u08, ['another organisation\'s door here' => ['problem', $uReachWords['another door']], 'another installation\'s door' => ['problem', $uReachWords['another door']],
        'a server answering 404' => ['problem', $uReachWords['not reached'] . ' The last answer this check received was HTTP 404.'],
        'a server answering 200 with something else' => ['problem', $uReachWords['not reached'] . ' The last answer this check received was HTTP 200.'],
        'a server sending it elsewhere' => ['problem', $uReachWords['not reached'] . ' The last answer this check received was HTTP 301.']]);
check('U09 on a server without curl (curl_init switched off), the real check says the public address could not be checked on this server',
    $uAsk('forward', '-d disable_functions=curl_init')['reach'] ?? null, ['untested', $uReachWords['no curl']]);

// ── The card on a TEST COPY ─────────────────────────────────────────────────
$channel = 'alpha';
$startServer();
$waitForServer();
$uAlphaRefusal = $http('GET', '/w/' . str_repeat('ab', 12) . '/events.json');
// The "Set up the public website" holder must get past the test-copy gate to
// see the card at all: the portal-wide Admin flag does that and nothing else.
$db->query("UPDATE `tblUsers` SET `isAdmin` = 1 WHERE `userID` = {$people['setup']['id']}");
$u10 = $http('GET', '/admin/public-website', ['jar' => $people['root']['jar']])['body'];
check('U10 on a test copy the card is drawn for A\'s global administrator: signed-in testers only now, what the installation allows (testers only), the choice form, NO installation form (they are not the installation\'s), and the plain statement that "signed-in testers only" works only when the address is opened directly',
    [str_contains($u10, 'data-door-test-card="testers"'), str_contains($u10, 'data-door-test-now="testers"'), str_contains($u10, 'data-door-test-limit="testers"'),
        str_contains($u10, 'data-door-test-form="choice"'), str_contains($u10, 'data-door-test-form="limit"'),
        str_contains($u10, 'so the public sees nothing from it')],
    [true, true, true, true, false, true]);
$uRootRefusedBefore = $logged('PublicWebsiteSetupRefused', $people['root']['id']);
$u11 = $card('root', 'door_test_choice', ['value' => 'anybody']);
check('U11 "open to anybody" posted while the installation allows testers only is REFUSED in the handler with the reason, recorded, and nothing written',
    [$uFlash($u11['page']), $logged('PublicWebsiteSetupRefused', $people['root']['id']) - $uRootRefusedBefore, $uOwnRow($siteA, 'public.door.testCopies.open')],
    ['This installation lets test copies\' public doors answer signed-in testers only, so "open to anybody" cannot be chosen. The installation\'s global administrator can change that. Nothing was saved.', 1, null]);
$u12 = $card('root', 'door_test_limit', ['value' => 'either']);
check('U12 A\'s global administrator — not the installation\'s — cannot raise the installation\'s limit: refused, recorded, unchanged',
    [$uFlash($u12['page']), $logged('PublicWebsiteSetupRefused', $people['root']['id']) - $uRootRefusedBefore, $installationRow('public.door.testCopies.permitted')],
    ['Only a global administrator of the whole installation can change what test copies\' public doors may do. Nothing was saved.', 2, 'testers']);
$u13page = $http('GET', '/admin/public-website', ['jar' => $instJar])['body'];
$u13 = $card('instroot', 'door_test_limit', ['value' => 'either']);
check('U13 the installation\'s global administrator sees the installation form and raises the limit to "either": stored, recorded as PublicDoorTestCopyLimitSaved, and the card says each organisation may choose',
    [str_contains($u13page, 'data-door-test-form="limit"'), $installationRow('public.door.testCopies.permitted'), $logged('PublicDoorTestCopyLimitSaved', $people['instroot']['id']),
        str_contains($u13['page'], 'data-door-test-limit="either"'), $uFlash($u13['page'])],
    [true, 'either', 1, true, 'Saved: on this test copy, each organisation\'s global administrator may now choose to open its public door to anybody (search engines are always told not to list it).']);
$uSetupRefusedBefore = $logged('PublicWebsiteSetupRefused', $people['setup']['id']);
$u14page = $http('GET', '/admin/public-website', ['jar' => $people['setup']['jar']])['body'];
$u14 = $card('setup', 'door_test_choice', ['value' => 'anybody']);
check('U14 somebody given "Set up the public website" sees the card and the state but NO choice form (D-087 names the global administrator); posted directly, the choice is refused, recorded, and nothing written',
    [str_contains($u14page, 'data-door-test-card='), str_contains($u14page, 'data-door-test-who="1"'), str_contains($u14page, 'data-door-test-form="choice"'),
        $uFlash($u14['page']), $logged('PublicWebsiteSetupRefused', $people['setup']['id']) - $uSetupRefusedBefore, $uOwnRow($siteA, 'public.door.testCopies.open')],
    [true, true, false, 'Only a global administrator of this organisation can choose who its public door answers on a test copy. Nothing was saved.', 1, null]);
$u15 = $card('root', 'door_test_choice', ['value' => 'anybody']);
check('U15 with the installation allowing it, A\'s global administrator chooses "open to anybody": stored on A\'s own row, recorded as PublicDoorTestCopyChoiceSaved, and the card says the door here answers anybody',
    [$uOwnRow($siteA, 'public.door.testCopies.open'), $logged('PublicDoorTestCopyChoiceSaved', $people['root']['id']), str_contains($u15['page'], 'data-door-test-now="anybody"')],
    ['anybody', 1, true]);
// Every surface, on BOTH forms of address, with no sign-in at all.
// Round 1 of P4d's check: MEDIA was missing from this list, so skipping the
// testers-only gate for media alone (and sending media with plain `noindex`)
// each left every check green. A poster's picture is a surface like any other.
$uSurfaces = static function () use ($http, $doorName, $siteA, $hostA, $uNonce, $upA): array {
    $out = [];
    foreach (['events.json', 'posters.json', 'door.js', 'page.html', 'media/' . $upA] as $what) {
        $out['/w/ ' . $what]    = $http('GET', '/w/' . (string) $doorName($siteA) . '/' . $what);
        $out['public ' . $what] = $http('GET', '/' . $what, ['host' => $hostA]);
    }
    $out['public door-check.txt'] = $http('GET', '/door-check.txt?n=' . $uNonce(), ['host' => $hostA]);
    return $out;
};
$uOpenBefore = $sessionFiles();
$uOpen = [];
foreach ($uSurfaces() as $what => $r) {
    $uOpen[$what] = [$r['status'], $r['headers']['cache-control'] ?? '', $r['headers']['x-robots-tag'] ?? '', $r['headers']['set-cookie'] ?? ''];
}
$uOpenWant = [];
foreach (array_keys($uOpen) as $what) {
    $uOpenWant[$what] = [200, $what === 'public door-check.txt' ? 'no-store' : 'public, max-age=60', 'noindex, nofollow, noarchive', ''];
}
check('U16 open to anybody: EVERY surface — both feeds, the script, the page and a poster\'s picture on /w/ AND on the public address, and the check address — answers with no sign-in, kept for a minute (the check never), every answer telling search engines not to list it, no cookie, no session file',
    [$uOpen, $sessionFiles() - $uOpenBefore], [$uOpenWant, 0]);
/** A media answer's whole header set, as it must be: `$cache` and `$vary` differ by who may be served. */
$uMediaWant = static function (array $r, string $cache, string $vary, string $perm) use ($uHeaderSet): array {
    // `Accept-Ranges: bytes` since #880: the door's media can be sent in pieces.
    $want = ['Accept-Ranges: bytes', 'Access-Control-Allow-Origin: *', 'Cache-Control: ' . $cache, 'Content-Length: ' . strlen($r['body']),
        "Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox", 'Content-Type: image/png',
        'Cross-Origin-Resource-Policy: cross-origin', 'Referrer-Policy: no-referrer', 'X-Content-Type-Options: nosniff',
        'X-Robots-Tag: noindex, nofollow, noarchive'];
    if ($vary !== '') {
        $want[] = 'Vary: ' . $vary;
    }
    if ($perm !== '') {
        $want[] = 'Permissions-Policy: ' . $perm;
    }
    sort($want);
    return $want;
};
$uMediaW = $http('GET', '/w/' . (string) $doorName($siteA) . '/media/' . $upA);
$uMediaP = $http('GET', '/media/' . $upA, ['host' => $hostA]);
$uMediaPerm = $uMediaW['headers']['permissions-policy'] ?? '';
check('U16b open to anybody, a poster\'s picture on BOTH forms carries its WHOLE header set: image/png, readable and loadable from any website, data only, kept a minute, and noindex, nofollow, noarchive — the test copy\'s words, never plain noindex',
    [$uMediaW['status'], $uHeaderSet($uMediaW), $uMediaP['status'], $uHeaderSet($uMediaP)],
    [200, $uMediaWant($uMediaW, 'public, max-age=60', '', $uMediaPerm), 200, $uMediaWant($uMediaP, 'public, max-age=60', '', $uMediaPerm)]);
$uRowsOpen = $uCheckRows('root');
check('U17 open to anybody, "Check my set-up" on the test copy FETCHES the public address (this server cannot reach it, so a problem — never "not tested" for the testers reason)',
    $uRowsOpen[3] ?? null, ['problem', $uReachWords['not reached']]);
$uBNone = [$http('GET', '/w/' . $nameB . '/events.json')['status'], $http('GET', '/events.json', ['host' => $hostB])['status']];
check('U18 A\'s choice is A\'s alone: B, which chose nothing, is still refused with no sign-in, on both forms', $uBNone, [404, 404]);
$u19 = $card('instroot', 'door_test_limit', ['value' => 'testers']);
$uClosed = [];
foreach ($uSurfaces() as $what => $r) {
    $uClosed[$what] = [$r['status'], $signature($r) === $signature($uAlphaRefusal)];
}
check('U19 the installation lowers the limit to testers only: saved, its message counting the one organisation whose choice now does nothing; A\'s "anybody" is kept, and EVERY surface on both forms is again the door\'s identical 404 with no sign-in — the stricter wins when the door READS',
    [$uFlash($u19['page']), $uOwnRow($siteA, 'public.door.testCopies.open'), $uClosed, $logged('PublicDoorTestCopyLimitSaved', $people['instroot']['id'])],
    ['Saved: on this test copy every organisation\'s public door answers signed-in testers only. 1 organisation had chosen "open to anybody"; that choice is kept but does nothing while this limit stands.',
        'anybody', array_fill_keys(array_keys($uOpen), [404, true]), 2]);
$u19b = $card('instroot', 'door_test_limit', ['value' => 'either']);
check('U19b raising the limit again says what happens to the one organisation that had chosen "open to anybody" — only whenever its door answers at all, never "open now" of a door that may be off',
    $uFlash($u19b['page']), 'Saved: on this test copy, each organisation\'s global administrator may now choose to open its public door to anybody (search engines are always told not to list it). 1 organisation had already chosen that; from now on, whenever that door answers at all, it answers anybody.');
$card('instroot', 'door_test_limit', ['value' => 'testers']);
// Round 1 of P4d's check: U20 used to send the tester's cookie with the PUBLIC
// address's Host by hand — a state no browser produces, because the session
// cookie is host-only (it names no domain) and the public address answers the
// door alone. A tester is served at the /w/ address on the portal's own
// address, which is where the card and the set-up check now send them.
$uTesterW = $feedA('events.json', ['cookie' => $cookieOf($people['tester']['jar'])]);
$uTesterMedia = $feedA('media/' . $upA, ['cookie' => $cookieOf($people['tester']['jar'])]);
check('U20 …and a signed-in tester is still served at the door\'s /w/ address on the portal\'s own address — the only form a browser carries the sign-in to — answered privately (private, no-store, Vary: Cookie); a poster\'s picture too, with its WHOLE header set',
    [$uTesterW['status'], $uTesterW['headers']['cache-control'] ?? '', $uTesterW['headers']['vary'] ?? '', $uTesterMedia['status'], $uHeaderSet($uTesterMedia)],
    [200, 'private, no-store', 'Cookie', 200, $uMediaWant($uTesterMedia, 'private, no-store', 'Cookie', $uMediaPerm)]);
$uWEvents = $base . '/w/' . (string) $doorName($siteA) . '/events.json';
$uFacts21 = ['rule' => 'testers', 'door' => 'on', 'public' => $hostA, 'wEvents' => $uWEvents,
    'wPosters' => $base . '/w/' . (string) $doorName($siteA) . '/posters.json', 'calendar' => true, 'noticeboard' => true];
$uWords21 = ($uWords([$uFacts21]))[0];
check('U21 with the door for testers only on a test copy, "Check my set-up" does NOT fetch: its reach row is doorReachWords()\'s sentence for this state, which says the public address answers nobody (nobody is signed in there) and names the /w/ address for testers',
    [($uCheckRows('root'))[3] ?? null, str_contains((string) $uWords21['reachRow'], $hostA . ' answers nobody here'), str_contains((string) $uWords21['reachRow'], 'Testers can try the door at ' . $uWEvents)],
    [['untested', $uWords21['reachRow']], true, true]);
$u21card = $http('GET', '/admin/public-website', ['jar' => $people['root']['jar']])['body'];
// Round 2 of P4d's check: U21b used to look for its clauses ANYWHERE on the
// page, so the alert's own main claim could be replaced by the removed advice
// and the test-copy card's paragraph still satisfied it. It reads the alert's
// OWN element now, and compares it WHOLE.
check('U21b with a public address in effect on a testers-only test copy, the door card\'s warning — its OWN element, compared whole — is doorReachWords()\'s: the addresses it lists answer nobody, and testers are given the /w/ addresses',
    [$uElementText($u21card, '#<div class="alert alert-warning" data-door-testers-only="1">(.*?)</div>#s'), str_contains((string) $uWords21['alert'], 'so they answer nobody'),
        str_contains((string) $uWords21['alert'], $uWEvents . ' (events) and ' . $uFacts21['wPosters'] . ' (posters)')],
    [$uWords21['alert'], true, true]);
$u22a = $card('root', 'door_test_choice', ['value' => 'open']);
$u22b = $card('root', 'door_test_choice');
$u22c = $card('instroot', 'door_test_limit', ['value' => 'anybody']);
check('U22 a choice nobody offers ("open", or "anybody" as the installation\'s limit) is refused; a post with no value at all is refused, never read as a choice; nothing changes',
    [$uFlash($u22a['page']), $uFlash($u22b['page']), $uFlash($u22c['page']), $uOwnRow($siteA, 'public.door.testCopies.open'), $installationRow('public.door.testCopies.permitted')],
    ['That choice was not recognised. Nothing was saved.', 'That form did not arrive whole. Nothing was changed.', 'That choice was not recognised. Nothing was saved.', 'anybody', 'testers']);
$u23 = $card('root', 'door_test_choice', ['value' => 'testers']);
check('U23 choosing "signed-in testers only" is always allowed: stored, recorded, and the card says so', [$uOwnRow($siteA, 'public.door.testCopies.open'),
    $logged('PublicDoorTestCopyChoiceSaved', $people['root']['id']), str_contains($u23['page'], 'data-door-test-now="testers"')], ['testers', 2, true]);
// The test copy's own staff gate switched off: the whole copy is open, so is the door.
$uGateWas = $installationRow('portal.gatekeeper.enabled');
$setInstallation('portal.gatekeeper.enabled', 'false');
$u24card = $http('GET', '/admin/public-website', ['jar' => $people['root']['jar']])['body'];
$u24w = $feedA();
$u24p = $http('GET', '/events.json', ['host' => $hostA]);
// Round 1 of P4d's check: saving while the gate is off said the door answers
// testers only — false here. Both messages are worded from what the door does.
$u24choice = $card('root', 'door_test_choice', ['value' => 'testers']);
$u24limit  = $card('instroot', 'door_test_limit', ['value' => 'testers']);
$setInstallation('portal.gatekeeper.enabled', $uGateWas);
check('U24 with the test copy\'s own staff sign-in gate switched off, the card says the door is open to anybody whatever is chosen, and it is — on both forms, still telling search engines not to list it',
    [str_contains($u24card, 'data-door-test-card="ungated"'), [$u24w['status'], $u24w['headers']['x-robots-tag'] ?? ''], [$u24p['status'], $u24p['headers']['x-robots-tag'] ?? '']],
    [true, [200, 'noindex, nofollow, noarchive'], [200, 'noindex, nofollow, noarchive']]);
$uWords24 = ($uWords([['rule' => 'ungated', 'choice' => 'testers', 'limit' => 'testers', 'chose' => 0] + $uFacts21]))[0];
check('U24b with the gate switched off, saving "signed-in testers only" — the organisation\'s choice and the installation\'s limit — is stored, and each message is doorReachWords()\'s for that state: open to anybody until the gate is back on, never that it answers testers only',
    [$uFlash($u24choice['page']), $uFlash($u24limit['page']), str_contains($uFlash($u24choice['page']), 'answers only people signed in'),
        str_contains($uFlash($u24limit['page']), 'gate is switched off'), $uOwnRow($siteA, 'public.door.testCopies.open'), $installationRow('public.door.testCopies.permitted')],
    [$uWords24['savedChoice'], $uWords24['savedLimit'], false, true, 'testers', 'testers']);
$db->query("UPDATE `tblUsers` SET `isAdmin` = 0 WHERE `userID` = {$people['setup']['id']}");
$setOwn($siteA, 'public.door.testCopies.open', null);
$setInstallation('public.door.testCopies.permitted', 'testers');
$channel = 'prod';
$startServer();
$waitForServer();
$u25 = $http('GET', '/events.json', ['host' => $hostA]);
check('U25 back on the live portal, the door\'s feed says only noindex — the test-copy rule asks the channel first, so it does nothing here', [$u25['status'], $u25['headers']['x-robots-tag'] ?? ''], [200, 'noindex']);

// ── Rounds 2 and 3 of P4d's check: EVERY sentence about who the door answers ─
// A sentence about who the door answers was untrue in one combination of
// states two rounds running, so the sentences live WHOLE in ONE method,
// PublicWebsiteSetup::doorReachWords(). U26 judges the method's truth for
// every combination of the facts it is keyed by, by rules written HERE (not
// copied from it) — and, since round 3, by each state's DISTINGUISHING words:
// swapping two states' sentences must fail, which a rule asking only for a
// word both share cannot see (round 3's plant A). U27 renders every state a
// page can be in over real HTTP and requires each place to show exactly the
// method's sentence. U28 POSTS each change of choice and compares the message
// with the method's words for the state AFTER the save (round 3's plant D).
$uHost = 'pub.selftest.test';
$uWa   = 'https://portal.selftest.test/w/0123456789abcdef01234567/events.json';
$uWp   = 'https://portal.selftest.test/w/0123456789abcdef01234567/posters.json';
$uApps = ['both' => [true, true], 'calendar only' => [true, false], 'noticeboard only' => [false, true], 'neither' => [false, false]];
/** The test's OWN reading of a state: which of the method's states it is. */
$uClass = static function (array $f): string {
    if ($f['door'] !== 'on') {
        return 'door ' . $f['door'];
    }
    if ($f['public'] === '' && $f['wEvents'] === '') {
        return 'no address';
    }
    if ($f['calendar'] === false && $f['noticeboard'] === false) {
        return 'nothing to show';
    }
    return $f['rule'];
};
// Each state's DISTINGUISHING words, in the test-copy card and in the door
// row. A state must show its own and NONE of any other state's.
$uCardMark = ['door off' => 'Your public door is switched off', 'door spoiled' => 'changed by hand', 'door unreadable' => 'could not be read just now',
    'no address' => 'it has no address yet', 'nothing to show' => 'it has nothing to show', 'ungated' => 'own staff sign-in gate is switched off',
    'anybody' => 'answers anybody on the internet', 'testers' => 'answers only people signed in to this test copy'];
$uRowMark = ['door off' => 'While the door is switched off', 'door spoiled' => 'Its stored name is not one the portal makes',
    'door unreadable' => 'Whether the door is on could not be read', 'no address' => 'The door has no address yet',
    'nothing to show' => 'the door has nothing to show', 'live' => 'Open its addresses in your own browser', 'ungated' => 'Open its addresses in your own browser',
    'anybody' => 'Open its addresses in your own browser', 'testers' => 'the public sees nothing from the door'];
$uTable = [];
foreach (['live', 'ungated', 'testers', 'anybody'] as $rule) {
    foreach (['on', 'off', 'spoiled', 'unreadable'] as $door) {
        foreach (['', $uHost] as $pub) {
            foreach (['', $uWa] as $w) {
                foreach ($uApps as [$cal, $nb]) {
                    foreach (['testers', 'anybody'] as $choice) {
                        $uTable[] = ['rule' => $rule, 'door' => $door, 'public' => $pub, 'wEvents' => $door === 'on' ? $w : '',
                            'wPosters' => ($door === 'on' && $w !== '') ? $uWp : '', 'calendar' => $cal, 'noticeboard' => $nb, 'choice' => $choice];
                    }
                }
            }
        }
    }
}
$uTableWords = $uWords($uTable);
$uWrong = [];
foreach ($uTable as $i => $f) {
    $w     = $uTableWords[$i];
    $class = $uClass($f);
    $all   = implode(' | ', array_filter([$w['alert'], $w['card'], $w['reachRow'], $w['doorRow'], $w['savedChoice']], 'is_string'));
    $why   = [];
    // Each state's own words, and nobody else's (round 3: plant A swapped two).
    if ($f['rule'] !== 'live') {
        foreach ($uCardMark as $other => $mark) {
            if (($other === $class) !== str_contains((string) $w['card'], $mark)) {
                $why[] = 'the card ' . ($other === $class ? 'lacks its own words' : 'carries the words of "' . $other . '"');
            }
        }
    }
    foreach (array_unique($uRowMark) as $mark) {
        if (($uRowMark[$class] === $mark) !== str_contains($w['doorRow'], $mark)) {
            $why[] = 'the door row ' . ($uRowMark[$class] === $mark ? 'lacks its own words' : 'carries another state\'s: "' . $mark . '"');
        }
    }
    if (str_contains($all, 'below') === true) {
        $why[] = 'says "below"';
    }
    if (substr_count($all, 'until it is opened to anybody') !== substr_count($all, 'until it is opened to anybody, which a global administrator of this organisation chooses')) {
        $why[] = 'an "until" that does not say who decides';
    }
    if ($f['rule'] === 'live' && ($w['card'] !== null || $w['alert'] !== null || $w['savedChoice'] !== '')) {
        $why[] = 'a test-copy sentence on the live portal';
    }
    // The reach row: for a public address in effect, skipped exactly when
    // there is nothing to fetch or nobody can be signed in there.
    $skip = $f['public'] !== '' && ($f['door'] !== 'on' || ($f['rule'] === 'testers' && $class !== 'no address'));
    if (($w['reachRow'] !== null) !== $skip) {
        $why[] = 'the reach row skipped or fetched wrongly';
    }
    // NO WORD CONTAINING "fram" (round 4 of P4d's check) — which is all this
    // guard can see: a frame claim spelt without it ("embedded in any
    // website", "shown inside any website you list") passes, as round 5 of
    // the check measured. The sentence about
    // frames was untrue two rounds running — a "never" the browser does not
    // keep, then a promise the framing rule refuses by default. Whether a frame
    // shows anything is the framing card's business; these sentences say who
    // the door answers, and where a tester can open it DIRECTLY.
    if (preg_match('/fram/i', $all) === 1) {
        $why[] = 'says something about frames';
    }
    if (str_contains($all, 'never sends that sign-in to a script') === true) {
        $why[] = 'claims a browser never sends the sign-in to a script';
    }
    if ($class === 'testers' && $f['wEvents'] !== '' && (str_contains((string) $w['card'], 'can open it directly at its address on the portal\'s own address') === false
        || str_contains($w['doorRow'], 'a pasted script shows nothing and a website\'s own server gets nothing') === false
        || str_contains($w['doorRow'], 'a website gets nothing') === true)) {
        $why[] = 'a testers-only door does not say where a tester opens it, or that a script and a website\'s server get nothing';
    }
    // Round 4's finding P9: the door row too.
    if ($class === 'testers' && $f['public'] !== '' && (str_contains((string) $w['card'], $f['public'] . ', answers nobody') === false
        || str_contains((string) $w['alert'], 'so they answer nobody') === false || str_contains((string) $w['reachRow'], $f['public'] . ' answers nobody here') === false
        || str_contains($w['doorRow'], $f['public'] . ', answers nobody') === false)) {
        $why[] = 'a testers-only public address not said to answer nobody';
    }
    if ($f['public'] !== '' && str_contains($all, 'https://' . $f['public']) === true) {
        $why[] = 'sends somebody to the public address';
    }
    // Addresses: each label names ITS address (round 3's plant F), and an app
    // switched off is never pointed at.
    if (substr_count($all, ' (events)') !== substr_count($all, $uWa . ' (events)') || substr_count($all, ' (posters)') !== substr_count($all, $uWp . ' (posters)')) {
        $why[] = 'an address labelled as the other one';
    }
    if ($class === 'testers' && $f['wEvents'] !== '') {
        if ($f['calendar'] === true && str_contains((string) $w['alert'], $uWa . ' (events)') === false) {
            $why[] = 'the events address testers can use is not named';
        }
        if ($f['noticeboard'] === true && str_contains((string) $w['alert'], $uWp . ' (posters)') === false) {
            $why[] = 'the posters address testers can use is not named';
        }
    }
    if ($f['calendar'] === false && str_contains($all, $uWa) === true) {
        $why[] = 'points at the events address while Calendar is off';
    }
    if ($f['noticeboard'] === false && str_contains($all, $uWp) === true) {
        $why[] = 'points at the posters address while the Noticeboard is off';
    }
    if (in_array($class, ['live', 'ungated', 'anybody', 'testers'], true) === true && $f['calendar'] !== $f['noticeboard']) {
        $note = $f['calendar'] === false ? 'gives no events' : 'gives no posters';
        if (str_contains($w['doorRow'], $note) === false || ($f['rule'] !== 'live' && str_contains((string) $w['card'], $note) === false)) {
            $why[] = 'one app off, not said';
        }
    }
    if (str_contains($all, ' works') === true || str_contains($all, 'a copy pasted into a real website') === true) {
        $why[] = 'promises what a pasted copy does';
    }
    if ($f['rule'] !== 'live') {
        if ($w['savedChoice'] !== 'Saved: your choice is ' . ($f['choice'] === 'anybody' ? 'open to anybody' : 'signed-in testers only') . '. ' . $w['card']) {
            $why[] = 'the save\'s message is not the choice and then the whole state';
        }
        $amber = !(($class === 'anybody') || ($class === 'testers' && $f['wEvents'] !== ''));
        if (($w['savedChoiceType'] === 'warning') !== $amber) {
            $why[] = 'the save\'s colour does not follow its content';
        }
    }
    if ($why !== []) {
        $uWrong[] = json_encode([$f['rule'], $f['door'], $f['public'], $f['wEvents'] !== '', $f['calendar'], $f['noticeboard'], $f['choice']]) . ': ' . implode('; ', array_unique($why));
    }
}
$uLimitTable = [];
foreach (['live', 'ungated', 'testers', 'anybody'] as $rule) {
    foreach (['testers', 'either'] as $limit) {
        foreach ([0, 1, 2] as $chose) {
            $uLimitTable[] = ['rule' => $rule, 'limit' => $limit, 'chose' => $chose];
        }
    }
}
foreach ($uWords($uLimitTable) as $i => $w) {
    $f = $uLimitTable[$i];
    $why = [];
    if ($w['savedLimit'] === '' || str_contains($w['savedLimit'], 'is open to anybody') === true || str_contains($w['savedLimit'], 'open to anybody now') === true) {
        $why[] = 'empty, or claims a door is open';
    }
    if (($f['rule'] === 'ungated') !== str_contains($w['savedLimit'], 'gate is switched off') || ($f['rule'] === 'ungated') !== ($w['savedLimitType'] === 'warning')) {
        $why[] = 'the gate off said, or its colour, where it is not so';
    }
    if ($f['rule'] !== 'ungated' && $f['chose'] > 0 && str_contains($w['savedLimit'], (string) $f['chose'] . ' organisation') === false) {
        $why[] = 'the organisations that had chosen are not counted';
    }
    if ($why !== []) {
        $uWrong[] = 'limit ' . json_encode($f) . ': ' . implode('; ', $why);
    }
}
check('U26 doorReachWords() is TRUE, and each state DISTINGUISHABLE, in every one of ' . count($uTable) . ' combinations of its facts (rule × door on, off, spoiled or unreadable × public address × /w/ address × the two apps × choice) and ' . count($uLimitTable)
    . ' for the limit\'s save — judged by rules written in this test: each state shows its own words and no other state\'s, a testers-only public address always "answers nobody", no word containing "fram" (the frame claims round 4 removed; a claim spelt without it is not seen), each address under its own label and no app\'s address while it is off, no "below", every "until" naming who decides, the save\'s colour following its words',
    [count($uTable), count($uLimitTable), $uWrong], [512, 24, []]);

/** Render one state of A over real HTTP: the door card's warning, the test-copy card's sentence, the set-up check's two rows and the flash colour. */
$uRender = static function () use ($http, $people, $uElementText, $uCheckAll): array {
    $page = $http('GET', '/admin/public-website', ['jar' => $people['root']['jar']])['body'];
    $rows = $uCheckAll('root');
    $door = null;
    $reach = null;
    foreach ($rows as [$rowState, $text]) {
        if ($rowState === 'untested' && str_starts_with($text, 'The public door:') === true) {
            $door = trim(substr($text, strlen('The public door:')));
        }
        if (str_starts_with($text, 'Public address:') === true) {
            $reach = [$rowState, trim(substr($text, strlen('Public address:')))];
        }
    }
    return [
        'alert'   => $uElementText($page, '#<div class="alert alert-warning" data-door-testers-only="1">(.*?)</div>#s'),
        'card'    => $uElementText($page, '#<p class="mb-2" data-door-test-now="[^"]*">(.*?)</p>#s'),
        'doorRow' => $door,
        'reach'   => $reach,
    ];
};
// A run killed while the door table is renamed would leave it aside: put it back.
register_shutdown_function(static function () use ($db): void {
    try {
        $aside = $db->query("SELECT COUNT(*) AS `n` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblPublicDoorNamesP4dAside'")->fetch_assoc();
        if ((int) ($aside['n'] ?? 0) === 1) {
            $db->query('RENAME TABLE `tblPublicDoorNamesP4dAside` TO `tblPublicDoorNames`');
        }
    } catch (Throwable $e) {
        echo "  ! could not put tblPublicDoorNames back; rename tblPublicDoorNamesP4dAside by hand.\n";
    }
});
/** Put A in one state; return the facts that state IS (built here, from what was set). */
$uSetState = static function (string $rule, string $what) use ($db, $siteA, $hostA, $base, $doorName, $uDoorOff, $setInstallation, $setOwn): array {
    $setInstallation('public.door.testCopies.permitted', $rule === 'anybody' ? 'either' : 'testers');
    $setOwn($siteA, 'public.door.testCopies.open', $rule === 'anybody' ? 'anybody' : null);
    $setInstallation('portal.gatekeeper.enabled', $rule === 'ungated' ? 'false' : null);
    $approved = in_array($what, ['/w/ only', 'no address'], true) === false;
    $db->query('UPDATE `tblPublicHosts` SET `approvedAtUtc` = ' . ($approved === true ? 'UTC_TIMESTAMP()' : 'NULL') . " WHERE `siteID` = {$siteA}");
    $wOk = in_array($what, ['public only', 'no address'], true) === false;
    $setInstallation('site.url', $wOk === true ? $base : null);
    $cal = in_array($what, ['calendar off', 'nothing to show'], true) === false;
    $nb  = $what !== 'nothing to show';
    $setOwn($siteA, 'calendar.enabled', $cal === true ? null : 'false');
    $setOwn($siteA, 'noticeboard.enabled', $nb === true ? null : 'false');
    $name = (string) $doorName($siteA);
    $door = 'on';
    if ($what === 'door off') {
        $uDoorOff(true);
        $door = 'off';
    } elseif ($what === 'spoiled') {
        $db->query("UPDATE `tblPublicDoorNames` SET `publicName` = 'NOT-A-NAME' WHERE `siteID` = {$siteA}");
        $door = 'spoiled';
    } elseif ($what === 'unreadable') {
        $db->query('RENAME TABLE `tblPublicDoorNames` TO `tblPublicDoorNamesP4dAside`');
        $door = 'unreadable';
    }
    $on = $door === 'on';
    return ['rule' => $rule, 'door' => $door, 'public' => $approved === true ? $hostA : '',
        'wEvents' => ($on === true && $wOk === true) ? $base . '/w/' . $name . '/events.json' : '',
        'wPosters' => ($on === true && $wOk === true) ? $base . '/w/' . $name . '/posters.json' : '',
        'calendar' => $cal, 'noticeboard' => $nb, '_name' => $name];
};
$uPutBack = static function (string $what, string $name) use ($db, $siteA, $base, $uDoorOff, $setInstallation, $setOwn): void {
    if ($what === 'door off') {
        $uDoorOff(false);
    } elseif ($what === 'spoiled') {
        $db->query("UPDATE `tblPublicDoorNames` SET `publicName` = '{$name}' WHERE `siteID` = {$siteA}");
    } elseif ($what === 'unreadable') {
        $db->query('RENAME TABLE `tblPublicDoorNamesP4dAside` TO `tblPublicDoorNames`');
    }
    $setInstallation('site.url', $base);
    $setInstallation('public.door.testCopies.permitted', 'testers');
    $setOwn($siteA, 'public.door.testCopies.open', null);
    $setOwn($siteA, 'calendar.enabled', null);
    $setOwn($siteA, 'noticeboard.enabled', null);
    $setInstallation('portal.gatekeeper.enabled', null);
    $db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = {$siteA}");
};
$uStates = ['door off', 'spoiled', 'unreadable', 'public and /w/', 'public only', '/w/ only', 'no address', 'calendar off', 'nothing to show'];
$uGateKept = $installationRow('portal.gatekeeper.enabled');
$uCombos = [];
$uSeen   = [];
foreach (['prod' => ['live'], 'alpha' => ['testers', 'anybody', 'ungated']] as $uChannel => $uRules) {
    $channel = $uChannel;
    $startServer();
    $waitForServer();
    foreach ($uRules as $rule) {
        // NOT `$state`: that name is the run's own bookkeeping (the server
        // process, the settings to put back), shared by reference.
        foreach ($uStates as $uState) {
            $facts = $uSetState($rule, $uState);
            try {
                $uSeen[$rule . ', ' . $uState] = $uRender();
            } finally {
                $uPutBack($uState, $facts['_name']);
            }
            unset($facts['_name']);
            $uCombos[$rule . ', ' . $uState] = $facts;
        }
    }
}
$setInstallation('portal.gatekeeper.enabled', $uGateKept);
$channel = 'prod';
$startServer();
$waitForServer();
$uComboWords = $uWords(array_values($uCombos));
$uMismatch = [];
$i = 0;
foreach ($uCombos as $label => $facts) {
    $w    = $uComboWords[$i++];
    $seen = $uSeen[$label];
    // The reach row: doorReachWords()'s when it does not fetch; otherwise the
    // row of another state — waiting for approval (not in effect), or the
    // fetch, which this server cannot complete.
    $reachWant = $w['reachRow'] !== null ? ['untested', $w['reachRow']]
        : ($facts['public'] === '' ? ['untested', $uReachWords['waiting']] : ['problem', $uReachWords['not reached']]);
    $want = ['alert' => $w['alert'], 'card' => $w['card'], 'doorRow' => $w['doorRow'], 'reach' => $reachWant];
    if ($seen !== $want) {
        $uMismatch[$label] = ['shown' => $seen, 'doorReachWords' => $want];
    }
}
check('U27 over real HTTP, in every one of ' . count($uCombos) . ' states a page can be in (the live portal and a test copy for testers, open to anybody, or with its gate off; × the door off, its name spoiled, its state unreadable, a public address and a /w/ address, a public address only, a /w/ address only, no address, Calendar off, both apps off), the door card\'s warning, the test-copy card\'s sentence and the set-up check\'s door and reach rows are EXACTLY doorReachWords()\'s for that state — each read from its own element',
    [count($uCombos), $uMismatch], [36, []]);

// U28 (round 3's plant D; round 4's plant P3): the choice's save message must
// describe the state the save LEFT, in the colour that state calls for. Each
// change of choice is POSTED and the message compared with doorReachWords()
// for the facts AFTER the save, and the colour the HANDLER returned compared
// with a colour written here — four transitions end green, three must end
// AMBER (the door off, a public address only, the test copy's gate off), so a
// handler that always answered green fails.
$channel = 'alpha';
$startServer();
$waitForServer();
$setInstallation('public.door.testCopies.permitted', 'either');
$uTransitions = [];
$uSlots = [
    'testers → anybody, with a public address' => ['testers', 'anybody', 'public and /w/'],
    'anybody → testers, with a public address' => ['anybody', 'testers', 'public and /w/'],
    'testers → anybody, no public address'     => ['testers', 'anybody', '/w/ only'],
    'anybody → testers, no public address'     => ['anybody', 'testers', '/w/ only'],
    'testers → anybody, the door off'          => ['testers', 'anybody', 'door off'],
    'anybody → testers, a public address only' => ['anybody', 'testers', 'public only'],
    'testers → anybody, the gate off'          => ['testers', 'anybody', 'gate off'],
];
foreach ($uSlots as $label => [$from, $to, $where]) {
    $name = (string) $doorName($siteA);
    $db->query('UPDATE `tblPublicHosts` SET `approvedAtUtc` = ' . ($where === '/w/ only' ? 'NULL' : 'UTC_TIMESTAMP()') . " WHERE `siteID` = {$siteA}");
    if ($where === 'public only') {
        $setInstallation('site.url', null);
    }
    if ($where === 'door off') {
        $uDoorOff(true);
    }
    if ($where === 'gate off') {
        $setInstallation('portal.gatekeeper.enabled', 'false');
    }
    $setOwn($siteA, 'public.door.testCopies.open', $from);
    try {
        $page = $card('root', 'door_test_choice', ['value' => $to])['page'];
    } finally {
        if ($where === 'public only') {
            $setInstallation('site.url', $base);
        }
        if ($where === 'door off') {
            $uDoorOff(false);
        }
        if ($where === 'gate off') {
            $setInstallation('portal.gatekeeper.enabled', $uGateKept);
        }
    }
    $wOk   = $where === 'public and /w/' || $where === '/w/ only' || $where === 'gate off';
    $after = ['rule' => $where === 'gate off' ? 'ungated' : $to, 'door' => $where === 'door off' ? 'off' : 'on',
        'public' => $where === '/w/ only' ? '' : $hostA,
        'wEvents' => $wOk === true ? $base . '/w/' . $name . '/events.json' : '', 'wPosters' => $wOk === true ? $base . '/w/' . $name . '/posters.json' : '',
        'calendar' => true, 'noticeboard' => true, 'choice' => $to];
    $words  = ($uWords([$after]))[0];
    $colour = preg_match('#<div class="alert alert-([a-z]+)" role="alert">#', $page, $m) === 1 ? $m[1] : '';
    $uTransitions[$label] = [$uFlash($page) === $words['savedChoice'], $colour, $words['savedChoiceType'], $uOwnRow($siteA, 'public.door.testCopies.open')];
}
$db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = {$siteA}");
$setOwn($siteA, 'public.door.testCopies.open', null);
$setInstallation('public.door.testCopies.permitted', 'testers');
$channel = 'prod';
$startServer();
$waitForServer();
check('U28 each change of choice, POSTED in ' . count($uSlots) . ' states, stores the new choice; its message is doorReachWords()\'s for the state AFTER the save; and the colour the HANDLER returned is green where the choice is in force and the door answers somebody, and AMBER otherwise (the door off, a public address only, and the test copy\'s gate off — where the door answers everybody but the choice has no effect)',
    $uTransitions, [
        'testers → anybody, with a public address' => [true, 'success', 'success', 'anybody'],
        'anybody → testers, with a public address' => [true, 'success', 'success', 'testers'],
        'testers → anybody, no public address'     => [true, 'success', 'success', 'anybody'],
        'anybody → testers, no public address'     => [true, 'success', 'success', 'testers'],
        'testers → anybody, the door off'          => [true, 'warning', 'warning', 'anybody'],
        'anybody → testers, a public address only' => [true, 'warning', 'warning', 'testers'],
        'testers → anybody, the gate off'          => [true, 'warning', 'warning', 'anybody'],
    ]);

// =============================================================================
echo "P. The plants: each guard taken out of a COPY, and its scenario re-run\n";
// =============================================================================
$plant = static function (string $relPath, string|array $from, string|array $to, callable $scenario, string $env = 'prod') use ($child, $sep, $startServer, $waitForServer, &$channel): array {
    $path   = $child . $sep . 'web' . $sep . str_replace('/', $sep, $relPath);
    $before = (string) file_get_contents($path);
    $after  = $before;
    foreach ((array) $from as $i => $one) {
        $count = substr_count($after, $one);
        if ($count !== 1) {
            echo "      ! PLANT NOT APPLIED: the text was found {$count} times, not once, in {$relPath}: " . substr(str_replace("\n", '\n', $one), 0, 90) . "\n";
            return ['applied' => false, 'result' => null];
        }
        $after = str_replace($one, ((array) $to)[$i], $after);
    }
    file_put_contents($path, $after);
    $channel = $env;
    $startServer();
    $waitForServer();
    $result = $scenario();
    file_put_contents($path, $before);
    $channel = 'prod';
    $startServer();
    $waitForServer();
    return ['applied' => true, 'result' => $result];
};
$status = static fn (array $r): int => $r['status'];

// The gate's questions.
$p = $plant('_core/PublicDoor.php', "if (PublicWebsiteSetup::doorStateFor(\$db, \$siteId)['state'] !== 'on') {", 'if (false) {',
    static function () use ($db, $siteA, $http, $nameA): int {
        $db->query("DELETE FROM `tblPublicDoorNames` WHERE `siteID` = {$siteA}");
        $s = $http('GET', '/events.json', ['host' => 'door-a.selftest.test'])['status'];
        $db->query("INSERT INTO `tblPublicDoorNames` (`siteID`, `publicName`, `changedAtUtc`) VALUES ({$siteA}, '{$nameA}', UTC_TIMESTAMP())");
        return $s;
    });
check('P01 with the "door on?" question taken out, a door switched off answers on its public address again', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', 'if (AppRegistry::isEnabledForSite($app, $siteId) === false) {', 'if (false) {',
    static function () use ($setOwn, $siteA, $feedA): int {
        $setOwn($siteA, 'calendar.enabled', 'false');
        $s = $feedA()['status'];
        $setOwn($siteA, 'calendar.enabled', null);
        return $s;
    });
check('P02 with the app switch taken out, events answer with Calendar switched off', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', 'if (Maintenance::isActive() === true) {', 'if (false) {',
    static function () use ($setInstallation, $feedA): int {
        $setInstallation('portal.maintenance.active', '1');
        $s = $feedA()['status'];
        $setInstallation('portal.maintenance.active', '0');
        return $s;
    });
check('P03 with the maintenance question taken out, the door answers 200 while the portal is closed', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', "if (\$method !== 'GET' && \$method !== 'HEAD') {", 'if (false) {',
    static fn (): int => $http('POST', '/w/' . $nameA . '/events.json', ['post' => ['x' => '1']])['status']);
check('P04 with the method question taken out, a POST is answered', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', 'return $n <= self::MAX_LIMIT ? $n : null;', 'return $n;',
    static fn (): int => $feedA('events.json?limit=51')['status']);
check('P05 with the ceiling on limit taken out, limit=51 is answered', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', 'if (Site::doorOnPublicHost() === false && PortalAddress::visitorCanChooseHost() === true) {', 'if (false) {',
    static function () use ($setInstallation, $feedA, $base): int {
        $setInstallation('site.url', null);
        $s = $feedA()['status'];
        $setInstallation('site.url', $base);
        return $s;
    });
check('P06 with the "own address stored?" question taken out, /w/ answers when its links would follow the visitor\'s host', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', '$siteId = Site::doorSiteId();', '$siteId = Site::id();',
    static fn (): int => $http('GET', '/w/' . str_repeat('ab', 12) . '/events.json')['status']);
check('P07 reading Site::id() instead of doorSiteId(), an UNKNOWN name falls back to organisation 1 and is served', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicWebsiteSetup.php', 'WHERE n.`publicName` = ? AND s.`isActive` = 1 LIMIT 1', 'WHERE n.`publicName` = ? LIMIT 1',
    static fn (): int => $http('GET', '/w/' . $nameC . '/events.json')['status']);
check('P08 with "active organisations only" taken out of the name look-up, the inactive organisation answers', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/Site.php', "if (\$hostRow['active'] === true && self::doorOrganisationAllowed(", 'if (self::doorOrganisationAllowed(',
    static fn (): int => $http('GET', '/events.json', ['host' => 'door-c.selftest.test'])['status']);
check('P08b with "active organisations only" taken out of the public-address step, the inactive organisation answers on its public address', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/Site.php', "if (\$hostRow !== null && \$hostRow['usable'] === true && \$hostRow['approved'] === true) {", 'if (false) {',
    static fn (): int => $http('GET', '/w/' . $nameB . '/events.json', ['host' => 'door-a.selftest.test'])['status']);
check('P09 if a public address stopped being recognised first, B\'s name on A\'s public address would be served', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/Site.php', "if (\$owner !== null && (int) \$owner['siteID'] !== \$siteId) {", 'if (false) {',
    static function () use ($db, $siteB, $setInstallation, $http, $nameB): int {
        $db->query("UPDATE `tblSites` SET `hostPattern` = 'members-a.selftest.test' WHERE `siteID` = 1");
        $setInstallation('multisite.detectionMode', 'subdomain');
        $s = $http('GET', '/w/' . $nameB . '/events.json', ['host' => 'members-a.selftest.test'])['status'];
        $setInstallation('multisite.detectionMode', 'session');
        $db->query("UPDATE `tblSites` SET `hostPattern` = NULL WHERE `siteID` = 1");
        return $s;
    });
check('P10 with the "another organisation\'s members\' address" question taken out, B\'s name answers on A\'s', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/Site.php', 'return $multi === true || $siteId === 1;', 'return true;',
    static function () use ($setInstallation, $http, $nameB): int {
        $setInstallation('multisite.enabled', 'false');
        $s = $http('GET', '/w/' . $nameB . '/events.json')['status'];
        $setInstallation('multisite.enabled', 'true');
        return $s;
    });
check('P11 with "organisation 1 only when multi-organisation is off" taken out, B\'s name answers', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', 'if (Gatekeeper::mayUseTestCopy($db, self::peekSignedInAccount(), PORTAL_ENV) === false) {', 'if (false) {',
    static fn (): int => $feedA()['status'], 'alpha');
check('P12 with D-087\'s tester question taken out, a test copy\'s door answers anybody', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicWebsiteSetup.php', "return \$own === 'anybody';", 'return true;',
    static function () use ($setInstallation, $feedA): int {
        $setInstallation('public.door.testCopies.permitted', 'either');
        $s = $feedA()['status'];
        $setInstallation('public.door.testCopies.permitted', 'testers');
        return $s;
    }, 'alpha');
check('P13 with D-087\'s reader made to ignore the ORGANISATION\'s choice, a limit of "either" alone opens the test copy\'s door to anybody', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicWebsiteSetup.php', "if (\$limit === null || (string) (\$limit['settingValue'] ?? '') !== 'either') {", 'if (false) {',
    static function () use ($setOwn, $siteA, $feedA): int {
        $setOwn($siteA, 'public.door.testCopies.open', 'anybody');
        $s = $feedA()['status'];
        $setOwn($siteA, 'public.door.testCopies.open', null);
        return $s;
    }, 'alpha');
check('P13b with D-087\'s reader made to ignore the INSTALLATION\'s limit, the organisation\'s "anybody" alone opens the test copy\'s door', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/Gatekeeper.php', "if (is_array(\$row) === false || (int) \$row['isActive'] !== 1) {\n            return false;\n        }",
    "if (is_array(\$row) === false) {\n            return false;\n        }",
    static function () use ($db, $people, $cookieOf, $feedA): int {
        $db->query("UPDATE `tblUsers` SET `isActive` = 0 WHERE `userID` = {$people['tester']['id']}");
        $s = $feedA('events.json', ['cookie' => $cookieOf($people['tester']['jar'])])['status'];
        $db->query("UPDATE `tblUsers` SET `isActive` = 1 WHERE `userID` = {$people['tester']['id']}");
        return $s;
    }, 'alpha');
check('P14 with "an account switched off is nobody" taken out of the test-copy rule, a switched-off tester\'s old session is served', [$p['applied'], $p['result']], [true, 200]);
check('P14b (and with the rule in place, that switched-off tester is refused)', (static function () use ($db, $people, $cookieOf, $feedA, &$channel, $startServer, $waitForServer): int {
    $channel = 'alpha';
    $startServer();
    $waitForServer();
    $db->query("UPDATE `tblUsers` SET `isActive` = 0 WHERE `userID` = {$people['tester']['id']}");
    $s = $feedA('events.json', ['cookie' => $cookieOf($people['tester']['jar'])])['status'];
    $db->query("UPDATE `tblUsers` SET `isActive` = 1 WHERE `userID` = {$people['tester']['id']}");
    $channel = 'prod';
    $startServer();
    $waitForServer();
    return $s;
})(), 404);

// The readers.
$p = $plant('_core/PublicDoor.php', "e.`isDeleted` = 0 AND e.`status` = \\'published\\' ", "1 = 1 AND e.`status` IN (\\'published\\', \\'draft\\', \\'cancelled\\') ",
    static fn (): array => array_values(array_intersect(['PDS A deleted', 'PDS A draft', 'PDS A cancelled'], $titles($feedA('events.json?limit=50'), 'events') ?? [])));
check('P15 with "published and not deleted" taken out, the deleted, draft and cancelled events are given out', [$p['applied'], $p['result']],
    [true, ['PDS A deleted', 'PDS A draft', 'PDS A cancelled']]);
// #882 moved "still listed" into PublicDoor::stillListedWhere(); the default
// (end-of-day) branch is the one in force here.
$p = $plant('_core/PublicDoor.php', "'ELSE ' . \$dayEnds . ' END) >= ' . \$now['sql'],", "'ELSE ' . \$dayEnds . ' END) >= ' . \$now['sql'] . ' - INTERVAL 30 DAY',",
    static fn (): bool => in_array('PDS A finished', $titles($feedA('events.json?limit=50'), 'events') ?? [], true));
check('P16 with "not finished yet" loosened, a finished event is given out', [$p['applied'], $p['result']], [true, true]);
$p = $plant('_core/PublicDoor.php', '$row  = EventVisibility::redact($row, (int) $row[\'canSeeFull\'] === 1);', '$row  = EventVisibility::redact($row, true);',
    static function () use ($setInstallation, $copiedUnder): mixed {
        $setInstallation('public.events.copiedRule', 'calendar');
        $one = $copiedUnder();
        $setInstallation('public.events.copiedRule', 'event');
        return $one['description'] ?? null;
    });
check('P17 with the full-detail rule taken out, a copied-in event\'s withheld description is given out', [$p['applied'], $p['result']], [true, 'PDS COPIED DESCRIPTION']);
$p = $plant('_core/PublicDoor.php', "PublicWebsite::copiedRuleFor(\$db, \$siteId));\n        \$full", "EventVisibility::COPIED_BY_CALENDAR);\n        \$full",
    static fn (): bool => $copiedUnder() !== null);
check('P18 with the copied-in rule in force replaced by the looser one, the calendar-ticked event is given out under "one tick per event"', [$p['applied'], $p['result']], [true, true]);
$p = $plant('_core/PublicDoor.php', "if (\$used === false) {\n            return null;\n        }", "if (false) {\n            return null;\n        }",
    static fn (): int => $feedA('media/' . $upA2)['status']);
check('P19 with "a public poster uses it" taken out, an unticked poster\'s upload is served', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', 'SELECT `mimeType` FROM `tblNoticeboardUploads` WHERE `siteID` = ? AND `storedName` = ? LIMIT 1',
    'SELECT `mimeType` FROM `tblNoticeboardUploads` WHERE `siteID` + 0 * ? >= 0 AND `storedName` = ? LIMIT 1',
    static fn (): int => $feedA('media/' . $upB)['status']);
check('P20 with "this organisation\'s upload" taken out of the media reader, B\'s upload is served through A\'s door (A\'s ticked poster names it)', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', 'if (isset($own[$stored]) === false || self::isMediaName($stored) === false || $mediaBase === \'\') {',
    'if (self::isMediaName($stored) === false || $mediaBase === \'\') {',
    static fn (): mixed => (static function () use ($feedA): mixed {
        foreach (json_decode($feedA('posters.json')['body'], true)['posters'] ?? [] as $one) {
            if ($one['title'] === 'PDS poster naming B upload') {
                return $one['media'];
            }
        }
        return 'missing';
    })());
check('P21 with "this organisation\'s upload" taken out of the posters reader, A\'s feed names B\'s upload', [$p['applied'], $p['result']],
    [true, ['type' => 'image', 'url' => 'https://door-a.selftest.test/media/' . $upB]]);

// The headers.
$hdr = static fn (string $name, string $what = 'events.json', array $opt = []): callable => static fn (): string => $feedA($what, $opt)['headers'][$name] ?? '';
$p = $plant('_core/PublicDoor.php', "header('Content-Type: ' . \$contentType);\n        header('Access-Control-Allow-Origin: *');",
    "header('Content-Type: ' . \$contentType);", $hdr('access-control-allow-origin'));
check('P22 with Access-Control-Allow-Origin taken out, a feed carries none', [$p['applied'], $p['result']], [true, '']);
$p = $plant('_core/PublicDoor.php', "header('Access-Control-Allow-Origin: *');\n        header('Cross-Origin-Resource-Policy: cross-origin');\n        header('Content-Security-Policy: ' . self::DATA_CSP);\n        header('Referrer-Policy: no-referrer');\n        header('X-Content-Type-Options: nosniff');\n        if (\$testersOnly",
    "header('Access-Control-Allow-Origin: *');\n        header('Content-Security-Policy: ' . self::DATA_CSP);\n        header('Referrer-Policy: no-referrer');\n        header('X-Content-Type-Options: nosniff');\n        if (\$testersOnly",
    $hdr('cross-origin-resource-policy', 'media/' . $upA));
check('P23 with Cross-Origin-Resource-Policy taken out, media carries none (start-up\'s same-origin is removed, not left behind)', [$p['applied'], $p['result']], [true, '']);
// #880: with the door's media sent whole again (the code before ByteRange),
// a browser asking for the first two bytes gets the whole file and a 200.
$p = $plant('_core/PublicDoor.php', "            if (ByteRange::send(\$file['path']) === false) {",
    "            header('Content-Length: ' . (string) \$file['size']); readfile(\$file['path']); if (false) {",
    static fn (): array => array_slice($piece($feedA('media/' . $upA, ['range' => 'bytes=0-1'])), 0, 3));
check('P23b with the door\'s media sent whole again, a request for two bytes gets a 200 and no range headers (C14c catches it)',
    [$p['applied'], $p['result']], [true, [200, '', '']]);
$p = $plant('_core/PublicDoor.php', "header('Content-Security-Policy: ' . self::DATA_CSP);\n        header('Referrer-Policy: no-referrer');\n        header('X-Content-Type-Options: nosniff');\n        if (\$testersOnly",
    "header('Referrer-Policy: no-referrer');\n        header('X-Content-Type-Options: nosniff');\n        if (\$testersOnly", $hdr('content-security-policy'));
check('P24 with the content rule taken out, a feed carries none', [$p['applied'], $p['result']], [true, '']);
$p = $plant('_core/PublicDoor.php', "header('Referrer-Policy: no-referrer');\n        header('X-Content-Type-Options: nosniff');\n        if (\$testersOnly",
    "header('X-Content-Type-Options: nosniff');\n        if (\$testersOnly", $hdr('referrer-policy'));
check('P25 with Referrer-Policy taken out, a feed carries none (start-up\'s is removed, not left behind)', [$p['applied'], $p['result']], [true, '']);
$p = $plant('_core/PublicDoor.php', "header('Cache-Control: public, max-age=60');", "header('Cache-Control: public, max-age=86400');", $hdr('cache-control'));
check('P26 with the minute\'s caching changed, a feed says so', [$p['applied'], $p['result']], [true, 'public, max-age=86400']);
$p = $plant('_core/PublicDoor.php', "'noindex, nofollow, noarchive' : 'noindex'));", "'noindex, nofollow, noarchive' : 'all'));", $hdr('x-robots-tag'));
check('P27 with the live robots line changed, a feed says so', [$p['applied'], $p['result']], [true, 'all']);
$p = $plant('_core/PublicDoor.php', "'Set-Cookie', 'X-Frame-Options', 'Cross-Origin-Opener-Policy',", "'Set-Cookie', 'Cross-Origin-Opener-Policy',", $hdr('x-frame-options'));
check('P28 with X-Frame-Options no longer removed, start-up\'s SAMEORIGIN reaches a feed', [$p['applied'], $p['result']], [true, 'SAMEORIGIN']);
$p = $plant('_core/PublicDoor.php', "'Set-Cookie', 'X-Frame-Options', 'Cross-Origin-Opener-Policy',", "'Set-Cookie', 'X-Frame-Options',", $hdr('cross-origin-opener-policy'));
check('P29 with Cross-Origin-Opener-Policy no longer removed, start-up\'s reaches a feed', [$p['applied'], $p['result']], [true, 'same-origin']);
$p = $plant('_core/PublicDoor.php', "header('Cache-Control: private, no-store');", "header('Cache-Control: public, max-age=60');",
    static fn (): string => $feedA('events.json', ['cookie' => $cookieOf($people['tester']['jar'])])['headers']['cache-control'] ?? '', 'alpha');
check('P30 with the tester\'s private caching changed, a test copy\'s answer for one person may be kept for everybody', [$p['applied'], $p['result']], [true, 'public, max-age=60']);
$p = $plant('_core/PublicDoor.php', "header('X-Robots-Tag: ' . (\$testCopy === true ? 'noindex, nofollow, noarchive' : 'noindex'));",
    "header('X-Robots-Tag: noindex');",
    static fn (): string => $feedA('events.json', ['cookie' => $cookieOf($people['tester']['jar'])])['headers']['x-robots-tag'] ?? '', 'alpha');
check('P31 with the test copy\'s robots line taken out, a test copy says only noindex', [$p['applied'], $p['result']], [true, 'noindex']);
$p = $plant('public_html/index.php', ["if (Site::isDoorRequest() === true) {\n    PublicDoor::answer(\$mysqli);\n}", "Auth::ensureSession();\n"],
    ['', "Auth::ensureSession();\nif (Site::isDoorRequest() === true) {\n    PublicDoor::answer(\$mysqli);\n}\n"],
    static function () use ($sessionFiles, $feedA): int {
        $before = $sessionFiles();
        $feedA();
        return $sessionFiles() - $before;
    });
check('P32 with the door answered AFTER the session start, a door request leaves a session file behind', [$p['applied'], ($p['result'] ?? 0) > 0], [true, true]);
$p = $plant('_core/PublicDoor.php', "if (PublicWebsiteSetup::doorStateFor(\$db, \$siteId)['state'] !== 'on') {\n            self::notFound();",
    "if (PublicWebsiteSetup::doorStateFor(\$db, \$siteId)['state'] !== 'on') {\n            http_response_code(404);\n            echo 'door off';\n            exit();",
    static function () use ($db, $siteA, $http, $nameA, $signature, $ref): bool {
        $db->query("DELETE FROM `tblPublicDoorNames` WHERE `siteID` = {$siteA}");
        $s = $signature($http('GET', '/events.json', ['host' => 'door-a.selftest.test'])) === $ref;
        $db->query("INSERT INTO `tblPublicDoorNames` (`siteID`, `publicName`, `changedAtUtc`) VALUES ({$siteA}, '{$nameA}', UTC_TIMESTAMP())");
        return $s;
    });
check('P33 with one refusal given words of its own, the byte-for-byte comparison tells it apart', [$p['applied'], $p['result']], [true, false]);
$faultAt = "\$org    = self::organisationFacts(\$db, \$siteId);\n        \$today  = self::organisationToday(\$siteId);";
$p = $plant('_core/PublicDoor.php', $faultAt, "throw new \\mysqli_sql_exception('planted fault', 2006);\n        " . $faultAt,
    static fn (): bool => $signature($feedA()) === $ref);
check('P34 a planted database fault in the events reader gives the SAME 404 as every other refusal', [$p['applied'], $p['result']], [true, true]);
$p = $plant('_core/PublicDoor.php', [$faultAt, "        } catch (\\Throwable \$e) {\n            error_log('[WebMS-Intra] PublicDoor: a request"],
    ["throw new \\mysqli_sql_exception('planted fault', 2006);\n        " . $faultAt, "        } catch (\\LogicException \$e) {\n            error_log('[WebMS-Intra] PublicDoor: a request"],
    static fn (): int => $feedA()['status']);
check('P35 with the door\'s catch narrowed, the same fault escapes as a 500 — the catch is what turns a fault into the identical 404', [$p['applied'], $p['result']], [true, 500]);
$p = $plant('_core/PublicDoor.php', "'id'             => (string) \$row['eventSlug'],", "'id'             => (string) \$row['eventSlug'], 'eventID' => 1,",
    static function () use ($feedA, $answers, $schemaRun): int {
        $r = $feedA();
        file_put_contents($answers . DIRECTORY_SEPARATOR . 'events-planted.json', $r['body']);
        $out = $schemaRun()['code'];
        @unlink($answers . DIRECTORY_SEPARATOR . 'events-planted.json');
        return $out;
    });
check('P36 with a database number added to each event, the schema check over the captured answer fails', [$p['applied'], $p['result']], [true, 1]);

// Round 1 of P4a's check: the new hand-offs and guards, each planted out.
$p = $plant('public_html/error.php', "if (class_exists('Portal\\\\Core\\\\Site', false) === true && \\Portal\\Core\\Site::isDoorRequest() === true) {",
    'if (false) {', static fn (): bool => $signature($http('GET', '/error.php?code=404', ['host' => 'door-a.selftest.test'])) === $ref);
check('P37 with error.php\'s hand-off taken out, a public address draws something other than the door\'s 404', [$p['applied'], $p['result']], [true, false]);
$p = $plant('public_html/api-docs/index.php', 'if (\\Portal\\Core\\Site::isDoorRequest() === true) {', 'if (false) {',
    static fn (): int => $http('GET', '/api-docs/index.php', ['host' => 'door-a.selftest.test'])['status']);
check('P38 with the API description\'s hand-off taken out, it answers 200 on a public address', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', "if (\$lifetime > 0 && (\$touched === false || \$touched + \$lifetime < time())) {", 'if (false) {',
    static function () use ($ageOut, $feedA, $cookieOf, $people): int {
        $ageOut(true);
        $s = $feedA('events.json', ['cookie' => $cookieOf($people['tester']['jar'])])['status'];
        $ageOut(false);
        return $s;
    }, 'alpha');
check('P39 with the session-lifetime question taken out, an expired tester session is served on a test copy', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', "\$allDay  = 'DATE_ADD(DATE(COALESCE(e.`endDateTime`, e.`startDateTime`)), INTERVAL 1 DAY) - INTERVAL 1 SECOND';",
    "\$allDay  = 'COALESCE(e.`endDateTime`, e.`startDateTime`)';",
    $allDayShown);
check('P40 judging an all-day event by its stored midnight again, it vanishes at the start of its own day', [$p['applied'], $p['result']], [true, []]);

// #882 (D-129) and #898: the old code put back, piece by piece. The expected
// value of each is what the scenario gives UNDER the fault.
$p = $plant('_core/PublicDoor.php', "'ELSE ' . \$dayEnds . ' END) >= ' . \$now['sql'],", "'ELSE e.`startDateTime` END) >= ' . \$now['sql'],", $nListed);
check('PN1 with an event with no end time ending at its START again (the code before #882), every one that has started is gone (N01 catches it)',
    [$p['applied'], $p['result']], [true, []]);
$p = $plant('_core/PublicDoor.php', "WHEN e.`endDateTime` IS NOT NULL THEN e.`endDateTime` '", "'", $nListed);
check('PN2 with the end-of-day rule reaching an event WITH an end time, one that ended a minute ago is kept to midnight (N01 catches it)',
    [$p['applied'], in_array('PDS N ended a minute ago', $p['result'] ?? [], true)], [true, true]);
$nUnder = static function (string $value, callable $then) use ($setOwn, $siteA): mixed {
    $setOwn($siteA, 'public.events.noEndListedFor', $value);
    try {
        return $then();
    } finally {
        $setOwn($siteA, 'public.events.noEndListedFor', null);
    }
};
$p = $plant('_core/PublicDoor.php', '$noEnd  = PublicWebsiteSetup::noEndListingFor($db, $siteId);', '$noEnd  = null;',
    static fn (): mixed => $nUnder('120', $nListed));
check('PN3 with the organisation\'s choice never read, 2 hours chosen still keeps the event that started 150 minutes ago (N05 catches it)',
    [$p['applied'], in_array('PDS N no end 150 minutes ago', $p['result'] ?? [], true)], [true, true]);
$p = $plant('_core/PublicDoor.php', "'OR (e.`isAllDay` <> 1 AND e.`endDateTime` IS NULL AND '", "'OR (e.`isAllDay` <> 1 AND '",
    static fn (): mixed => $nUnder('120', $nListed));
check('PN4 with the fixed time reaching an event WITH an end time, one that ended a minute ago (started 30 minutes ago) is listed again (N05 catches it)',
    [$p['applied'], in_array('PDS N ended a minute ago', $p['result'] ?? [], true)], [true, true]);
$p = $plant('_core/PublicDoor.php', "\$started = Events::startsAtOrAfter(\$db, 'e', \$siteId, \$atUtc - \$noEndMinutes * 60);",
    "\$started = ['sql' => 'DATE_ADD(e.`startDateTime`, INTERVAL ' . \$noEndMinutes . ' MINUTE) >= ' . \$now['sql'], 'types' => \$now['types'], 'params' => \$now['params']];",
    static fn (): mixed => $nUnder('120', static fn (): array => [
        $nClockAt('PDS N clock 23-hour day', ['2027-03-28 02:15:00']), $nClockAt('PDS N clock 25-hour day', ['2026-10-25 01:45:00'])]));
check('PN5 with the fixed time counted on the clock\'s hands (the reading plus 2 hours), the clocks going forward drop it an hour early and going back keep it an hour late (N14 and N15 catch it)',
    [$p['applied'], $p['result']], [true, [['2027-03-28 02:15:00' => false], ['2026-10-25 01:45:00' => true]]]);
$p = $plant('_core/PublicDoor.php', '$noEnd  = PublicWebsiteSetup::noEndListingFor($db, $siteId);', '$noEnd  = PublicWebsiteSetup::noEndListingFor($db, $siteId) ?? 1440;',
    static fn (): array => [$nClockAt('PDS N clock 25-hour day', ['2026-10-25 23:45:00']), $nClockAt('PDS N clock 23-hour day', ['2027-03-28 23:15:00'])]);
check('PN6 with "the end of its day" taken as 24 real hours, the 25-hour day drops it before its midnight and the 23-hour day keeps it past it (N12 and N13 catch it)',
    [$p['applied'], $p['result']], [true, [['2026-10-25 23:45:00' => false], ['2027-03-28 23:15:00' => true]]]);
// #811: the order moved, unchanged, to Events::sortByRealStart(), which the door now asks.
$p = $plant('_core/Events.php', 'usort($keyed, static function (array $a, array $b): int {', 'if (false) usort($keyed, static function (array $a, array $b): int {',
    static fn (): mixed => $nShow($nOrder, static fn (): ?array => $nTitles($feedA('events.json?limit=' . $nLimit))));
check('PN7 (#898) with the order left as the READINGS\' (the code before #898), the limited list gives the later event and drops the sooner (N10 catches it)',
    [$p['applied'], $p['result']], [true, ['PDS N later, earlier reading']]);
// #811: the window moved, unchanged, to Events::soonestByRealStart().
// #1024 round 3: the widening is worked out into `$ahead` first (two days
// when zonesInUse() stopped at its limit), so the plant drops that.
$p = $plant('_core/Events.php', '$latest + $ahead', '$latest',
    static fn (): mixed => $nShow($nOrder, static fn (): ?array => $nTitles($feedA('events.json?limit=' . $nLimit))));
check('PN8 (#898) with the second read\'s window not widened by the clocks\' offsets, the sooner event on the clock furthest ahead is never read (N10 catches it)',
    [$p['applied'], $p['result']], [true, ['PDS N later, earlier reading']]);
$p = $plant('_core/PublicWebsiteSetup.php', 'if ($chosen < self::NO_END_MIN_MINUTES || $chosen > self::NO_END_MAX_MINUTES) {', 'if (false) {',
    static function () use ($card, $nOwnRow, $setOwn, $siteA): ?string {
        $card('root', 'no_end_listing', ['mode' => 'fixed', 'hours' => '0', 'minutes' => '10']);
        $got = $nOwnRow();
        $setOwn($siteA, 'public.events.noEndListedFor', null);
        return $got;
    });
check('PN9 with the card\'s bounds taken out, 10 minutes is stored (N03 catches it)', [$p['applied'], $p['result']], [true, '10']);
$p = $plant('_core/PublicWebsiteSetup.php', 'if ($minutes < self::NO_END_MIN_MINUTES || $minutes > self::NO_END_MAX_MINUTES) {', 'if (false) {',
    static fn (): mixed => $nUnder('5', static fn (): bool => in_array('PDS N no end 90 minutes ago', $nListed() ?? [], true)));
check('PN10 with the stored value no longer checked when read, a hand-edited 5 minutes drops the event that started 90 minutes ago (N16 catches it)',
    [$p['applied'], $p['result']], [true, false]);
$p = $plant('_core/PublicDoor.php', "'(((e.`isAllDay` = 1 OR e.`endDateTime` IS NOT NULL) AND", "'(((e.`endDateTime` IS NOT NULL) AND",
    static fn (): mixed => $nUnder('120', $allDayShown));
check('PN11 with the fixed time\'s rule forgetting all-day events, an all-day event with no end time vanishes while a fixed time is chosen (N06 catches it)',
    [$p['applied'], $p['result']], [true, []]);
// Round 1 of #882's check: three faults that left every check above green.
// (#811 moved `maxOffsetOfZones()`, unchanged, from PublicDoor to Events, so
// PN12 and PN16-PN18 plant it there.)
$nLoop = "                foreach (\$states as \$state) {\n";
$p = $plant('_core/Events.php', "            if (is_array(\$states) === true) {\n" . $nLoop . "                    \$max = max(\$max, (int) \$state['offset']);\n                }\n            }\n",
    '', $nWindows);
check('PN12 (plant A) with the second read\'s window built from each zone\'s CURRENT offset only, the sooner event is never read and the later one is given (N20 and N21 catch it)',
    [$p['applied'], $p['result']], [true, [['PDS N historic New York'], ['PDS N late New York']]]);
// Round 2 of #882's check: three more wrong windows, which the first version
// of N20 (held up by Pago Pago's +12:37 local mean time) let through.
$p = $plant('_core/Events.php', $nLoop, "                foreach (array_slice(\$states, 0, 1) as \$state) {\n", $nWindows);
check('PN16 (V1) with only the FIRST entry of each zone\'s history read (its local mean time), both sooner events are missed (N20 and N21 catch it)',
    [$p['applied'], $p['result']], [true, [['PDS N historic New York'], ['PDS N late New York']]]);
$p = $plant('_core/Events.php', $nLoop, "                foreach (array_slice(\$states, 0, intdiv(count(\$states), 2)) as \$state) {\n", $nWindows);
check('PN17 (V3) with only the FIRST HALF of each zone\'s history read: London\'s 1941 +2 is in its first half, so N20 rightly still passes; the Azores\' 1993 +1 is in its second half, so N21 catches it',
    [$p['applied'], $p['result']], [true, [['PDS N historic London'], ['PDS N late New York']]]);
$p = $plant('_core/Events.php', '            $states = $zone->getTransitions();',
    "            \$states = \$zone->getName() === 'Europe/London' ? false : \$zone->getTransitions();", $nWindows);
check('PN18 (V5) with London\'s history ignored (its offset today only), N20 catches it; N21, with no London in it, is rightly unaffected',
    [$p['applied'], $p['result']], [true, [['PDS N historic New York'], ['PDS N late Azores']]]);
$p = $plant('_core/PublicDoor.php', "\$basis = \$allDay === true && \$row['endDateTime'] !== null ? I18n::wallClockReading((string) \$row['endDateTime']) : \$start;",
    '$basis = $start;', $nUntil);
check('PN13 (plant B) with an all-day event\'s listedUntil taken from its FIRST day, the three-day event leaves the list two days early (N17 catches it)',
    [$p['applied'], $p['result']['PDS N all day, three days'][0] ?? null], [true, $nUntilWant['PDS N all day, one day'][0]]);
$p = $plant('_core/PublicDoor.php', "if (\$allDay === false && \$row['endDateTime'] !== null) {", 'if (false) {', $nUntil);
check('PN14 (plant C) with an event WITH an end time given midnight instead of its end, listedUntil is no longer its end (N17 catches it)',
    [$p['applied'], ($p['result']['PDS N with an end time'][0] ?? null) === $nEndAt], [true, false]);
$p = $plant('_core/PublicWebsiteSetup.php', "            return ['minutes' => null, 'own' => \$own !== null, 'ignored' => true];",
    "            error_log('planted: a bad stored value'); return ['minutes' => null, 'own' => \$own !== null, 'ignored' => true];",
    static fn (): mixed => $nUnder('5', $nScenario16));
check('PN15 with a log line written for a bad stored value again, every public request writes one (N16 catches it)',
    [$p['applied'], ($p['result'][1] ?? 0) > 0], [true, true]);
$p = $plant('_core/PublicDoor.php', '| JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT', '',
    static fn (): bool => str_contains($feedA('events.json?limit=50')['body'], '</script>'));
check('P41 with the JSON_HEX_ flags taken out, a live </script> reaches the answer', [$p['applied'], $p['result']], [true, true]);
$p = $plant('_core/Site.php', "if (\$isW === false && \$multi === true && \$mode === self::MODE_PATH", "if (false && \$isW === false && \$multi === true && \$mode === self::MODE_PATH",
    static function () use ($setInstallation, $http, $nameB, $sessionFiles): int {
        $setInstallation('multisite.detectionMode', 'path');
        $before = $sessionFiles();
        $http('GET', '/selftest-door-b/w/' . $nameB . '/events.json');
        $setInstallation('multisite.detectionMode', 'session');
        return $sessionFiles() - $before;
    });
check('P42 with the prefixed /w/ form left to the router, the refusal comes after the session start and leaves a session file', [$p['applied'], ($p['result'] ?? 0) > 0], [true, true]);
$hostFault = ["SELECT h.`siteID`, h.`hostName`, h.`approvedAtUtc`, s.`isActive` FROM", "SELECT h.`siteID`, h.`hostNameX`, h.`approvedAtUtc`, s.`isActive` FROM"];
// (An unknown column is thrown, not swallowed: publicHostRow() treats error 1054 as
// "no public address" ONLY when the approval column itself is missing.)
$failureState = static function () use ($http, $feedA, $sessionFiles): array {
    $before = $sessionFiles();
    $out    = [];
    // Round 3 of P4a's check: a request carrying a STALE session cookie made
    // an empty session file in this state (Site::preDetect()'s session peek),
    // so the last two carry one, on the members' address and a public one.
    $stale = 'PHPSESSID=' . str_repeat('k', 32);
    foreach ([['/login', 'door-a.selftest.test', null], ['/api-docs/index.php', 'door-b.selftest.test', null],
        ['/api-docs/index.php', 'door-c.selftest.test', null], ['/error.php?code=404', 'door-b.selftest.test', null], ['/login', null, null],
        ['/login', null, $stale], ['/error.php?code=404', 'door-a.selftest.test', $stale]] as [$path, $host, $cookie]) {
        $opt = $host === null ? [] : ['host' => $host];
        if ($cookie !== null) {
            $opt['cookie'] = $cookie;
        }
        $r = $http('GET', $path, $opt);
        $out[] = [$r['status'], $r['body'], $r['headers']['set-cookie'] ?? ''];
    }
    return ['answers' => $out, 'door' => $feedA()['status'], 'session files made' => $sessionFiles() - $before];
};
$tryAgain = [503, "Please try again in a moment.\n", ''];
$p = $plant('_core/PublicWebsiteSetup.php', $hostFault[0], $hostFault[1], $failureState);
check('P43 with the public-address look-up FAILING (a planted database fault), EVERY way in — index.php on a public and on the members\' address, the API description on B\'s and switched-off C\'s address, error.php — gives the same plain "try again": no page, no organisation named, no cookie, and no session file even for a request carrying a stale session cookie; and the door refuses',
    [$p['applied'], $p['result']], [true, ['answers' => [$tryAgain, $tryAgain, $tryAgain, $tryAgain, $tryAgain, $tryAgain, $tryAgain],
        'door' => 404, 'session files made' => 0]]);
/** Several edits across files at once, each found exactly once, then put back byte for byte. */
$plantMany = static function (array $edits, callable $scenario) use ($child, $sep, $startServer, $waitForServer): array {
    $was = [];
    foreach ($edits as [$rel, $from, $to]) {
        $path = $child . $sep . 'web' . $sep . str_replace('/', $sep, $rel);
        $text = $was[$path] ?? (string) file_get_contents($path);
        $was[$path] ??= $text;
        if (substr_count($text, $from) !== 1) {
            echo "      ! PLANT NOT APPLIED: the text was found " . substr_count($text, $from) . " times in {$rel}\n";
            foreach ($was as $p => $t) {
                file_put_contents($p, $t);
            }
            return ['applied' => false, 'result' => null];
        }
        file_put_contents($path, str_replace($from, $to, $text));
    }
    $startServer();
    $waitForServer();
    $result = $scenario();
    foreach ($was as $p => $t) {
        file_put_contents($p, $t);
    }
    $startServer();
    $waitForServer();
    return ['applied' => true, 'result' => $result];
};
$faultEdit = ['_core/PublicWebsiteSetup.php', $hostFault[0], $hostFault[1]];
$p = $plantMany([$faultEdit, ['public_html/index.php', "if (Site::publicAddressUnknown() === true) {\n    PublicDoor::tryAgain();\n}\n", '']],
    static function () use ($http, $sessionFiles): int {
        $before = $sessionFiles();
        $http('GET', '/login', ['host' => 'door-a.selftest.test']);
        return $sessionFiles() - $before;
    });
check('P46 with index.php\'s failure-state answer taken out, the same fault leaves a session file on what may be a public address', [$p['applied'], ($p['result'] ?? 0) > 0], [true, true]);
$p = $plantMany([$faultEdit, ['public_html/error.php', "&& \\Portal\\Core\\Site::publicAddressUnknown() === true) {", '&& false) {']],
    static function () use ($http, $sessionFiles): int {
        $before = $sessionFiles();
        $http('GET', '/error.php?code=404', ['host' => 'door-b.selftest.test']);
        return $sessionFiles() - $before;
    });
check('P47 with error.php\'s failure-state answer taken out, the same fault leaves a session file', [$p['applied'], ($p['result'] ?? 0) > 0], [true, true]);
$p = $plantMany([$faultEdit, ['public_html/api-docs/index.php', 'if (\\Portal\\Core\\Site::publicAddressUnknown() === true) {', 'if (false) {']],
    static fn (): int => $http('GET', '/api-docs/index.php', ['host' => 'door-c.selftest.test'])['status']);
check('P48 with the API description\'s failure-state answer taken out, the same fault draws it (200) on switched-off C\'s address', [$p['applied'], $p['result']], [true, 200]);
$p = $plantMany([$faultEdit, ['_core/Site.php', "        if (\$doorLookupFailed === true) {\n            if (\$multi !== null) {", "        if (false) {\n            if (\$multi !== null) {"]],
    static function () use ($http, $sessionFiles): int {
        $before = $sessionFiles();
        $http('GET', '/login', ['cookie' => 'PHPSESSID=' . str_repeat('m', 32)]);
        return $sessionFiles() - $before;
    });
check('P49 with Site::preDetect() no longer stopping before its session peek in the failure state, a stale session cookie makes a session file', [$p['applied'], ($p['result'] ?? 0) > 0], [true, true]);
$p = $plant('_core/PublicWebsiteSetup.php', $hostFault[0], $hostFault[1], static function () use ($sweepWebRoot, $entryPoints): array {
    $sweep = $sweepWebRoot('door-b.selftest.test');
    $entry = [];
    foreach ($entryPoints as $path) {
        $entry[] = array_values(array_unique(array_values($sweep['statuses'][$path] ?? [])));
    }
    return ['bad' => $sweep['bad'], 'entry points' => $entry];
});
check('FS01 in the FAILURE state (the P43 fault) every web-root PHP file, run directly on B\'s public address in all 8 shapes, sets no cookie, makes no session file and names nobody; the three entry points answer only the plain 503',
    [$p['applied'], $p['result']], [true, ['bad' => [], 'entry points' => [[503], [503], [503]]]]);
$p = (static function () use ($child, $sep, $hostFault, $startServer, $waitForServer, $http): array {
    $site  = $child . $sep . 'web' . $sep . '_core' . $sep . 'Site.php';
    $setup = $child . $sep . 'web' . $sep . '_core' . $sep . 'PublicWebsiteSetup.php';
    $was   = [file_get_contents($site), file_get_contents($setup)];
    $one   = "\$doorLookupFailed = \$door === 'failed';";
    if (substr_count($was[0], $one) !== 1 || substr_count($was[1], $hostFault[0]) !== 1) {
        echo "      ! PLANT NOT APPLIED: P44's text was not found exactly once\n";
        return ['applied' => false, 'result' => null];
    }
    file_put_contents($site, str_replace($one, "\$doorLookupFailed = false;", $was[0]));
    file_put_contents($setup, str_replace($hostFault[0], $hostFault[1], $was[1]));
    $startServer();
    $waitForServer();
    $r = $http('GET', '/login', ['host' => 'door-a.selftest.test']);
    file_put_contents($site, $was[0]);
    file_put_contents($setup, $was[1]);
    $startServer();
    $waitForServer();
    return ['applied' => true, 'result' => [$r['status'], str_contains($r['body'], 'name="password"')]];
})();
check('P44 with the fail-closed rule taken out as well, the same fault puts the sign-in page on a public address', [$p['applied'], $p['result']], [true, [200, true]]);

$p = $plant('_core/PublicWebsite.php', "self::FRAME_PERMITTED_KEY,\n        self::DOOR_TEST_PERMITTED_KEY, self::DOOR_TEST_OPEN_KEY, self::NO_END_LISTING_KEY];", 'self::FRAME_PERMITTED_KEY, self::NO_END_LISTING_KEY];',
    static fn (): array => array_map(static fn (array $one): bool => $one[0], (array) $askLocks()));
check('P45 with D-087\'s two settings taken out of the editor lock, the general settings editor would store them', [$p['applied'], $p['result']],
    [true, ['public.door.testCopies.permitted' => false, 'public.door.testCopies.open' => false]]);

// D-101's guards, each planted out.
$p = $plant('_core/Site.php', "if (\$hostRow !== null && \$hostRow['usable'] === true && \$hostRow['approved'] === true) {",
    "if (\$hostRow !== null && \$hostRow['usable'] === true) {",
    static function () use ($db, $siteA, $http): int {
        $db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = NULL WHERE `siteID` = {$siteA}");
        $s = $http('GET', '/events.json', ['host' => 'door-a.selftest.test'])['status'];
        $db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = {$siteA}");
        return $s;
    });
check('P57 with "approved" taken out of the door step, an UNAPPROVED public address is the door', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicWebsiteSetup.php', "`changedAtUtc` = UTC_TIMESTAMP(), '\n                        . '`approvedAtUtc` = NULL, `approvedByID` = NULL WHERE `siteID` = ?')",
    "`changedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = ?')",
    static function () use ($cardAs, $approvedAt, $siteA, $hostA): mixed {
        $cardAs('setup', 'public_host', ['value' => 'changed.selftest.test']);
        $after = $approvedAt($siteA);
        $cardAs('setup', 'public_host', ['value' => $hostA]);
        return $after !== null;
    });
check('P58 with "saving clears approval" taken out, a CHANGED address keeps the old approval', [$p['applied'], $p['result']], [true, true]);
$p = $plant('_core/PublicWebsiteSetup.php', "        if (\$isRootAdmin === false) {\n            return self::approvalRefused(\$siteId, 'Only a global administrator of the whole '\n                . 'installation can approve",
    "        if (false) {\n            return self::approvalRefused(\$siteId, 'Only a global administrator of the whole '\n                . 'installation can approve",
    static function () use ($db, $siteA, $cardAs, $hostRow, $hostA): mixed {
        $db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = NULL WHERE `siteID` = {$siteA}");
        $cardAs('setup', 'public_host_approve', ['site' => '1', 'host' => $hostA]);
        $approved = ($hostRow($siteA)['approvedAtUtc'] ?? null) !== null;
        $db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = {$siteA}");
        return $approved;
    });
check('P59 with "the installation\'s global administrator only" taken out of approving, a "Set up the public website" holder approves their own address', [$p['applied'], $p['result']], [true, true]);
$p = $plant('_core/PublicWebsiteSetup.php', "                if (PortalAddress::visitorCanChooseHost() === true) {\n                    return self::refusedAndRecorded('public address'",
    "                if (false) {\n                    return self::refusedAndRecorded('public address'",
    static function () use ($setInstallation, $base, $cardAs, $hostRow, $siteA, $hostA): mixed {
        $setInstallation('site.url', null);
        $cardAs('setup', 'public_host', ['value' => 'another.selftest.test']);
        $setInstallation('site.url', $base);
        $saved = $hostRow($siteA)['hostName'] ?? null;
        $cardAs('setup', 'public_host', ['value' => $hostA]);
        return $saved;
    });
check('P60 with "the portal\'s own address must be set" taken out, a public address is saved while the portal cannot tell members\' addresses', [$p['applied'], $p['result']], [true, 'another.selftest.test']);
// Round 7 of P4a's check: each guard above that was pinned by nothing.
$p = $plant('_core/PublicWebsiteSetup.php', "'VALUES (?, ?, UTC_TIMESTAMP(), NULL, NULL)'", "'VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL)'", $scnFirstAddress);
check('P61 with an organisation\'s FIRST address stored approved, it is the door the moment it is saved', [$p['applied'], is_string($p['result'][0] ?? null) && $p['result'][0] !== 'no row', $p['result'][1] ?? null], [true, true, true]);
$p = $plant('_core/PublicWebsiteSetup.php', "        if (\$isRootAdmin === false) {\n            return self::approvalRefused(\$siteId, 'Only a global administrator of the whole '\n                . 'installation can withdraw",
    "        if (false) {\n            return self::approvalRefused(\$siteId, 'Only a global administrator of the whole '\n                . 'installation can withdraw",
    $scnWithdrawRefused);
check('P62 with "the installation\'s global administrator only" taken out of withdrawing, A\'s holder withdraws A\'s approval AND B\'s', [$p['applied'], $p['result'][0] ?? null, $p['result'][1] ?? null], [true, false, false]);
$p = $plant('_core/PublicDoor.php', '$host = PublicWebsiteSetup::publicHostInEffect($db, $siteId);', '$host = PublicWebsiteSetup::publicHostFor($db, $siteId);', $scnUnapprovedNotShown);
check('P63 with addressFor() back on any stored address, the card publishes an UNAPPROVED address as the door\'s', [$p['applied'], $p['result'][0] ?? null], [true, true]);
$p = $plant('_apps/admin/public-website/index.php', "<?php if (\$publicHostInEffect !== ''): ?>\n                        <?php // Round 1 of P4a's check",
    "<?php if (\$publicHost !== ''): ?>\n                        <?php // Round 1 of P4a's check", $scnUnapprovedNotShown);
check('P64 with the card\'s "Replace" advice back on any stored address, it tells an organisation an UNAPPROVED address is unaffected', [$p['applied'], $p['result'][2] ?? null], [true, true]);
$p = $plant('_apps/admin/public-website/save.php', "PublicWebsiteSetup::publicHostInEffect(\$mysqli, \$siteId) !== ''", "PublicWebsiteSetup::publicHostFor(\$mysqli, \$siteId) !== ''", $scnUnapprovedNotShown);
check('P65 with the missing-tick refusal back on any stored address, it disagrees with the card about an UNAPPROVED address', [$p['applied'], $p['result'][3] ?? null], [true, false]);
$p = $plant('_core/PublicDoor.php', "|| PublicWebsiteSetup::publicHostInEffect(\$db, \$siteId) !== '') {", "|| PublicWebsiteSetup::publicHostFor(\$db, \$siteId) !== '') {", $scnUnapprovedNotShown);
check('P66 with whyNoAddress() back on any stored address, the card cannot say why the door has no address', [$p['applied'], $p['result'][4] ?? null], [true, false]);
$p = $plant('_core/PublicWebsiteSetup.php', "could not be listed: ' . \$e->getMessage());\n\n            return null;", "could not be listed: ' . \$e->getMessage());\n\n            return [];", $scnListUnreadable);
check('P67 with an unreadable list answered as an empty one, the installation\'s administrator is told no organisation has entered an address', [$p['applied'], $p['result']], [true, [false, true]]);
$p = $plant('_core/PublicWebsiteSetup.php', "        if (PortalAddress::visitorCanChooseHost() === true) {\n            return self::approvalRefused(\$siteId",
    "        if (false) {\n            return self::approvalRefused(\$siteId", $scnApproveNoSiteUrl);
check('P68 with "the portal\'s own address must be set" taken out of approving, an address is approved while nobody can tell members\' addresses', [$p['applied'], $p['result'][0] ?? null], [true, true]);
// Round 8 of P4a's check: the two publicHostInEffect() callers nothing pinned.
$p = $plant('_core/PublicWebsiteSetup.php', "        \$publicHost = self::publicHostInEffect(\$db, \$siteId);\n        Logger::activity('PublicDoorNameReplaced',",
    "        \$publicHost = self::publicHostFor(\$db, \$siteId);\n        Logger::activity('PublicDoorNameReplaced',", $scnReplaceWords);
check('P69 with "Replace the name" back on any stored address, it tells an organisation a WAITING address and a members\' address are not affected, and records that',
    [$p['applied'], $p['result']['waiting'] ?? null, $p['result']['a members\' address'] ?? null], [true, [true, true], [true, true]]);
$p = $plant('_core/PublicWebsiteSetup.php', "self::HOST_MEMBERS   => ['label' => 'Public address', 'state' => 'problem'",
    "'not-a-status'       => ['label' => 'Public address', 'state' => 'problem'", $scnCheckRow);
check('P70 with the set-up check\'s "a members\' address" row taken out, it no longer says the door does not answer on such an address',
    [$p['applied'], ($p['result']['a members\' address'] ?? null) === $checkRowWant['a members\' address']], [true, false]);
// Round 9 of P4a's check: the WAITING row was pinned by nothing (the card prints
// the same words). Two plants on it: an "ok" row claiming the address is ready,
// and a mere rewording; D18 must see both.
$waitingArm = "default              => ['label' => 'Public address', 'state' => 'problem', 'words' => 'Waiting for the '\n"
    . "                    . 'installation\\'s administrator to approve it. Until then it does nothing.'],";
$p = $plant('_core/PublicWebsiteSetup.php', $waitingArm,
    "default              => ['label' => 'Public address', 'state' => 'ok', 'words' => 'Your public address is set and ready.'],", $scnCheckRow);
check('P71 with the set-up check\'s waiting row turned into "ok — set and ready", a waiting address reads as ready',
    [$p['applied'], ($p['result']['waiting'] ?? null) === $checkRowWant['waiting']], [true, false]);
$p = $plant('_core/PublicWebsiteSetup.php', $waitingArm,
    "default              => ['label' => 'Public address', 'state' => 'problem', 'words' => 'Approval pending.'],", $scnCheckRow);
check('P72 with the set-up check\'s waiting row merely reworded ("Approval pending."), it no longer says the address does nothing until approved',
    [$p['applied'], ($p['result']['waiting'] ?? null) === $checkRowWant['waiting']], [true, false]);
// P58 and P60 put A's own address back, which (rightly) leaves it waiting:
// approve it again directly so the tidy-up starts from the state it expects.
$db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = {$siteA}");

// ── #493 P4b: the script's own guards, and the deleted widget ───────────────
// Each guard taken out of the copy (or, for the widget, the deleted thing put
// back into it), and the check that must notice re-run. As above, the expected
// value is the value UNDER the fault.
$scriptAppGate = "            if (AppRegistry::isEnabledForSite('calendar', \$siteId) === false\n"
    . "                && AppRegistry::isEnabledForSite('noticeboard', \$siteId) === false) {";
$p = $plant('_core/PublicDoor.php', $scriptAppGate, '            if (false) {',
    static function () use ($setOwn, $siteA, $feedA): int {
        $setOwn($siteA, 'calendar.enabled', 'false');
        $setOwn($siteA, 'noticeboard.enabled', 'false');
        $s = $feedA('door.js')['status'];
        $setOwn($siteA, 'calendar.enabled', null);
        $setOwn($siteA, 'noticeboard.enabled', null);
        return $s;
    });
check('PJ1 with the script\'s "at least one app" question taken out, the script is served with BOTH apps off (J05 catches it)', [$p['applied'], $p['result']], [true, 200]);
$scriptSend = "self::sendDataHeaders('text/javascript; charset=utf-8', \$testCopy, \$testersOnly);";
$p = $plant('_core/PublicDoor.php', $scriptSend, "self::sendDataHeaders('text/plain; charset=utf-8', \$testCopy, \$testersOnly);",
    static fn (): string => $feedA('door.js')['headers']['content-type'] ?? '');
check('PJ2 with the script sent as plain text, a browser honouring nosniff would refuse to run it (J01 catches it)', [$p['applied'], $p['result']], [true, 'text/plain; charset=utf-8']);
$p = $plant('_core/PublicDoor.php', $scriptSend, "header('Content-Type: text/javascript; charset=utf-8');",
    static fn (): bool => $scriptHeaders($feedA('door.js')) === $scriptHeadersWant);
check('PJ3 with the script sent WITHOUT the door\'s data headers (start-up\'s X-Frame-Options and COOP left, no CORS, no caching rule), J02 notices', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_core/PublicDoor.php', 'if (Gatekeeper::mayUseTestCopy($db, self::peekSignedInAccount(), PORTAL_ENV) === false) {', 'if (false) {',
    static fn (): int => $feedA('door.js')['status'], 'alpha');
check('PJ4 with D-087\'s tester question taken out, a test copy serves the script to anybody (T10 catches it)', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('public_html/index.php', ["if (Site::isDoorRequest() === true) {\n    PublicDoor::answer(\$mysqli);\n}", "Auth::ensureSession();\n"],
    ['', "Auth::ensureSession();\nif (Site::isDoorRequest() === true) {\n    PublicDoor::answer(\$mysqli);\n}\n"],
    static function () use ($sessionFiles, $feedA): array {
        $before = $sessionFiles();
        $r = $feedA('door.js');
        return [$sessionFiles() - $before > 0, ($r['headers']['set-cookie'] ?? '') !== ''];
    });
// MEASURED, not assumed: the session file IS made, but the door's own header
// clean-up (REMOVED_HEADERS) still strips the Set-Cookie it would carry — so
// J04's session-file count is the check that catches this, NOT J02. The first
// version of this plant claimed both and went red on that claim.
check('PJ5 with the door answered AFTER the session start, a request for the script leaves a session file behind (J04 catches it; the door still strips the cookie header, so J02 alone would not)',
    [$p['applied'], $p['result']], [true, [true, false]]);
// The widget: the deleted things put BACK into the copy, one at a time, to
// prove W01 can tell them apart from "gone".
$oldPage = $child . $sep . 'web' . $sep . '_apps' . $sep . 'widget' . $sep . 'countdown-preview.php';
@mkdir(dirname($oldPage), 0700, true);
file_put_contents($oldPage, "<?php\necho 'PLANTED old countdown embed page';\n");
$db->query("INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES ('widget/countdown', 'widget/countdown-preview.php', 0)");
$wRoute = $scnWidgetGone();
$db->query("DELETE FROM `tblRoutes` WHERE `routeKey` = 'widget/countdown' AND `targetFile` = 'widget/countdown-preview.php'");
@unlink($oldPage);
@rmdir(dirname($oldPage));
check('PJ6 with the old embed page\'s route and file put back, W01 sees /widget/countdown answer and the route row', [$wRoute['/widget/countdown'], $wRoute['route rows']],
    ['200 PLANTED old countdown embed page', 1]);
$oldScript = $child . $sep . 'web' . $sep . 'public_html' . $sep . 'widget' . $sep . 'countdown.js';
@mkdir(dirname($oldScript), 0700, true);
file_put_contents($oldScript, "/* PLANTED old countdown.js */\n");
$wFolder = $scnWidgetGone();
@unlink($oldScript);
@rmdir(dirname($oldScript));
check('PJ7 with the web root\'s widget folder put back, W01 sees /widget/countdown.js served from disk again', $wFolder['/widget/countdown.js'], "200 /* PLANTED old countdown.js */\n");
check('PJ8 with both put away again, W01\'s answer is "gone" once more', $scnWidgetGone(), $widgetGoneWant);
// Round 1 of P4b's check: the "door on?" question exempting the script. On a
// public address that question is the ONLY guard (switching off deletes the
// name row, so /w/{name}/ is refused for another reason).
$p = $plant('_core/PublicDoor.php', "if (PublicWebsiteSetup::doorStateFor(\$db, \$siteId)['state'] !== 'on') {",
    "if (\$surface !== self::SURFACE_SCRIPT && PublicWebsiteSetup::doorStateFor(\$db, \$siteId)['state'] !== 'on') {",
    static function () use ($db, $siteA, $http, $hostA): int {
        $was = $db->query("SELECT `publicName` FROM `tblPublicDoorNames` WHERE `siteID` = {$siteA}")->fetch_assoc();
        $db->query("DELETE FROM `tblPublicDoorNames` WHERE `siteID` = {$siteA}");
        $s = $http('GET', '/door.js', ['host' => $hostA])['status'];
        if ($was !== null) {
            $db->query("INSERT INTO `tblPublicDoorNames` (`siteID`, `publicName`, `changedAtUtc`) VALUES ({$siteA}, '{$was['publicName']}', UTC_TIMESTAMP())");
        }
        return $s;
    });
check('PJ9 with the script let past the "door on?" question, a switched-off door serves it on its approved public address (J09 catches it)', [$p['applied'], $p['result']], [true, 200]);
// #777: the reading to SHOW taken from the moved instant instead of the store.
$p = $plant('_core/PublicDoor.php', "        return \$m[1] . 'T' . \$m[2];", "        return substr((string) self::instantText(\$reading, \$zone), 0, 19);",
    static function () use ($makeEvent, $siteA, $jGapDay, $feedA, $db): ?string {
        $id  = $makeEvent('PDS A in the missing hour', $siteA, 'Europe/London', $jGapDay . ' 01:30:00', $jGapDay . ' 03:00:00');
        $got = null;
        foreach (json_decode($feedA('events.json?limit=50')['body'], true)['events'] ?? [] as $one) {
            if ($one['title'] === 'PDS A in the missing hour') {
                $got = $one['startsOnClock'];
            }
        }
        $db->query("DELETE FROM `tblEvents` WHERE `eventID` = {$id}");
        return $got;
    });
check('PJ10 with the reading to show taken from the instant, the missing-hour event reaches websites as 02:30 (J10 catches it)', [$p['applied'], $p['result']], [true, $jGapDay . 'T02:30:00']);
// Round 2 of P4b's check: the reading to show withheld for any time at 00:00
// (the checker's own plant) — J11's rule, not construction, must catch it.
$p = $plant('_core/PublicDoor.php', "        return \$m[1] . 'T' . \$m[2];", "        return str_starts_with(\$m[2], '00:') ? null : \$m[1] . 'T' . \$m[2];",
    static fn (): array => $scnClockFields());
check('PJ11 with startsOnClock withheld for any reading at 00:00, J11 sees it null beside a real instant (and its premise still holds, so it bit on a real 00:00 event)',
    [$p['applied'], $p['result']['premise'] ?? null, ($p['result']['problems'] ?? []) !== []], [true, true, true]);

// ── #493 P4c: the framed page's own guards ──────────────────────────────────
// Each taken out of the copy and the check that must notice re-run; the
// expected value is the value UNDER the fault. The renderer's own plants
// (VP02-VP16) need no web server and are in section V.
$p = $plant('_core/PublicDoor.php', "if (\$show !== 'posters' && AppRegistry::isEnabledForSite('calendar', \$siteId) === false) {", 'if (false) {',
    static function () use ($setOwn, $siteA, $pageA): int {
        $setOwn($siteA, 'calendar.enabled', 'false');
        $s = $pageA('?show=events')['status'];
        $setOwn($siteA, 'calendar.enabled', null);
        return $s;
    });
check('PV01 with the page\'s Calendar question taken out, its events are drawn with Calendar switched off (V16 catches it)', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', "if (\$show !== 'events' && AppRegistry::isEnabledForSite('noticeboard', \$siteId) === false) {", 'if (false) {',
    static function () use ($setOwn, $siteA, $pageA): int {
        $setOwn($siteA, 'noticeboard.enabled', 'false');
        $s = $pageA('?show=posters')['status'];
        $setOwn($siteA, 'noticeboard.enabled', null);
        return $s;
    });
check('PV02 with the page\'s Noticeboard question taken out, its posters are drawn with the Noticeboard switched off (V16 catches it)', [$p['applied'], $p['result']], [true, 200]);
$pageSend = 'self::sendPageHeaders(PublicWebsiteSetup::frameAncestorsFor($db, $siteId), $testCopy, $testersOnly);';
$p = $plant('_core/PublicDoor.php', $pageSend,
    'self::sendPageHeaders(PublicWebsiteSetup::frameAncestorsValue(PublicWebsiteSetup::frameOriginsStored($db, $siteId)), $testCopy, $testersOnly);',
    static function () use ($setOwn, $setInstallation, $siteA, $pageA): bool {
        $setOwn($siteA, 'public.frame.origins', 'https://www.selftest.invalid');
        $setInstallation('public.frame.permitted', 'no');
        $csp = $pageA()['headers']['content-security-policy'] ?? '';
        $setInstallation('public.frame.permitted', 'yes');
        $setOwn($siteA, 'public.frame.origins', null);
        return str_contains($csp, 'frame-ancestors https://www.selftest.invalid;');
    });
check('PV03 with frame-ancestors built from the stored list rather than frameAncestorsFor(), the installation\'s "no" is ignored and the listed website may frame the page (V12 catches it)',
    [$p['applied'], $p['result']], [true, true]);
$p = $plant('_core/PublicDoor.php', $pageSend, "self::sendPageHeaders(\"'none'\", \$testCopy, \$testersOnly);",
    static function () use ($setOwn, $siteA, $pageA): bool {
        $setOwn($siteA, 'public.frame.origins', 'https://www.selftest.invalid');
        $csp = $pageA()['headers']['content-security-policy'] ?? '';
        $setOwn($siteA, 'public.frame.origins', null);
        return str_contains($csp, "frame-ancestors 'none';");
    });
check('PV04 with frame-ancestors fixed at \'none\' rather than asked of frameAncestorsFor(), a listed website is refused too (V10 catches it)', [$p['applied'], $p['result']], [true, true]);
$p = $plant('_core/PublicDoor.php', "if (\$frameAncestors === \"'none'\") {", 'if (true) {',
    static function () use ($setOwn, $siteA, $pageA): string {
        $setOwn($siteA, 'public.frame.origins', 'https://www.selftest.invalid');
        $x = $pageA()['headers']['x-frame-options'] ?? '';
        $setOwn($siteA, 'public.frame.origins', null);
        return $x;
    });
check('PV05 with X-Frame-Options DENY sent whatever is listed, a browser honouring it would refuse the listed website (V10 catches it)', [$p['applied'], $p['result']], [true, 'DENY']);
$p = $plant('_core/PublicDoor.php', "if (\$frameAncestors === \"'none'\") {", 'if (false) {',
    static fn (): string => $pageA()['headers']['x-frame-options'] ?? '');
check('PV06 with X-Frame-Options never sent, a browser too old for frame-ancestors would let ANY website frame a page that lists none (V09 catches it)', [$p['applied'], $p['result']], [true, '']);
$p = $plant('_core/PublicDoor.php', "base64_encode(hash('sha256', self::PAGE_CSS, true))", "base64_encode(hash('sha256', '', true))",
    static function () use ($pageA, $pageCspFor, $pageFacts): bool {
        $r = $pageA();
        return ($r['headers']['content-security-policy'] ?? '') === $pageCspFor($pageFacts($r['body']), "'none'");
    });
check('PV07 with the stylesheet\'s hash worked out from anything but the stylesheet sent, V09 sees the content rule no longer allow it', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_core/PublicDoor.php', "'sandbox allow-popups allow-popups-to-escape-sandbox'", "'upgrade-insecure-requests'",
    static function () use ($pageA, $pageCspFor, $pageFacts): bool {
        $r = $pageA();
        return ($r['headers']['content-security-policy'] ?? '') === $pageCspFor($pageFacts($r['body']), "'none'");
    });
check('PV08 with the sandbox taken out of the content rule, V09 notices', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_core/PublicDoor.php', 'if (is_string($raw) === false || in_array($raw, self::PAGE_SHOWS, true) === false) {', 'if (is_string($raw) === false) {',
    static fn (): int => $pageA('?show=Events')['status']);
check('PV09 with the exact list of shows taken out, show=Events is answered (V17 catches it)', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', "if (PublicWebsiteSetup::doorStateFor(\$db, \$siteId)['state'] !== 'on') {",
    "if (\$surface !== self::SURFACE_PAGE && PublicWebsiteSetup::doorStateFor(\$db, \$siteId)['state'] !== 'on') {",
    static function () use ($db, $siteA, $http, $hostA): int {
        $was = $db->query("SELECT `publicName` FROM `tblPublicDoorNames` WHERE `siteID` = {$siteA}")->fetch_assoc();
        $db->query("DELETE FROM `tblPublicDoorNames` WHERE `siteID` = {$siteA}");
        $s = $http('GET', '/page.html', ['host' => $hostA])['status'];
        if ($was !== null) {
            $db->query("INSERT INTO `tblPublicDoorNames` (`siteID`, `publicName`, `changedAtUtc`) VALUES ({$siteA}, '{$was['publicName']}', UTC_TIMESTAMP())");
        }
        return $s;
    });
check('PV10 with the page let past the "door on?" question, a switched-off door serves it on its approved public address (V19 catches it)', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', "\$reading = \$ev['startsOnClock'] ?? null;",
    "\$reading = isset(\$ev['startsAt']) === true ? substr((string) \$ev['startsAt'], 0, 19) : null;",
    static function () use ($makeEvent, $siteA, $jGapDay, $pageA, $pageFacts, $vWhen, $db): ?string {
        $id = $makeEvent('PDS A in the missing hour', $siteA, 'Europe/London', $jGapDay . ' 01:30:00', $jGapDay . ' 03:00:00');
        $w  = $vWhen($pageFacts($pageA('?limit=50')['body']), 'PDS A in the missing hour');
        $db->query("DELETE FROM `tblEvents` WHERE `eventID` = {$id}");
        return $w;
    });
check('PV11 with the time shown taken from the instant rather than the stored reading, the missing-hour event shows 02:30 (V05 catches it)',
    [$p['applied'], $p['result']], [true, (new DateTimeImmutable($jGapDay . ' 00:00:00', new DateTimeZone('UTC')))->format('D j M Y') . ', 02:30' . $vLondon]);
$p = $plant('_core/PublicDoor.php', $pageSend, "self::sendDataHeaders('text/html; charset=utf-8', \$testCopy, \$testersOnly);",
    static function () use ($pageA, $pageHeaders, $pageHeadersWant, $pageCspFor, $pageFacts): bool {
        $r = $pageA();
        return $pageHeaders($r) === $pageHeadersWant($pageCspFor($pageFacts($r['body']), "'none'"), 'DENY');
    });
check('PV12 with the page sent under the FEEDS\' headers (never framable, readable by any script), V09 notices', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_core/PublicDoor.php', "        self::clearHeaders();\n        http_response_code(200);\n        foreach (self::pageHeaderLines(",
    "        http_response_code(200);\n        foreach (self::pageHeaderLines(",
    static function () use ($setOwn, $siteA, $pageA, $pageHeaders): array {
        $setOwn($siteA, 'public.frame.origins', 'https://www.selftest.invalid');
        $h = $pageHeaders($pageA());
        $setOwn($siteA, 'public.frame.origins', null);
        return [$h['x-frame-options'], $h['cross-origin-opener-policy']];
    });
check('PV13 with start-up\'s headers no longer cleared first, its X-Frame-Options SAMEORIGIN and COOP reach a page that lists a website, and the listed website is refused (V10 catches it)',
    [$p['applied'], $p['result']], [true, ['SAMEORIGIN', 'same-origin']]);
$p = $plant('_core/PublicDoor.php', 'if (Gatekeeper::mayUseTestCopy($db, self::peekSignedInAccount(), PORTAL_ENV) === false) {', 'if (false) {',
    static fn (): int => $pageA()['status'], 'alpha');
check('PV14 with D-087\'s tester question taken out, a test copy serves the page to anybody (T11 catches it)', [$p['applied'], $p['result']], [true, 200]);
// Round 1 of P4c's check: three faults confined to the APPROVED PUBLIC
// ADDRESS passed every check, because those checks asked on /w/ only. Each
// plant below ADDS a condition beside the existing line, so every other
// plant's anchor text is left intact in the file.
$p = $plant('_core/PublicDoor.php', "            if (\$limit === null || \$show === null) {\n",
    "            if (\$limit === null || \$show === null) {\n                if (Site::doorOnPublicHost() === true) {\n                    http_response_code(404);\n                    exit();\n                }\n",
    static fn (): bool => $signature($pagePublicA('?show=Events')) === $signature($refusals['unknown name']));
check('PV15 with a bad show or limit answered by a bare 404 on a PUBLIC ADDRESS only, R01 tells it apart (it holds those refusals on both forms)', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_core/PublicDoor.php', 'if (Gatekeeper::mayUseTestCopy($db, self::peekSignedInAccount(), PORTAL_ENV) === false) {',
    'if (Gatekeeper::mayUseTestCopy($db, self::peekSignedInAccount(), PORTAL_ENV) === false && ($surface !== self::SURFACE_PAGE || Site::doorOnPublicHost() === false)) {',
    static fn (): int => $pagePublicA()['status'], 'alpha');
check('PV16 with D-087\'s tester question skipped for the page on a PUBLIC ADDRESS only, a test copy serves it to anybody there (T12 catches it)', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', 'if (Maintenance::isActive() === true) {',
    'if (Maintenance::isActive() === true && ($surface !== self::SURFACE_PAGE || Site::doorOnPublicHost() === false)) {',
    static function () use ($setInstallation, $pagePublicA): int {
        $setInstallation('portal.maintenance.active', '1');
        $s = $pagePublicA()['status'];
        $setInstallation('portal.maintenance.active', '0');
        return $s;
    });
check('PV17 with the maintenance question skipped for the page on a PUBLIC ADDRESS only, it is served while the portal is closed (V18 catches it)', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', "if (\$frameAncestors === \"'none'\") {", "if (\$frameAncestors === \"'none'\" && \$testersOnly === false) {",
    static fn (): string => $pageA('', ['cookie' => $cookieOf($people['tester']['jar'])])['headers']['x-frame-options'] ?? '', 'alpha');
check('PV18 with X-Frame-Options dropped from a testers-only test copy\'s \'none\' answer, it carries none (T11 catches it)', [$p['applied'], $p['result']], [true, '']);
// Round 2 of P4c's check (N4): the same two headers on a test copy's PUBLIC
// ADDRESS only, for a tester — the one combination T12 had compared only four
// headers on. Each ADDS a condition, leaving every other plant's anchor intact.
$tPubPageHeaders = static function () use ($http, $hostA, $cookieOf, $people, $pageHeaders, $pageHeadersWant, $pageCspFor, $pageFacts): array {
    $r = $http('GET', '/page.html', ['host' => $hostA, 'cookie' => $cookieOf($people['tester']['jar'])]);
    return ['same' => $pageHeaders($r) === $pageHeadersWant($pageCspFor($pageFacts($r['body']), "'none'"), 'DENY', 'private, no-store', 'Cookie',
        'noindex, nofollow, noarchive'), 'csp' => $r['headers']['content-security-policy'] ?? '', 'xfo' => $r['headers']['x-frame-options'] ?? ''];
};
$p = $plant('_core/PublicDoor.php', 'self::sendPageHeaders(PublicWebsiteSetup::frameAncestorsFor($db, $siteId), $testCopy, $testersOnly);',
    'self::sendPageHeaders(($testCopy === true && Site::doorOnPublicHost() === true) ? \'*\' : PublicWebsiteSetup::frameAncestorsFor($db, $siteId), $testCopy, $testersOnly);',
    $tPubPageHeaders, 'alpha');
check('PV19 (N4) with the frame rule loosened to "*" on a test copy\'s PUBLIC ADDRESS only, the tester\'s page says frame-ancestors * there and T12 no longer matches',
    [$p['applied'], $p['result']['same'] ?? null, str_contains((string) ($p['result']['csp'] ?? ''), 'frame-ancestors *;')], [true, false, true]);
$p = $plant('_core/PublicDoor.php', "if (\$frameAncestors === \"'none'\") {",
    "if (\$frameAncestors === \"'none'\" && (\$testersOnly === false || Site::doorOnPublicHost() === false)) {",
    $tPubPageHeaders, 'alpha');
check('PV20 with X-Frame-Options DENY dropped on a test copy\'s PUBLIC ADDRESS only, the tester\'s page carries none there and T12 no longer matches',
    [$p['applied'], $p['result']['same'] ?? null, $p['result']['xfo'] ?? null], [true, false, '']);

// ── #493 P4d: D-087's card and the reach check, each guard planted out ───────
// The expected value is the value UNDER the fault. Each scenario puts back what
// it changed (the rows and the "Set up" holder's Admin flag).
$p = $plant('_core/PublicWebsiteSetup.php', "if (\$value === 'anybody' && \$limit !== 'either') {", 'if (false) {',
    static function () use ($card, $uOwnRow, $setOwn, $siteA): ?string {
        $card('root', 'door_test_choice', ['value' => 'anybody']);
        $got = $uOwnRow($siteA, 'public.door.testCopies.open');
        $setOwn($siteA, 'public.door.testCopies.open', null);
        return $got;
    }, 'alpha');
check('PU01 with the installation\'s ceiling taken out of the organisation\'s save, "open to anybody" is stored under a ceiling of testers only (U11 catches it)', [$p['applied'], $p['result']], [true, 'anybody']);
$p = $plant('_core/PublicWebsiteSetup.php', "\$what = 'limit for test copies\\' public doors';\n        if (\$isRootAdmin === false) {",
    "\$what = 'limit for test copies\\' public doors';\n        if (false) {",
    static function () use ($card, $installationRow, $setInstallation): ?string {
        $card('root', 'door_test_limit', ['value' => 'either']);
        $got = $installationRow('public.door.testCopies.permitted');
        $setInstallation('public.door.testCopies.permitted', 'testers');
        return $got;
    }, 'alpha');
check('PU02 with "the installation\'s global administrator only" taken out of the ceiling\'s save, an organisation\'s global administrator raises the installation\'s ceiling (U12 catches it)', [$p['applied'], $p['result']], [true, 'either']);
$p = $plant('_core/PublicWebsiteSetup.php', 'if (self::isGlobalAdministratorOf($db, (int) $actorId, $siteId) === false) {', 'if (false) {',
    static function () use ($db, $people, $card, $uOwnRow, $setOwn, $setInstallation, $siteA): ?string {
        $setInstallation('public.door.testCopies.permitted', 'either');
        $db->query("UPDATE `tblUsers` SET `isAdmin` = 1 WHERE `userID` = {$people['setup']['id']}");
        $card('setup', 'door_test_choice', ['value' => 'anybody']);
        $got = $uOwnRow($siteA, 'public.door.testCopies.open');
        $db->query("UPDATE `tblUsers` SET `isAdmin` = 0 WHERE `userID` = {$people['setup']['id']}");
        $setOwn($siteA, 'public.door.testCopies.open', null);
        $setInstallation('public.door.testCopies.permitted', 'testers');
        return $got;
    }, 'alpha');
check('PU03 with "the organisation\'s global administrator only" taken out of the choice\'s save, somebody given "Set up the public website" opens the door to anybody (U14 catches it)', [$p['applied'], $p['result']], [true, 'anybody']);
$p = $plant('_core/PublicWebsiteSetup.php', "if (self::testCopyDoorRule(\$db, \$siteId) === self::DOOR_TEST_LIVE) {\n            return self::refusedAndRecorded(\$what, \$siteId, self::LIVE_REFUSAL, \$actorId);",
    "if (false) {\n            return self::refusedAndRecorded(\$what, \$siteId, self::LIVE_REFUSAL, \$actorId);",
    static function () use ($card, $uOwnRow, $setOwn, $siteA): ?string {
        $card('root', 'door_test_choice', ['value' => 'testers']);
        $got = $uOwnRow($siteA, 'public.door.testCopies.open');
        $setOwn($siteA, 'public.door.testCopies.open', null);
        return $got;
    });
check('PU04 with "only on a test copy" taken out of the choice\'s save, the LIVE portal stores a choice that does nothing there (U05 catches it)', [$p['applied'], $p['result']], [true, 'testers']);
$p = $plant('_apps/admin/public-website/index.php', '<?php if ($doorTestRule !== PublicWebsiteSetup::DOOR_TEST_LIVE): ?>', '<?php if (true): ?>',
    static fn (): bool => str_contains($http('GET', '/admin/public-website', ['jar' => $people['root']['jar']])['body'], 'data-door-test-card='));
check('PU05 with "only on a test copy" taken out of the card, the live portal draws it (U04 catches it)', [$p['applied'], $p['result']], [true, true]);
$p = $plant('_apps/admin/public-website/index.php', '<?php if ($isOrgGlobal === true): ?>', '<?php if (true): ?>',
    static function () use ($db, $people, $http): bool {
        $db->query("UPDATE `tblUsers` SET `isAdmin` = 1 WHERE `userID` = {$people['setup']['id']}");
        $page = $http('GET', '/admin/public-website', ['jar' => $people['setup']['jar']])['body'];
        $db->query("UPDATE `tblUsers` SET `isAdmin` = 0 WHERE `userID` = {$people['setup']['id']}");
        return str_contains($page, 'data-door-test-form="choice"');
    }, 'alpha');
check('PU06 with the card\'s "global administrator only" taken out, the "Set up" holder is offered the choice form (U14 catches it)', [$p['applied'], $p['result']], [true, true]);
$p = $plant('_core/PublicDoor.php', "if (Site::doorOnPublicHost() === false || self::isCheckNonce(\$_GET['n'] ?? null) === false) {",
    "if (self::isCheckNonce(\$_GET['n'] ?? null) === false) {",
    static fn (): int => $feedA('door-check.txt?n=' . $uNonce())['status']);
check('PU07 with "on a public address only" taken out, the check address answers on the /w/ form (U03 catches it)', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', "if (Site::doorOnPublicHost() === false || self::isCheckNonce(\$_GET['n'] ?? null) === false) {",
    'if (Site::doorOnPublicHost() === false) {',
    static fn (): int => $http('GET', '/door-check.txt?n=x', ['host' => $hostA])['status']);
check('PU08 with the value\'s shape taken out, the check address answers a value nobody\'s check made (U03 catches it)', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicWebsiteSetup.php', "if (hash_equals(PublicDoor::CHECK_FORMAT . ' ' . \$expectedProof . \"\\n\", \$body) === true) {",
    "if (str_starts_with(\$body, PublicDoor::CHECK_FORMAT . ' ') === true) {",
    static fn (): mixed => $uAsk('forward-b')['reach'][0] ?? null);
check('PU09 with the proof compared by its FORM only, another organisation\'s door reads as "it reaches your door" (U08 catches it)', [$p['applied'], $p['result']], [true, 'ok']);
$p = $plant('_core/PublicDoor.php', "return hash_hmac('sha256', \"webms-public-door-check-v1\\n\" . \$siteId . \"\\n\" . \$host . \"\\n\" . \$nonce, \$doorName);",
    'return hash(\'sha256\', $nonce);',
    static fn (): mixed => $uAsk('forward-b')['reach'][0] ?? null);
check('PU10 with the proof made from the value alone (no name, number or address), every door gives the same proof and another organisation\'s door reads as yours (U01, U02 and U08 catch it)', [$p['applied'], $p['result']], [true, 'ok']);
$p = $plant('_core/PublicWebsiteSetup.php', "if (\$reach['reachRow'] !== null) {", 'if (false) {',
    static fn (): mixed => ($uCheckRows('root'))[3][0] ?? null, 'alpha');
check('PU11 with "testers only: not checked" taken out, the check fetches a door that must refuse it and calls the address a problem (U21 catches it)', [$p['applied'], $p['result']], [true, 'problem']);
$p = $plant('_core/PublicWebsiteSetup.php', "\$out['reachRow'] = \$reachNot . 'the door is switched off, so nothing answers there for your organisation.';", "\$out['reachRow'] = null;",
    static function () use ($uDoorOff, $uCheckRows): mixed {
        $uDoorOff(true);
        $got = ($uCheckRows('root'))[3][0] ?? null;
        $uDoorOff(false);
        return $got;
    });
check('PU12 with "the door is off: not checked" taken out, the check fetches a switched-off door and calls the address a problem (U06 catches it)', [$p['applied'], $p['result']], [true, 'problem']);
$p = $plant('_core/PublicWebsiteSetup.php', 'return self::testCopyDoorOpenToAnybody($db, $siteId) === true ? self::DOOR_TEST_ANYBODY : self::DOOR_TEST_TESTERS;',
    'return self::DOOR_TEST_ANYBODY;', static fn (): int => $feedA()['status'], 'alpha');
check('PU13 with the rule answering "anybody" whatever the two settings say, a test copy\'s door answers anybody (T01 and U19 catch it)', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicWebsiteSetup.php', "if (in_array(\$channel, Channel::PRE_RELEASE, true) === false) {\n            return self::DOOR_TEST_LIVE;",
    "if (false) {\n            return self::DOOR_TEST_LIVE;",
    static fn (): string => $http('GET', '/events.json', ['host' => $hostA])['headers']['x-robots-tag'] ?? '');
check('PU14 with the channel no longer asked first, the LIVE portal\'s door is treated as a test copy\'s and tells search engines nofollow and noarchive too (U25 catches it)', [$p['applied'], $p['result']], [true, 'noindex, nofollow, noarchive']);
$p = $plant('_core/PublicWebsiteSetup.php', "if (Gatekeeper::shouldEnforce(\$channel, \$source) === false) {\n            return self::DOOR_TEST_UNGATED;",
    "if (false) {\n            return self::DOOR_TEST_UNGATED;",
    static function () use ($installationRow, $setInstallation, $feedA): int {
        $was = $installationRow('portal.gatekeeper.enabled');
        $setInstallation('portal.gatekeeper.enabled', 'false');
        $s = $feedA()['status'];
        $setInstallation('portal.gatekeeper.enabled', $was);
        return $s;
    }, 'alpha');
check('PU15 with "the test copy\'s gate is switched off" taken out of the rule, a door on an ungated test copy refuses what every other page there serves (U24 catches it)', [$p['applied'], $p['result']], [true, 404]);

$p = $plant('_core/PublicWebsiteSetup.php', 'if (self::testCopyDoorRule($db, 0) === self::DOOR_TEST_LIVE) {', 'if (false) {',
    static function () use ($card, $installationRow, $setInstallation): ?string {
        $card('instroot', 'door_test_limit', ['value' => 'either']);
        $got = $installationRow('public.door.testCopies.permitted');
        $setInstallation('public.door.testCopies.permitted', 'testers');
        return $got;
    });
check('PU16 with "only on a test copy" taken out of the ceiling\'s save, the LIVE portal stores a ceiling that does nothing there (U05 catches it)', [$p['applied'], $p['result']], [true, 'either']);
$p = $plant('_core/PublicWebsiteSetup.php', "if (\$value !== 'testers' && \$value !== 'anybody') {", 'if (false) {',
    static function () use ($card, $uOwnRow, $setOwn, $siteA): ?string {
        $card('root', 'door_test_choice', ['value' => 'open']);
        $got = $uOwnRow($siteA, 'public.door.testCopies.open');
        $setOwn($siteA, 'public.door.testCopies.open', null);
        return $got;
    }, 'alpha');
check('PU17 with the choice\'s list of answers taken out, a word nobody offers is stored as the organisation\'s choice (U22 catches it)', [$p['applied'], $p['result']], [true, 'open']);
$p = $plant('_core/PublicWebsiteSetup.php', "if (\$value !== 'testers' && \$value !== 'either') {", 'if (false) {',
    static function () use ($card, $installationRow, $setInstallation): ?string {
        $card('instroot', 'door_test_limit', ['value' => 'anybody']);
        $got = $installationRow('public.door.testCopies.permitted');
        $setInstallation('public.door.testCopies.permitted', 'testers');
        return $got;
    }, 'alpha');
check('PU18 with the ceiling\'s list of answers taken out, a word nobody offers is stored as the installation\'s ceiling (U22 catches it)', [$p['applied'], $p['result']], [true, 'anybody']);
// Round 1 of P4d's check: the two media plants that left 297/297 green.
$p = $plant('_core/PublicDoor.php', "        if (\$testersOnly === true) {\n            if (Gatekeeper::mayUseTestCopy(",
    "        if (\$testersOnly === true && \$media === '') {\n            if (Gatekeeper::mayUseTestCopy(",
    static fn (): int => $feedA('media/' . $upA)['status'], 'alpha');
check('PU19 with the testers-only gate skipped for media alone, a test copy serves a poster\'s picture to anybody (U19 catches it)', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', 'self::sendDataHeaders($file[\'type\'], $testCopy, $testersOnly);', 'self::sendDataHeaders($file[\'type\'], false, $testersOnly);',
    static function () use ($setInstallation, $setOwn, $siteA, $feedA, $upA): string {
        $setInstallation('public.door.testCopies.permitted', 'either');
        $setOwn($siteA, 'public.door.testCopies.open', 'anybody');
        $r = $feedA('media/' . $upA)['headers']['x-robots-tag'] ?? '';
        $setOwn($siteA, 'public.door.testCopies.open', null);
        $setInstallation('public.door.testCopies.permitted', 'testers');
        return $r;
    }, 'alpha');
check('PU20 with a test copy\'s media sent with the live portal\'s plain noindex, search engines may follow and archive it (U16 and U16b catch it)', [$p['applied'], $p['result']], [true, 'noindex']);
$p = $plant('_core/PublicWebsiteSetup.php', '} elseif ($rule !== self::DOOR_TEST_TESTERS) {',
    '} elseif ($rule === self::DOOR_TEST_ANYBODY || $rule === self::DOOR_TEST_LIVE) {',
    static function () use ($card, $installationRow, $setInstallation, $uFlash): string {
        $was = $installationRow('portal.gatekeeper.enabled');
        $setInstallation('portal.gatekeeper.enabled', 'false');
        $page = $card('root', 'door_test_choice', ['value' => 'testers'])['page'];
        $setInstallation('portal.gatekeeper.enabled', $was);
        return $uFlash($page);
    }, 'alpha');
// Under the fault the message is, word for word, the TESTERS-ONLY state's
// (doorReachWords() for A as it stands, unplanted, asked in U21).
check('PU21 with the "gate switched off" state taken out of doorReachWords(), saving the choice tells a test copy open to anybody that its door answers signed-in testers only (U24b and U27 catch it)', [$p['applied'], $p['result']],
    [true, 'Saved: your choice is signed-in testers only. ' . $uWords21['card']]);
$p = $plant('_core/PublicWebsiteSetup.php', "? 'Testers can try the door at ' . \$we . ' (events) and ' . \$wp . ' (posters), in a browser signed in here.'",
    "? 'Testers can try the door at https://' . \$host . '/events.json (events) and ' . \$wp . ' (posters), in a browser signed in here.'",
    static fn (): bool => str_contains((string) (($uCheckRows('root'))[3][1] ?? ''), 'Testers can try the door at https://' . $hostA . '/'), 'alpha');
check('PU22 with doorReachWords() naming the public address instead of the /w/ one, the set-up check sends testers to the public address, which answers nobody (U21 and U26 catch it)', [$p['applied'], $p['result']], [true, true]);
$p = $plant('_apps/admin/public-website/index.php', "<div class=\"alert alert-warning\" data-door-testers-only=\"1\"><?php echo \$esc(\$doorReach['alert']); ?></div>",
    "<div class=\"alert alert-warning\" data-door-testers-only=\"1\">This is a test copy, so open the addresses above in this browser to try them.</div>",
    static fn (): bool => str_contains($http('GET', '/admin/public-website', ['jar' => $people['root']['jar']])['body'], 'open the addresses above in this browser to try them'), 'alpha');
check('PU23 with the door card writing its OWN warning (round 2\'s plant m1: the removed advice back), it tells testers to open public addresses that answer nobody (U21b and U27 catch it)', [$p['applied'], $p['result']], [true, true]);

// ── D-121 (#895): each of section Q's rules taken out, and its scenario re-run ─
$p = $plant('_core/PublicWebsiteSetup.php', "if (\$others['approvedSiteId'] !== null) {\n                    return self::refusedAndRecorded('public address', \$siteId, self::TAKEN_FOR_ORGANISATION, \$actorId);",
    "if (false) {\n                    return self::refusedAndRecorded('public address', \$siteId, self::TAKEN_FOR_ORGANISATION, \$actorId);", $scnQLater);
check('PQ1 with the save no longer refusing a name another organisation is approved for, a later request for it is stored (Q08 catches it)',
    [$p['applied'], $p['result'][0] ?? null], [true, 'shared-895.selftest.test']);
$p = $plant('_core/PublicWebsiteSetup.php', "return \$others['approvedSiteId'] !== null ? self::HOST_TAKEN : self::HOST_CLASHING;", 'return self::HOST_CLASHING;', $scnQClash);
check('PQ2 with "taken" no longer worked out, the losing organisation\'s card still says it is waiting (Q05 catches it)',
    [$p['applied'], $p['result']['taken on the card'][0] ?? null], [true, 'waiting-clashing']);
$p = $plant('_core/PublicWebsiteSetup.php', ". 'ORDER BY (h.`approvedAtUtc` IS NULL), h.`siteID` LIMIT 1'", ". 'LIMIT 1'", $scnQClash);
check('PQ3 with the door\'s look-up no longer reading the APPROVED row first, the approved organisation\'s door stops answering on a name another organisation merely asked for (Q06 catches it)',
    [$p['applied'], $p['result']['door after'][1] ?? null], [true, false]);
$p = $plant('_core/PublicWebsiteSetup.php', ". 'WHERE `hostName` = ? FOR UPDATE');", ". 'WHERE `hostName` = ?');", $scnQInterleave);
check('PQ4 with the approval\'s lock taken out, the second approval is stopped only by the database\'s backstop, and says so (Q11 catches it)',
    [$p['applied'], $p['result']['refused by the ask'] ?? null, $p['result']['approved'] ?? null], [true, false, 1]);
/** The database's unique key on approvedHost removed for one scenario, and put back exactly as migration 276 makes it. */
$qWithoutKey = static function (callable $scenario) use ($db): array {
    $db->query('ALTER TABLE `tblPublicHosts` DROP INDEX `uq_public_host_approved`');
    try {
        return $scenario();
    } finally {
        $db->query('ALTER TABLE `tblPublicHosts` ADD UNIQUE KEY `uq_public_host_approved` (`approvedHost`)');
    }
};
register_shutdown_function(static function () use ($db): void {
    try {
        $rs = $db->query("SHOW INDEX FROM `tblPublicHosts` WHERE `Key_name` = 'uq_public_host_approved'");
        if ($rs !== false && $rs->num_rows === 0) {
            $db->query('ALTER TABLE `tblPublicHosts` ADD UNIQUE KEY `uq_public_host_approved` (`approvedHost`)');
        }
    } catch (Throwable $e) {
        echo "  ! tblPublicHosts may be missing its key uq_public_host_approved; add it back by hand (migration 276).\n";
    }
});
$pq5 = $qWithoutKey($scnQKey);
check('PQ5 with the database\'s unique key on approvedHost removed, a second APPROVED row for one name is accepted (Q10 catches it)', $pq5, [0, 2]);
$pq6 = $qWithoutKey($scnQInterleave);
check('PQ6 with the key removed but the lock kept, the second approval still waits and is refused by the locked ask: the lock alone holds (Q11 stays green)',
    $pq6, ['the request waited' => true, 'approved' => 1, 'refused by the ask' => true]);
$p = $plant('_core/PublicWebsiteSetup.php', ". 'WHERE `hostName` = ? FOR UPDATE');", ". 'WHERE `hostName` = ?');",
    static fn (): array => $qWithoutKey($scnQInterleave));
check('PQ7 with BOTH the lock and the key taken out, a second approval made while the first is open is approved too: two organisations approved for one name (Q11 catches it)',
    [$p['applied'], $p['result']['approved'] ?? null], [true, 2]);
$p = $plant('_core/PublicWebsiteSetup.php', "\$alsoAsked++;\n                    if (\$row['approvedAtUtc'] !== null) {", "\$alsoAsked++;\n                    if (false) {", $scnQClash);
check('PQ8 with the approval no longer asking whether another organisation is approved, the second approval reaches the database and only its backstop refuses it (Q07 catches it)',
    [$p['applied'], $p['result']['second approval'] ?? null], [true, [null, true, true, true]]);
$p = $plant('_core/PublicWebsiteSetup.php', "\$takenBy = (string) \$other['siteName'];", '$takenBy = null;', $scnQClash);
check('PQ9 with the list no longer working out who an address is taken by, the losing request is offered for approval as a mere clash (Q05 catches it)',
    [$p['applied'], array_slice($p['result']['taken on the list'] ?? [], 0, 2)], [true, ['waiting-clashing', true]]);
$p = $plant('_apps/admin/public-website/index.php', "<?php if (\$rowState !== 'taken'): ?>", '<?php if (true): ?>', $scnQClash);
check('PQ10 with the list drawing an Approve button on a taken request, the installation\'s administrator is offered one (Q05 catches it)',
    [$p['applied'], $p['result']['taken on the list'][1] ?? null], [true, true]);
$qMp1From = ["            \$db->begin_transaction();\n            try {\n                \$lock = \$db->prepare(",
    "                \$lock->close();\n                if (\$mine === null"];
$qMp1To   = ["            try {\n                \$lock = \$db->prepare(",
    "                \$lock->close();\n                \$db->begin_transaction();\n                if (\$mine === null"];
$p = $plant('_core/PublicWebsiteSetup.php', $qMp1From, $qMp1To, $scnQHeld);
check('PQ12 with the transaction begun only AFTER the locking read (the lock released with its own statement, #697), the other organisation\'s row is free while the approver waits to write — with the database\'s key in place (Q13 catches it; Q11 cannot)',
    [$p['applied'], $p['result']['B\'s row still locked by it'] ?? null], [true, false]);
$p = $plant('_core/PublicWebsiteSetup.php', 'Logger::activity($type, Site::id() === $siteId ? $ownWords : $otherWords, $actorId);',
    'Logger::activity($type, $ownWords, $actorId);', $scnQLogs);
check('PQ13 with an approval recorded in its own words whichever organisation is open, the losing organisation\'s log names the winner by number and gives the address (Q14 catches it)',
    [$p['applied'], array_slice($p['result']['A after the approval'] ?? [], 1, 2)], [true, [true, true]]);
$p = $plant('_core/PublicWebsiteSetup.php', ". ' public address approval. ' . (\$logged ?? \$shown),", ". ' public address approval. ' . \$shown,", $scnQLogs);
check('PQ14 with the refusal recorded in the words SHOWN, the losing organisation\'s log names the winner (Q14 catches it)',
    [$p['applied'], $p['result']['A after the refusal'][0] ?? null], [true, true]);

$p = $plant('_core/PublicWebsiteSetup.php', ". ' public address approval. ' . (\$logged ?? \$shown),",
    ". ' public address approval. ' . (Site::id() === \$siteId ? (\$logged ?? \$shown) : \$shown),", $scnQLogs);
check('PQ15 (round 2\'s M2) with the refusal recorded in the words SHOWN whenever it is about another organisation, A\'s log and export name C, the third organisation holding the address (Q14 catches it; with only two organisations nothing could)',
    [$p['applied'], $p['result']['A after a refusal about B while C holds it: page'][0] ?? null, $p['result']['A after a refusal about B while C holds it: export'][0] ?? null], [true, true, true]);
$p = $plant('_apps/admin/activity/export.php', 'WHERE (l.siteID = ? OR l.siteID IS NULL)', 'WHERE (l.siteID = ? OR 1 = 1)', $scnQLogs);
check('PQ16 (round 2\'s M4) with the activity export left unfiltered, A\'s ordinary administrator downloads B\'s records, with B\'s number and the address (Q14 catches it)',
    [$p['applied'], array_slice($p['result']['A\'s export: B\'s name, number, the shared address'] ?? [], 1, 2)], [true, [true, true]]);
$p = $plant('_core/PublicWebsiteSetup.php', "                \$others = self::otherRequestsFor(\$db, \$siteId, \$host);\n                if (\$others === null) {",
    "                if (\$host === \$was) {\n                    return ['saved' => true, 'type' => 'info', 'message' => 'Saved. Nothing was different.'];\n                }\n"
    . "                \$others = self::otherRequestsFor(\$db, \$siteId, \$host);\n                if (\$others === null) {", $scnQResave);
check('PQ17 (round 2\'s M6) with "nothing was different" asked before "taken", a losing organisation saving its address again is told "Saved" while its card says taken (Q15 catches it)',
    [$p['applied'], $p['result'] ?? null], [true, ['taken', false, true, 'shared-895.selftest.test']]);

$qKeyBack = $db->query("SHOW INDEX FROM `tblPublicHosts` WHERE `Key_name` = 'uq_public_host_approved' AND `Non_unique` = 0 AND `Column_name` = 'approvedHost'")->num_rows;
check('PQ11 after the key plants, the unique key on approvedHost is back exactly as migration 276 makes it', $qKeyBack, 1);

// ── Tidy up ─────────────────────────────────────────────────────────────────
$db->query("DELETE FROM `tblPublicDoorNames` WHERE `siteID` IN (1, {$siteB}, {$siteC})");
$db->query("DELETE FROM `tblPublicHosts` WHERE `siteID` IN (1, {$siteB}, {$siteC})");
$db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'PDS %'");
$db->query("DELETE FROM `tblExternalFeeds` WHERE `name` = 'PDS outside calendar'");
$db->query("DELETE FROM `tblNoticeboardUploads` WHERE `storedName` IN ('{$upA}', '{$upA2}', '{$upB}')");
$db->query("DELETE FROM `tblNoticeboardPosters` WHERE `title` LIKE 'PDS %'");
foreach (['calendar.enabled', 'noticeboard.enabled', 'public.door.testCopies.open', 'public.frame.origins', 'public.events.copiedRule'] as $k) {
    $db->query("DELETE FROM `tblSettings` WHERE `siteID` IN (1, {$siteB}, {$siteC}) AND `settingKey` = '{$k}'");
}
$db->query("DELETE FROM `tblSettings` WHERE `siteID` IN ({$siteB}, {$siteC})");
$db->query("DELETE FROM `tblUserSites` WHERE `siteID` IN ({$siteB}, {$siteC})");
$db->query("DELETE FROM `tblRoles` WHERE `siteID` IN ({$siteB}, {$siteC})");
foreach ($state['people'] as $who) {
    $removeMember((int) $who);
}
$state['people'] = [];
$db->query("DELETE FROM `tblSites` WHERE `siteID` IN ({$siteB}, {$siteC})");
$cleanUp();

$total = $passed + $failed;
echo "\n{$passed} of {$total} checks passed.\n";
if ($failed > 0) {
    exit(1);
}
if ($total !== PDS_EXPECTED_CHECKS) {
    echo "But " . PDS_EXPECTED_CHECKS . " were expected: a block was skipped or ran twice.\n";
    exit(1);
}
exit(0);
