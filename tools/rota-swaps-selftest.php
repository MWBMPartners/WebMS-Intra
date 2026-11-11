<?php
// Path: tools/rota-swaps-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Rota swap requests are asked, answered, and the right people told —
 * self-test 🔁 (#657)
 * -----------------------------------------------------------------------------
 * Until #657 a member could ask to swap a duty and nothing else happened:
 * nobody was told, and no page listed a request or offered to answer it.
 * `Portal\Core\RotaSwaps` now decides every rule, the Duty Roster page lists
 * and answers requests, and the person asked (and later the asker) is e-mailed
 * through their notification preferences.
 *
 * WHAT IT COVERS — every section drives the REAL pages over HTTP and reads the
 * database back (D-065: where the property is "this code runs", drive it),
 * except T, which is about two answers at once (see below):
 *
 *   A  asking: the request is saved and the person asked is e-mailed; a person
 *      outside the organisation, the asker themselves, a switched-off account
 *      and a removed member cannot be asked; open requests obey their setting
 *   B  the person asked sees the request with Accept and Decline; a third
 *      person does not
 *   C  only the person asked may answer: a third person and the asker are
 *      refused, and nothing moves
 *   D  accepting moves the duty, both people can see it moved, the asker is
 *      told — and a SECOND accept is refused and tells nobody twice
 *   E  declining leaves the duty where it was and says so, and tells the asker
 *   F  the `rotaSwaps` switch, saved through the REAL Notification
 *      preferences page, stops the e-mail
 *   G  a suspended or removed asker is sent nothing when their request is
 *      answered (the duty still moves)
 *   H  withdrawing: only the asker may, and a withdrawn request cannot then
 *      be accepted
 *   I  an open request: anyone but the asker may take it, nobody may decline
 *      it, and (D-141) it is e-mailed to every member with the switch on —
 *      not to a member who switched it off, a suspended member, somebody in
 *      another organisation or none, a member with no usable address, or the
 *      asker
 *   J  a duty an administrator has moved cannot be given away by an old
 *      request
 *   K  accepting one request cancels the others for the same duty
 *   L  a post with no form token, or a wrong one, changes nothing (both pages)
 *   O  another organisation's request number gets exactly the answer of a
 *      number that does not exist, and nothing changes
 *   Q  a duty whose date has passed can be neither offered nor taken
 *   R  the page offers only buttons the handler would accept; with open
 *      requests switched off an open request cannot be taken but can be
 *      withdrawn
 *   W  every sentence about where a duty is says where it is NOW, after an
 *      administrator has moved it: the asker's list and e-mail after a
 *      decline, what the decliner reads, what a withdrawing asker reads, and
 *      the duty taken off everybody or held by somebody with no name
 *   M  what the e-mails really carry: the mail route is pointed at a closed
 *      port, so each message is KEPT in the mail queue and opened here — the
 *      asker's words go as text, the use-by moment is set, the decline
 *      e-mail says where the duty is now
 *   T  THE LOCK: this test holds the duty's row; the real
 *      `RotaSwaps::respond()` runs in its own PROCESS and must WAIT (asked of
 *      information_schema, not assumed from a sleep); the duty is moved while
 *      it waits; the accept must then be refused. Without the lock the accept
 *      goes through and gives away a duty that was no longer the asker's.
 *   U  two members take one open request at once, each in its own process,
 *      the first slowed inside its write by a scratch trigger: one wins
 *   V  THE TRANSACTION: a scratch trigger makes the SECOND write fail; the
 *      first (the duty's move) must be undone. T cannot see this fault — it
 *      proves the lock exists, not that the read and writes are one unit
 *   P  THE PLANTS: each fault put into the COPY of the code (count them
 *      from the list in section P — no number is written here, because one
 *      already went stale here), each of which must turn the check that
 *      guards it RED, and all green again after
 *
 * WHY T, U AND V ARE NOT ALL HTTP TESTS. PHP's built-in server runs one
 * request at a time here, so two answers never overlap and a race test over
 * HTTP would pass on broken code (#647). Sections C and D prove the page calls
 * `respond()`; T and U run it from separate processes. V IS driven over HTTP:
 * it needs a failure, not an overlap.
 *
 * WHAT IT CANNOT DO, said plainly:
 *   - It sees what the portal ASKED the mail route to send (every attempt is
 *     written to `tblEmailLog`); no mail server is contacted, so it cannot see
 *     an e-mail arrive. The mail route is the SMTP mailbox with no server
 *     named, which records each attempt as "not set up".
 *   - It does not drive a real browser; nothing on these pages needs script.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_rotaswaps SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         php tools/rota-swaps-selftest.php
 *
 *         The database must already hold `web/_sql/full_schema.sql`. Needs
 *         curl, proc_open(), and an account that may read
 *         information_schema.INNODB_TRX (the PROCESS privilege) — asked up
 *         front. It REFUSES rather than skipping when anything is missing,
 *         because a skipped test reads exactly like a passing one. It puts the
 *         mail route back and removes its own people, rota and copy of web/
 *         at the end (also on Ctrl-C when PHP has pcntl).
 *
 * Exit:   0 only when every check ran and passed.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/657
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);

$passed = 0;
$failed = 0;
$state  = ['proc' => null, 'child' => null, 'workers' => [], 'hold' => null, 'db' => null, 'settings' => [], 'mailRows' => null];

/** The scratch triggers sections U and V create, dropped by the clean-up whatever happens. */
const SCRATCH_TRIGGERS = ['zzRotaSwapSlowTrg', 'zzRotaSwapFailTrg', 'zzRotaSwapCanTrg'];

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

function section(string $name): void
{
    echo "\n── {$name}\n";
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
$db->set_charset('utf8mb4');
$state['db'] = $db;
// A short lock wait on this test's own connection, so a mistake here waits
// fifteen seconds rather than MySQL's default of a year for metadata locks.
$db->query('SET SESSION lock_wait_timeout = 15, innodb_lock_wait_timeout = 15');

$tables = (int) ($db->query('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetch_assoc()['n'] ?? 0);
if ($tables < 100) {
    refuse("the database holds only {$tables} tables — load web/_sql/full_schema.sql first");
}
try {
    $db->query('SELECT COUNT(*) AS n FROM information_schema.INNODB_TRX')->free();
} catch (Throwable $e) {
    refuse("sections T and U read information_schema.INNODB_TRX to see that an answer is really WAITING on a lock, which needs the PROCESS privilege; '{$dbUser}' does not have it");
}
if (function_exists('sodium_crypto_secretbox') === false) {
    refuse('section M seals the mailbox password and opens the kept message the way the portal does, which needs PHP\'s sodium extension');
}
// Sections U and V use scratch TRIGGERs. Asked up front, on a scratch table,
// so a missing right is a plain REFUSED with nothing held. (On MySQL 8 with
// binary logging, a trigger needs SUPER or log_bin_trust_function_creators.)
try {
    $db->query('CREATE TABLE IF NOT EXISTS `zzRotaSwapsProbe` (`probeID` INT NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
    $db->query('DROP TRIGGER IF EXISTS `zzRotaSwapCanTrg`');
    $db->query('CREATE TRIGGER `zzRotaSwapCanTrg` BEFORE INSERT ON `zzRotaSwapsProbe` FOR EACH ROW SET NEW.`probeID` = NEW.`probeID`');
    $db->query('DROP TRIGGER `zzRotaSwapCanTrg`');
    $db->query('DROP TABLE `zzRotaSwapsProbe`');
} catch (Throwable $e) {
    refuse("sections U and V create scratch TRIGGERs, which '{$dbUser}' may not do here (" . $e->getMessage() . ')');
}
foreach (SCRATCH_TRIGGERS as $trigger) {
    $db->query('DROP TRIGGER IF EXISTS `' . $trigger . '`');
}
// Every installation-wide mail setting as it stands, so the clean-up can put
// back exactly this — section M points the mail route at a server that is
// down, and the mail code may record what it found there in settings of its own.
$state['mailRows'] = $db->query("SELECT `settingKey`, `settingValue`, `isSensitive` FROM `tblSettings` WHERE `siteID` IS NULL AND `settingKey` LIKE 'mail.%'")->fetch_all(MYSQLI_ASSOC);

/** Run one statement with bound values; answers the rows for a SELECT. */
function q(string $sql, string $types = '', array $params = []): array
{
    global $db;
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = $res === false ? [] : $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/** Set an installation-wide setting, remembering its old value once so the clean-up puts it back. */
function setting(string $key, string $value, int $secret = 0): void
{
    global $state;
    $row = q('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [$key]);
    if (array_key_exists($key, $state['settings']) === false) {
        $state['settings'][$key] = $row === [] ? null : (string) $row[0]['settingValue'];
    }
    if ($row === []) {
        q('INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, ?, ?, ?, ?)', 'sssi', [$key, $value, $value, $secret]);

        return;
    }
    q('UPDATE `tblSettings` SET `settingValue` = ?, `isSensitive` = ? WHERE `settingKey` = ? AND `siteID` IS NULL', 'sis', [$value, $secret, $key]);
}

// ── Putting things back: ONE clean-up, in a safe ORDER ───────────────────────
// The held lock is ended first (a worker waits on it), then the worker and
// the web server stopped, then the settings and rows put back, and the copy
// of web/ (which holds the database password and a key) deleted last.
//
// WHAT A HARD KILL (`kill -9`) LEAVES: the folder `rota-swaps-<random>` under
// the system temp folder (delete it by hand: it holds the password), the web
// server on a port from 10600 to 10800, the mail route set to SMTP with no
// server, and the test people and rota, which the next run removes first.
$removePeople = null;
register_shutdown_function(static function () use (&$state, &$removePeople): void {
    // 🔐 A second Ctrl-C must not cut the clean-up short: from here on the
    //    signals are ignored, and the two secrets in the copy of web/ (the
    //    database password and the key) are deleted FIRST, before anything
    //    slow, so they are gone even if the rest of the clean-up stalls.
    if (function_exists('pcntl_signal') === true) {
        foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
            pcntl_signal($signal, SIG_IGN);
        }
    }
    if ($state['child'] !== null) {
        foreach (['auth_creds.php', 'enc.key'] as $secret) {
            @unlink($state['child'] . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . $secret);
        }
    }
    if ($state['hold'] instanceof mysqli) {
        try {
            $state['hold']->rollback();
            $state['hold']->close();
        } catch (Throwable $e) {
            // Already gone: the server rolls it back when the connection drops.
        }
        $state['hold'] = null;
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
    $state['proc'] = null;
    if ($state['db'] instanceof mysqli) {
        foreach (SCRATCH_TRIGGERS as $trigger) {
            try {
                $state['db']->query('DROP TRIGGER IF EXISTS `' . $trigger . '`');
            } catch (Throwable $e) {
                echo "  ! could not drop the scratch trigger {$trigger}\n";
            }
        }
        try {
            $state['db']->query('DROP TABLE IF EXISTS `zzRotaSwapsProbe`');
        } catch (Throwable $e) {
            echo "  ! could not drop the scratch table zzRotaSwapsProbe\n";
        }
        foreach ($state['settings'] as $key => $was) {
            try {
                if ($was === null) {
                    q('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [$key]);
                } else {
                    q('UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingKey` = ? AND `siteID` IS NULL', 'ss', [$was, $key]);
                }
            } catch (Throwable $e) {
                echo "  ! could not put back the setting {$key}\n";
            }
        }
        // The mail settings exactly as they were: anything added since is
        // removed, anything changed is put back (value and secret mark).
        if (is_array($state['mailRows']) === true) {
            try {
                $keep = array_column($state['mailRows'], 'settingKey');
                foreach (q("SELECT `settingKey` FROM `tblSettings` WHERE `siteID` IS NULL AND `settingKey` LIKE 'mail.%'") as $now) {
                    if (in_array($now['settingKey'], $keep, true) === false) {
                        q('DELETE FROM `tblSettings` WHERE `siteID` IS NULL AND `settingKey` = ?', 's', [$now['settingKey']]);
                    }
                }
                foreach ($state['mailRows'] as $was) {
                    q('UPDATE `tblSettings` SET `settingValue` = ?, `isSensitive` = ? WHERE `siteID` IS NULL AND `settingKey` = ?', 'sis', [$was['settingValue'], (int) $was['isSensitive'], $was['settingKey']]);
                }
            } catch (Throwable $e) {
                echo "  ! could not put the mail settings back\n";
            }
        }
        if (is_callable($removePeople) === true) {
            $removePeople();
        }
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
if (function_exists('pcntl_async_signals') === true && function_exists('pcntl_signal') === true) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
        pcntl_signal($signal, static function (int $which): void {
            echo "\nStopped by a signal ({$which}); putting things back.\n";
            exit(3);
        });
    }
}

// ── A copy of web/, so a planted fault never touches the repository ─────────
$child = sys_get_temp_dir() . $sep . 'rota-swaps-' . bin2hex(random_bytes(6));
$state['child'] = $child;
mkdir($child, 0700, true);
$copied = 0;
$walk   = new RecursiveIteratorIterator(
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
    "<?php\n// Written by tools/rota-swaps-selftest.php for ONE run.\nreturn "
    . var_export(['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort], true) . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));
$copyCore = $child . $sep . 'web' . $sep . '_core' . $sep;

// The router imitates web/public_html/.htaccess, as the other page tests do.
file_put_contents($child . $sep . 'router.php', <<<'ROUTER'
<?php
// Written by tools/rota-swaps-selftest.php for ONE run. Test only.
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

// The worker for section T: the REAL class, from the COPY (so a plant in the
// copy reaches it), on its own connection, in its own process. It writes its
// connection's thread id to a file BEFORE it asks for the row, so the test
// can see that THIS connection — not some other — is the one waiting.
file_put_contents($child . $sep . 'worker.php', <<<'WORKER'
<?php
// Written by tools/rota-swaps-selftest.php for ONE run. Test only.
declare(strict_types=1);
// argv: the copy's _core folder, the site, the request, who answers, the thread-id file.
define('PORTAL_ROOT', dirname($argv[1]));
foreach (['App', 'Site', 'Logger', 'RotaSwaps'] as $class) {
    require_once $argv[1] . DIRECTORY_SEPARATOR . $class . '.php';
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
// `$mysqli` is a global on purpose: App::db() falls back to it.
$mysqli = new mysqli((string) getenv('W_HOST'), (string) getenv('W_USER'), (string) getenv('W_PASS'), (string) getenv('W_NAME'), (int) getenv('W_PORT'));
$mysqli->set_charset('utf8mb4');
\Portal\Core\Site::init($mysqli, ['site' => ['timezoneKeptAt' => \Portal\Core\Site::ZONE_MARK_VALUE]], (int) $argv[2]);
file_put_contents($argv[5], (string) $mysqli->thread_id);
// Open requests allowed: the setting is the handler's to read, and these
// sections are about the lock and the transaction, not that setting.
echo json_encode(\Portal\Core\RotaSwaps::respond($mysqli, (int) $argv[2], (int) $argv[3], (int) $argv[4], 'accept', '', true));
WORKER);

// ── The web server ──────────────────────────────────────────────────────────
$port = 10600 + random_int(0, 200);
$env  = getenv();
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

/**
 * One request as one person (each has their own cookie jar). Redirects are
 * NOT followed: where a handler sends somebody is a fact this test reads.
 *
 * @return array{status:int, location:string, body:string}
 */
function http(string $who, string $method, string $path, array $post = []): array
{
    global $port, $child;
    $jar    = $child . DIRECTORY_SEPARATOR . 'jar-' . $who . '.txt';
    $handle = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => ['Expect:'],
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
}

$up = false;
for ($i = 0; $i < 80; $i++) {
    if (http('probe', 'GET', '/login')['status'] > 0) {
        $up = true;
        break;
    }
    usleep(250000);
}
if ($up === false) {
    refuse('the test server never answered');
}

// ── The world ───────────────────────────────────────────────────────────────
$siteId = (int) (q('SELECT MIN(`siteID`) AS `s` FROM `tblSites`')[0]['s'] ?? 0);
if ($siteId <= 0) {
    refuse('the database has no organisation to put a member in');
}
// Every attempt to send is written to tblEmailLog by the SMTP route with no
// server named; nothing is contacted. The rota is switched on (its seed).
setting('mail.provider', 'smtp');
setting('mail.smtp.host', '');
setting('rota.enabled', '1');
setting('rota.allow_open_swap', '1');

$mark = 'rota-swaps-selftest';
/** Remove every person, role and duty an earlier or this run made. */
$removePeople = static function () use ($mark): void {
    foreach (q("SELECT `userID` FROM `tblUsers` WHERE `emailAddress` LIKE '" . $mark . "-%'") as $row) {
        $who = (int) $row['userID'];
        foreach (q("SELECT `TABLE_NAME`, `COLUMN_NAME` FROM information_schema.KEY_COLUMN_USAGE WHERE `TABLE_SCHEMA` = DATABASE() AND `REFERENCED_TABLE_NAME` = 'tblUsers'") as $fk) {
            try {
                q('DELETE FROM `' . $fk['TABLE_NAME'] . '` WHERE `' . $fk['COLUMN_NAME'] . '` = ?', 'i', [$who]);
            } catch (Throwable $e) {
                // A table whose own parent has already gone, or a NOT NULL
                // column an earlier delete in this loop already emptied.
            }
        }
        foreach (['tblErrors', 'tblActivityLogs'] as $t) {
            try {
                q('DELETE FROM `' . $t . '` WHERE `userID` = ?', 'i', [$who]);
            } catch (Throwable $e) {
                // Not every installation's table carries the column.
            }
        }
        q('DELETE FROM `tblUsers` WHERE `userID` = ?', 'i', [$who]);
    }
    q("DELETE FROM `tblRotaRoleType` WHERE `name` LIKE 'Selftest swap duty%'");
    q("DELETE FROM `tblEmailLog` WHERE `toRecipients` LIKE '" . $mark . "-%'");
    q("DELETE FROM `tblMailQueue` WHERE `toRecipients` LIKE '%" . $mark . "-%'");
    // The second organisation (section O), once nothing points at it.
    foreach (q("SELECT `siteID` FROM `tblSites` WHERE `siteKey` = 'selftest-rotaswaps-b'") as $b) {
        try {
            q('DELETE FROM `tblUserSites` WHERE `siteID` = ?', 'i', [(int) $b['siteID']]);
            q('DELETE FROM `tblSites` WHERE `siteID` = ?', 'i', [(int) $b['siteID']]);
        } catch (Throwable $e) {
            echo "  ! could not remove the second organisation (" . $e->getMessage() . ")\n";
        }
    }
};
$removePeople();

// 🏢 A SECOND organisation, with its own member, rota and request (section O).
q("INSERT INTO `tblSites` (`siteKey`, `siteName`, `timezone`, `isActive`) VALUES ('selftest-rotaswaps-b', 'Selftest rota swaps B', 'UTC', 1)");
$siteB = (int) $db->insert_id;

$run      = bin2hex(random_bytes(3));
$password = 'Sel' . bin2hex(random_bytes(8)) . '!9x';
$people   = [];
/**
 * Make one person with a password and, unless `$memberOf` is 0, an active
 * membership of `$memberOf` (this organisation by default). `$address` false
 * gives them an address that is NOT an e-mail address (it still starts with
 * this run's mark, so the clean-up finds them).
 */
$makePerson = static function (string $key, string $name, ?int $memberOf = null, bool $address = true) use (&$people, $mark, $run, $siteId, $password): int {
    $email = $mark . '-' . $key . '-' . $run . ($address === true ? '@selftest.invalid' : '');
    q("INSERT INTO `tblUsers` (`fullName`, `emailAddress`, `isActive`, `isAdmin`, `createdAt`) VALUES (?, ?, 1, 0, NOW())", 'ss', [$name, $email]);
    $id = (int) $GLOBALS['db']->insert_id;
    $memberOf ??= $siteId;
    if ($memberOf > 0) {
        q('INSERT INTO `tblUserSites` (`siteID`, `userID`, `isActive`) VALUES (?, ?, 1)', 'ii', [$memberOf, $id]);
    }
    q('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)', 'iss', [$id, $mark . '-' . $key . '-' . $run, password_hash($password, PASSWORD_DEFAULT)]);
    $people[$key] = ['id' => $id, 'email' => $email, 'name' => $name, 'login' => $mark . '-' . $key . '-' . $run];

    return $id;
};
$makePerson('asker', 'Ada Asker');
$makePerson('asked', 'Bea Asked');
$makePerson('third', 'Cal Third');
$makePerson('off', 'Dot Switchedoff');
$makePerson('outsider', 'Eve Outsider', 0);
$makePerson('gone', 'Fay Suspended');
q('UPDATE `tblUsers` SET `isActive` = 0 WHERE `userID` = ?', 'i', [$people['gone']['id']]);
$makePerson('noaddr', 'Gus Noaddress', null, false);
$makePerson('otherorg', 'Hal Otherorg', $siteB);
// A member whose name cannot be read (section W's "another member" branch).
$makePerson('noname', 'Ivy Noname');
q('UPDATE `tblUsers` SET `fullName` = NULL WHERE `userID` = ?', 'i', [$people['noname']['id']]);

$roleName = 'Selftest swap duty ' . $run;
q('INSERT INTO `tblRotaRoleType` (`siteID`, `name`) VALUES (?, ?)', 'is', [$siteId, $roleName]);
$roleType = (int) $db->insert_id;

$nextDay = 10;
/**
 * A new duty for `$owner`, on a date no other duty in this run uses, so each
 * e-mail can be told apart by its subject. `$daysAgo` puts it in the PAST
 * instead (section Q); `$notes` marks it for a scratch trigger (section U).
 */
$newSlot = static function (int $owner, string $notes = '', int $daysAgo = 0, ?int $site = null, ?int $role = null) use (&$nextDay, $siteId, $roleType): array {
    if ($daysAgo > 0) {
        $date = gmdate('Y-m-d', time() - 86400 * $daysAgo);
    } else {
        $date = gmdate('Y-m-d', time() + 86400 * $nextDay);
        $nextDay++;
    }
    q('INSERT INTO `tblRotaSlot` (`siteID`, `roleTypeID`, `slotDate`, `startTime`, `endTime`, `assignedToID`, `notes`, `reminderSentAt`) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())', 'iisssis', [$site ?? $siteId, $role ?? $roleType, $date, '10:00:00', '12:00:00', $owner, $notes]);

    return ['id' => (int) $GLOBALS['db']->insert_id, 'date' => $date, 'words' => gmdate('l j F Y', (int) strtotime($date . ' 12:00:00 UTC'))];
};
$holder = static fn (int $slot): ?int => ($r = q('SELECT `assignedToID` FROM `tblRotaSlot` WHERE `slotID` = ?', 'i', [$slot])) === [] || $r[0]['assignedToID'] === null ? null : (int) $r[0]['assignedToID'];
$swap   = static fn (int $id): array => q('SELECT * FROM `tblRotaSwapRequest` WHERE `swapID` = ?', 'i', [$id])[0] ?? [];
/** How many e-mails the portal tried to send to one person whose subject starts with `$subject`. */
$mails  = static function (string $who, string $subject) use (&$people): int {
    return count(q('SELECT `emailLogID` FROM `tblEmailLog` WHERE `toRecipients` = ? AND `subject` LIKE CONCAT(?, \'%\')', 'ss', [$people[$who]['email'] ?? '', $subject]));
};

/** Sign in through the REAL form. */
$signIn = static function (string $who) use (&$people, $password, $child): void {
    @unlink($child . DIRECTORY_SEPARATOR . 'jar-' . $who . '.txt');
    $page = http($who, 'GET', '/login');
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $page['body'], $m) !== 1) {
        refuse('the sign-in page carried no form token');
    }
    $in = http($who, 'POST', '/login', ['csrf_token' => $m[1], 'username' => $people[$who]['login'], 'password' => $password]);
    if ($in['status'] !== 302 && $in['status'] !== 303) {
        refuse("signing in as {$who} did not answer a redirect (HTTP {$in['status']})");
    }
};
foreach (['asker', 'asked', 'third', 'off'] as $who) {
    $signIn($who);
}

/** The current form token for one person, read from the page's own meta tag. */
$token = static function (string $who, string $path = '/rota'): string {
    $page = http($who, 'GET', $path);

    return preg_match('/<meta name="csrf-token" content="([^"]+)"/', $page['body'], $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : '';
};

/** The Duty Roster as one person sees it, and the message at its top. */
$roster = static function (string $who): array {
    $page = http($who, 'GET', '/rota');
    $flash = preg_match('#<div class="alert alert-[a-z]+" role="status">(.*?)</div>#s', $page['body'], $m) === 1 ? html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8') : '';

    return ['status' => $page['status'], 'body' => $page['body'], 'flash' => $flash];
};

/**
 * `$asker` asks `$target` (0 = an open request) to take `$slot`, through the
 * real form. Answers the new request's number (0 when none was made), the
 * handler's answer and the page's message.
 */
$ask = static function (string $asker, array $slot, int $target, string $message = '') use ($token): array {
    $before = (int) (q('SELECT COALESCE(MAX(`swapID`), 0) AS `m` FROM `tblRotaSwapRequest` WHERE `slotID` = ?', 'i', [$slot['id']])[0]['m'] ?? 0);
    $path   = '/rota/swap?slot=' . $slot['id'];
    $res    = http($asker, 'POST', $path, ['csrf_token' => $token($asker, $path), 'targetUserID' => (string) $target, 'requestMessage' => $message]);
    $after  = (int) (q('SELECT COALESCE(MAX(`swapID`), 0) AS `m` FROM `tblRotaSwapRequest` WHERE `slotID` = ?', 'i', [$slot['id']])[0]['m'] ?? 0);
    $shown  = preg_match('#<div class="alert alert-[a-z]+">(.*?)</div>#s', $res['body'], $m) === 1 ? html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8') : '';

    return ['swapId' => $after > $before ? $after : 0, 'status' => $res['status'], 'location' => $res['location'], 'shown' => $shown];
};

/** `$who` gives `$action` to request `$swapId`, through the real handler. Answers the message the Duty Roster then shows. */
$answer = static function (string $who, int $swapId, string $action, string $reply = '') use ($token, $roster): array {
    $res = http($who, 'POST', '/rota/swap-respond', ['csrf_token' => $token($who), 'swapID' => (string) $swapId, 'action' => $action, 'responseMessage' => $reply]);

    return ['status' => $res['status'], 'location' => $res['location'], 'flash' => $roster($who)['flash']];
};

$A = $people['asker']['id'];
$B = $people['asked']['id'];
$C = $people['third']['id'];

// =============================================================================
// The scenarios. Each makes its own duties, so each can be run again during
// the plants (section P) against fresh rows, and answers named booleans.
// =============================================================================

/** C: a third person, and the asker, cannot answer a request aimed at B. */
$scnThirdPerson = static function () use ($newSlot, $ask, $answer, $holder, $swap, $A, $B): array {
    $slot = $newSlot($A);
    $req  = $ask('asker', $slot, $B, 'Third person test');
    $c1   = $answer('third', $req['swapId'], 'accept');
    $afterAccept = ['holder' => $holder($slot['id']), 'status' => (string) ($swap($req['swapId'])['status'] ?? '')];
    $c2   = $answer('third', $req['swapId'], 'decline');
    $afterDecline = (string) ($swap($req['swapId'])['status'] ?? '');
    $c3   = $answer('asker', $req['swapId'], 'accept');
    $afterOwn = ['holder' => $holder($slot['id']), 'status' => (string) ($swap($req['swapId'])['status'] ?? '')];

    return [
        'made'          => $req['swapId'] > 0,
        'thirdAccept'   => $afterAccept['holder'] === $A && $afterAccept['status'] === 'pending',
        'thirdWords'    => str_contains($c1['flash'], 'not yours to answer'),
        'thirdDecline'  => $afterDecline === 'pending',
        'askerAccept'   => $afterOwn['holder'] === $A && $afterOwn['status'] === 'pending',
        'detail'        => json_encode([$afterAccept, $afterDecline, $afterOwn, $c1['flash'], $c3['flash']]),
    ];
};

/** D: B accepts, both can see it moved, A is told once; a second accept is refused. */
$scnAcceptOnce = static function () use ($newSlot, $ask, $answer, $holder, $swap, $roster, $mails, $roleName, $A, $B, $people): array {
    $slot   = $newSlot($A);
    $req    = $ask('asker', $slot, $B, 'Please cover');
    $first  = $answer('asked', $req['swapId'], 'accept', 'Happy to');
    $row    = $swap($req['swapId']);
    $moved  = $holder($slot['id']) === $B && ($row['status'] ?? '') === 'accepted';
    // NOT `?? 'x'`: `??` treats a NULL value as missing, so that spelling
    // could never see the NULL this check is about (it failed on correct code).
    $stampRow = q('SELECT `reminderSentAt` FROM `tblRotaSlot` WHERE `slotID` = ?', 'i', [$slot['id']]);
    $stamp    = $stampRow === [] ? 'no such duty' : $stampRow[0]['reminderSentAt'];
    $bPage  = $roster('asked')['body'];
    $aPage  = $roster('asker')['body'];
    $told   = $mails('asker', 'Your rota swap was accepted: ' . $roleName . ' on ' . $slot['words']);
    $second = $answer('asked', $req['swapId'], 'accept');
    $toldAfter = $mails('asker', 'Your rota swap was accepted: ' . $roleName . ' on ' . $slot['words']);

    return [
        'firstWords'    => $first['flash'] === 'Swap accepted. The duty was moved to you.',
        'moved'         => $moved,
        'stampCleared'  => $stamp === null,
        'bSees'         => str_contains($bPage, '/rota/swap?slot=' . $slot['id'] . '"'),
        'aNoLonger'     => str_contains($aPage, '/rota/swap?slot=' . $slot['id'] . '"') === false,
        'aSeesAccepted' => preg_match('#data-swap-mine="' . $req['swapId'] . '" data-swap-status="accepted"#', $aPage) === 1
            && str_contains($aPage, 'it is now with ' . $people['asked']['name']),
        'toldOnce'      => $told === 1,
        'secondRefused' => $holder($slot['id']) === $B && str_contains($second['flash'], 'already been answered'),
        'notToldTwice'  => $toldAfter === 1,
        'detail'        => json_encode(['holder' => $holder($slot['id']), 'status' => $row['status'] ?? '', 'told' => $told, 'toldAfter' => $toldAfter, 'first' => $first['flash'], 'second' => $second['flash']]),
    ];
};

/** F: with `rotaSwaps` switched off, the person asked is not e-mailed. */
$scnSwitchedOff = static function () use ($newSlot, $ask, $mails, $roleName, $A, $people): array {
    $slot = $newSlot($A);
    $req  = $ask('asker', $slot, $people['off']['id'], 'Switched off test');

    return [
        'made'     => $req['swapId'] > 0,
        'notTold'  => $mails('off', 'Rota swap request: ' . $roleName . ' on ' . $slot['words']) === 0,
    ];
};

/** F (other half): somebody who has NOT switched it off IS told — so the check above cannot pass because nothing is ever sent. */
$scnSwitchedOn = static function () use ($newSlot, $ask, $mails, $roleName, $A, $B): array {
    $slot = $newSlot($A);
    $req  = $ask('asker', $slot, $B, 'Switched on test');

    return ['made' => $req['swapId'] > 0, 'told' => $mails('asked', 'Rota swap request: ' . $roleName . ' on ' . $slot['words']) === 1];
};

/**
 * G: the asker is SUSPENDED (account switched off) or REMOVED from the
 * organisation after asking; the answer still moves the duty, and the asker
 * is sent nothing. Run as one closure so the plant phase can repeat it.
 */
$scnGoneAsker = static function (string $how) use ($newSlot, $ask, $answer, $holder, $mails, $roleName, $A, $B, $siteId, $signIn): array {
    $slot = $newSlot($A);
    $req  = $ask('asker', $slot, $B, 'Gone asker test');
    if ($how === 'suspended') {
        q('UPDATE `tblUsers` SET `isActive` = 0 WHERE `userID` = ?', 'i', [$A]);
    } else {
        q('UPDATE `tblUserSites` SET `isActive` = 0 WHERE `userID` = ? AND `siteID` = ?', 'ii', [$A, $siteId]);
    }
    $answer('asked', $req['swapId'], 'accept');
    $moved = $holder($slot['id']) === $B;
    $sent  = $mails('asker', 'Your rota swap was accepted: ' . $roleName . ' on ' . $slot['words']);
    q('UPDATE `tblUsers` SET `isActive` = 1 WHERE `userID` = ?', 'i', [$A]);
    q('UPDATE `tblUserSites` SET `isActive` = 1 WHERE `userID` = ? AND `siteID` = ?', 'ii', [$A, $siteId]);
    // A switched-off account has its session ended on its next request.
    $signIn('asker');

    return ['made' => $req['swapId'] > 0, 'moved' => $moved, 'notSent' => $sent === 0, 'detail' => "e-mails to the asker: {$sent}"];
};

/** J: an administrator moved the duty; an old request cannot give it away. */
$scnChangedHands = static function () use ($newSlot, $ask, $answer, $holder, $swap, $A, $B, $C): array {
    $slot = $newSlot($A);
    $req  = $ask('asker', $slot, $B, 'Changed hands test');
    q('UPDATE `tblRotaSlot` SET `assignedToID` = ? WHERE `slotID` = ?', 'ii', [$C, $slot['id']]);
    $res  = $answer('asked', $req['swapId'], 'accept');

    return [
        'refused' => $holder($slot['id']) === $C && ($swap($req['swapId'])['status'] ?? '') === 'pending',
        'words'   => str_contains($res['flash'], 'no longer with the person who asked'),
        'detail'  => 'holder ' . var_export($holder($slot['id']), true) . ', said: ' . $res['flash'],
    ];
};

/**
 * T: the lock. This test holds the duty's row; the real respond() runs in its
 * own process and must WAIT; the duty is moved to C while it waits; the
 * accept must then be refused and the duty stay with C.
 */
/**
 * Start the real `RotaSwaps::respond()` (accept) in its own PROCESS, as
 * `$userId`. Answers the process, its output pipe, and the file it writes its
 * connection's thread id into BEFORE it asks for any row.
 *
 * @return array{proc:mixed, pipe:mixed, tidFile:string}
 */
$startWorker = static function (int $swapId, int $userId) use ($child, $copyCore, $siteId, $dbHost, $dbUser, $dbPass, $dbName, $dbPort, &$state): array {
    $tidFile = $child . DIRECTORY_SEPARATOR . 'worker-tid-' . bin2hex(random_bytes(3));
    $proc    = proc_open(
        [PHP_BINARY, $child . DIRECTORY_SEPARATOR . 'worker.php', rtrim($copyCore, DIRECTORY_SEPARATOR), (string) $siteId, (string) $swapId, (string) $userId, $tidFile],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $child . DIRECTORY_SEPARATOR . 'worker.log', 'a']],
        $wPipes,
        $child,
        ['W_HOST' => $dbHost, 'W_USER' => $dbUser, 'W_PASS' => $dbPass, 'W_NAME' => $dbName, 'W_PORT' => (string) $dbPort, 'PATH' => (string) getenv('PATH')]
    );
    $state['workers'][] = $proc;

    return ['proc' => $proc, 'pipe' => $wPipes[1], 'tidFile' => $tidFile];
};

/**
 * ⏳ A PREMISE, not a sleep: is the worker's own connection really in the
 * state named — `LOCK WAIT` (waiting for a row) or `User sleep` (inside the
 * scratch trigger's SLEEP)? Asked of information_schema for up to ten
 * seconds. Without this, a worker that had not started yet would pass every
 * check after it in either state.
 */
$workerIs = static function (array $worker, string $what): bool {
    for ($i = 0; $i < 100; $i++) {
        $tid = is_file($worker['tidFile']) === true ? (int) file_get_contents($worker['tidFile']) : 0;
        if ($tid > 0) {
            $sql = $what === 'LOCK WAIT'
                ? "SELECT COUNT(*) AS `n` FROM information_schema.INNODB_TRX WHERE `trx_mysql_thread_id` = {$tid} AND `trx_state` = 'LOCK WAIT'"
                : "SELECT COUNT(*) AS `n` FROM information_schema.PROCESSLIST WHERE `ID` = {$tid} AND `STATE` = 'User sleep'";
            if ((int) ($GLOBALS['db']->query($sql)->fetch_assoc()['n'] ?? 0) > 0) {
                return true;
            }
        }
        usleep(100000);
    }

    return false;
};

/** Wait for a worker to finish and decode what `respond()` answered. */
$finishWorker = static function (array $worker) use (&$state): array {
    $said = (string) stream_get_contents($worker['pipe']);
    fclose($worker['pipe']);
    proc_close($worker['proc']);
    $state['workers'] = array_values(array_filter($state['workers'], static fn (mixed $p): bool => $p !== $worker['proc']));
    $answer = json_decode($said, true);

    return is_array($answer) === true ? $answer + ['raw' => $said] : ['outcome' => '(no answer)', 'reason' => '', 'raw' => $said];
};

$scnLock = static function () use ($newSlot, $ask, $holder, $swap, $startWorker, $workerIs, $finishWorker, $dbHost, $dbUser, $dbPass, $dbName, $dbPort, $A, $B, $C, &$state): array {
    $slot = $newSlot($A);
    $req  = $ask('asker', $slot, $B, 'Lock test');

    $hold = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $hold->query('SET SESSION innodb_lock_wait_timeout = 30');
    $state['hold'] = $hold;
    $hold->begin_transaction();
    $hold->query('SELECT `assignedToID` FROM `tblRotaSlot` WHERE `slotID` = ' . (int) $slot['id'] . ' FOR UPDATE')->fetch_all();

    $worker  = $startWorker($req['swapId'], $B);
    $waiting = $workerIs($worker, 'LOCK WAIT');

    // An administrator moves the duty while the answer waits.
    $hold->query('UPDATE `tblRotaSlot` SET `assignedToID` = ' . (int) $C . ' WHERE `slotID` = ' . (int) $slot['id']);
    $hold->commit();
    $hold->close();
    $state['hold'] = null;

    $answer = $finishWorker($worker);

    return [
        'waited'  => $waiting,
        'refused' => ($answer['outcome'] ?? '') === 'refused' && ($answer['reason'] ?? '') === 'changed-hands',
        'kept'    => $holder($slot['id']) === $C && ($swap($req['swapId'])['status'] ?? '') === 'pending',
        'detail'  => 'the worker said ' . trim($answer['raw']) . '; the duty is with ' . var_export($holder($slot['id']), true),
    ];
};

/**
 * U: two members take ONE open request at the same moment, each through the
 * real respond() in its own process. A scratch trigger makes the first
 * taker's write to the duty SLEEP for two seconds, so the second really
 * arrives while the first is part-way through; both states are asked of the
 * database. Exactly one may get the duty.
 */
$scnTwoTakers = static function () use ($newSlot, $ask, $holder, $swap, $startWorker, $workerIs, $finishWorker, $A, $B, $C): array {
    $slot = $newSlot($A, 'U-slow');
    $req  = $ask('asker', $slot, 0, 'Two takers');
    $GLOBALS['db']->query('DROP TRIGGER IF EXISTS `zzRotaSwapSlowTrg`');
    $GLOBALS['db']->query("CREATE TRIGGER `zzRotaSwapSlowTrg` BEFORE UPDATE ON `tblRotaSlot` FOR EACH ROW BEGIN IF OLD.`notes` = 'U-slow' THEN DO SLEEP(2); END IF; END");
    $first       = $startWorker($req['swapId'], $B);
    $firstInside = $workerIs($first, 'User sleep');
    $second      = $startWorker($req['swapId'], $C);
    $secondWaits = $workerIs($second, 'LOCK WAIT');
    $a1 = $finishWorker($first);
    $a2 = $finishWorker($second);
    $GLOBALS['db']->query('DROP TRIGGER IF EXISTS `zzRotaSwapSlowTrg`');
    $outcomes = [($a1['outcome'] ?? ''), ($a2['outcome'] ?? '')];
    sort($outcomes);

    return [
        'premise'  => $firstInside && $secondWaits,
        'oneWins'  => $outcomes === ['done', 'refused'],
        'firstWon' => ($a1['outcome'] ?? '') === 'done' && $holder($slot['id']) === $B && ($swap($req['swapId'])['status'] ?? '') === 'accepted',
        'detail'   => 'first: ' . trim($a1['raw']) . '; second: ' . trim($a2['raw']) . '; holder ' . var_export($holder($slot['id']), true)
            . '; first inside its write: ' . var_export($firstInside, true) . '; second waiting: ' . var_export($secondWaits, true),
    ];
};

/**
 * V: the read and the writes are ONE unit. A scratch trigger refuses the
 * SECOND write (marking the request accepted); the first write (moving the
 * duty) must be undone with it. Driven through the real page.
 */
$scnAllOrNothing = static function () use ($newSlot, $ask, $answer, $holder, $swap, $A, $B): array {
    $slot = $newSlot($A);
    $req  = $ask('asker', $slot, $B, 'V-fail');
    $GLOBALS['db']->query('DROP TRIGGER IF EXISTS `zzRotaSwapFailTrg`');
    $GLOBALS['db']->query("CREATE TRIGGER `zzRotaSwapFailTrg` BEFORE UPDATE ON `tblRotaSwapRequest` FOR EACH ROW BEGIN IF NEW.`status` = 'accepted' AND OLD.`requestMessage` = 'V-fail' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused on purpose by tools/rota-swaps-selftest.php'; END IF; END");
    $res = $answer('asked', $req['swapId'], 'accept');
    $GLOBALS['db']->query('DROP TRIGGER IF EXISTS `zzRotaSwapFailTrg`');

    return [
        'undone' => $holder($slot['id']) === $A && ($swap($req['swapId'])['status'] ?? '') === 'pending',
        'said'   => str_contains($res['flash'], 'could not be answered just now'),
        'detail' => 'holder ' . var_export($holder($slot['id']), true) . ', status ' . ($swap($req['swapId'])['status'] ?? '?') . ', said: ' . $res['flash'],
    ];
};

/** L: a post with no form token, or a wrong one, changes nothing — on both pages. */
$scnTokens = static function () use ($newSlot, $ask, $holder, $swap, $roster, $A, $B): array {
    $slot   = $newSlot($A);
    $path   = '/rota/swap?slot=' . $slot['id'];
    $count  = static fn (): int => count(q('SELECT `swapID` FROM `tblRotaSwapRequest` WHERE `slotID` = ?', 'i', [$slot['id']]));
    $none   = http('asker', 'POST', $path, ['targetUserID' => (string) $B, 'requestMessage' => 'no token']);
    $wrong  = http('asker', 'POST', $path, ['csrf_token' => str_repeat('0', 64), 'targetUserID' => (string) $B, 'requestMessage' => 'wrong token']);
    $askOk  = $count() === 0 && str_contains($none['body'], 'form had expired') && str_contains($wrong['body'], 'form had expired');

    $req    = $ask('asker', $slot, $B, 'Token test');
    $r1     = http('asked', 'POST', '/rota/swap-respond', ['swapID' => (string) $req['swapId'], 'action' => 'accept']);
    $r2     = http('asked', 'POST', '/rota/swap-respond', ['csrf_token' => str_repeat('0', 64), 'swapID' => (string) $req['swapId'], 'action' => 'accept']);
    $said   = $roster('asked')['flash'];
    $answerOk = $holder($slot['id']) === $A && ($swap($req['swapId'])['status'] ?? '') === 'pending' && str_contains($said, 'form had expired');

    return ['askRefused' => $askOk, 'answerRefused' => $answerOk, 'detail' => 'requests ' . $count() . ', holder ' . var_export($holder($slot['id']), true) . ', said: ' . $said . ' (HTTP ' . $r1['status'] . '/' . $r2['status'] . ')'];
};

/**
 * O: a member of THIS organisation posts the number of an open request that
 * belongs to ANOTHER organisation. They must get exactly the answer a number
 * that does not exist gets, and nothing may change.
 */
$scnOtherOrganisation = static function () use ($newSlot, $answer, $holder, $swap, $siteB, $people): array {
    $other   = $people['otherorg']['id'];
    $roleB   = (int) (q("SELECT `roleTypeID` FROM `tblRotaRoleType` WHERE `siteID` = ? AND `name` LIKE 'Selftest swap duty B%' LIMIT 1", 'i', [$siteB])[0]['roleTypeID'] ?? 0);
    if ($roleB === 0) {
        q('INSERT INTO `tblRotaRoleType` (`siteID`, `name`) VALUES (?, ?)', 'is', [$siteB, 'Selftest swap duty B ' . bin2hex(random_bytes(2))]);
        $roleB = (int) $GLOBALS['db']->insert_id;
    }
    $slotB = $newSlot($other, '', 0, $siteB, $roleB);
    q("INSERT INTO `tblRotaSwapRequest` (`slotID`, `requestedByID`, `targetUserID`, `requestMessage`) VALUES (?, ?, NULL, 'Other organisation')", 'ii', [$slotB['id'], $other]);
    $swapB   = (int) $GLOBALS['db']->insert_id;
    $missing = (int) (q('SELECT COALESCE(MAX(`swapID`), 0) + 1000 AS `m` FROM `tblRotaSwapRequest`')[0]['m'] ?? 0);
    $forB    = $answer('third', $swapB, 'accept');
    $forNone = $answer('third', $missing, 'accept');

    return [
        'sameAnswer' => $forB['flash'] === $forNone['flash'] && str_contains($forB['flash'], 'could not be found'),
        'unchanged'  => $holder($slotB['id']) === $other && ($swap($swapB)['status'] ?? '') === 'pending',
        'detail'     => 'their request: "' . $forB['flash'] . '"; a missing one: "' . $forNone['flash'] . '"; holder ' . var_export($holder($slotB['id']), true),
    ];
};

/** Q: a duty whose date has passed cannot be swapped, by either road. */
$scnPast = static function () use ($newSlot, $answer, $holder, $swap, $A, $B): array {
    $slot = $newSlot($A, '', 5);
    q("INSERT INTO `tblRotaSwapRequest` (`slotID`, `requestedByID`, `targetUserID`, `requestMessage`) VALUES (?, ?, ?, 'Past duty')", 'iii', [$slot['id'], $A, $B]);
    $req  = (int) $GLOBALS['db']->insert_id;
    $res  = $answer('asked', $req, 'accept');
    $page = http('asker', 'GET', '/rota/swap?slot=' . $slot['id']);

    return [
        'acceptRefused' => $holder($slot['id']) === $A && ($swap($req)['status'] ?? '') === 'pending' && str_contains($res['flash'], 'already passed'),
        'askRefused'    => $page['status'] === 403,
        'detail'        => 'holder ' . var_export($holder($slot['id']), true) . ', said: ' . $res['flash'] . '; asking page HTTP ' . $page['status'],
    ];
};

/** The part of a Duty Roster page that draws ONE request's row. */
$rowOf = static function (string $body, string $marker): string {
    $at = strpos($body, $marker);
    if ($at === false) {
        return '';
    }
    $end = strlen($body);
    foreach (['data-swap-for-me=', 'data-swap-open=', 'data-swap-mine=', 'id="swap-requests-mine"', 'My upcoming duties'] as $next) {
        $n = strpos($body, $next, $at + strlen($marker));
        if ($n !== false && $n < $end) {
            $end = $n;
        }
    }

    return substr($body, $at, $end - $at);
};

/**
 * R1: the page asks the rule before drawing a button. A request whose duty
 * has already moved is shown with the reason and NO Accept (Decline stays,
 * because saying no moves nothing).
 */
$scnPageAsks = static function () use ($newSlot, $ask, $roster, $rowOf, $A, $B, $C): array {
    $slot = $newSlot($A);
    $req  = $ask('asker', $slot, $B, 'Page asks test');
    q('UPDATE `tblRotaSlot` SET `assignedToID` = ? WHERE `slotID` = ?', 'ii', [$C, $slot['id']]);
    $row  = $rowOf($roster('asked')['body'], 'data-swap-for-me="' . $req['swapId'] . '"');

    return [
        'noAccept' => $row !== '' && str_contains($row, 'value="accept"') === false && str_contains($row, 'data-swap-cannot-accept') && str_contains($row, 'no longer with the person who asked'),
        'decline'  => str_contains($row, 'value="decline"'),
        'detail'   => substr(preg_replace('/\s+/', ' ', strip_tags($row)) ?? '', 0, 300),
    ];
};

/**
 * R2: with open requests switched OFF, an open request posted while they were
 * on cannot be taken — not offered on the page, refused by the handler — and
 * the asker can still withdraw it.
 */
$scnOpenSwitchedOff = static function () use ($newSlot, $ask, $answer, $roster, $rowOf, $holder, $swap, $A): array {
    $slot = $newSlot($A);
    $req  = $ask('asker', $slot, 0, 'Open then switched off');
    setting('rota.allow_open_swap', '0');
    $row   = $rowOf($roster('third')['body'], 'data-swap-open="' . $req['swapId'] . '"');
    $take  = $answer('third', $req['swapId'], 'accept');
    $kept  = $holder($slot['id']) === $A && ($swap($req['swapId'])['status'] ?? '') === 'pending';
    $mine  = $rowOf($roster('asker')['body'], 'data-swap-mine="' . $req['swapId'] . '"');
    $wd    = $answer('asker', $req['swapId'], 'withdraw');
    setting('rota.allow_open_swap', '1');

    return [
        'notOffered' => $row !== '' && str_contains($row, 'value="accept"') === false && str_contains($row, 'switched off'),
        'refused'    => $kept && str_contains($take['flash'], 'switched off'),
        'withdraw'   => str_contains($mine, 'value="withdraw"') && ($swap($req['swapId'])['status'] ?? '') === 'cancelled',
        'detail'     => 'take said: ' . $take['flash'] . '; withdraw said: ' . $wd['flash'],
    ];
};

/**
 * W: a decline after an administrator has moved the duty must NOT tell the
 * asker "the duty is still yours" — on the page (here) or in the e-mail (M).
 */
$scnWhereNow = static function () use ($newSlot, $ask, $answer, $roster, $rowOf, $A, $B, $C, $people): array {
    $slot = $newSlot($A);
    $req  = $ask('asker', $slot, $B, 'Where now');
    q('UPDATE `tblRotaSlot` SET `assignedToID` = ? WHERE `slotID` = ?', 'ii', [$C, $slot['id']]);
    $declined = $answer('asked', $req['swapId'], 'decline');
    $row = $rowOf($roster('asker')['body'], 'data-swap-mine="' . $req['swapId'] . '"');

    return [
        'true'   => str_contains($row, 'data-swap-status="declined"') && str_contains($row, 'it is now with ' . $people['third']['name']) && stripos($row, 'still yours') === false,
        // The message the DECLINER reads: it used to say "The duty stays with
        // the person who asked." whoever held it (round 3).
        'declinerTrue' => $declined['flash'] === 'You declined the request. This did not move the duty.',
        'slot'   => $slot,
        'swapId' => $req['swapId'],
        'detail' => substr(preg_replace('/\s+/', ' ', strip_tags($row)) ?? '', 0, 300) . ' | the decliner read: ' . $declined['flash'],
    ];
};

/**
 * W (withdraw): an asker who withdraws after an administrator moved the duty
 * must NOT be told "The duty is still yours." — the flash it used to show.
 */
$scnWithdrawMoved = static function () use ($newSlot, $ask, $answer, $A, $B, $C, $people): array {
    $slot = $newSlot($A);
    $req  = $ask('asker', $slot, $B, 'Withdraw after move');
    q('UPDATE `tblRotaSlot` SET `assignedToID` = ? WHERE `slotID` = ?', 'ii', [$C, $slot['id']]);
    $res = $answer('asker', $req['swapId'], 'withdraw');

    return [
        'true'   => $res['flash'] === 'Your request was withdrawn. This did not move the duty.',
        'detail' => $res['flash'],
    ];
};

/**
 * W (the two rarer branches of whereDutyIsNow()): the duty taken off
 * everybody, and a holder whose name cannot be read. Both through the real
 * pages: what the decliner reads, and what the asker's list says.
 */
$scnWhereNowRare = static function () use ($newSlot, $ask, $answer, $roster, $rowOf, $A, $B, $people): array {
    $slot = $newSlot($A);
    $req  = $ask('asker', $slot, $B, 'Taken off everybody');
    q('UPDATE `tblRotaSlot` SET `assignedToID` = NULL WHERE `slotID` = ?', 'i', [$slot['id']]);
    $nobody = $answer('asked', $req['swapId'], 'decline');
    $rowN   = $rowOf($roster('asker')['body'], 'data-swap-mine="' . $req['swapId'] . '"');

    $slot2 = $newSlot($A);
    $req2  = $ask('asker', $slot2, $B, 'Holder with no name');
    q('UPDATE `tblRotaSlot` SET `assignedToID` = ? WHERE `slotID` = ?', 'ii', [$people['noname']['id'], $slot2['id']]);
    $unnamed = $answer('asked', $req2['swapId'], 'decline');
    $rowU    = $rowOf($roster('asker')['body'], 'data-swap-mine="' . $req2['swapId'] . '"');

    return [
        // The asker's LIST reads the duty fresh while drawing, so it is the
        // one place that says where the duty is; what the decliner reads is
        // the fixed sentence about their own answer.
        'nobody'  => $nobody['flash'] === 'You declined the request. This did not move the duty.'
            && str_contains($rowN, 'The duty is no longer yours: it is not assigned to anybody at the moment.'),
        'unnamed' => $unnamed['flash'] === 'You declined the request. This did not move the duty.'
            && str_contains($rowU, 'The duty is no longer yours: it is now with another member.'),
        'detail'  => $nobody['flash'] . ' | ' . $unnamed['flash'] . ' | ' . substr(preg_replace('/\s+/', ' ', strip_tags($rowN . ' ' . $rowU)) ?? '', 0, 300),
    ];
};

/** D-141: an open request is e-mailed to every active member with the switch on, and nobody else. */
$scnOpenMail = static function () use ($newSlot, $ask, $mails, $roleName, $A): array {
    $slot    = $newSlot($A);
    $req     = $ask('asker', $slot, 0, 'Anyone? (D-141)');
    $subject = 'Open rota swap request: ' . $roleName . ' on ' . $slot['words'];
    $count   = [];
    foreach (['asked', 'third', 'off', 'gone', 'outsider', 'otherorg', 'noaddr', 'asker'] as $who) {
        $count[$who] = $mails($who, $subject);
    }

    return [
        'made'      => $req['swapId'] > 0,
        'members'   => $count['asked'] === 1 && $count['third'] === 1,
        'switchOff' => $count['off'] === 0,
        'suspended' => $count['gone'] === 0,
        'outside'   => $count['outsider'] === 0 && $count['otherorg'] === 0,
        'noAddress' => $count['noaddr'] === 0,
        'notAsker'  => $count['asker'] === 0,
        'detail'    => json_encode($count),
    ];
};

// ── Section M's mail route: a REAL route pointed at a server that is DOWN ────
// With no server named (the rest of this test) Mailer only logs a subject and
// an address. To read what would really be SENT — the text, how it is marked
// up, and its use-by moment — the route is filled in and pointed at a port
// nobody listens on. The connection is refused, so the message is KEPT in the
// mail queue (`tblMailQueue`), sealed with the installation's key, exactly as
// it would be on a portal whose mail server was briefly down. Nothing leaves
// this machine.
$keyText = (string) file_get_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key');
$seal    = static function (string $plain) use ($keyText): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, sodium_hex2bin(hash('sha256', $keyText))));
};
$unseal  = static function (?string $stored) use ($keyText): string {
    $bin = base64_decode((string) $stored, true);
    if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return '';
    }
    $plain = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), sodium_hex2bin(hash('sha256', $keyText)));
    // The queue seals "mq1\n" + the body (MailQueue::SEAL).
    return $plain === false ? '' : (str_starts_with($plain, "mq1\n") === true ? substr($plain, 4) : $plain);
};
$routeDown = static function (bool $on) use ($seal): void {
    if ($on === false) {
        setting('mail.provider', 'smtp');
        setting('mail.smtp.host', '');

        return;
    }
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $free   = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
    fclose($server);
    setting('mail.provider', 'smtp');
    setting('mail.smtp.host', '127.0.0.1');
    setting('mail.smtp.port', (string) $free);
    setting('mail.smtp.security', 'starttls');
    setting('mail.smtp.username', 'selftest-rota-mailbox');
    setting('mail.smtp.password', $seal('selftest-' . bin2hex(random_bytes(6))), 1);
    setting('mail.defaultFromAddress', 'office@rotaswaps.selftest.invalid');
    setting('mail.defaultFromName', 'Selftest Rota Office');
    setting('mail.defaultReplyTo', '');
    setting('mail.limit.perHour', '');
    setting('mail.fallbackProvider', '');
};
/** The newest message KEPT for one person whose subject starts with `$subject`, opened. */
$kept = static function (string $who, string $subject) use (&$people, $unseal): ?array {
    $rows = q('SELECT `bodyText`, `bodyHtml`, `expiresAt` FROM `tblMailQueue` WHERE `toRecipients` LIKE CONCAT(\'%\', ?, \'%\') AND `subject` LIKE CONCAT(?, \'%\') ORDER BY `queueID` DESC LIMIT 1', 'ss', [$people[$who]['email'], $subject]);
    if ($rows === []) {
        return null;
    }

    return ['text' => $unseal($rows[0]['bodyText']), 'html' => $unseal($rows[0]['bodyHtml']), 'expiresAt' => $rows[0]['expiresAt']];
};
/** The use-by moment the e-mail about a duty on `$date` must carry: the start of the next day on this organisation's clock, in UTC. */
$expectedUseBy = static function (string $date) use ($siteId): string {
    $zone = (string) (q('SELECT `timezone` FROM `tblSites` WHERE `siteID` = ?', 'i', [$siteId])[0]['timezone'] ?? '');
    if (in_array($zone, timezone_identifiers_list(), true) === false) {
        $zone = 'UTC';
    }
    $next = (new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');

    return (new DateTimeImmutable($next . ' 00:00:00', new DateTimeZone($zone)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
};

/**
 * M: what the e-mails really say and carry. A message quoting the asker's
 * own words `<b>x</b>` must go as TEXT (shown as typed, never as markup); it
 * must carry its use-by moment; and the decline e-mail after the duty has
 * been moved must say where it is now.
 */
$scnMailContent = static function () use ($routeDown, $kept, $expectedUseBy, $newSlot, $ask, $answer, $scnWhereNow, $roleName, $A, $B, $people): array {
    $routeDown(true);
    $slot = $newSlot($A);
    $ask('asker', $slot, $B, 'Can you <b>x</b> cover?');
    $msg  = $kept('asked', 'Rota swap request: ' . $roleName . ' on ' . $slot['words']);
    $w    = $scnWhereNow();
    $decl = $kept('asker', 'Your rota swap was declined: ' . $roleName . ' on ' . $w['slot']['words']);
    // An accept of a request aimed at B, and of an OPEN request taken by C:
    // the name in the e-mail must be whoever respond() recorded taking it.
    $slotT = $newSlot($A);
    $reqT  = $ask('asker', $slotT, $B, 'Accept e-mail');
    $answer('asked', $reqT['swapId'], 'accept');
    $accT  = $kept('asker', 'Your rota swap was accepted: ' . $roleName . ' on ' . $slotT['words']);
    $slotO = $newSlot($A);
    $reqO  = $ask('asker', $slotO, 0, 'Open accept e-mail');
    $answer('third', $reqO['swapId'], 'accept');
    $accO  = $kept('asker', 'Your rota swap was accepted: ' . $roleName . ' on ' . $slotO['words']);
    $routeDown(false);
    $dutyOf = static fn (array $slot): string => $roleName . ' on ' . $slot['words'] . ', 10:00–12:00';

    return [
        'kept'    => $msg !== null,
        'asText'  => $msg !== null && str_contains($msg['text'], 'Can you <b>x</b> cover?')
            && str_contains($msg['html'], 'Can you &lt;b&gt;x&lt;/b&gt; cover?') && str_contains($msg['html'], '<b>x</b>') === false,
        'useBy'   => $msg !== null && (string) $msg['expiresAt'] === $expectedUseBy($slot['date']),
        // Whole sentences, each between its own line breaks, so a wrong
        // fixed sentence cannot hide behind a matching fragment (round 4:
        // the accept sentences were pinned by nothing, and planting them
        // back to wrong ones stayed green).
        'declined' => $decl !== null && str_contains($decl['text'], "\n" . $people['asked']['name'] . ' declined your request to swap ' . $dutyOf($w['slot']) . ". This did not move the duty.\n")
            && stripos($decl['text'], 'still yours') === false,
        'accepted' => $accT !== null && str_contains($accT['text'], "\n" . $people['asked']['name'] . ' accepted your request to swap ' . $dutyOf($slotT) . ", and the duty was moved to them.\n"),
        'openTaker' => $accO !== null && str_contains($accO['text'], "\n" . $people['third']['name'] . ' accepted your request to swap ' . $dutyOf($slotO) . ", and the duty was moved to them.\n"),
        'detail'  => json_encode(['expiresAt' => $msg['expiresAt'] ?? null, 'expected' => $expectedUseBy($slot['date']), 'text' => substr($msg['text'] ?? '', 0, 300), 'decline' => substr($decl['text'] ?? '', 0, 300), 'accept' => substr($accT['text'] ?? '', 0, 300), 'open' => substr($accO['text'] ?? '', 0, 300)], JSON_UNESCAPED_UNICODE),
    ];
};

// =============================================================================
section('A  asking');
// =============================================================================
$slotA = $newSlot($A);
$page  = http('asker', 'GET', '/rota');
check('A1 the asker\'s Duty Roster offers "Request swap" for their own duty', str_contains($page['body'], '/rota/swap?slot=' . $slotA['id'] . '"'), 'HTTP ' . $page['status']);
$req = $ask('asker', $slotA, $B, 'Away that weekend <b>sorry</b>');
check('A2 the request is saved, aimed at the person chosen, and waiting', $req['swapId'] > 0 && ($swap($req['swapId'])['status'] ?? '') === 'pending' && (int) ($swap($req['swapId'])['targetUserID'] ?? 0) === $B, json_encode($req));
check('A3 the handler answers with a redirect to the Duty Roster (so Refresh cannot send it twice)', ($req['status'] === 302 || $req['status'] === 303) && str_ends_with($req['location'], '/rota'), json_encode($req));
$subjectA = 'Rota swap request: ' . $roleName . ' on ' . $slotA['words'];
check('A4 the person asked is e-mailed (one attempt, to their own address)', $mails('asked', $subjectA) === 1, 'attempts: ' . $mails('asked', $subjectA));
check('A5 nobody else is e-mailed about it', $mails('third', $subjectA) === 0 && $mails('asker', $subjectA) === 0);
$aPage = $roster('asker');
check('A6 the asker is told where the request is waiting, without being promised an e-mail', str_contains($aPage['flash'], 'waiting on their Duty Roster page') && stripos($aPage['flash'], 'e-mail') === false, $aPage['flash']);
check('A7 the asker sees the request under "Swap requests you made", waiting for the person asked', preg_match('#data-swap-mine="' . $req['swapId'] . '" data-swap-status="pending"#', $aPage['body']) === 1 && str_contains($aPage['body'], 'Waiting for ' . $people['asked']['name']));
$swapA = $req['swapId'];

$slotX = $newSlot($A);
$out = $ask('asker', $slotX, $people['outsider']['id']);
check('A8 a person who is NOT a member of the organisation cannot be asked (a hand-made post), and is sent nothing', $out['swapId'] === 0 && str_contains($out['shown'], 'not a member') && $mails('outsider', 'Rota swap request:') === 0, json_encode($out));
$self = $ask('asker', $slotX, $A);
check('A9 the asker cannot ask themselves', $self['swapId'] === 0 && str_contains($self['shown'], 'cannot ask yourself'), json_encode($self));
$gone = $ask('asker', $slotX, $people['gone']['id']);
check('A10 a SUSPENDED member (account switched off) cannot be asked, and is sent nothing', $gone['swapId'] === 0 && $mails('gone', 'Rota swap request:') === 0, json_encode($gone));
q('UPDATE `tblUserSites` SET `isActive` = 0 WHERE `userID` = ? AND `siteID` = ?', 'ii', [$C, $siteId]);
$removed = $ask('asker', $slotX, $C);
q('UPDATE `tblUserSites` SET `isActive` = 1 WHERE `userID` = ? AND `siteID` = ?', 'ii', [$C, $siteId]);
check('A11 a member REMOVED from the organisation cannot be asked, and is sent nothing', $removed['swapId'] === 0 && $mails('third', 'Rota swap request: ' . $roleName . ' on ' . $slotX['words']) === 0, json_encode($removed));
setting('rota.allow_open_swap', '0');
$openOff = $ask('asker', $slotX, 0);
setting('rota.allow_open_swap', '1');
check('A12 with open requests switched off, an open request is refused even when posted by hand', $openOff['swapId'] === 0 && str_contains($openOff['shown'], 'switched off'), json_encode($openOff));
$notMine = http('third', 'GET', '/rota/swap?slot=' . $slotX['id']);
check('A13 nobody can ask about a duty that is not theirs', $notMine['status'] === 403, 'HTTP ' . $notMine['status']);
/** A14: a member whose address is not an e-mail address can still be asked (they see it on the page), but no e-mail is ATTEMPTED to that text. */
$scnNoAddress = static function () use ($newSlot, $ask, $A, $people): array {
    // Counted before AND after, so an attempt left by an earlier run of this
    // scenario (during the plants) is not counted against this one.
    $attempts = static fn (): int => count(q('SELECT `emailLogID` FROM `tblEmailLog` WHERE `toRecipients` LIKE CONCAT(\'%\', ?, \'%\')', 's', [$people['noaddr']['email']]))
        + count(q('SELECT `queueID` FROM `tblMailQueue` WHERE `toRecipients` LIKE CONCAT(\'%\', ?, \'%\')', 's', [$people['noaddr']['email']]));
    $before = $attempts();
    $slot   = $newSlot($A);
    $req    = $ask('asker', $slot, $people['noaddr']['id'], 'No address test');
    $sent   = $attempts() - $before;

    return ['made' => $req['swapId'] > 0, 'notSent' => $sent === 0, 'detail' => "attempts to that address: {$sent}"];
};
$na = $scnNoAddress();
check('A14 a member with no usable e-mail address can be asked, but no e-mail is attempted', $na['made'] && $na['notSent'], $na['detail']);

// =============================================================================
section('B  the person asked sees it; a third person does not');
// =============================================================================
$bPage = $roster('asked')['body'];
check('B1 the person asked sees the request, who asked, and the message — escaped', str_contains($bPage, 'data-swap-for-me="' . $swapA . '"') && str_contains($bPage, 'Asked by ' . $people['asker']['name']) && str_contains($bPage, '&lt;b&gt;sorry&lt;/b&gt;'));
check('B2 with an Accept and a Decline button, posting to the real handler', preg_match('#action="[^"]*/rota/swap-respond"#', $bPage) === 1 && str_contains($bPage, 'value="accept"') && str_contains($bPage, 'value="decline"'));
check('B3 a third person does not see a request aimed at somebody else', str_contains($roster('third')['body'], 'data-swap-for-me="' . $swapA . '"') === false);

// =============================================================================
section('C  only the person asked may answer');
// =============================================================================
$c = $scnThirdPerson();
check('C0 the request was made', $c['made'], $c['detail']);
check('C1 a third person pressing Accept moves nothing: the duty stays with the asker and the request waits', $c['thirdAccept'], $c['detail']);
check('C2 …and is told it is not theirs to answer', $c['thirdWords'], $c['detail']);
check('C3 a third person cannot decline it either', $c['thirdDecline'], $c['detail']);
check('C4 the asker cannot accept their own request', $c['askerAccept'], $c['detail']);

// =============================================================================
section('D  accepting moves the duty, and only once');
// =============================================================================
$d = $scnAcceptOnce();
check('D1 the person who accepts reads exactly what their answer did: "Swap accepted. The duty was moved to you."', $d['firstWords'], $d['detail']);
check('D2 the duty moves to them and the request is marked accepted', $d['moved'], $d['detail']);
check('D3 the "reminder already sent" stamp is cleared, so the new holder gets their own reminder', $d['stampCleared'], $d['detail']);
check('D4 the new holder sees it among their own duties', $d['bSees'], $d['detail']);
check('D5 the asker no longer has it among their duties', $d['aNoLonger'], $d['detail']);
check('D6 the asker sees that it was accepted, and who has it now', $d['aSeesAccepted'], $d['detail']);
check('D7 the asker is e-mailed that it was accepted — once', $d['toldOnce'], $d['detail']);
check('D8 a SECOND accept (a double-click) is refused and moves nothing', $d['secondRefused'], $d['detail']);
check('D9 …and nobody is e-mailed twice', $d['notToldTwice'], $d['detail']);

// =============================================================================
section('E  declining leaves the duty where it was');
// =============================================================================
$res = $answer('asked', $swapA, 'decline', 'Sorry, away too');
check('E1 the person who declines reads exactly what their answer did: "You declined the request. This did not move the duty."', $res['flash'] === 'You declined the request. This did not move the duty.', $res['flash']);
check('E2 the duty is still the asker\'s, and the request is marked declined with the reply kept', $holder($slotA['id']) === $A && ($swap($swapA)['status'] ?? '') === 'declined' && ($swap($swapA)['responseMessage'] ?? '') === 'Sorry, away too');
$aPage = $roster('asker')['body'];
check('E3 the asker sees it was declined and that the duty is still theirs', preg_match('#data-swap-mine="' . $swapA . '" data-swap-status="declined"#', $aPage) === 1 && str_contains($aPage, 'The duty is still yours.') && str_contains($aPage, '/rota/swap?slot=' . $slotA['id'] . '"'));
check('E4 the asker is e-mailed that it was declined', $mails('asker', 'Your rota swap was declined: ' . $roleName . ' on ' . $slotA['words']) === 1);
check('E5 the request no longer waits on the person asked\'s page', str_contains($roster('asked')['body'], 'data-swap-for-me="' . $swapA . '"') === false);

// =============================================================================
section('F  the notification switch');
// =============================================================================
$on = $scnSwitchedOn();
check('F1 (the other half, so F4 cannot pass because nothing is ever sent) a member who never chose IS e-mailed', $on['made'] && $on['told']);
$prefPage = http('off', 'GET', '/account/notifications');
check('F2 the Notification preferences page draws the "Rota swap requests" switch, with its "drawn" marker', str_contains($prefPage['body'], 'name="prefs[rotaSwaps]"') && str_contains($prefPage['body'], 'name="prefsShown[rotaSwaps]"'));
// Press Save with every switch the page draws left as it is, except this one.
$fields = [];
if (preg_match('#<form\b[^>]*account/notifications/save[^>]*>(.*?)</form>#s', $prefPage['body'], $form) === 1) {
    preg_match_all('/<input\b[^>]*>/i', $form[1], $inputs);
    foreach ($inputs[0] as $input) {
        if (preg_match('/\bname="([^"]+)"/', $input, $n) !== 1) {
            continue;
        }
        $type = preg_match('/\btype="([^"]+)"/', $input, $t) === 1 ? strtolower($t[1]) : 'text';
        if (($type === 'checkbox' || $type === 'radio') && preg_match('/\bchecked\b/', $input) !== 1) {
            continue;
        }
        $fields[html_entity_decode($n[1], ENT_QUOTES, 'UTF-8')] = preg_match('/\bvalue="([^"]*)"/', $input, $v) === 1 ? html_entity_decode($v[1], ENT_QUOTES, 'UTF-8') : '';
    }
}
unset($fields['prefs[rotaSwaps]']);
parse_str(http_build_query($fields), $posted);
$saved  = http('off', 'POST', '/account/notifications/save', $posted);
$stored = json_decode((string) (q('SELECT `notifyPrefs` FROM `tblUsers` WHERE `userID` = ?', 'i', [$people['off']['id']])[0]['notifyPrefs'] ?? 'null'), true);
check('F3 switching it off on the real page stores rotaSwaps = false', is_array($stored) === true && ($stored['rotaSwaps'] ?? null) === false, 'HTTP ' . $saved['status'] . ', stored: ' . json_encode($stored));
$f = $scnSwitchedOff();
check('F4 a member who switched it off is NOT e-mailed when asked — the request is still made and waits for them', $f['made'] && $f['notTold']);

// =============================================================================
section('G  a suspended or removed member is sent nothing');
// =============================================================================
$g = $scnGoneAsker('suspended');
check('G1 an asker SUSPENDED after asking: the answer still moves the duty', $g['made'] && $g['moved'], $g['detail']);
check('G2 …and the suspended asker is sent nothing', $g['notSent'], $g['detail']);
$g = $scnGoneAsker('removed');
check('G3 an asker REMOVED from the organisation after asking: the answer still moves the duty', $g['made'] && $g['moved'], $g['detail']);
check('G4 …and the removed asker is sent nothing', $g['notSent'], $g['detail']);

// =============================================================================
section('H  withdrawing');
// =============================================================================
$slotH = $newSlot($A);
$reqH  = $ask('asker', $slotH, $B, 'Withdraw test');
$byB   = $answer('asked', $reqH['swapId'], 'withdraw');
check('H1 the person asked cannot withdraw somebody else\'s request', ($swap($reqH['swapId'])['status'] ?? '') === 'pending', $byB['flash']);
$byA = $answer('asker', $reqH['swapId'], 'withdraw');
check('H2 the asker can withdraw it; the duty is still theirs', ($swap($reqH['swapId'])['status'] ?? '') === 'cancelled' && $holder($slotH['id']) === $A && $byA['flash'] === 'Your request was withdrawn. This did not move the duty.', $byA['flash']);
$late = $answer('asked', $reqH['swapId'], 'accept');
check('H3 a withdrawn request cannot then be accepted', $holder($slotH['id']) === $A && str_contains($late['flash'], 'already been answered'), $late['flash']);
check('H4 it no longer waits on the person asked\'s page', str_contains($roster('asked')['body'], 'data-swap-for-me="' . $reqH['swapId'] . '"') === false);

// =============================================================================
section('I  an open request');
// =============================================================================
$slotI = $newSlot($A);
$reqI  = $ask('asker', $slotI, 0, 'Anyone?');
$rowI = $swap($reqI['swapId']);
check('I1 an open request is saved with nobody named', $reqI['swapId'] > 0 && array_key_exists('targetUserID', $rowI) === true && $rowI['targetUserID'] === null, json_encode($rowI));
$iSaid = $roster('asker')['flash'];
check('I2 the asker is told the request is offered to the other members, and is NOT promised that anybody is e-mailed (A6\'s rule)', str_contains($iSaid, 'offered to the other members') && stripos($iSaid, 'e-mail') === false, $iSaid);
$om = $scnOpenMail();
check('I2a (D-141) members of the organisation with the switch on are each e-mailed — one message each', $om['made'] && $om['members'], $om['detail']);
check('I2b …a member who switched the e-mails off is not', $om['switchOff'], $om['detail']);
check('I2c …a SUSPENDED member is not', $om['suspended'], $om['detail']);
check('I2d …somebody outside the organisation, and a member of ANOTHER organisation, are not', $om['outside'], $om['detail']);
check('I2e …a member with no usable e-mail address is not', $om['noAddress'], $om['detail']);
check('I2f …and the asker is not', $om['notAsker'], $om['detail']);
check('I3 another member sees it, offered to take it', str_contains($roster('third')['body'], 'data-swap-open="' . $reqI['swapId'] . '"'));
check('I4 the asker is not offered their own open request to take', str_contains($roster('asker')['body'], 'data-swap-open="' . $reqI['swapId'] . '"') === false);
$decl = $answer('third', $reqI['swapId'], 'decline');
check('I5 an open request cannot be declined: it stays open', ($swap($reqI['swapId'])['status'] ?? '') === 'pending' && str_contains($decl['flash'], 'cannot be declined'), $decl['flash']);
$own = $answer('asker', $reqI['swapId'], 'accept');
check('I6 the asker cannot take their own open request', $holder($slotI['id']) === $A && ($swap($reqI['swapId'])['status'] ?? '') === 'pending', $own['flash']);
$take = $answer('third', $reqI['swapId'], 'accept');
check('I7 another member takes it: the duty is theirs, and they read exactly what their answer did', $holder($slotI['id']) === $C && ($swap($reqI['swapId'])['status'] ?? '') === 'accepted' && $take['flash'] === 'Swap accepted. The duty was moved to you.', $take['flash']);
check('I8 the asker is e-mailed that it was accepted', $mails('asker', 'Your rota swap was accepted: ' . $roleName . ' on ' . $slotI['words']) === 1);
$again = $answer('asked', $reqI['swapId'], 'accept');
check('I9 a second member pressing Take afterwards moves nothing', $holder($slotI['id']) === $C, $again['flash']);

// =============================================================================
section('J  a duty that has already moved');
// =============================================================================
$j = $scnChangedHands();
check('J1 an old request cannot give away a duty an administrator has already moved', $j['refused'], $j['detail']);
check('J2 …and says why', $j['words'], $j['detail']);

// =============================================================================
section('K  one duty, several people asked');
// =============================================================================
$slotK = $newSlot($A);
$toB   = $ask('asker', $slotK, $B, 'K to B');
$toC   = $ask('asker', $slotK, $C, 'K to C');
$answer('third', $toC['swapId'], 'accept');
check('K1 the first to accept gets the duty', $holder($slotK['id']) === $C);
check('K2 the other request for the same duty is cancelled', ($swap($toB['swapId'])['status'] ?? '') === 'cancelled');
$answer('asked', $toB['swapId'], 'accept');
check('K3 the other person asked can no longer take it', $holder($slotK['id']) === $C);

// =============================================================================
section('L  a post without the right form token changes nothing');
// =============================================================================
$l = $scnTokens();
check('L1 asking with no form token, or a wrong one, saves no request and says the form had expired', $l['askRefused'], $l['detail']);
check('L2 answering with no form token, or a wrong one, moves nothing and says the form had expired', $l['answerRefused'], $l['detail']);

// =============================================================================
section('O  another organisation\'s request');
// =============================================================================
$o = $scnOtherOrganisation();
check('O1 posting the number of ANOTHER organisation\'s request gets exactly the answer a number that does not exist gets', $o['sameAnswer'], $o['detail']);
check('O2 …and nothing about that request or its duty changes', $o['unchanged'], $o['detail']);

// =============================================================================
section('Q  a duty whose date has passed');
// =============================================================================
$qp = $scnPast();
check('Q1 a waiting request for a duty in the PAST cannot be accepted, and says why', $qp['acceptRefused'], $qp['detail']);
check('Q2 a duty in the past cannot be offered for a swap at all', $qp['askRefused'], $qp['detail']);

// =============================================================================
section('R  the page offers only what the handler would allow');
// =============================================================================
$r1 = $scnPageAsks();
check('R1 a request whose duty has already moved is shown with the reason and NO Accept button', $r1['noAccept'], $r1['detail']);
check('R2 …and can still be declined', $r1['decline'], $r1['detail']);
$r2 = $scnOpenSwitchedOff();
check('R3 with open requests switched OFF, an open request posted earlier is not offered to take', $r2['notOffered'], $r2['detail']);
check('R4 …the handler refuses it too, and nothing moves', $r2['refused'], $r2['detail']);
check('R5 …and the asker can still withdraw it', $r2['withdraw'], $r2['detail']);

// =============================================================================
section('W  where the duty is now, after a decline');
// =============================================================================
$w = $scnWhereNow();
check('W1 a decline after an administrator moved the duty does NOT tell the asker "still yours": it names who has it', $w['true'], $w['detail']);
check('W2 …and the person who declined reads only what their answer did ("This did not move the duty."), never "the duty stays with the person who asked"', $w['declinerTrue'], $w['detail']);
$ww = $scnWithdrawMoved();
check('W3 an asker who withdraws after the duty was moved reads only what their answer did, never "the duty is still yours"', $ww['true'], $ww['detail']);
$wr = $scnWhereNowRare();
check('W4 a duty taken off everybody is described as not assigned to anybody on the asker\'s list (the decliner reads the fixed sentence)', $wr['nobody'], $wr['detail']);
check('W5 a holder whose name cannot be read is described as "another member", never a blank', $wr['unnamed'], $wr['detail']);

// =============================================================================
section('M  what the e-mails really say and carry');
// =============================================================================
$m = $scnMailContent();
check('M1 (the premise) with the mail server down, the e-mail is KEPT in the mail queue, where it can be read', $m['kept'], $m['detail']);
check('M2 the asker\'s own words go as TEXT: shown as typed in the text part, escaped in the HTML part, never as markup', $m['asText'], $m['detail']);
check('M3 the e-mail carries its use-by moment: the start of the day after the duty, on the organisation\'s clock', $m['useBy'], $m['detail']);
check('M4 the decline e-mail says what the answer did, word for word — "<name> declined your request to swap <duty>. This did not move the duty." — even with the duty moved', $m['declined'], $m['detail']);
check('M5 the accept e-mail says, word for word, "<name> accepted your request to swap <duty>, and the duty was moved to them."', $m['accepted'], $m['detail']);
check('M6 for an OPEN request it names whoever TOOK it, as respond() recorded it', $m['openTaker'], $m['detail']);

// =============================================================================
section('T  the lock: two answers at once');
// =============================================================================
$t = $scnLock();
check('T1 (the premise) the real respond(), in its own process, was WAITING on the duty this test held', $t['waited'], $t['detail']);
check('T2 once the duty had moved, the waiting accept was REFUSED as no longer the asker\'s', $t['refused'], $t['detail']);
check('T3 the duty stayed with the person it was moved to, and the request still waits', $t['kept'], $t['detail']);

// =============================================================================
section('U  two members take one open request at the same moment');
// =============================================================================
$u = $scnTwoTakers();
check('U1 (the premise) the first taker was inside its write, and the second was WAITING for it — both asked of the database', $u['premise'], $u['detail']);
check('U2 exactly one of them got the duty; the other was refused', $u['oneWins'], $u['detail']);
check('U3 the duty is with the one who got it, and the request is marked accepted', $u['firstWon'], $u['detail']);

// =============================================================================
section('V  the answer is all or nothing');
// =============================================================================
$v = $scnAllOrNothing();
check('V1 when the second write fails, the duty\'s move is UNDONE and the request still waits', $v['undone'], $v['detail']);
check('V2 …and the member is told it could not be answered, not that it worked', $v['said'], $v['detail']);

// =============================================================================
section('P  the plants: each fault must turn its check red');
// =============================================================================
// Each plant names the file in the COPY of web/ it edits, the exact text it
// replaces (each piece must be found exactly once, or the plant is reported
// as not applied rather than silently doing nothing), and the scenario that
// must go red while it is in place and green again once it is taken out.
$copyWeb = $child . $sep . 'web' . $sep;
$plants  = [
    'P1 the lock removed (no FOR UPDATE)' => ['_core/RotaSwaps.php',
        ["                . 'FOR UPDATE'\n", "                . ''\n"],
        static fn (): bool => ($r = $scnLock())['refused'] && $r['kept'],
    ],
    'P2 the "only the person asked" gate removed' => ['_core/RotaSwaps.php',
        ['if ($target !== null && $target !== $userId) {', 'if (false) {'],
        static fn (): bool => $scnThirdPerson()['thirdAccept'],
    ],
    'P3 the notification switch ignored' => ['_core/RotaSwaps.php',
        ['WebPush::notifyPrefAllows($prefs, self::PREF_KEY) === false', 'false'],
        static fn (): bool => $scnSwitchedOff()['notTold'],
    ],
    'P4 the suspended / removed check removed' => ['_core/RotaSwaps.php',
        ["if ((int) (\$person['isActive'] ?? 0) !== 1) {", 'if (false) {', 'Site::isOwnOrganisation($userId, $siteId, $db) === false', 'false'],
        static fn (): bool => $scnGoneAsker('suspended')['notSent'] && $scnGoneAsker('removed')['notSent'],
    ],
    'P5 the "still waiting?" test removed' => ['_core/RotaSwaps.php',
        ["if (\$facts['status'] !== 'pending') {", 'if (false) {'],
        static fn (): bool => ($r = $scnAcceptOnce())['secondRefused'] && $r['notToldTwice'],
    ],
    'P6 the "duty changed hands" test removed' => ['_core/RotaSwaps.php',
        ["if (\$facts['assignedToID'] === null || \$facts['assignedToID'] !== \$asker) {", 'if (false) {'],
        static fn (): bool => $scnChangedHands()['refused'],
    ],
    'P7 the transaction removed (each statement commits on its own)' => ['_core/RotaSwaps.php',
        ['$began = $db->begin_transaction();', '$began = true;'],
        static fn (): bool => $scnAllOrNothing()['undone'],
    ],
    'P8 the form-token check on the asking page removed' => ['_apps/rota/swap.php',
        ["if (Auth::verifyCsrf(\$_POST['csrf_token'] ?? '') === false) {", 'if (false) {'],
        static fn (): bool => $scnTokens()['askRefused'],
    ],
    'P9 the form-token check on the answering page removed' => ['_apps/rota/swap-respond.php',
        ["if (Auth::verifyCsrf(\$_POST['csrf_token'] ?? '') === false) {", 'if (false) {'],
        static fn (): bool => $scnTokens()['answerRefused'],
    ],
    'P10 the organisation removed from the locked read' => ['_core/RotaSwaps.php',
        ["                . 'WHERE `w`.`swapID` = ? AND `s`.`siteID` = ? '\n                . 'FOR UPDATE'", "                . 'WHERE `w`.`swapID` = ? AND ? > 0 '\n                . 'FOR UPDATE'"],
        static fn (): bool => ($r = $scnOtherOrganisation())['sameAnswer'] && $r['unchanged'],
    ],
    'P11 the "date has passed" refusal removed from the rule' => ['_core/RotaSwaps.php',
        ["if (strcmp(\$facts['slotDate'], \$today) < 0) {", 'if (false) {'],
        static fn (): bool => $scnPast()['acceptRefused'],
    ],
    'P12 the "date has passed" refusal removed from the asking page' => ['_apps/rota/swap.php',
        ["if (strcmp((string) \$slot['slotDate'], RotaSwaps::today(\$siteId)) < 0) {", 'if (false) {'],
        static fn (): bool => $scnPast()['askRefused'],
    ],
    'P13 the e-mail no longer marked as plain text (bodyIsText)' => ['_core/RotaSwaps.php',
        ["\$options = ['siteId' => \$siteId, 'bodyIsText' => true];", "\$options = ['siteId' => \$siteId];"],
        static fn (): bool => $scnMailContent()['asText'],
    ],
    'P14 the use-by moment no longer passed' => ['_core/RotaSwaps.php',
        ["\$options['expiresAt'] = \$useBy;", '$ignored = $useBy;'],
        static fn (): bool => $scnMailContent()['useBy'],
    ],
    'P15 the "usable address" check removed' => ['_core/RotaSwaps.php',
        ["if (filter_var((string) (\$person['emailAddress'] ?? ''), FILTER_VALIDATE_EMAIL) === false) {", 'if (false) {'],
        static fn (): bool => $scnNoAddress()['notSent'],
    ],
    'P16 the page no longer asks the rule before drawing Accept' => ['_apps/rota/index.php',
        ['$acceptWhy  = $whyNot($w, RotaSwaps::ACCEPT);', "\$acceptWhy  = '';"],
        static fn (): bool => $scnPageAsks()['noAccept'],
    ],
    'P17 the asker\'s list no longer asks who holds the duty' => ['_core/RotaSwaps.php',
        ['if ($holderId !== null && $holderId === $askerId) {', 'if (true) {'],
        static fn (): bool => $scnWhereNow()['true'],
    ],
    'P18 the "open requests switched off" refusal removed' => ['_core/RotaSwaps.php',
        ['if ($target === null && $openAllowed === false) {', 'if (false) {'],
        static fn (): bool => ($r = $scnOpenSwitchedOff())['notOffered'] && $r['refused'],
    ],
    'P19 the open-request e-mail no longer asks who may be told (D-141)' => ['_core/RotaSwaps.php',
        ["if (\$who === null || self::whyNotTold(\$db, \$siteId, \$id, \$who) !== '') {", 'if ($who === null) {'],
        static fn (): bool => ($r = $scnOpenMail())['switchOff'] && $r['suspended'] && $r['noAddress'],
    ],
    'P21 the accept message back to its old, wrong sentence' => ['_core/RotaSwaps.php',
        ["self::ACCEPT   => 'Swap accepted. The duty was moved to you.',", "self::ACCEPT   => 'Swap accepted. The duty is now yours and is on your list below.',"],
        static fn (): bool => $scnAcceptOnce()['firstWords'],
    ],
    'P24 the decline message back to "the duty stays with the person who asked"' => ['_core/RotaSwaps.php',
        ["self::DECLINE  => 'You declined the request. This did not move the duty.',", "self::DECLINE  => 'Swap declined. The duty stays with the person who asked.',"],
        static fn (): bool => $scnWhereNow()['declinerTrue'],
    ],
    'P25 the withdraw message back to "the duty is still yours"' => ['_core/RotaSwaps.php',
        ["self::WITHDRAW => 'Your request was withdrawn. This did not move the duty.',", "self::WITHDRAW => 'Your swap request was withdrawn. The duty is still yours.',"],
        static fn (): bool => $scnWithdrawMoved()['true'],
    ],
    'P26 the accept e-mail back to its old, wrong sentence' => ['_core/RotaSwaps.php',
        ["', and the duty was moved to them.'", "'. The duty is now theirs, and is no longer on your list.'"],
        static fn (): bool => $scnMailContent()['accepted'],
    ],
    'P27 the accept e-mail names the person ASKED, not who took it' => ['_core/RotaSwaps.php',
        ["\$answerer = (string) (\$did['answererName'] ?? '');", "\$answerer = \$req['targetName'];"],
        static fn (): bool => $scnMailContent()['openTaker'],
    ],
    'P28 the decline e-mail back to "the duty is still yours"' => ['_core/RotaSwaps.php',
        ["'. This did not move the duty.',\n", "'. The duty is still yours.',\n"],
        static fn (): bool => $scnMailContent()['declined'],
    ],
    'P22 "not assigned to anybody" no longer recognised' => ['_core/RotaSwaps.php',
        ['if ($holderId === null) {', 'if (false) {'],
        static fn (): bool => $scnWhereNowRare()['nobody'],
    ],
    'P23 a holder with no name written as a blank' => ['_core/RotaSwaps.php',
        ["(\$holderName !== '' ? \$holderName : 'another member')", '$holderName'],
        static fn (): bool => $scnWhereNowRare()['unnamed'],
    ],
];
foreach ($plants as $name => [$file, $edits, $guard]) {
    $path     = $copyWeb . str_replace('/', $sep, $file);
    $original = (string) file_get_contents($path);
    $planted  = $original;
    $applied  = true;
    for ($i = 0; $i < count($edits); $i += 2) {
        if (substr_count($planted, $edits[$i]) !== 1) {
            $applied = false;
            break;
        }
        $planted = str_replace($edits[$i], $edits[$i + 1], $planted);
    }
    check($name . ' — applied to the copy (each piece found exactly once)', $applied);
    if ($applied === false) {
        continue;
    }
    file_put_contents($path, $planted);
    try {
        $greenWhilePlanted = $guard();
    } finally {
        file_put_contents($path, $original);
    }
    check($name . ' — CAUGHT: its check goes red', $greenWhilePlanted === false);
    check($name . ' — and green again once put back', $guard() === true);
}
$untouched = true;
foreach (['_core/RotaSwaps.php', '_apps/rota/swap.php', '_apps/rota/swap-respond.php', '_apps/rota/index.php'] as $file) {
    $rel = str_replace('/', $sep, $file);
    if ((string) file_get_contents($copyWeb . $rel) !== (string) file_get_contents($repo . $sep . 'web' . $sep . $rel)) {
        $untouched = false;
    }
}
check('P20 every planted file in the copy is byte-for-byte what the repository holds afterwards', $untouched);

// ── Nothing went to the error log the test did not expect ──────────────────
$errors = is_file($child . $sep . 'php-errors.log') === true ? trim((string) file_get_contents($child . $sep . 'php-errors.log')) : '';
check('Z1 the web server wrote nothing to PHP\'s error log', $errors === '', substr($errors, 0, 1500));

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
