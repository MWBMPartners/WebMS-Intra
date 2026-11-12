<?php
// Path: tools/notify-prefs-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Two pages save a member's notification choices, and neither may lose the
 * other's — self-test 🔔 (#667)
 * -----------------------------------------------------------------------------
 * A member's notification choices live in ONE column, `tblUsers.notifyPrefs`,
 * and two pages save them: the full Notification preferences page
 * (`/account/notifications`) and the small card on `/account`. Until #667 the
 * full page REBUILT the whole column from its own list and wrote it over the
 * top, dropping every key it did not list, and the card's read-then-write had
 * no lock. Both now go through `Portal\Core\NotifyPrefs::merge()`.
 *
 * WHAT IT COVERS — every section drives the REAL pages over HTTP and reads the
 * column back, except F, which is about two saves at once (see below):
 *   A  each page draws a hidden "this form drew me" marker beside EVERY
 *      switch, and beside nothing else
 *   B  saving on the full page keeps every key it does not draw — the card's
 *      retired keys, an unknown key, and the push choices it does not show —
 *      and an unticked switch it DOES draw is stored as off
 *   C  saving on the card keeps every key the full page owns, an unticked box
 *      reads off, and the retired `emailDigest` / `expenseUpdates` are no
 *      longer written
 *   D  a field that did not ARRIVE changes nothing (#677): a post with no
 *      markers, a marker for a key nobody may set, markers naming ONLY such
 *      keys (which must not unsubscribe the member from the newsletter
 *      either), and the Sabbath choice when its buttons were not drawn; and a
 *      tick counts only when it is exactly '1'; and (#875) the newsletter
 *      choice is saved only from a form that DREW it, so a page opened while
 *      the newsletter was off cannot unsubscribe anybody once it is on, and
 *      a box drawn for one organisation cannot set another's choice after
 *      the member switches organisation (D14-D17, the real switch page);
 *      only a CHANGE to the box is written, so an old page cannot
 *      resubscribe a member who left by a newsletter's own unsubscribe link
 *      (D20, D21), and an opted-out member is drawn unticked (D22); a tick
 *      subscribes (D23), and an old unticked page cannot undo a subscription
 *      made in another session (D24); a shown box gets plain "Preferences
 *      saved." (D20, D21, D23, D24)
 *   E  a stored value that is valid JSON but not a set of named choices is
 *      replaced by the keys just saved, and the replacement is RECORDED — on
 *      BOTH pages
 *   F  two saves at once lose nothing: the real `merge()` runs in its own
 *      PROCESS while this test holds the row, the way
 *      `erasure-review-selftest.php` section T does it; and F3 MEASURES that
 *      its read and write are one transaction, which no before-and-after
 *      comparison of the keys can see (a scratch trigger records when the
 *      writing connection's transaction began); and F4/F5 have two real
 *      saves both WAITING at their read before either writes — an EXCLUSIVE
 *      lock then lets them read one after the other and both land, while a
 *      shared one would let both read at once, deadlock, and lose a save
 *   G  when the database refuses the save (a scratch trigger refuses it for
 *      real), both pages say so and nothing changes
 *   H  THE PLANTS: twenty-one faults, each put into a COPY of the code, each of
 *      which must turn the check that guards it RED, and all green again after
 *
 * NOT DRIVEN: `merge()` also checks each `execute()`'s answer, so that its
 * "never throws, answers failed" promise does not depend on the portal's
 * strict error reporting. With strict reporting (how every page runs) a
 * refused statement throws instead, which section G drives; the
 * answers-false path needs reporting switched off and is read, not run.
 *
 * WHY SECTION F IS NOT AN HTTP TEST. The page harness runs PHP's built-in
 * server with ONE worker, so two requests never overlap and a race test built
 * on it would pass on the BROKEN code (#647). So F drives the class the pages
 * call. Section A/B/C prove the pages call it; F proves the lock in it holds.
 *
 * WHAT IT CANNOT DO, said plainly:
 *   - Invalid TEXT in the column cannot be stored here to test: MySQL's JSON
 *     column refuses it and MariaDB adds a JSON_VALID check. `merge()` still
 *     handles it; only the valid-but-not-an-object case is driven (E).
 *   - It does not drive the push switches as SHOWN, because push is not set up
 *     on a fresh install; `go-live-button-selftest.php` covers that page with
 *     push set up.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_notifyprefs SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         php tools/notify-prefs-selftest.php
 *
 *         The database must already hold `web/_sql/full_schema.sql`. Needs
 *         PHP's curl extension and `proc_open()`, and a database account that
 *         may read `information_schema.INNODB_TRX` (the PROCESS privilege) and
 *         create scratch tables and TRIGGERs — both asked up front. On MySQL 8
 *         with binary logging on (the default), creating a trigger needs SUPER
 *         or `log_bin_trust_function_creators = 1` as well as the TRIGGER
 *         right, so in practice this means the root account. It REFUSES
 *         rather than skipping when anything is missing, because a skipped
 *         test reads exactly like a passing one. Stopped with Ctrl-C (when PHP
 *         has pcntl) it removes its triggers, scratch table, web server,
 *         workers and copy of web/, and switches the newsletter back off — but
 *         NOT the test member and their rows, which stay until the next run
 *         removes them first. What a `kill -9` leaves is listed beside the
 *         clean-up.
 *
 * Exit:   0 only when every check ran and passed.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/667
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);

$passed = 0;
$failed = 0;
// Everything the clean-up must undo, in one place, so the shutdown function
// and a Ctrl-C reach the same list (see "Putting things back" below).
$state  = ['proc' => null, 'child' => null, 'workers' => [], 'a' => null, 'db' => null, 'newsletterTouched' => false, 'multisiteWas' => null, 'siteB' => null];

function refuse(string $why): never
{
    echo "REFUSED: {$why}.\n";
    echo "Nothing was checked, and this run fails. A skipped test reads exactly\n";
    echo "like a passing one, which is why this refuses rather than skipping.\n";
    exit(2);
}

/** One check. `$detail` is printed only when it fails. */
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok === true) {
        $passed += 1;
        echo "  ✓ {$name}\n";
        return;
    }
    $failed += 1;
    echo "  ✗ {$name}\n";
    if ($detail !== '') {
        echo '      ' . $detail . "\n";
    }
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

$state['db'] = $db;
// ⏱️ A SHORT WAIT FOR LOCKS on this test's own connection. MySQL's default
//    for a metadata lock (`lock_wait_timeout`) is 31,536,000 seconds — a
//    year — so a DROP TRIGGER that ever queued behind a transaction this test
//    forgot to end did not fail, it HUNG (independent check, round 2). Fifteen
//    seconds is far more than any step here needs.
$db->query('SET SESSION lock_wait_timeout = 15, innodb_lock_wait_timeout = 15');

$tables = (int) ($db->query(
    'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
)->fetch_assoc()['n'] ?? 0);
if ($tables < 100) {
    refuse("the database holds only {$tables} tables — load web/_sql/full_schema.sql first");
}

// ── Putting things back: ONE clean-up, in the only safe ORDER ────────────────
// Run at the end, on refuse(), on an uncaught error, and on Ctrl-C (SIGINT),
// SIGTERM or SIGHUP when PHP has the pcntl extension — the handler below only
// calls exit(), which runs this.
//
// ⚠️ THE ORDER IS THE POINT. Section F holds a row lock on a SECOND connection
//    (`$state['a']`) and starts worker processes that wait on it. Dropping a
//    trigger on `tblUsers` needs a metadata lock that waits for every open
//    transaction on that table — so dropping it while `$a` is still open made
//    this process wait on ITSELF, for a year (see above). So: end `$a` first,
//    stop the workers, stop the web server, and only then drop the scratch
//    triggers and table; then delete the copy of web/, which holds the
//    database password (`auth_creds.php`) and an encryption key.
//
// WHAT A HARD KILL (`kill -9`) STILL LEAVES, because nothing can run then:
//   - two possible scratch triggers on `tblUsers` (`zzNotifyPrefsProbeTrg`,
//     `zzNotifyPrefsRefuseTrg`) and the table `zzNotifyPrefsProbe` — the
//     next run drops the two triggers before it uses them and REUSES the
//     table (CREATE TABLE IF NOT EXISTS, then DELETE FROM), but until then a
//     refusing trigger would refuse writes to the test member's choices;
//   - the folder `notify-prefs-<random>` under the system temp folder,
//     holding the database password and a key — delete it by hand;
//   - the web server (`php -S 127.0.0.1:<port>`, a port from 10300 to 10500)
//     and any worker, which the
//     operating system may leave running — stop them by hand;
//   - `newsletter.enabled` set to '1' if the kill landed inside D8/D9;
//   - inside D14-D17, the two `multisite.*` rows and organisation 987875 are
//     put back by the shutdown function, but not after a `kill -9`;
//   - the test member and their rows, which the next run removes first.
register_shutdown_function(static function () use (&$state): void {
    if ($state['a'] instanceof mysqli) {
        try {
            $state['a']->rollback();
            $state['a']->close();
        } catch (Throwable $e) {
            // Already gone: the server rolls it back when the connection drops.
        }
        $state['a'] = null;
    }
    foreach ($state['workers'] as $worker) {
        if (is_resource($worker) === true) {
            proc_terminate($worker);
            proc_close($worker);
        }
    }
    $state['workers'] = [];
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
    }
    if ($state['db'] instanceof mysqli) {
        $sqls = [
            'DROP TRIGGER IF EXISTS `zzNotifyPrefsProbeTrg`',
            'DROP TRIGGER IF EXISTS `zzNotifyPrefsRefuseTrg`',
            'DROP TRIGGER IF EXISTS `zzNotifyPrefsCanTrg`',
            'DROP TABLE IF EXISTS `zzNotifyPrefsProbe`',
        ];
        if ($state['newsletterTouched'] === true) {
            $sqls[] = "UPDATE `tblSettings` SET `settingValue` = '0' WHERE `settingKey` = 'newsletter.enabled' AND `siteID` IS NULL";
        }
        // D14-D17 (#875): the organisation chosen per session, and organisation B.
        if (is_array($state['multisiteWas']) === true) {
            foreach ($state['multisiteWas'] as $was) {
                $sqls[] = "UPDATE `tblSettings` SET `settingValue` = '" . $state['db']->real_escape_string((string) $was['settingValue'])
                    . "' WHERE `settingKey` = '" . $state['db']->real_escape_string((string) $was['settingKey']) . "' AND `siteID` IS NULL";
            }
        }
        if (is_int($state['siteB']) === true) {
            $sqls[] = 'DELETE FROM `tblNewsletterSubscription` WHERE `siteID` = ' . $state['siteB'];
            $sqls[] = 'DELETE FROM `tblUserSites` WHERE `siteID` = ' . $state['siteB'];
            $sqls[] = 'DELETE FROM `tblSites` WHERE `siteID` = ' . $state['siteB'];
        }
        foreach ($sqls as $sql) {
            try {
                $state['db']->query($sql);
            } catch (Throwable $e) {
                echo "  ! could not put back: {$sql} (" . $e->getMessage() . ")\n";
            }
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

// Ctrl-C and a polite kill run the clean-up above (#649's pattern). Without
// the pcntl extension only the shutdown function is there, which an
// uncaught signal does NOT run — so on such a PHP, Ctrl-C leaves what a hard
// kill leaves (listed above). A signal that arrives while PHP is inside a
// blocking database call is acted on when that call returns, and every lock
// wait here is capped at fifteen seconds.
if (function_exists('pcntl_async_signals') === true && function_exists('pcntl_signal') === true) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
        pcntl_signal($signal, static function (int $which): void {
            echo "\nStopped by a signal ({$which}); putting things back.\n";
            exit(3);
        });
    }
}

// ── The two database rights section F and G need, asked UP FRONT ─────────────
// ⚠️ ASKED BEFORE ANYTHING HOLDS A LOCK. Section F reads
//    information_schema.INNODB_TRX, which needs the PROCESS privilege; without
//    it the read THROWS (error 1227) — and the first version met that throw
//    while its second connection held a row lock, then hung (above). Asked
//    here, a missing right is a plain REFUSED with nothing held.
try {
    $db->query('SELECT COUNT(*) AS n FROM information_schema.INNODB_TRX')->free();
} catch (Throwable $e) {
    refuse("this test reads information_schema.INNODB_TRX (to see that a save is really waiting, and when its transaction began), which needs the PROCESS privilege; '{$dbUser}' does not have it (" . $e->getMessage() . ')');
}
try {
    $db->query('CREATE TABLE IF NOT EXISTS `zzNotifyPrefsProbe` (`probeID` INT NOT NULL AUTO_INCREMENT PRIMARY KEY, `connID` BIGINT NULL, `trxStarted` DATETIME NULL, `seenAt` DATETIME(6) NOT NULL) ENGINE=InnoDB');
    $db->query('DROP TRIGGER IF EXISTS `zzNotifyPrefsCanTrg`');
    $db->query('CREATE TRIGGER `zzNotifyPrefsCanTrg` BEFORE INSERT ON `zzNotifyPrefsProbe` FOR EACH ROW SET NEW.`connID` = NEW.`connID`');
    $db->query('DROP TRIGGER `zzNotifyPrefsCanTrg`');
} catch (Throwable $e) {
    refuse("this test creates scratch tables and TRIGGERs (sections F and G), which '{$dbUser}' may not do here (" . $e->getMessage() . ')');
}
// Anything an earlier, killed run left on tblUsers.
$db->query('DROP TRIGGER IF EXISTS `zzNotifyPrefsProbeTrg`');
$db->query('DROP TRIGGER IF EXISTS `zzNotifyPrefsRefuseTrg`');

// ── A copy of web/, so a planted fault never touches the repository ─────────
$child = sys_get_temp_dir() . $sep . 'notify-prefs-' . bin2hex(random_bytes(6));
$state['child'] = $child;

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
    "<?php\n// Written by tools/notify-prefs-selftest.php for ONE run.\nreturn "
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
// Written by tools/notify-prefs-selftest.php for ONE run. Test only.
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

// The worker for section F: the REAL class, from the COPY (so a plant in the
// copy reaches it), on its own connection, in its own process.
$worker = <<<'WORKER'
<?php
// Written by tools/notify-prefs-selftest.php for ONE run. Test only.
declare(strict_types=1);
// argv: the class file, the account number, the ONE key to switch off, and a
// file to write this connection's thread id into BEFORE it asks for the row,
// so the test can see that THIS connection — not some other — is waiting.
require $argv[1];
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli((string) getenv('NP_HOST'), (string) getenv('NP_USER'), (string) getenv('NP_PASS'), (string) getenv('NP_NAME'), (int) getenv('NP_PORT'));
file_put_contents($argv[4], (string) $db->thread_id);
$answer = \Portal\Core\NotifyPrefs::merge($db, (int) $argv[2], [$argv[3] => false]);
echo json_encode($answer);
WORKER;
file_put_contents($child . $sep . 'worker.php', $worker);

// A range no other self-test uses (8700-8950 and 8960-9210 are taken, and the
// fixed SELFTEST_WEB_PORT values run 9068-9893), so this can run beside them.
$port = 10300 + random_int(0, 200);
$jar  = $child . $sep . 'cookies.txt';

// ⚠️ ONE server for the whole run. opcache is OFF, so a file the plants change
//    in the copy is read afresh on the next request — no restart is needed,
//    and a restart is a race with the port.
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
    [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $child . $sep . 'server.log', 'a'],
        2 => ['file', $child . $sep . 'server.log', 'a'],
    ],
    $pipes,
    $child,
    $env
);
if (is_resource($state['proc']) === false) {
    refuse('could not start PHP\'s web server');
}

/**
 * One request. `CURLOPT_FOLLOWLOCATION` is OFF on purpose: where a save sends
 * the member is a fact this test reads.
 *
 * @param array<string,mixed> $post
 * @return array{status:int, location:string, body:string}
 */
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
        CURLOPT_HTTPHEADER     => ['Expect:'],
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

// Wait for the server by asking it for a page, never by sleeping blind.
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

/**
 * The fields a browser would post from ONE form on a page: every hidden
 * field, and every checkbox or radio button that is ticked. `$pick` chooses
 * the form by a piece of its opening tag or body.
 *
 * Field names are kept WHOLE (`prefsShown[eventReminders]`), and
 * http_build_query() turns them back into PHP arrays on the other side.
 *
 * @return array{found:bool, action:string, fields:array<string,string>, switches:list<string>, markers:list<string>}
 */
$formOn = static function (string $html, string $pick): array {
    $none = ['found' => false, 'action' => '', 'fields' => [], 'switches' => [], 'markers' => []];
    if (preg_match_all('#<form\b([^>]*)>(.*?)</form>#s', $html, $forms, PREG_SET_ORDER) < 1) {
        return $none;
    }
    foreach ($forms as $form) {
        if (str_contains($form[0], $pick) === false) {
            continue;
        }
        $action = preg_match('/\baction="([^"]*)"/', $form[1], $a) === 1 ? html_entity_decode($a[1], ENT_QUOTES, 'UTF-8') : '';
        $fields = [];
        $switches = [];
        $markers  = [];
        preg_match_all('/<input\b[^>]*>/i', $form[2], $inputs);
        foreach ($inputs[0] as $input) {
            if (preg_match('/\bname="([^"]+)"/', $input, $n) !== 1) {
                continue;
            }
            $name  = html_entity_decode($n[1], ENT_QUOTES, 'UTF-8');
            $value = preg_match('/\bvalue="([^"]*)"/', $input, $v) === 1 ? html_entity_decode($v[1], ENT_QUOTES, 'UTF-8') : '';
            $type  = preg_match('/\btype="([^"]+)"/', $input, $t) === 1 ? strtolower($t[1]) : 'text';
            if (preg_match('/^prefs\[([A-Za-z]+)\]$/', $name, $k) === 1 && $type === 'checkbox') {
                $switches[] = $k[1];
            }
            if (preg_match('/^prefsShown\[([A-Za-z]+)\]$/', $name, $k) === 1 && $type === 'hidden') {
                $markers[] = $k[1];
            }
            if (($type === 'checkbox' || $type === 'radio') && preg_match('/\bchecked\b/', $input) !== 1) {
                continue;
            }
            $fields[$name] = $value;
        }
        sort($switches);
        sort($markers);

        return ['found' => true, 'action' => $action, 'fields' => $fields, 'switches' => $switches, 'markers' => $markers];
    }

    return $none;
};

// ── The member ──────────────────────────────────────────────────────────────
/**
 * Remove a member and everything that names them, children first. The tables
 * are asked of the schema rather than listed from memory: signing in writes
 * activity records, and `tblActivityLogs.userID` has no cascade.
 */
$removeMember = static function (int $who) use ($db): int {
    if ($who <= 0) {
        return 0;
    }
    $rs = $db->query(
        'SELECT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE '
        . "WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'tblUsers' "
        . "AND COLUMN_NAME = 'userID'"
    );
    $cleared = 0;
    while ($row = $rs->fetch_assoc()) {
        $table = (string) $row['TABLE_NAME'];
        try {
            $db->query("DELETE FROM `{$table}` WHERE `userID` = {$who}");
            $cleared += 1;
        } catch (Throwable $e) {
            // A table whose own parent has already gone, or a different shape.
        }
    }
    $rs->free();
    try {
        $db->query("DELETE FROM `tblErrors` WHERE `userID` = {$who}");
        $db->query("DELETE FROM `tblUsers` WHERE `userID` = {$who}");
    } catch (Throwable $e) {
        return -1;
    }
    return $cleared;
};

$email      = 'notify.prefs@selftest.invalid';
$signInName = 'selftest-notify-prefs';
$password   = 'Sel' . bin2hex(random_bytes(8)) . '!9x';

$old = $db->prepare('SELECT `userID` FROM `tblUsers` WHERE `emailAddress` = ?');
$old->bind_param('s', $email);
$old->execute();
$leftovers = $old->get_result();
while ($row = $leftovers->fetch_assoc()) {
    $removeMember((int) $row['userID']);
}
$old->close();

$siteId = (int) ($db->query('SELECT MIN(`siteID`) AS s FROM `tblSites`')->fetch_assoc()['s'] ?? 0);
if ($siteId <= 0) {
    refuse('the database has no organisation to put a member in');
}

$ins = $db->prepare(
    'INSERT INTO `tblUsers` (`fullName`, `emailAddress`, `isActive`, `isAdmin`, `createdAt`) '
    . "VALUES ('Notify Prefs Member', ?, 1, 0, NOW())"
);
$ins->bind_param('s', $email);
$ins->execute();
$userId = (int) $db->insert_id;
$ins->close();
$db->query("INSERT IGNORE INTO `tblUserSites` (`siteID`, `userID`, `isActive`) VALUES ({$siteId}, {$userId}, 1)");

$hash = password_hash($password, PASSWORD_DEFAULT);
$acc  = $db->prepare(
    'INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)'
);
$acc->bind_param('iss', $userId, $signInName, $hash);
$acc->execute();
$acc->close();

/** Put a raw value in the column (null for SQL NULL). */
$setStored = static function (?string $raw) use ($db, $userId): void {
    $st = $db->prepare('UPDATE `tblUsers` SET `notifyPrefs` = ? WHERE `userID` = ?');
    $st->bind_param('si', $raw, $userId);
    $st->execute();
    $st->close();
};

/** The column, decoded as an ARRAY with its keys sorted, or null for NULL. */
$stored = static function () use ($db, $userId): ?array {
    $row = $db->query("SELECT `notifyPrefs` FROM `tblUsers` WHERE `userID` = {$userId}")->fetch_assoc();
    if ($row === null || $row['notifyPrefs'] === null) {
        return null;
    }
    $decoded = json_decode((string) $row['notifyPrefs'], true);
    if (is_array($decoded) === false) {
        return ['(not an object)' => (string) $row['notifyPrefs']];
    }
    ksort($decoded);
    return $decoded;
};

/** The same column decoded as an OBJECT, so a nested `{}` can be told from `[]`. */
$storedObject = static function () use ($db, $userId): mixed {
    $row = $db->query("SELECT `notifyPrefs` FROM `tblUsers` WHERE `userID` = {$userId}")->fetch_assoc();
    return json_decode((string) ($row['notifyPrefs'] ?? 'null'), false);
};

/** Sign in through the REAL form, with the REAL token. */
$signIn = static function () use ($http, $signInName, $password, $jar): void {
    @unlink($jar);
    $page = $http('GET', '/login');
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $page['body'], $m) !== 1) {
        refuse('the sign-in page carried no form token, so nothing can be posted');
    }
    $in = $http('POST', '/login', ['csrf_token' => $m[1], 'username' => $signInName, 'password' => $password]);
    if ($in['status'] !== 302 && $in['status'] !== 303) {
        refuse('signing in did not answer a redirect (HTTP ' . $in['status'] . ')');
    }
};
$signIn();

/**
 * Open the full page, untick `$untick`, tick everything else it draws, and
 * press Save. Returns the save's answer.
 *
 * @param list<string> $untick
 * @return array{status:int, location:string, body:string}
 */
$saveFullPage = static function (array $untick) use ($http, $formOn): array {
    $page = $http('GET', '/account/notifications');
    $form = $formOn($page['body'], 'account/notifications/save');
    if ($form['found'] === false) {
        return ['status' => -1, 'location' => '', 'body' => 'the form was not on the page'];
    }
    $fields = $form['fields'];
    foreach ($form['switches'] as $key) {
        unset($fields['prefs[' . $key . ']']);
        if (in_array($key, $untick, true) === false) {
            $fields['prefs[' . $key . ']'] = '1';
        }
    }
    return $http('POST', $form['action'], $fields);
};

/**
 * Open /account, set the card's one switch, and press Save.
 *
 * @return array{status:int, location:string, body:string}
 */
$saveCard = static function (bool $eventReminders, array $extra = []) use ($http, $formOn): array {
    $page = $http('GET', '/account');
    $form = $formOn($page['body'], 'value="update_notifications"');
    if ($form['found'] === false) {
        return ['status' => -1, 'location' => '', 'body' => 'the card was not on the page'];
    }
    $fields = $form['fields'];
    unset($fields['prefs[eventReminders]']);
    if ($eventReminders === true) {
        $fields['prefs[eventReminders]'] = '1';
    }
    return $http('POST', $form['action'], $fields + $extra);
};

$fmt = static fn (mixed $v): string => (string) json_encode($v, JSON_UNESCAPED_SLASHES);

// =============================================================================
echo "\nA. Each page marks every switch it draws, and nothing else\n";
// =============================================================================
$full = $http('GET', '/account/notifications');
check('A1 the Notification preferences page answers, and finished drawing (a truncated page still answers 200)',
    $full['status'] === 200 && str_contains($full['body'], '</html>') === true, 'HTTP ' . $full['status']);
$fullForm = $formOn($full['body'], 'account/notifications/save');
check('A2 its form has switches, and EXACTLY the same set of markers — a switch without one would never be saved, a marker without one would switch a choice off',
    $fullForm['found'] === true && $fullForm['switches'] !== [] && $fullForm['switches'] === $fullForm['markers'],
    'switches ' . $fmt($fullForm['switches']) . ', markers ' . $fmt($fullForm['markers']));
check('A3 push is not set up here, so neither a push switch nor a push marker is drawn (B relies on that)',
    array_intersect(['pushLivestream', 'pushServiceReminders', 'pushApprovals'], $fullForm['markers']) === []
    && str_contains($full['body'], 'name="prefs[pushLivestream]"') === false,
    'markers ' . $fmt($fullForm['markers']));
$account = $http('GET', '/account');
$card    = $formOn($account['body'], 'value="update_notifications"');
check('A4 the Account page answers and finished drawing', $account['status'] === 200 && str_contains($account['body'], '</html>') === true,
    'HTTP ' . $account['status']);
check('A5 its card draws ONE switch, "Event reminders", with its marker, and no marker for the retired emailDigest / expenseUpdates',
    $card['found'] === true && $card['switches'] === ['eventReminders'] && $card['markers'] === ['eventReminders'],
    'switches ' . $fmt($card['switches']) . ', markers ' . $fmt($card['markers']));

// =============================================================================
echo "\nB. Saving on the full page keeps every key it does not draw\n";
// =============================================================================
$beforeB = '{"emailDigest": false, "expenseUpdates": false, "pushLivestream": false, "pushApprovals": true,'
    . ' "zzFutureChoice": {"nested": {}, "count": 3, "words": "kept as stored"}, "taskReminders": true, "rotaReminders": false}';
/** @return array{0:bool, 1:string} */
$scenarioB = static function () use ($setStored, $stored, $storedObject, $saveFullPage, $beforeB, $fmt): array {
    $setStored($beforeB);
    $answer = $saveFullPage(['taskReminders']);
    $now    = $stored() ?? [];
    $obj    = $storedObject();
    $keptOthers = ($now['emailDigest'] ?? 'gone') === false && ($now['expenseUpdates'] ?? 'gone') === false
        && ($now['pushLivestream'] ?? 'gone') === false && ($now['pushApprovals'] ?? 'gone') === true
        && is_object($obj) === true && isset($obj->zzFutureChoice) === true && $obj->zzFutureChoice->nested instanceof \stdClass
        && $obj->zzFutureChoice->count === 3 && $obj->zzFutureChoice->words === 'kept as stored';
    return [$answer['status'] === 302 && $keptOthers, 'save HTTP ' . $answer['status'] . '; stored ' . $fmt($now)];
};
[$okB, $detailB] = $scenarioB();
check('B1 THE #667 PROPERTY: the card\'s retired keys, an unknown key (with a nested {} still an object) and the undrawn push choices all survive',
    $okB, $detailB);
$afterB = $stored() ?? [];
check('B2 the switch left UNTICKED on the page is stored as off', ($afterB['taskReminders'] ?? null) === false, $fmt($afterB));
check('B3 a switch ticked on the page is stored as on (rotaReminders went from off to on)', ($afterB['rotaReminders'] ?? null) === true, $fmt($afterB));
check('B4 every switch the page draws was written, and nothing the page does not draw was added',
    array_keys($afterB) === (static function () use ($fullForm): array {
        $k = array_merge($fullForm['markers'], ['emailDigest', 'expenseUpdates', 'pushApprovals', 'pushLivestream', 'zzFutureChoice']);
        $k = array_values(array_unique($k));
        sort($k);
        return $k;
    })(), $fmt(array_keys($afterB)));

// =============================================================================
echo "\nC. Saving on the Account card keeps every key the full page owns\n";
// =============================================================================
$beforeC = '{"eventReminders": true, "givingStatements": false, "taskReminders": false, "rotaReminders": false,'
    . ' "approvalRequests": false, "pushLivestream": false, "zzFutureChoice": "kept"}';
/** @return array{0:bool, 1:string} */
$scenarioC = static function () use ($setStored, $stored, $saveCard, $beforeC, $fmt): array {
    $setStored($beforeC);
    $answer = $saveCard(false);
    $now    = $stored() ?? [];
    $want   = json_decode($beforeC, true);
    $want['eventReminders'] = false;
    ksort($want);
    return [$answer['status'] === 302 && $now === $want, 'save HTTP ' . $answer['status'] . ' to ' . $answer['location'] . '; stored ' . $fmt($now) . ', wanted ' . $fmt($want)];
};
[$okC, $detailC] = $scenarioC();
check('C1 the card\'s box left UNTICKED reads off, and every other key — the full page\'s five and an unknown one — is exactly as it was, with no emailDigest or expenseUpdates written',
    $okC, $detailC);
$tick = $saveCard(true);
check('C2 ticking it again stores it on', ($stored()['eventReminders'] ?? null) === true && $tick['status'] === 302, $fmt($stored()));
check('C3 the card sends the member back to /account saying it was saved (the old message went to the session and was never shown)',
    str_contains($tick['location'], 'notify_saved=1') === true, 'to ' . $tick['location']);
$shown = $http('GET', '/account?notify_saved=1');
check('C4 ...and the Account page really shows that sentence',
    str_contains($shown['body'], 'Your notification preferences have been saved.') === true, 'HTTP ' . $shown['status']);

// =============================================================================
echo "\nD. A field that did not arrive changes nothing (#677)\n";
// =============================================================================
$beforeD = '{"eventReminders": true, "taskReminders": true, "zzFutureChoice": "kept"}';
/** @return array{0:bool, 1:string} */
$scenarioD = static function () use ($setStored, $stored, $http, $formOn, $beforeD, $fmt): array {
    $setStored($beforeD);
    $want = $stored();
    $page = $http('GET', '/account/notifications');
    $form = $formOn($page['body'], 'account/notifications/save');
    // Everything a browser would send EXCEPT the markers: an out-of-date page.
    $fields = array_filter($form['fields'], static fn (string $name): bool => str_starts_with($name, 'prefsShown[') === false, ARRAY_FILTER_USE_KEY);
    unset($fields['prefs[taskReminders]']);
    $answer = $http('POST', '/account/notifications/save', $fields);
    $now    = $stored();
    return [$answer['status'] === 302 && $now === $want, 'save HTTP ' . $answer['status'] . '; stored ' . $fmt($now) . ', wanted ' . $fmt($want)];
};
[$okD, $detailD] = $scenarioD();
check('D1 the full page posted with NO markers (an out-of-date page) changes nothing — taskReminders, unticked, is still on', $okD, $detailD);
$told = $http('GET', '/account/notifications');
check('D2 ...and the member is told nothing was changed, rather than "saved"',
    str_contains($told['body'], 'This page was out of date, so nothing was changed.') === true, 'HTTP ' . $told['status']);

$setStored($beforeD);
$wantD3 = $stored();
$cardPage = $http('GET', '/account');
$cardForm = $formOn($cardPage['body'], 'value="update_notifications"');
$noMarker = array_filter($cardForm['fields'], static fn (string $name): bool => str_starts_with($name, 'prefsShown[') === false, ARRAY_FILTER_USE_KEY);
unset($noMarker['prefs[eventReminders]']);
$stale = $http('POST', '/account/save', $noMarker);
check('D3 the card posted with no marker changes nothing either, and says why',
    $stored() === $wantD3 && str_contains($stale['location'], 'error=notify_stale') === true,
    'to ' . $stale['location'] . '; stored ' . $fmt($stored()));

$crafted = $saveCard(true, [
    'prefsShown[emailDigest]' => '1', 'prefs[emailDigest]' => '1',
    'prefsShown[isAdmin]'     => '1', 'prefs[isAdmin]'     => '1',
    'prefsShown[0]'           => '1',
]);
$afterCraft = $stored() ?? [];
check('D4 a marker for a key no member may set (a retired one, "isAdmin", a number) is ignored — none is written',
    $crafted['status'] === 302 && array_key_exists('emailDigest', $afterCraft) === false && array_key_exists('isAdmin', $afterCraft) === false
    && array_key_exists('0', $afterCraft) === false && ($afterCraft['zzFutureChoice'] ?? null) === 'kept',
    $fmt($afterCraft));

$db->query("UPDATE `tblUsers` SET `sabbathHonour` = 'off' WHERE `userID` = {$userId}");
check('D5 (the premise) the Sabbath buttons are NOT drawn, because the organisation has the feature off',
    str_contains($http('GET', '/account/notifications')['body'], 'name="sabbathHonour"') === false);
$saveFullPage([]);
$sabbath = (string) ($db->query("SELECT `sabbathHonour` FROM `tblUsers` WHERE `userID` = {$userId}")->fetch_assoc()['sabbathHonour'] ?? '');
check('D6 so saving the page leaves the member\'s Sabbath choice ("off") alone — it used to be reset to "inherit"', $sabbath === 'off', 'is ' . $sabbath);

// The scratch triggers and the probe table below are removed by the ONE
// clean-up near the top ("Putting things back"), which ends any open
// transaction of this test's first — a second shutdown function here used to
// drop them on its own, in the wrong order.

/** Create a scratch trigger, refusing loudly when the database will not let us. */
$makeTrigger = static function (string $sql) use ($db): void {
    try {
        $db->query($sql);
    } catch (Throwable $e) {
        refuse('the database refused to create a scratch trigger on tblUsers (' . $e->getMessage() . '), which sections F and G need');
    }
};

/**
 * D7: the tick must be EXACTLY '1'. A marked switch posted as "on" is off.
 *
 * @return array{0:bool, 1:string}
 */
$scenarioTick = static function () use ($setStored, $stored, $saveCard, $fmt): array {
    $setStored('{"eventReminders": true}');
    $answer = $saveCard(false, ['prefs[eventReminders]' => 'on']);
    $now    = $stored();
    return [$answer['status'] === 302 && $now === ['eventReminders' => false], 'save HTTP ' . $answer['status'] . '; stored ' . $fmt($now)];
};
[$okTick, $detailTick] = $scenarioTick();
check('D7 a marked switch posted as "on" rather than "1" is stored as OFF — only the exact value a page sends switches a choice on', $okTick, $detailTick);

/**
 * D8: markers that name ONLY keys nobody may set are no markers. With the
 * newsletter switched on, the old answer ("saved") also unsubscribed the
 * member, because the post carried no newsletter checkbox.
 *
 * @return array{0:bool, 1:string, 2:bool}  ok, detail, premise (the newsletter box is drawn)
 */
$scenarioOnlyUnsettable = static function () use (&$state, $db, $http, $formOn, $setStored, $stored, $userId, $siteId, $beforeD, $fmt): array {
    // Known to the clean-up, so a Ctrl-C inside this scenario still switches
    // the newsletter back off (a `finally` does not run on exit()).
    $state['newsletterTouched'] = true;
    $db->query("UPDATE `tblSettings` SET `settingValue` = '1' WHERE `settingKey` = 'newsletter.enabled' AND `siteID` IS NULL");
    $db->query("DELETE FROM `tblNewsletterSubscription` WHERE `userID` = {$userId}");
    $token = bin2hex(random_bytes(20));
    $db->query("INSERT INTO `tblNewsletterSubscription` (`siteID`, `userID`, `optedIn`, `unsubToken`) VALUES ({$siteId}, {$userId}, 1, '{$token}')");
    try {
        $setStored($beforeD);
        $want    = $stored();
        $page    = $http('GET', '/account/notifications');
        $premise = str_contains($page['body'], 'name="newsletterOptedIn"') === true;
        $form    = $formOn($page['body'], 'account/notifications/save');
        $answer  = $http('POST', '/account/notifications/save', [
            'csrf_token'              => $form['fields']['csrf_token'] ?? '',
            'prefsShown[isAdmin]'     => '1',
            'prefs[isAdmin]'          => '1',
            'prefsShown[emailDigest]' => '1',
        ]);
        $now   = $stored();
        $subs  = $db->query("SELECT COUNT(*) AS n, MIN(`optedIn`) AS lowest FROM `tblNewsletterSubscription` WHERE `userID` = {$userId}")->fetch_assoc();
        $told  = str_contains($http('GET', '/account/notifications')['body'], 'This page was out of date, so nothing was changed.') === true;
        $ok    = $answer['status'] === 302 && $now === $want && (int) $subs['n'] === 1 && (int) $subs['lowest'] === 1 && $told === true;
        return [$ok, 'save HTTP ' . $answer['status'] . '; stored ' . $fmt($now) . '; newsletter rows ' . $subs['n'] . ', lowest optedIn ' . $subs['lowest'] . '; told out of date: ' . ($told ? 'yes' : 'no'), $premise];
    } finally {
        $db->query("UPDATE `tblSettings` SET `settingValue` = '0' WHERE `settingKey` = 'newsletter.enabled' AND `siteID` IS NULL");
        $db->query("DELETE FROM `tblNewsletterSubscription` WHERE `userID` = {$userId}");
        $state['newsletterTouched'] = false;
    }
};
[$okUnsettable, $detailUnsettable, $premiseUnsettable] = $scenarioOnlyUnsettable();
check('D8 (the premise) with the newsletter switched on, the page draws its checkbox', $premiseUnsettable);
check('D9 a post whose markers name ONLY keys nobody may set changes nothing, does NOT unsubscribe the member from the newsletter, and says the page was out of date',
    $okUnsettable, $detailUnsettable);

/**
 * D10/D11 (#875): the newsletter choice is saved only from a form that DREW
 * it. The page draws the tick box only while the newsletter is switched on;
 * an unticked box is not sent at all, so "not drawn" and "unticked" were the
 * same post, and a page opened while the newsletter was OFF and saved after it
 * was switched ON unsubscribed the member.
 *
 * `$drawnOn` true: the page is opened with the newsletter ON (the box is drawn)
 * and saved with the box unticked. False: opened with it OFF, then switched ON,
 * then saved exactly as drawn. `$hadRow`: whether the member already has a
 * subscription row (opted in) before the save.
 *
 * @return array{0:bool, 1:string, 2:array{drawn:bool, markers:int}}  ok, detail, premise (box and marker on the page)
 */
$scenarioNewsletter = static function (bool $drawnOn, bool $hadRow, bool $onAtSave = true) use (&$state, $db, $http, $formOn, $userId, $siteId, $fmt): array {
    $setNewsletter = static function (string $on) use ($db): void {
        $db->query("UPDATE `tblSettings` SET `settingValue` = '{$on}' WHERE `settingKey` = 'newsletter.enabled' AND `siteID` IS NULL");
    };
    // Known to the clean-up, so a Ctrl-C inside this scenario still switches
    // the newsletter back off (a `finally` does not run on exit()).
    $state['newsletterTouched'] = true;
    $db->query("DELETE FROM `tblNewsletterSubscription` WHERE `userID` = {$userId}");
    if ($hadRow === true) {
        $token = bin2hex(random_bytes(20));
        $db->query("INSERT INTO `tblNewsletterSubscription` (`siteID`, `userID`, `optedIn`, `unsubToken`) VALUES ({$siteId}, {$userId}, 1, '{$token}')");
    }
    try {
        $setNewsletter($drawnOn === true ? '1' : '0');
        $page    = $http('GET', '/account/notifications');
        $premise = ['drawn' => str_contains($page['body'], 'name="newsletterOptedIn"'), 'markers' => substr_count($page['body'], 'name="newsletterShown"')];
        $form    = $formOn($page['body'], 'account/notifications/save');
        $fields  = $form['fields'];
        unset($fields['newsletterOptedIn']); // unticked (and, when it was not drawn, simply absent)
        $setNewsletter($onAtSave === true ? '1' : '0'); // on or off at the moment of saving
        $answer  = $http('POST', $form['action'], $fields);
        $subs    = $db->query("SELECT COUNT(*) AS n, MIN(`optedIn`) AS lowest FROM `tblNewsletterSubscription` WHERE `userID` = {$userId}")->fetch_assoc();
        $after   = $http('GET', '/account/notifications')['body'];
        $saved   = str_contains($after, 'Preferences saved.');
        // The success message adds "Your newsletter choice was not changed"
        // exactly when the newsletter is on AND the posted form did not show
        // the choice for this organisation. A shown box (changed or left as
        // drawn) and any save while the newsletter is off get plain
        // "Preferences saved." - D20, D21 and D23/D24 read the shown-box case.
        $toldNot = str_contains($after, 'Your newsletter choice was not changed');
        // Drawn and unticked: the member chose to leave, so ONE row, opted out -
        // whether or not the newsletter is on when they press Save (round 3).
        // Not drawn: nothing about the newsletter changes, row or no row.
        $want    = $drawnOn === true ? [1, 0] : ($hadRow === true ? [1, 1] : [0, null]);
        $now     = [(int) $subs['n'], $subs['lowest'] === null ? null : (int) $subs['lowest']];
        // "Not changed" is said exactly when the newsletter is on at save time
        // and the page did not show the choice; never while it is off.
        $ok      = $answer['status'] === 302 && $saved === true && $now === $want && $toldNot === ($drawnOn === false && $onAtSave === true);
        return [$ok, 'save HTTP ' . $answer['status'] . '; newsletter rows/lowest optedIn ' . $fmt($now) . ' (want ' . $fmt($want) . '); "Preferences saved." ' . ($saved ? 'shown' : 'NOT shown')
            . '; "newsletter choice was not changed" ' . ($toldNot ? 'said' : 'not said'), $premise];
    } finally {
        $setNewsletter('0');
        $db->query("DELETE FROM `tblNewsletterSubscription` WHERE `userID` = {$userId}");
        $state['newsletterTouched'] = false;
    }
};
[$okNotDrawnRow, $detailNotDrawnRow, $premiseOff] = $scenarioNewsletter(false, true);
[$okNotDrawnNone, $detailNotDrawnNone]             = $scenarioNewsletter(false, false);
[$okDrawn, $detailDrawn, $premiseOn]               = $scenarioNewsletter(true, true);
check('D10 (the premise) the newsletter box and its "drawn" marker are on the page, once each, only while the newsletter is switched on',
    $premiseOff === ['drawn' => false, 'markers' => 0] && $premiseOn === ['drawn' => true, 'markers' => 1], 'off ' . $fmt($premiseOff) . '; on ' . $fmt($premiseOn));
check('D11 (#875) a page opened with the newsletter OFF and saved after it was switched ON does NOT unsubscribe a member who is subscribed, and says the newsletter choice was not changed',
    $okNotDrawnRow, $detailNotDrawnRow);
check('D12 (#875) ...and writes no "opted out" row for a member who had never chosen',
    $okNotDrawnNone, $detailNotDrawnNone);
check('D13 a page that DREW the box, saved with it unticked, does unsubscribe (the choice still works)',
    $okDrawn, $detailDrawn);
[$okOffOff, $detailOffOff] = $scenarioNewsletter(false, false, false);
check('D18 with the newsletter OFF, drawn and saved, the page says only "Preferences saved." - nothing about a newsletter choice the page never offered - and writes no row',
    $okOffOff, $detailOffOff);
[$okOnOff, $detailOnOff] = $scenarioNewsletter(true, true, false);
check('D19 (#875, round 3) a member who saw the box and unticked it is unsubscribed even when the newsletter was switched OFF before they pressed Save, so switching it back on does not resubscribe them',
    $okOnOff, $detailOnOff);

/**
 * D20-D22 (#875, round 4 of its check): an OLD page must not resubscribe a
 * member who has since left by a newsletter's own unsubscribe link.
 *
 * The member is subscribed (a row, opted in, with a known unsubscribe
 * token); the page is drawn with the newsletter ON, so the box is ticked;
 * the newsletter is then switched OFF or left ON; the member uses the real
 * `/unsubscribe?token=` page (it works signed out and whatever the switch
 * says); then presses Save on the old page, box still ticked as drawn. The
 * box was not changed, so nothing may be written: the row stays opted OUT.
 *
 * @return array{0:bool, 1:string}
 */
$scenarioStaleAfterLink = static function (bool $onWhenSaved) use (&$state, $db, $http, $formOn, $userId, $siteId, $fmt): array {
    $setNewsletter = static function (string $on) use ($db): void {
        $db->query("UPDATE `tblSettings` SET `settingValue` = '{$on}' WHERE `settingKey` = 'newsletter.enabled' AND `siteID` IS NULL");
    };
    $state['newsletterTouched'] = true;
    $db->query("DELETE FROM `tblNewsletterSubscription` WHERE `userID` = {$userId}");
    $token = bin2hex(random_bytes(20));
    $db->query("INSERT INTO `tblNewsletterSubscription` (`siteID`, `userID`, `optedIn`, `unsubToken`) VALUES ({$siteId}, {$userId}, 1, '{$token}')");
    $optedIn = static fn (): ?int => ($r = $db->query("SELECT `optedIn` FROM `tblNewsletterSubscription` WHERE `userID` = {$userId} AND `siteID` = {$siteId}")->fetch_assoc()) === null ? null : (int) $r['optedIn'];
    try {
        $setNewsletter('1');
        $page   = $http('GET', '/account/notifications');
        $form   = $formOn($page['body'], 'account/notifications/save');
        $ticked = ($form['fields']['newsletterOptedIn'] ?? null) === '1';
        $setNewsletter($onWhenSaved === true ? '1' : '0');
        $link   = $http('GET', '/unsubscribe?token=' . $token);
        $afterLink = $optedIn();
        $answer = $http('POST', $form['action'], $form['fields']); // the old page, box left ticked
        $final  = $optedIn();
        // The commonest save of all - a shown box left as drawn - must say plain
        // "Preferences saved.", never that the choice "was not changed" because
        // the page "did not show it" (round 5 of the check: nothing pinned it).
        $after  = $http('GET', '/account/notifications')['body'];
        $plain  = str_contains($after, 'Preferences saved.') && str_contains($after, 'Your newsletter choice was not changed') === false;
        $ok = $ticked === true && $link['status'] === 200 && $afterLink === 0 && $answer['status'] === 302 && $final === 0 && $plain === true;
        return [$ok, 'box drawn ticked: ' . ($ticked ? 'yes' : 'no') . '; unsubscribe link HTTP ' . $link['status'] . ', then optedIn ' . $fmt($afterLink)
            . '; old page saved HTTP ' . $answer['status'] . ', then optedIn ' . $fmt($final) . ' (want 0); plain "Preferences saved.": ' . ($plain ? 'yes' : 'no')];
    } finally {
        $setNewsletter('0');
        $db->query("DELETE FROM `tblNewsletterSubscription` WHERE `userID` = {$userId}");
        $state['newsletterTouched'] = false;
    }
};
[$okStaleOff, $detailStaleOff] = $scenarioStaleAfterLink(false);
check('D20 (#875, round 4) a page drawn ticked, then the newsletter switched OFF, then the member unsubscribes by the e-mail link, then Save on the old page: they stay UNSUBSCRIBED, and are told plainly "Preferences saved."',
    $okStaleOff, $detailStaleOff);
[$okStaleOn, $detailStaleOn] = $scenarioStaleAfterLink(true);
check('D21 ...and the same with the newsletter left ON throughout (a window older than #875): they stay UNSUBSCRIBED',
    $okStaleOn, $detailStaleOn);

/**
 * D22: a member who has opted out is DRAWN UNTICKED, with a marker saying so.
 * Nothing else pins this, and the change rule depends on it: a page drawing
 * every box ticked would read an unchanged box as "they unticked it".
 *
 * @return array{0:bool, 1:string}
 */
$scenarioDrawnUnticked = static function () use (&$state, $db, $http, $formOn, $userId, $siteId): array {
    $state['newsletterTouched'] = true;
    $db->query("DELETE FROM `tblNewsletterSubscription` WHERE `userID` = {$userId}");
    $token = bin2hex(random_bytes(20));
    $db->query("INSERT INTO `tblNewsletterSubscription` (`siteID`, `userID`, `optedIn`, `unsubToken`) VALUES ({$siteId}, {$userId}, 0, '{$token}')");
    try {
        $db->query("UPDATE `tblSettings` SET `settingValue` = '1' WHERE `settingKey` = 'newsletter.enabled' AND `siteID` IS NULL");
        $body   = $http('GET', '/account/notifications')['body'];
        $form   = $formOn($body, 'account/notifications/save');
        $drawn  = str_contains($body, 'name="newsletterOptedIn"');
        $ticked = array_key_exists('newsletterOptedIn', $form['fields']);
        $marker = preg_match('/name="newsletterShown" value="([^"]*)"/', $body, $m) === 1 ? $m[1] : '(none)';
        return [$drawn === true && $ticked === false && $marker === $siteId . ':0',
            'box drawn: ' . ($drawn ? 'yes' : 'no') . '; ticked: ' . ($ticked ? 'yes' : 'no') . '; marker ' . $marker];
    } finally {
        $db->query("UPDATE `tblSettings` SET `settingValue` = '0' WHERE `settingKey` = 'newsletter.enabled' AND `siteID` IS NULL");
        $db->query("DELETE FROM `tblNewsletterSubscription` WHERE `userID` = {$userId}");
        $state['newsletterTouched'] = false;
    }
};
[$okUnticked, $detailUnticked] = $scenarioDrawnUnticked();
check('D22 a member who has opted out is drawn with the box UNTICKED, and the marker records it as drawn unticked',
    $okUnticked, $detailUnticked);

/**
 * D23-D24 (#875, round 5 of its check): OPTING IN, both ways round.
 *
 * D23: a member who has opted out sees the box drawn unticked, ticks it and
 * saves: they are subscribed again. Nothing pinned this, so a rule that never
 * let a tick subscribe anybody left every check green.
 *
 * D24, the mirror of D20: the page is drawn UNTICKED in one session; the
 * member subscribes through the page in a SECOND session (a second browser,
 * say: a second tab in the same session would spend the first page's form
 * token); then presses Save on the old page, box still unticked as drawn.
 * They must STAY subscribed - an old, unchanged box writes nothing.
 *
 * Both read the message too: a shown box gets plain "Preferences saved.",
 * never the "not changed" sentence.
 *
 * @return array{0:bool, 1:string}
 */
$scenarioOptIn = static function (bool $staleMirror) use (&$state, $db, $http, $formOn, $signIn, $jar, $userId, $siteId, $fmt): array {
    $state['newsletterTouched'] = true;
    $db->query("DELETE FROM `tblNewsletterSubscription` WHERE `userID` = {$userId}");
    $token = bin2hex(random_bytes(20));
    $db->query("INSERT INTO `tblNewsletterSubscription` (`siteID`, `userID`, `optedIn`, `unsubToken`) VALUES ({$siteId}, {$userId}, 0, '{$token}')");
    $optedIn = static fn (): ?int => ($r = $db->query("SELECT `optedIn` FROM `tblNewsletterSubscription` WHERE `userID` = {$userId} AND `siteID` = {$siteId}")->fetch_assoc()) === null ? null : (int) $r['optedIn'];
    $firstJar = $jar . '.first-session';
    try {
        $db->query("UPDATE `tblSettings` SET `settingValue` = '1' WHERE `settingKey` = 'newsletter.enabled' AND `siteID` IS NULL");
        $page  = $http('GET', '/account/notifications');
        $form  = $formOn($page['body'], 'account/notifications/save');
        $drawn = array_key_exists('newsletterOptedIn', $form['fields']) === false && str_contains($page['body'], 'name="newsletterOptedIn"');
        if ($staleMirror === false) {
            // D23: tick the box drawn unticked, and save.
            $answer = $http('POST', $form['action'], $form['fields'] + ['newsletterOptedIn' => '1']);
            $want   = 1;
        } else {
            // D24: subscribe in a SECOND session, then save the old page unchanged.
            copy($jar, $firstJar);
            $signIn(); // a fresh session in the same cookie file
            $other = $formOn($http('GET', '/account/notifications')['body'], 'account/notifications/save');
            $http('POST', $other['action'], $other['fields'] + ['newsletterOptedIn' => '1']);
            $between = $optedIn();
            copy($firstJar, $jar); // back to the first session and its old page
            $answer = $http('POST', $form['action'], $form['fields']);
            $want   = 1;
        }
        $final = $optedIn();
        $after = $http('GET', '/account/notifications')['body'];
        $plain = str_contains($after, 'Preferences saved.') && str_contains($after, 'Your newsletter choice was not changed') === false;
        $ok = $drawn === true && $answer['status'] === 302 && $final === $want && $plain === true && ($staleMirror === false || ($between ?? null) === 1);
        return [$ok, 'box drawn unticked: ' . ($drawn ? 'yes' : 'no') . ($staleMirror === true ? '; subscribed in the second session: optedIn ' . $fmt($between ?? null) : '')
            . '; saved HTTP ' . $answer['status'] . ', then optedIn ' . $fmt($final) . ' (want ' . $want . '); plain "Preferences saved.": ' . ($plain ? 'yes' : 'no')];
    } finally {
        @unlink($firstJar);
        $db->query("UPDATE `tblSettings` SET `settingValue` = '0' WHERE `settingKey` = 'newsletter.enabled' AND `siteID` IS NULL");
        $db->query("DELETE FROM `tblNewsletterSubscription` WHERE `userID` = {$userId}");
        $state['newsletterTouched'] = false;
    }
};
[$okOptIn, $detailOptIn] = $scenarioOptIn(false);
check('D23 (#875) a member who opted out, shown the box UNTICKED, ticks it and saves: they are subscribed again, and told plainly "Preferences saved."',
    $okOptIn, $detailOptIn);
[$okMirror, $detailMirror] = $scenarioOptIn(true);
check('D24 (#875) the mirror of D20: a page drawn unticked, the member subscribes in a second session, then Save on the old page left unchanged: they STAY subscribed',
    $okMirror, $detailMirror);

/**
 * D14-D17 (#875, round 2 of its check): a box drawn for ONE organisation must
 * never set ANOTHER organisation's newsletter choice. The save writes the
 * subscription of the organisation the request is in when it is POSTED; with
 * the organisation chosen per session, a member can switch in another tab
 * between drawing the page and pressing Save.
 *
 * The world: the organisation chosen per session (`multisite.*`), a second
 * organisation B of this test's own, the member in both, the newsletter on,
 * and a subscription row (opted IN) in each. The member switches through the
 * REAL switch page.
 *
 * Two posts of the SAME form, drawn for A, after switching to B:
 *   - with the form's OWN token. The switch spent that token (every accepted
 *     form replaces it), so this post is refused before the newsletter is
 *     reached. Measured, not assumed; it is D15, and it passes on the code
 *     before round 2 too.
 *   - with a CURRENT token, standing for any way the token on an open page
 *     can be renewed. Then only the organisation in the marker stands between
 *     A's box and B's subscription: D16, which goes red on the old marker
 *     (plant H15).
 *
 * @return array<string, mixed>
 */
$siteB = 987875;
$scenarioOtherOrganisation = static function () use (&$state, $db, $http, $formOn, $userId, $siteId, $siteB, $fmt): array {
    $tokenOn = static fn (string $html): string => preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : '';
    $switchTo = static function (int $site) use ($http, $tokenOn): int {
        $page = $http('GET', '/site/choose');
        return $http('POST', '/site/switch', ['csrf_token' => $tokenOn($page['body']), 'site_id' => (string) $site])['status'];
    };
    $markerOn = static fn (string $html): string => preg_match('/name="newsletterShown" value="([^"]*)"/', $html, $m) === 1 ? $m[1] : '(none)';
    $subs = static function () use ($db, $userId, $siteId, $siteB): array {
        $out = [];
        foreach ([$siteId => 'A', $siteB => 'B'] as $site => $label) {
            $row = $db->query("SELECT `optedIn` FROM `tblNewsletterSubscription` WHERE `userID` = {$userId} AND `siteID` = {$site}")->fetch_assoc();
            $out[$label] = $row === null ? null : (int) $row['optedIn'];
        }
        return $out;
    };
    $setSubs = static function () use ($db, $userId, $siteId, $siteB): void {
        $db->query("DELETE FROM `tblNewsletterSubscription` WHERE `userID` = {$userId}");
        foreach ([$siteId, $siteB] as $site) {
            $token = bin2hex(random_bytes(20));
            $db->query("INSERT INTO `tblNewsletterSubscription` (`siteID`, `userID`, `optedIn`, `unsubToken`) VALUES ({$site}, {$userId}, 1, '{$token}')");
        }
    };

    // Remember the two installation-wide multisite rows exactly, so the clean-up
    // (and a Ctrl-C, through the shutdown function) can put them back.
    $state['multisiteWas'] = $db->query("SELECT `settingKey`, `settingValue` FROM `tblSettings` WHERE `siteID` IS NULL AND `settingKey` IN ('multisite.enabled', 'multisite.detectionMode')")->fetch_all(MYSQLI_ASSOC);
    $state['siteB'] = $siteB;
    $state['newsletterTouched'] = true;
    $db->query("UPDATE `tblSettings` SET `settingValue` = 'true' WHERE `settingKey` = 'multisite.enabled' AND `siteID` IS NULL");
    $db->query("UPDATE `tblSettings` SET `settingValue` = 'session' WHERE `settingKey` = 'multisite.detectionMode' AND `siteID` IS NULL");
    $db->query("UPDATE `tblSettings` SET `settingValue` = '1' WHERE `settingKey` = 'newsletter.enabled' AND `siteID` IS NULL");
    $db->query("INSERT INTO `tblSites` (`siteID`, `siteKey`, `siteName`, `isActive`) VALUES ({$siteB}, 'selftest-notify-b', 'Notify Prefs Organisation B', 1)");
    $db->query("INSERT INTO `tblUserSites` (`siteID`, `userID`, `isActive`) VALUES ({$siteB}, {$userId}, 1)");
    try {
        $out = [];
        // ── A page drawn for A, saved after switching to B with its own token ──
        $setSubs();
        $out['switch to A'] = $switchTo($siteId);
        $pageA = $http('GET', '/account/notifications')['body'];
        $out['marker on A\'s page'] = $markerOn($pageA);
        $formA = $formOn($pageA, 'account/notifications/save');
        $fieldsA = $formA['fields'];
        unset($fieldsA['newsletterOptedIn']); // unticked on A's page
        $out['switch to B'] = $switchTo($siteB);
        $out['marker on B\'s page'] = $markerOn($http('GET', '/account/notifications')['body']);
        $out['own token: answer'] = $http('POST', $formA['action'], $fieldsA)['status'];
        $out['own token: subscriptions'] = $subs();
        $out['own token: told expired'] = str_contains($http('GET', '/account/notifications')['body'], 'Invalid or expired form token.');
        // ── The same fields, with a CURRENT token ──
        $setSubs();
        $fresh = $formOn($http('GET', '/account/notifications')['body'], 'account/notifications/save');
        $out['current token: answer'] = $http('POST', $formA['action'], ['csrf_token' => $fresh['fields']['csrf_token'] ?? ''] + $fieldsA)['status'];
        $out['current token: subscriptions'] = $subs();
        $out['current token: told not changed'] = str_contains($http('GET', '/account/notifications')['body'], 'Your newsletter choice was not changed');
        // ── B's own page, unticked, in B: B's choice changes, A's does not ──
        $setSubs();
        $fieldsB = $fresh['fields'];
        $fieldsB['csrf_token'] = $formOn($http('GET', '/account/notifications')['body'], 'account/notifications/save')['fields']['csrf_token'] ?? '';
        unset($fieldsB['newsletterOptedIn']);
        $out['B\'s own page: answer'] = $http('POST', $fresh['action'], $fieldsB)['status'];
        $out['B\'s own page: subscriptions'] = $subs();
        $out['B\'s own page: told not changed'] = str_contains($http('GET', '/account/notifications')['body'], 'Your newsletter choice was not changed');
        $switchTo($siteId);
        return $out;
    } finally {
        $db->query("DELETE FROM `tblNewsletterSubscription` WHERE `userID` = {$userId}");
        $db->query("DELETE FROM `tblUserSites` WHERE `siteID` = {$siteB}");
        $rs = $db->query("SELECT `TABLE_NAME`, `COLUMN_NAME` FROM `information_schema`.`KEY_COLUMN_USAGE` WHERE `TABLE_SCHEMA` = DATABASE() AND `REFERENCED_TABLE_NAME` = 'tblSites'");
        while ($fk = $rs->fetch_assoc()) {
            try {
                $db->query('DELETE FROM `' . $fk['TABLE_NAME'] . '` WHERE `' . $fk['COLUMN_NAME'] . "` = {$siteB}");
            } catch (Throwable $e) {
                // A table whose own parent has already gone, or another shape.
            }
        }
        try {
            $db->query("DELETE FROM `tblActivityLogs` WHERE `siteID` = {$siteB}");
        } catch (Throwable $e) {
            // Removed with the other rows that name B above, if the column links.
        }
        $db->query("DELETE FROM `tblSites` WHERE `siteID` = {$siteB}");
        foreach ($state['multisiteWas'] as $was) {
            $u = $db->prepare('UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingKey` = ? AND `siteID` IS NULL');
            $u->bind_param('ss', $was['settingValue'], $was['settingKey']);
            $u->execute();
            $u->close();
        }
        $db->query("UPDATE `tblSettings` SET `settingValue` = '0' WHERE `settingKey` = 'newsletter.enabled' AND `siteID` IS NULL");
        $state['multisiteWas'] = null;
        $state['siteB'] = null;
        $state['newsletterTouched'] = false;
    }
};
$both = ['A' => 1, 'B' => 1];
$other = $scenarioOtherOrganisation();
check('D14 (the premise) the organisation really is chosen per session: the page drawn in A marks the newsletter box for A, and after the real switch it marks it for B',
    ($other['marker on A\'s page'] ?? null) === $siteId . ':1' && ($other['marker on B\'s page'] ?? null) === $siteB . ':1', $fmt($other));
check('D15 a form drawn for A, posted with its own token after switching to B, is refused and changes neither subscription (the switch spent that token: measured, and true before this check existed too)',
    ($other['own token: told expired'] ?? null) === true && ($other['own token: subscriptions'] ?? null) === $both,
    $fmt([$other['own token: answer'] ?? null, $other['own token: told expired'] ?? null, $other['own token: subscriptions'] ?? null]));
check('D16 (#875) a form drawn for A, posted in B with a CURRENT token, does NOT change B\'s newsletter choice (or A\'s): its box was about A; and the member is told the newsletter choice was not changed',
    ($other['current token: answer'] ?? null) === 302 && ($other['current token: subscriptions'] ?? null) === $both && ($other['current token: told not changed'] ?? null) === true,
    $fmt([$other['current token: answer'] ?? null, $other['current token: subscriptions'] ?? null, $other['current token: told not changed'] ?? null]));
check('D17 ...while B\'s own page, saved unticked in B, does unsubscribe from B and leaves A alone (the marker still works)',
    ($other['B\'s own page: answer'] ?? null) === 302 && ($other['B\'s own page: subscriptions'] ?? null) === ['A' => 1, 'B' => 0] && ($other['B\'s own page: told not changed'] ?? null) === false,
    $fmt([$other['B\'s own page: answer'] ?? null, $other['B\'s own page: subscriptions'] ?? null, $other['B\'s own page: told not changed'] ?? null]));
// Every section below runs as the member signed in afresh, in one organisation.
$signIn();

// =============================================================================
echo "\nE. A stored value that is not a set of named choices, on BOTH pages\n";
// =============================================================================
/**
 * Save on one page over a stored value that is valid JSON but not a set of
 * named choices, and read back what was stored and how many replacements
 * were recorded.
 *
 * @return array{0:bool, 1:string}
 */
$scenarioUnreadable = static function (string $raw, callable $save, array $want) use ($db, $setStored, $stored, $fmt): array {
    $db->query("DELETE FROM `tblErrors` WHERE `errorCode` = 'NOTIFY_PREFS_UNREADABLE'");
    $setStored($raw);
    $answer   = $save();
    $now      = $stored();
    $recorded = (int) ($db->query("SELECT COUNT(*) AS n FROM `tblErrors` WHERE `errorCode` = 'NOTIFY_PREFS_UNREADABLE'")->fetch_assoc()['n'] ?? 0);
    ksort($want);
    return [$answer['status'] === 302 && $now === $want && $recorded === 1, 'save HTTP ' . $answer['status'] . '; stored ' . $fmt($now) . '; recorded ' . $recorded . ' time(s)'];
};
$fullDrawnOn = array_fill_keys($fullForm['markers'], true);
$scenarioECard = static fn (): array => $scenarioUnreadable('[false, true]', static fn (): array => $saveCard(false), ['eventReminders' => false]);
$scenarioEFull = static fn (): array => $scenarioUnreadable('[1, 2]', static fn (): array => $saveFullPage([]), $fullDrawnOn);

[$okE1, $detailE1] = $scenarioECard();
check('E1 on the CARD, a stored JSON list is replaced by the one choice just saved, and the replacement is RECORDED (every other choice still reads as ON, as it already did)', $okE1, $detailE1);
[$okE2, $detailE2] = $scenarioUnreadable('5', static fn (): array => $saveCard(true), ['eventReminders' => true]);
check('E2 ...and a bare number the same way', $okE2, $detailE2);
[$okE3, $detailE3] = $scenarioEFull();
check('E3 on the FULL PAGE, a stored JSON list is replaced by the switches it drew, and the replacement is RECORDED there too', $okE3, $detailE3);
$db->query("DELETE FROM `tblErrors` WHERE `errorCode` = 'NOTIFY_PREFS_UNREADABLE'");
$setStored(null);
$saveCard(true);
$quiet = (int) ($db->query("SELECT COUNT(*) AS n FROM `tblErrors` WHERE `errorCode` = 'NOTIFY_PREFS_UNREADABLE'")->fetch_assoc()['n'] ?? 0);
check('E4 an EMPTY column is "nothing chosen yet", saved without any record', $quiet === 0 && $stored() === ['eventReminders' => true], 'rows ' . $quiet . '; stored ' . $fmt($stored()));
// =============================================================================
echo "\nF. Two saves at once lose nothing\n";
// =============================================================================
/**
 * Start one worker: the REAL `merge()`, from the COPY, in its own process,
 * switching ONE key off. It is put on `$state['workers']` the moment it
 * exists, so the clean-up can always stop it.
 *
 * @return array{proc:mixed, out:string, idFile:string}
 */
$startWorker = static function (string $key) use (&$state, $userId, $child, $sep, $dbHost, $dbUser, $dbPass, $dbName, $dbPort): array {
    $stem = $child . $sep . 'worker-' . bin2hex(random_bytes(4));
    $env  = getenv();
    $env['NP_HOST'] = $dbHost;
    $env['NP_USER'] = $dbUser;
    $env['NP_PASS'] = $dbPass;
    $env['NP_NAME'] = $dbName;
    $env['NP_PORT'] = (string) $dbPort;
    $proc = proc_open(
        [PHP_BINARY, $child . $sep . 'worker.php', $child . $sep . 'web' . $sep . '_core' . $sep . 'NotifyPrefs.php', (string) $userId, $key, $stem . '.id'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $stem . '.out', 'w'], 2 => ['file', $stem . '.err', 'w']],
        $pipes,
        $child,
        $env
    );
    if (is_resource($proc) === false) {
        throw new RuntimeException('could not start a worker process');
    }
    $state['workers'][] = $proc;
    return ['proc' => $proc, 'out' => $stem . '.out', 'idFile' => $stem . '.id'];
};

/**
 * Wait for a worker to finish (or, with `$stop`, stop it), and answer what
 * it printed. It is taken off `$state['workers']` either way.
 *
 * @param array{proc:mixed, out:string, idFile:string} $worker
 */
$finishWorker = static function (array $worker, bool $stop = false) use (&$state): string {
    if (is_resource($worker['proc']) === true) {
        for ($i = 0; $stop === false && $i < 300 && proc_get_status($worker['proc'])['running'] === true; $i++) {
            usleep(100000);
        }
        if (proc_get_status($worker['proc'])['running'] === true) {
            proc_terminate($worker['proc']);
        }
        proc_close($worker['proc']);
    }
    $state['workers'] = array_values(array_filter($state['workers'], static fn ($p): bool => $p !== $worker['proc']));
    return trim((string) @file_get_contents($worker['out']) . ' ' . (string) @file_get_contents(substr($worker['out'], 0, -4) . '.err'));
};

/**
 * ⚠️ THE PREMISE, ASKED OF EACH WORKER'S OWN CONNECTION. Is every one of these
 *    workers connected and WAITING for a lock right now? Each writes its
 *    connection's thread id to a file before it asks for the row, and only a
 *    lock wait on THAT thread counts — the first version counted any lock wait
 *    anywhere on the server, which another test sharing the server could
 *    satisfy (independent check, round 2). A sleep is a hope; this is the
 *    premise (#735).
 *
 * @param list<array{proc:mixed, out:string, idFile:string}> $workers
 */
$allWaiting = static function (array $workers) use ($dbHost, $dbUser, $dbPass, $dbName, $dbPort): bool {
    for ($i = 0; $i < 80; $i++) {
        usleep(100000);
        $ids = [];
        foreach ($workers as $worker) {
            $ids[] = (int) @file_get_contents($worker['idFile']);
        }
        if (in_array(0, $ids, true) === true) {
            continue;
        }
        $probe = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
        $n = (int) ($probe->query(
            "SELECT COUNT(*) AS n FROM information_schema.INNODB_TRX WHERE `trx_state` = 'LOCK WAIT' AND `trx_mysql_thread_id` IN (" . implode(',', $ids) . ')'
        )->fetch_assoc()['n'] ?? 0);
        $probe->close();
        if ($n === count($workers)) {
            return true;
        }
    }
    return false;
};

/**
 * End this test's locking connection FIRST, then stop any worker still
 * running. Every scenario below calls this from its `finally`, BEFORE it drops
 * a trigger: a DROP TRIGGER on `tblUsers` waits for every open transaction on
 * that table, so dropping one while `$a` was still open made this process wait
 * on itself (round 2 — then for a year, now for at most fifteen seconds).
 *
 * @param list<array{proc:mixed, out:string, idFile:string}|null> $workers
 */
$endHolders = static function (?mysqli $a, array $workers) use (&$state, $finishWorker): void {
    if ($a !== null) {
        try {
            $a->rollback();
            $a->close();
        } catch (Throwable $e) {
            // Already committed and closed on the normal path, or gone.
        }
    }
    $state['a'] = null;
    foreach ($workers as $worker) {
        if ($worker !== null) {
            $finishWorker($worker, true);
        }
    }
};

/** A second connection for this test, with short lock waits, known to the clean-up. */
$openHolder = static function () use (&$state, $dbHost, $dbUser, $dbPass, $dbName, $dbPort): mysqli {
    $a = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $state['a'] = $a;
    $a->query('SET SESSION lock_wait_timeout = 15, innodb_lock_wait_timeout = 15');
    return $a;
};

/**
 * F1-F3. This test's own connection takes the row lock and holds it. The REAL
 * `merge()` then runs in its own process (one process cannot block on
 * itself) and must WAIT; while it waits, this connection changes a
 * different key and commits. With the lock, the worker reads the committed
 * value afterwards and keeps that change. Without it, the worker reads the
 * old value straight away, then waits only at its write, and writes the old
 * value back over this connection's change (F2).
 *
 * ⚠️ F2 CANNOT SEE THE TRANSACTION GO, and the independent check proved it:
 *    with `begin_transaction()` removed, the worker's `FOR UPDATE` read still
 *    BLOCKS here (this connection holds the row), then autocommits, reads the
 *    committed value and writes it — so the keys come out right. The lock
 *    still evaporated between the read and the write; it is just that THIS
 *    interleaving cannot exploit it. So F3 measures the transaction itself:
 *    a scratch BEFORE UPDATE trigger records, for the worker's write, when
 *    its InnoDB transaction BEGAN (`INNODB_TRX.trx_started`). With the
 *    transaction, it began at the blocked read — held here for seconds on
 *    purpose — so it began well before the write. Without it, it began AT the
 *    write. `trx_started` has whole seconds only, hence the 2.5-second hold
 *    and the 1.5-second threshold.
 *
 * @return array{0:bool, 1:string, 2:bool, 3:bool, 4:string}  ok, detail, premise, one transaction, its detail
 */
$scenarioF = static function () use ($db, $makeTrigger, $setStored, $stored, $userId, $fmt, $startWorker, $finishWorker, $allWaiting, $endHolders, $openHolder): array {
    $setStored('{"rotaReminders": true, "taskReminders": true, "zzFutureChoice": "kept"}');
    $db->query('DELETE FROM `zzNotifyPrefsProbe`');
    $db->query('DROP TRIGGER IF EXISTS `zzNotifyPrefsProbeTrg`');
    $a      = null;
    $worker = null;
    try {
        $a   = $openHolder();
        $aId = (int) $a->thread_id;
        // ⚠️ THE TRIGGER MUST NOT READ INNODB_TRX FOR THIS TEST'S OWN WRITE.
        //    MySQL serves INNODB_TRX from a cache that is refreshed only when it
        //    has not been read for 0.1 s. This connection's write comes a
        //    moment before the worker's, so when the first version recorded
        //    BOTH, the worker's lookup was answered from the cache filled by
        //    this connection's lookup a few milliseconds earlier — which still
        //    showed the worker's transaction from its blocked READ. So the
        //    plant that removes the transaction measured as "began at the
        //    read" and passed. Only the worker's own write reads it now, and
        //    nothing else here reads INNODB_TRX during the 2.5-second hold.
        $makeTrigger(
            'CREATE TRIGGER `zzNotifyPrefsProbeTrg` BEFORE UPDATE ON `tblUsers` FOR EACH ROW BEGIN '
            . "IF CONNECTION_ID() <> {$aId} THEN "
            . 'INSERT INTO `zzNotifyPrefsProbe` (`connID`, `trxStarted`, `seenAt`) '
            . 'SELECT CONNECTION_ID(), (SELECT `trx_started` FROM information_schema.INNODB_TRX WHERE `trx_mysql_thread_id` = CONNECTION_ID() LIMIT 1), NOW(6); '
            . 'END IF; END'
        );
        $a->begin_transaction();
        $held = $a->query("SELECT `notifyPrefs` FROM `tblUsers` WHERE `userID` = {$userId} FOR UPDATE")->fetch_assoc();

        $worker  = $startWorker('taskReminders');
        $waiting = $allWaiting([$worker]);
        // Hold the row long enough that a transaction begun at the worker's
        // read is measurably older than its write (see F3 above).
        usleep(2500000);

        $mine = json_decode((string) $held['notifyPrefs'], true);
        $mine['rotaReminders'] = false;
        $json = json_encode($mine);
        $w = $a->prepare('UPDATE `tblUsers` SET `notifyPrefs` = ? WHERE `userID` = ?');
        $w->bind_param('si', $json, $userId);
        $w->execute();
        $w->close();
        $a->commit();
        $a->close();
        $a = null;

        $said   = $finishWorker($worker);
        $worker = null;
        $now    = $stored() ?? [];
        $ok     = str_contains($said, '"outcome":"saved"') === true
            && ($now['rotaReminders'] ?? null) === false && ($now['taskReminders'] ?? null) === false && ($now['zzFutureChoice'] ?? null) === 'kept';

        $probeRow = $db->query(
            'SELECT `trxStarted`, `seenAt`, TIMESTAMPDIFF(MICROSECOND, `trxStarted`, `seenAt`) AS `gap` '
            . "FROM `zzNotifyPrefsProbe` WHERE `connID` <> {$aId} ORDER BY `probeID` DESC LIMIT 1"
        )->fetch_assoc();
        $gap      = ($probeRow !== null && $probeRow['gap'] !== null) ? ((int) $probeRow['gap']) / 1000000 : null;
        $oneTrx   = $gap !== null && $gap >= 1.5;
        $trxWords = $probeRow === null
            ? 'the worker\'s write was never recorded'
            : 'the worker\'s transaction began ' . ($probeRow['trxStarted'] ?? 'NOT RECORDED') . ', its write ran ' . $probeRow['seenAt']
                . ($gap === null ? '' : ' (' . number_format($gap, 2) . ' s later)');

        return [$ok, 'worker said ' . $said . '; stored ' . $fmt($now), $waiting, $oneTrx, $trxWords];
    } finally {
        $endHolders($a, [$worker]);
        $db->query('DROP TRIGGER IF EXISTS `zzNotifyPrefsProbeTrg`');
    }
};

/**
 * F4-F5. BOTH saves are WAITING at their read before EITHER writes (F4 is
 * that premise, measured on each worker's own connection). This test's
 * connection holds the row while two workers start, each switching a
 * different key off, and both wait at their read; then it lets go. With an EXCLUSIVE lock (`FOR
 * UPDATE`) only one may hold the row, so the second reads only after the
 * first has written: both changes land. With a SHARED lock (`LOCK IN SHARE
 * MODE` / `FOR SHARE`) both read at once, both then need the row to write,
 * and the database refuses one as a deadlock: one change is lost and that
 * worker answers "failed". F1-F3 cannot tell the two locks apart, because
 * there only ONE save is waiting (independent check, round 2).
 *
 * @return array{0:bool, 1:string, 2:bool}  ok, detail, premise (both really waited)
 */
$scenarioBoth = static function () use ($setStored, $stored, $userId, $fmt, $startWorker, $finishWorker, $allWaiting, $endHolders, $openHolder): array {
    $setStored('{"rotaReminders": true, "taskReminders": true, "zzFutureChoice": "kept"}');
    $a = null;
    $one = null;
    $two = null;
    try {
        $a = $openHolder();
        $a->begin_transaction();
        $a->query("SELECT `notifyPrefs` FROM `tblUsers` WHERE `userID` = {$userId} FOR UPDATE")->free();
        $one     = $startWorker('taskReminders');
        $two     = $startWorker('rotaReminders');
        $waiting = $allWaiting([$one, $two]);
        $a->rollback();
        $a->close();
        $a = null;
        $saidOne = $finishWorker($one);
        $one     = null;
        $saidTwo = $finishWorker($two);
        $two     = null;
        $now     = $stored() ?? [];
        $ok      = str_contains($saidOne, '"outcome":"saved"') === true && str_contains($saidTwo, '"outcome":"saved"') === true
            && ($now['taskReminders'] ?? null) === false && ($now['rotaReminders'] ?? null) === false && ($now['zzFutureChoice'] ?? null) === 'kept';
        return [$ok, 'first worker said ' . $saidOne . '; second said ' . $saidTwo . '; stored ' . $fmt($now), $waiting];
    } finally {
        $endHolders($a, [$one, $two]);
    }
};

[$okF, $detailF, $premiseF, $oneTrxF, $trxDetailF] = $scenarioF();
check('F1 (the premise) the real merge() was connected and WAITING on the row — its OWN connection — while this test held it; otherwise F2 and F3 prove nothing', $premiseF);
check('F2 THE LOCK: both saves landed — this connection\'s rotaReminders=off AND the worker\'s taskReminders=off — and the unknown key survived', $okF, $detailF);
check('F3 THE TRANSACTION: merge()\'s read and write are ONE transaction — it began at the blocked read, well before the write — so its lock is held across both (#647, #694)',
    $oneTrxF, $trxDetailF);
echo "      ({$trxDetailF})\n";
[$okBoth, $detailBoth, $premiseBoth] = $scenarioBoth();
check('F4 (the premise) BOTH workers were connected and waiting at their read at the same moment', $premiseBoth);
check('F5 THE LOCK IS EXCLUSIVE: with both saves waiting at their read before either writes, both still land and both answer "saved" — a shared lock would let both read at once, deadlock and lose one',
    $okBoth, $detailBoth);

// =============================================================================
echo "\nG. When the database refuses the save, both pages say so and nothing changes\n";
// =============================================================================
/**
 * A scratch trigger refuses any change to THIS member's choices, so the real
 * `merge()` meets a real refusal inside its transaction — no stand-in. Both
 * pages must then report the failure (the card by `?error=notify_db`, the
 * full page by its message) and the column must be as it was.
 *
 * @return array{0:bool, 1:string}
 */
$scenarioRefused = static function () use ($db, $makeTrigger, $http, $setStored, $stored, $saveCard, $saveFullPage, $userId, $fmt): array {
    $setStored('{"eventReminders": true, "taskReminders": true, "zzFutureChoice": "kept"}');
    $want = $stored();
    $db->query('DROP TRIGGER IF EXISTS `zzNotifyPrefsRefuseTrg`');
    $makeTrigger(
        'CREATE TRIGGER `zzNotifyPrefsRefuseTrg` BEFORE UPDATE ON `tblUsers` FOR EACH ROW BEGIN '
        . "IF NEW.`userID` = {$userId} AND NOT (NEW.`notifyPrefs` <=> OLD.`notifyPrefs`) THEN "
        . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by tools/notify-prefs-selftest.php'; END IF; END"
    );
    try {
        $card      = $saveCard(false);
        $afterCard = $stored();
        $cardSays  = str_contains($http('GET', '/account?error=notify_db')['body'], 'could not be saved, and nothing was changed') === true;
        $full      = $saveFullPage(['taskReminders']);
        $afterFull = $stored();
        $fullSays  = str_contains($http('GET', '/account/notifications')['body'], 'Database error saving preferences.') === true;
    } finally {
        $db->query('DROP TRIGGER IF EXISTS `zzNotifyPrefsRefuseTrg`');
    }
    $ok = str_contains($card['location'], 'error=notify_db') === true && $afterCard === $want && $cardSays === true
        && $full['status'] === 302 && $afterFull === $want && $fullSays === true;
    return [$ok, 'card to ' . $card['location'] . ', stored after ' . $fmt($afterCard) . ', card page says it: ' . ($cardSays ? 'yes' : 'no')
        . '; full page HTTP ' . $full['status'] . ', stored after ' . $fmt($afterFull) . ', page says it: ' . ($fullSays ? 'yes' : 'no')];
};
[$okG, $detailG] = $scenarioRefused();
check('G1 the card answers ?error=notify_db and the Account page says nothing was saved; the full page says "Database error saving preferences."; the column is unchanged by either',
    $okG, $detailG);

// =============================================================================
echo "\nH. The plants: each fault put back into a COPY must turn its check RED\n";
// =============================================================================
/**
 * Replace `$from` with `$to` in a file of the COPY, run `$scenario`, put the
 * file back, and report whether the plant really went in and whether the
 * scenario went red. A plant that did not apply is reported as such, never
 * counted as caught.
 *
 * @return array{applied:bool, red:bool, detail:string, restored:bool}
 */
$plant = static function (string $relative, string $from, string $to, callable $scenario) use ($child, $sep): array {
    $path   = $child . $sep . 'web' . $sep . str_replace('/', $sep, $relative);
    $before = (string) file_get_contents($path);
    if (substr_count($before, $from) !== 1) {
        return ['applied' => false, 'red' => false, 'detail' => 'the text to replace was found ' . substr_count($before, $from) . ' times', 'restored' => true];
    }
    file_put_contents($path, str_replace($from, $to, $before));
    clearstatcache(true, $path);
    try {
        $result = $scenario();
    } finally {
        file_put_contents($path, $before);
        clearstatcache(true, $path);
    }
    return ['applied' => true, 'red' => $result[0] === false, 'detail' => $result[1], 'restored' => (string) file_get_contents($path) === $before];
};

$plants = [];

$plants['H1 the OLD whole-column rebuild, in the full page\'s handler, turns B1 RED'] = $plant(
    '_apps/auth/account/notifications-save.php',
    '$merged = NotifyPrefs::merge($mysqli, $userId, $changes);',
    '$rebuilt = []; foreach (NotifyPrefs::SETTABLE as $k) { $rebuilt[$k] = ($changes[$k] ?? false); }'
    . ' $rebuiltJson = json_encode($rebuilt); $st = $mysqli->prepare(\'UPDATE tblUsers SET notifyPrefs = ? WHERE userID = ?\');'
    . ' $st->bind_param(\'si\', $rebuiltJson, $userId); $st->execute(); $st->close();'
    . ' $merged = [\'outcome\' => NotifyPrefs::SAVED, \'replacedUnreadable\' => false];',
    $scenarioB
);

$plants['H2 the LOCK removed from merge() turns F2 RED (one key is lost)'] = $plant(
    '_core/NotifyPrefs.php',
    "'SELECT `notifyPrefs` FROM `tblUsers` WHERE `userID` = ? FOR UPDATE'",
    "'SELECT `notifyPrefs` FROM `tblUsers` WHERE `userID` = ?'",
    static function () use ($scenarioF): array {
        $r = $scenarioF();
        return [$r[0], $r[1]];
    }
);

$plants['H3 the TRANSACTION removed from merge() (so FOR UPDATE autocommits) turns F3 RED'] = $plant(
    '_core/NotifyPrefs.php',
    '$began = $db->begin_transaction();',
    '$began = true;',
    static function () use ($scenarioF): array {
        $r = $scenarioF();
        return [$r[3], $r[4] . '; keys: ' . ($r[0] ? 'both landed' : 'one LOST')];
    }
);

$plants['H4 "no marker means change nothing" removed (a missing marker read as every switch drawn) turns D1 RED'] = $plant(
    '_core/NotifyPrefs.php',
    "        if (array_key_exists(self::SHOWN_FIELD, \$post) === false || is_array(\$post[self::SHOWN_FIELD]) === false) {\n            return null;\n        }",
    "        if (array_key_exists(self::SHOWN_FIELD, \$post) === false || is_array(\$post[self::SHOWN_FIELD]) === false) {\n            \$post[self::SHOWN_FIELD] = array_fill_keys(self::SETTABLE, '1');\n        }",
    $scenarioD
);

// The card's handler as it stood after #612 and before #667: read, change its
// own keys (including the two it no longer draws, written as off because an
// absent box looks unticked), write back, with no lock and no marker.
$plants['H5 the card\'s OLD handler (#612\'s read-then-write, still writing the two retired keys as off) turns C1 RED'] = $plant(
    '_apps/auth/account/save.php',
    '    $merged = NotifyPrefs::merge($mysqli, $userId, $changes);',
    '    $oldRead = $mysqli->prepare(\'SELECT notifyPrefs FROM tblUsers WHERE userID = ? LIMIT 1\'); $oldRead->bind_param(\'i\', $userId);'
    . ' $oldRead->execute(); $oldRow = $oldRead->get_result()->fetch_assoc(); $oldRead->close();'
    . ' $oldStored = json_decode((string) ($oldRow[\'notifyPrefs\'] ?? \'\'), true); if (is_array($oldStored) === false) { $oldStored = []; }'
    . ' $oldStored[\'emailDigest\'] = isset($_POST[\'emailDigest\']) === true; $oldStored[\'expenseUpdates\'] = isset($_POST[\'expenseUpdates\']) === true;'
    . ' $oldStored[\'eventReminders\'] = isset($_POST[\'prefs\'][\'eventReminders\']) === true; $oldJson = json_encode($oldStored);'
    . ' $oldWrite = $mysqli->prepare(\'UPDATE tblUsers SET notifyPrefs = ? WHERE userID = ?\'); $oldWrite->bind_param(\'si\', $oldJson, $userId);'
    . ' $oldWrite->execute(); $oldWrite->close(); $merged = [\'outcome\' => NotifyPrefs::SAVED, \'replacedUnreadable\' => false];',
    $scenarioC
);

$plants['H6 the FULL PAGE\'s record of a replaced value switched off turns E3 RED'] = $plant(
    '_apps/auth/account/notifications-save.php',
    "if (\$merged['replacedUnreadable'] === true) {",
    'if (false) {',
    $scenarioEFull
);

$plants['H7 the CARD\'s record of a replaced value switched off turns E1 RED'] = $plant(
    '_apps/auth/account/save.php',
    "    if (\$merged['replacedUnreadable'] === true) {",
    '    if (false) {',
    $scenarioECard
);

$plants['H8 merge() answering "saved" when the database refused turns G1 RED'] = $plant(
    '_core/NotifyPrefs.php',
    "rolls back for us.\n                }\n            }\n            return ['outcome' => self::FAILED, 'replacedUnreadable' => false];",
    "rolls back for us.\n                }\n            }\n            return ['outcome' => self::SAVED, 'replacedUnreadable' => false];",
    $scenarioRefused
);

$plants['H9 the tick loosened from exactly \'1\' to "anything truthy" turns D7 RED'] = $plant(
    '_core/NotifyPrefs.php',
    "\$ticked[\$key] === '1');",
    '(bool) $ticked[$key] === true);',
    $scenarioTick
);

$plants['H10 markers naming only unsettable keys treated as a save (not as out of date) turns D9 RED'] = $plant(
    '_core/NotifyPrefs.php',
    "        if (\$changes === []) {\n            return null;\n        }",
    "        if (\$changes === []) {\n        }",
    static function () use ($scenarioOnlyUnsettable): array {
        $r = $scenarioOnlyUnsettable();
        return [$r[0], $r[1]];
    }
);

// `LOCK IN SHARE MODE` rather than `FOR SHARE`, because MariaDB knows only
// the former; on MySQL the two are the same lock. F1-F3 stay GREEN with this
// plant (one waiting save cannot tell the locks apart); only F5 sees it.
$plants['H11 the lock made SHARED instead of exclusive turns F5 RED (a deadlock loses one save)'] = $plant(
    '_core/NotifyPrefs.php',
    "WHERE `userID` = ? FOR UPDATE'",
    "WHERE `userID` = ? LOCK IN SHARE MODE'",
    $scenarioBoth
);

// #875: the newsletter choice saved only from a form that drew it.
$plants['H13 the handler no longer asking whether the newsletter box was DRAWN (the old isset) turns D11 RED'] = $plant(
    '_apps/auth/account/notifications-save.php',
    "if (\$newsletterChoice !== null) {\n    \$optedIn      = \$newsletterChoice === true ? 1 : 0;",
    "if (\$newsletterOn === '1' || \$newsletterOn === 'true') {\n    \$optedIn      = isset(\$_POST['newsletterOptedIn']) === true ? 1 : 0;",
    static function () use ($scenarioNewsletter): array {
        $r = $scenarioNewsletter(false, true);
        return [$r[0], $r[1]];
    }
);
$plants['H14 the page no longer drawing the "drawn" marker beside the box turns D13 RED (unsubscribing stops working)'] = $plant(
    '_apps/auth/account/notifications.php',
    'echo Newsletter::choiceShownMarker($siteId, $newsletterOptedIn); ?>',
    '?>',
    static function () use ($scenarioNewsletter): array {
        $r = $scenarioNewsletter(true, true);
        return [$r[0], $r[1]];
    }
);

$plants['H15 the newsletter marker no longer naming the organisation (any marker read as "drawn") turns D16 RED: A\'s box sets B\'s choice'] = $plant(
    '_core/Newsletter.php',
    "        return in_array(\$post[self::CHOICE_SHOWN_FIELD] ?? null, [\$siteId . ':0', \$siteId . ':1'], true);",
    "        return preg_match('/^\\\\d+:[01]\$/', (string) (\$post[self::CHOICE_SHOWN_FIELD] ?? '')) === 1;",
    static function () use ($scenarioOtherOrganisation, $signIn): array {
        $r = $scenarioOtherOrganisation();
        $signIn();
        $ok = ($r['current token: subscriptions'] ?? null) === ['A' => 1, 'B' => 1];
        return [$ok, 'current token: subscriptions ' . json_encode($r['current token: subscriptions'] ?? null)];
    }
);

$plants['H16 the save asking again whether the newsletter is on NOW turns D19 RED: an unsubscribe the page showed is dropped'] = $plant(
    '_apps/auth/account/notifications-save.php',
    "if (\$newsletterChoice !== null) {\n    \$optedIn",
    "if ((\$newsletterOn === '1' || \$newsletterOn === 'true') && \$newsletterChoice !== null) {\n    \$optedIn",
    static function () use ($scenarioNewsletter): array {
        $r = $scenarioNewsletter(true, true, false);
        return [$r[0], $r[1]];
    }
);
$plants['H17 the "not changed" sentence no longer asking whether the newsletter is on turns D18 RED: it is said about a choice the page never offered'] = $plant(
    '_apps/auth/account/notifications-save.php',
    "\$_SESSION['notifications_flash']      = ((\$newsletterOn === '1' || \$newsletterOn === 'true') && \$newsletterShown === false)",
    "\$_SESSION['notifications_flash']      = (\$newsletterShown === false)",
    static function () use ($scenarioNewsletter): array {
        $r = $scenarioNewsletter(false, false, false);
        return [$r[0], $r[1]];
    }
);

$plants['H18 the save writing whatever an old page posted (not only a CHANGE) turns D20 RED: the link\'s unsubscribe is overwritten'] = $plant(
    '_core/Newsletter.php',
    "        return \$postedTick === \$drawnTicked ? null : \$postedTick;",
    "        return \$postedTick;",
    static fn (): array => $scenarioStaleAfterLink(false)
);
$plants['H19 the page drawing every box ticked turns D22 RED: an opted-out member is shown as subscribed'] = $plant(
    '_apps/auth/account/notifications.php',
    "<?php echo \$newsletterOptedIn === true ? 'checked' : ''; ?>>",
    "checked>",
    $scenarioDrawnUnticked
);

$plants['H20 the "not changed" sentence keyed on "nothing was written" (round 4\'s condition) turns D21 RED: a shown box left as drawn is told it was not shown'] = $plant(
    '_apps/auth/account/notifications-save.php',
    "&& \$newsletterShown === false)",
    "&& \$newsletterChoice === null)",
    static fn (): array => $scenarioStaleAfterLink(true)
);
$plants['H21 a tick never subscribing anybody turns D23 RED: an opted-out member who ticks the box stays out'] = $plant(
    '_core/Newsletter.php',
    "        return \$postedTick === \$drawnTicked ? null : \$postedTick;",
    "        return (\$postedTick === \$drawnTicked || \$postedTick === true) ? null : false;",
    static fn (): array => $scenarioOptIn(false)
);
$plants['H22 a box drawn unticked and left so writing "opted out" turns D24 RED: a subscription made elsewhere is undone by an old page'] = $plant(
    '_core/Newsletter.php',
    "        return \$postedTick === \$drawnTicked ? null : \$postedTick;",
    "        return (\$postedTick === \$drawnTicked && \$postedTick === true) ? null : \$postedTick;",
    static fn (): array => $scenarioOptIn(true)
);

foreach ($plants as $words => $g) {
    check($words, $g['applied'] === true && $g['red'] === true && $g['restored'] === true,
        ($g['applied'] ? 'applied' : 'NOT applied') . '; ' . $g['detail']);
}

// What each plant did, printed whether or not its check passed, so a reader
// can see each went red for the RIGHT reason.
foreach ($plants as $words => $g) {
    echo '      ' . substr($words, 0, 3) . ' with the plant in: ' . $g['detail'] . "\n";
}

// With every plant taken out, all are green again — so a red above can never
// be mistaken for a plant left behind.
$againF = $scenarioF();
check('H12 with the plants removed, every scenario they broke is green again',
    $scenarioB()[0] === true && $scenarioC()[0] === true && $scenarioD()[0] === true && $againF[0] === true && $againF[3] === true
    && $scenarioBoth()[0] === true
    && $scenarioEFull()[0] === true && $scenarioECard()[0] === true && $scenarioRefused()[0] === true && $scenarioTick()[0] === true
    && $scenarioOnlyUnsettable()[0] === true
    && $scenarioNewsletter(false, true)[0] === true && $scenarioNewsletter(true, true)[0] === true
    && $scenarioNewsletter(false, false, false)[0] === true && $scenarioNewsletter(true, true, false)[0] === true
    && $scenarioStaleAfterLink(false)[0] === true && $scenarioStaleAfterLink(true)[0] === true && $scenarioDrawnUnticked()[0] === true
    && $scenarioOptIn(false)[0] === true && $scenarioOptIn(true)[0] === true
    && (static function () use ($scenarioOtherOrganisation, $signIn): bool {
        $r = $scenarioOtherOrganisation();
        $signIn();
        return ($r['current token: subscriptions'] ?? null) === ['A' => 1, 'B' => 1];
    })());

// ── Tidy up the rows this test made ─────────────────────────────────────────
$db->query("DELETE FROM `tblErrors` WHERE `errorCode` = 'NOTIFY_PREFS_UNREADABLE'");
$db->query('DROP TABLE IF EXISTS `zzNotifyPrefsProbe`');
if ($removeMember($userId) === -1) {
    echo "  ! could not remove the test member ({$userId}). Remove it by hand in {$dbName}.\n";
}

echo "\n" . ($passed + $failed) . " checks: {$passed} passed, {$failed} failed.\n";
echo "Sections A-E and G drive the REAL pages over HTTP with ONE worker; F races the\n";
echo "real merge() from two processes, because one worker can never run two saves at once.\n";
exit($failed === 0 ? 0 : 1);
