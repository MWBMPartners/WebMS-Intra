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
 * countdown feed (`/widget/countdown.json`), `/sitemap.xml`, and the copied-in
 * rule's card on /admin/calendar/feeds with its handler — and reads every
 * result back out of the database rather than believing a message.
 *
 * WHAT IT COVERS
 *   C  THE LIVE EXPOSURE THIS PACKAGE CLOSES: the countdown feed shows a
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
 *   K  who may publish, asked of the real `PublicWebsite::mayPublish()` in the
 *      copy (a separate process): an administrator made on Admin → Users with
 *      the portal-wide flag counts where they BELONG, and nowhere else.
 *   R  the copied-in rule: who gets which form (the stricter rule too, so the
 *      page's own gate is what refuses), a looser organisation choice refused,
 *      the installation's choice capping the organisation's at READ time too,
 *      an unreadable rule counting as the stricter, and the countdown AND the
 *      sitemap following the rule for a copied-in event.
 *   V  what the calendar's page SAYS: every "on the public website" badge,
 *      label and approval sentence is true under the rule in force.
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
 *   - The countdown is cached for a minute by browsers and anything in
 *     between; curl here caches nothing, so that minute is not tested.
 *   - The series-repattern half (a date the pattern ADDS is off the website)
 *     is check S18w of tools/event-repeat-pages-selftest.php, not here.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_… SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         [SELFTEST_WEB_PORT=9493] php tools/public-website-tick-selftest.php
 *
 *         The database must already hold `web/_sql/full_schema.sql` (with
 *         migration 258). Needs PHP's curl extension and `proc_open()`. It
 *         REFUSES rather than skipping when anything is missing.
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
const PWT_EXPECTED_CHECKS = 114;

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

$startServer();
$waitForServer();

/** The countdown's "next event" number, or 0 for none. Asked as nobody. */
$countdownNext = static function () use ($http): int {
    $r = $http('GET', '/widget/countdown.json', [], [], false);
    $j = json_decode($r['body'], true);
    return (int) ($j['nextEvent']['id'] ?? 0);
};
$sitemapHas = static function (string $slug) use ($http): bool {
    $r = $http('GET', '/sitemap.xml', [], [], false);
    return $r['status'] === 200 && str_contains($r['body'], '/e/' . rawurlencode($slug) . '<');
};

// =============================================================================
echo "C. The countdown feed — the live exposure this package closes\n";
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
check('C1 a published, PUBLIC event that nobody ticked is NOT on the countdown (until #493 it was)', $scnUnticked(), true);
$setEv($cEvent, ['showOnWebsite' => '1']);
check('C2 the same event, ticked, IS on the countdown', $countdownNext(), $cEvent);
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
check('C9 another organisation\'s ticked event never reaches this organisation\'s countdown', $countdownNext() !== $other, true);

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
// ⚠️ WHY THIS SECTION EXISTS (round 3 of the check, 8 November 2026). Both
//    public readers take "today" from `date('Y-m-d')`, which is the
//    organisation's date only because start-up sets PHP's zone to
//    `Site::timezone()`. Every check above judges the dates against
//    organisation 1's "today" — and organisation 1 is seeded as UTC, the zone
//    the runners use, so here the organisation's today and UTC's were always
//    the same day and nothing above could tell a reader using the
//    organisation's calendar from one using UTC's (#549's trap: a fixture that
//    cannot tell two zones apart proves nothing about which one the code
//    uses). So this section moves organisation 1, for a few seconds, to a zone
//    whose date is NOT UTC's right now, ASSERTS that premise first (D0), and
//    dates the event to each of the two days in turn. P23 and P24 plant
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
 * organisation's today, then UTC's today — read on the countdown and in the
 * sitemap each time. Puts the event back off the website afterwards.
 */
$scnWhoseToday = static function () use ($inZoneApart, $setEv, $cEvent, $countdownNext, $sitemapHas, $cSlug): mixed {
    return $inZoneApart(static function (array $z) use ($setEv, $cEvent, $countdownNext, $sitemapHas, $cSlug): array {
        $setEv($cEvent, ['showOnWebsite' => '1', 'websiteFrom' => $z['orgToday'], 'websiteUntil' => $z['orgToday'],
            'isPublic' => '1', 'status' => 'published', 'isDeleted' => '0']);
        $org = ['countdown' => $countdownNext() === $cEvent, 'sitemap' => $sitemapHas($cSlug)];
        $setEv($cEvent, ['websiteFrom' => $z['utcToday'], 'websiteUntil' => $z['utcToday']]);
        $utc = ['countdown' => $countdownNext() === $cEvent, 'sitemap' => $sitemapHas($cSlug)];
        $setEv($cEvent, ['showOnWebsite' => '0', 'websiteFrom' => null, 'websiteUntil' => null]);
        return ['org' => $org, 'utc' => $utc];
    });
};
$dr = $scnWhoseToday();
check('D0 the premise held from before the first request to after the last: the organisation\'s day and UTC\'s were different, and neither moved',
    is_array($dr) === true ? true : $dr, true);
check('D1 dated to the ORGANISATION\'s today only: on the countdown', is_array($dr) === true ? $dr['org']['countdown'] : $dr, true);
check('D2 dated to the ORGANISATION\'s today only: in the sitemap', is_array($dr) === true ? $dr['org']['sitemap'] : $dr, true);
check('D3 dated to UTC\'s today only, which is not the organisation\'s: NOT on the countdown', is_array($dr) === true ? $dr['utc']['countdown'] : $dr, false);
check('D4 dated to UTC\'s today only, which is not the organisation\'s: NOT in the sitemap', is_array($dr) === true ? $dr['utc']['sitemap'] : $dr, false);

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
// soonest thing on the countdown when the rule lets it through.
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
check('R7 under "one box per calendar" the calendar-ticked copied-in event is on the countdown', $scnCalendarRule(), true);
check('R8 under "one tick per event" it is not; the organisation\'s own ticked event is', $scnEventRule(), true);
check('R9 an organisation row looser than the installation is held down when READ too', $scnReaderCaps(), true);
$db->query("UPDATE `tblEvents` SET `importSource` = 'date' WHERE `eventID` = {$impId}");
$setRule('event', null);
check('R10 under "one tick per event", a copied-in event whose "yes" came from a choice about that date IS on the countdown', $countdownNext(), $impId);
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
// engine reads it. The portal's OWN events are deliberately not asked about
// here: their markup still follows "Public" alone in this package (D-089,
// #850, decides it should need the tick too; that package adds its checks).
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
    // `website.publish` is the one this world gives a holder (the Website Editor).
    $rolesWant[$key] = $key === 'website.publish' ? 'no warning drawn' : 'right';
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
check('P1 with the switch dropped from the website rule, the unticked event reaches the countdown again', [$p['applied'], $p['result']], [true, false]);
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
check('P9 with the READ-time cap gone, the looser organisation row puts the copied-in event on the countdown', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/widget/countdown-json.php', 'PublicWebsite::copiedRuleFor($mysqli, $siteId)', 'EventVisibility::COPIED_BY_CALENDAR', $scnEventRule);
check('P10 with the countdown no longer passing the organisation\'s rule, "one tick per event" stops applying there', [$p['applied'], $p['result']], [true, false]);
$setEv($cEvent, ['showOnWebsite' => '1']);
$p = $plant('public_html/sitemap.php', 'PublicWebsite::copiedRuleFor($db, $siteId)', 'EventVisibility::COPIED_BY_CALENDAR', $scnSitemapRule);
check('P12 with the sitemap passing the looser rule, it lists the copied-in event under "one tick per event"', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/admin/calendar/feeds-website-rule.php', 'PublicWebsite::saveRuleForOrganisation($mysqli, $siteId, $posted, App::isSiteRootAdmin())',
    'PublicWebsite::saveRuleForOrganisation($mysqli, $siteId, $posted, true)', $scnStricterByAdmin);
check('P13 with the handler\'s "organisation\'s global administrator" gate gone, an ordinary administrator stores the organisation\'s rule', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_core/PublicWebsite.php', 'return $stored === EventVisibility::COPIED_BY_CALENDAR;', 'return $stored !== EventVisibility::COPIED_BY_EVENT;', $scnGarbageRule);
check('P14 with "anything not exactly \'event\' is looser", an unreadable rule lets the copied-in event onto the countdown', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/admin/calendar/feeds-view.php',
    "<?php if ((int) \$ev['importWebsite'] === 1 && EventVisibility::copiedTickCounts(\$ev['importSource'] === null ? null : (string) \$ev['importSource'], \$copiedRule) === true): ?>",
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
// The OLD line put back exactly: the markup on `importWebsite` alone.
$p = $plant('_apps/calendar/event.php',
    "    \$jsonLdAllowed = \$canSeeFull === true && (int) \$event['importWebsite'] === 1\n        && EventVisibility::copiedTickCounts(\n"
    . "            \$event['importSource'] === null ? null : (string) \$event['importSource'],\n"
    . "            PublicWebsite::copiedRuleFor(\$mysqli, \$siteId)\n        ) === true;",
    "    \$jsonLdAllowed = \$canSeeFull === true && (int) \$event['importWebsite'] === 1;",
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
// Section D's two readers, each made to take "today" from UTC instead of the
// organisation's calendar. Each scenario answers true only when the event
// dated to the organisation's today IS shown and the one dated to UTC's is NOT.
$scnWhoseTodayIn = static function (string $reader) use ($scnWhoseToday): mixed {
    $r = $scnWhoseToday();
    return is_array($r) === true ? ($r['org'][$reader] === true && $r['utc'][$reader] === false) : $r;
};
$p = $plant('_apps/widget/countdown-json.php', "\$today      = date('Y-m-d');", "\$today      = gmdate('Y-m-d');",
    static fn (): mixed => $scnWhoseTodayIn('countdown'));
check('P23 with the countdown taking "today" from UTC, the dates are judged on the wrong calendar', [$p['applied'], $p['result']], [true, false]);
$p = $plant('public_html/sitemap.php', "\$today      = date('Y-m-d');", "\$today      = gmdate('Y-m-d');",
    static fn (): mixed => $scnWhoseTodayIn('sitemap'));
check('P24 with the sitemap taking "today" from UTC, the dates are judged on the wrong calendar', [$p['applied'], $p['result']], [true, false]);
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
$setEv($cEvent, ['showOnWebsite' => '0']);
check('P11 with every plant removed again, the unticked event is off the countdown once more', $scnUnticked(), true);

// 🧾 The server's own error log. A page that throws part-way can still answer
//    200, so the log is read as well.
$serverLog = (string) @file_get_contents($child . $sep . 'php-errors.log');
$ours      = [];
foreach (preg_split('/\R/', $serverLog) ?: [] as $line) {
    if (stripos($line, 'Fatal') !== false || stripos($line, 'PublicWebsite') !== false || stripos($line, 'Uncaught') !== false) {
        $ours[] = $line;
    }
}
check('Z1 the server logged no fatal error and nothing about PublicWebsite', $ours, []);

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

$db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'PWT %' OR `siteID` = {$siteTwo}");
$db->query("DELETE FROM `tblExternalEventApprovals` WHERE `snapTitle` LIKE 'PWT approval %'");
$db->query("DELETE FROM `tblExternalFeedRules` WHERE `name` = 'PWT rule'");
$db->query("DELETE FROM `tblExternalFeeds` WHERE `name` = 'PWT outside calendar'");
$setRule($ruleWas ?? 'event', null);
$db->query("DELETE FROM `tblSettings` WHERE `siteID` = {$siteTwo}");
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
