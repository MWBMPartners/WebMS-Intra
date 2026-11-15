<?php
// Path: tools/series-suggestion-host-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Three owner's decisions, driven over real HTTP — self-test 🔁🚦🏠
 * (#847 / D-159, #965 / D-169, #952 / D-168)
 * -----------------------------------------------------------------------------
 * WHAT IT CHECKS, with two organisations (A and B) and several kinds of person
 * (an administrator of each organisation, a global administrator, a group's
 * leaders, hosts and plain members), everything through the real pages and
 * read back from the database:
 *
 *   W  #847, D-159: a repeating series keeps ONE fixed public-website window.
 *      The new-event form SAYS so (and the edit form, which makes no series,
 *      does not), and a weekly series made with a window really stores the
 *      same two dates on every date — the behaviour D-159 chose, pinned so a
 *      later change to it (#1016) is a visible decision. The form also says
 *      what the window DOES, and that is DRIVEN through the real public door
 *      (round 1 of the check: the first sentence was untrue): while today is
 *      inside the window every date still to come is listed, even those after
 *      the Until date; after the Until date none is; before the From date none.
 *   S  #965, D-169: a suggestion still waiting to be approved cannot be
 *      published (or cancelled or postponed, which members also see) by
 *      Manage Events' edit, by the events API's update, or by the series bulk
 *      edit; each is refused and the row is left exactly as it was. Controls:
 *      a draft-only change to the same suggestion is saved; after a real
 *      approval through the moderation page the same change is allowed; a
 *      bulk publish of the series' OTHER date alone is allowed; organisation
 *      B's administrator is refused on B's suggestion too, and A's
 *      administrator cannot reach B's at all.
 *   H  #952, D-168: when a host leaves a small group — on their own, removed
 *      by a leader, or offboarded from the whole portal — their homes stop
 *      being that group's meeting places at once (the listed home emptied and
 *      switched off, the usual place emptied but its country kept), the
 *      group's leaders are e-mailed (read from the send log, which records an
 *      attempt with its subject), and NOTHING else changes: another host's
 *      home, the same person's home in ANOTHER organisation's group (when they
 *      leave only A's), and a member without a home leaving changes no place.
 *      Only CURRENT leaders are told: a leader whose membership has ended and
 *      a co-leader whose account is switched off each stand in the world and
 *      neither is e-mailed (round 1 of the check: that rule was pinned by
 *      nothing).
 *   R  #952, the race, on all THREE saves that name a host: a member adding
 *      their own home, a leader naming the host of a listed home, and a
 *      leader naming the host of the group's usual place — each WHILE that
 *      person's departure is part-way through. This test holds the membership
 *      row locked on its own connection with the membership already ended
 *      (uncommitted), sends the save through the real page WITHOUT waiting
 *      (curl_multi), checks the request is really still waiting (a sleep is a
 *      hope; this is the premise), commits, and requires the save to have
 *      been REFUSED. Without the lock each save goes through.
 *   F  #952: offboarding's home step is ALL OR NOTHING. A trigger makes the
 *      step fail after it has already emptied one home; that home must be
 *      put back, no leader told, the step recorded as failed, and the rest of
 *      the offboarding still done.
 *   O  #952: the offboarding page names the small-group steps, the remembered
 *      map position, and what a rehire does NOT put back.
 *   P  the plants: each new guard is broken in a COPY of web/, one at a time,
 *      each proved applied, and the check meant to catch it must go red;
 *      every copy is then put back and compared byte for byte. A plant that
 *      cannot find its text STOPS the run as a failure: it tested nothing.
 *
 * WHAT IT CANNOT DO, said plainly
 *   - It drives ONE web worker, so two administrators pressing two buttons at
 *     the same moment is not tested; the race in R is made by this test's own
 *     database connection standing in for the departure.
 *   - No mail service takes anything: the route is the SMTP mailbox with no
 *     server named, so each attempt is written to the send log with its
 *     subject and recipient and goes nowhere. That proves WHO the portal tried
 *     to tell, not that a message arrived.
 *   - The suggestions are written straight into the database in the state the
 *     public form leaves them (`draft`, `pending`, a visitor's name and
 *     address); the form itself is covered by event-suggester-privacy-selftest.
 *
 * SAFETY
 *   Writes rows, so it REFUSES unless the database name starts `selftest_`.
 *   Its people are numbered 847001-847099, its organisations 847101-847102,
 *   groups 847201-847209 and places 847301-847309; they are removed at the
 *   START and the END. It changes the installation's `multisite.*`,
 *   `small-groups.enabled`, `offboarding.enabled`, `mail.provider` and
 *   `mail.smtp.host`, `mail.smtp.port`, `mail.smtp.username`,
 *   `mail.smtp.password`, `mail.smtp.security`, `mail.limit.perHour`,
 *   `mail.fallbackProvider`, `mail.defaultFromAddress` and `site.url` rows and the
 *   mail job's last-ran moment, and puts them back. Section F creates
 *   a trigger (`p847_fail_usual_place`) and drops it straight after, and the
 *   start and end of every run drop it too; the database user therefore needs
 *   CREATE TRIGGER, and the test fails loudly (an uncaught error) without it.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_… SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         php tools/series-suggestion-host-selftest.php
 *         The database must already hold `web/_sql/full_schema.sql`. Needs
 *         PHP's curl extension and proc_open(); refuses rather than skipping.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/952
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);

$passed  = 0;
$failed  = 0;
$results = [];
$quiet   = false;
$state   = ['proc' => null, 'child' => null, 'settings' => [], 'started' => false, 'cleaned' => false, 'mailJob' => null];

const ORG_A = 847101;
const ORG_B = 847102;
const AADMIN = 847001; // administrator of A alone
const BADMIN = 847002; // administrator of B alone
const GADMIN = 847003; // global administrator (offboards)
const LEAD_A = 847011; // leader of A's group
const LEAD_B = 847012; // leader of B's group
const HOST   = 847021; // hosts A's usual place AND a listed home in A AND a home in B's group
const HOST2  = 847022; // hosts another listed home in A
const PLAIN  = 847023; // plain member of A's group, no home
const HOST3  = 847024; // hosts a home in A's group and in B's group; offboarded
const RACER  = 847025; // section R: a member adding their own home while leaving
const HOST4  = 847026; // section F: offboarded while the home step fails part-way
const RACER2 = 847027; // section R: named host of the usual place while leaving
const RACER3 = 847028; // section R: named host of a listed home while leaving
const FORMER_LEAD = 847013; // section H: a leader whose membership has ENDED (role still "leader")
const OFF_LEAD    = 847014; // section H: an active co-leader whose ACCOUNT is switched off
const GROUP_A = 847201;
const GROUP_B = 847202;
const GROUP_R = 847203;
const GROUP_F = 847204;
const GROUP_U = 847205;
const PLACE_HOST4_A = 847306;
const PLACE_RACE    = 847307;
const GROUP_G       = 847206; // section F: a group whose ONLY leader is the person offboarded
const PLACE_HOST4_G = 847308;
const PLACE_HOST_A  = 847301;
const PLACE_HOST2_A = 847302;
const PLACE_HOST_B  = 847303;
const PLACE_HOST3_A = 847304;
const PLACE_HOST3_B = 847305;
const GROUP_A_NAME = 'P847 Riverside';
const GROUP_B_NAME = 'P847 Hilltop';

function refuse(string $why): never
{
    echo "REFUSED: {$why}.\n";
    echo "Nothing more was checked, and this run fails. A skipped test reads exactly\n";
    echo "like a passing one, which is why this refuses rather than skipping.\n";
    exit(2);
}

/** Record a check. In a plant run ($quiet) only the result is kept, by its number. */
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed, $results, $quiet;
    $id = strtok($name, ' ');
    $results[$id] = $ok;
    if ($quiet === true) {
        return;
    }
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

function head(string $title): void
{
    global $quiet;
    if ($quiet === false) {
        echo "\n{$title}\n";
    }
}

// ── What it needs ────────────────────────────────────────────────────────────
$dbName = (string) getenv('SELFTEST_DB_NAME');
if (preg_match('/^selftest_[A-Za-z0-9_]+$/', $dbName) !== 1) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}')");
}
foreach (['curl_init', 'curl_multi_init', 'proc_open'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this test needs {$needed}(), which this PHP does not have");
    }
}
$dbHost = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
$dbUser = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
$dbPass = getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '';
$dbPort = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;
$port   = getenv('SELFTEST_WEB_PORT') !== false ? (int) getenv('SELFTEST_WEB_PORT') : 18470 + random_int(0, 90);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
} catch (Throwable $e) {
    refuse("could not reach {$dbName} on {$dbHost}:{$dbPort}");
}
$db->set_charset('utf8mb4');
$tables = (int) ($db->query('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetch_assoc()['n'] ?? 0);
if ($tables < 100) {
    refuse("the database holds only {$tables} tables — load web/_sql/full_schema.sql first");
}

/** Run one statement with bound values; answers the result set or true. */
function q(string $sql, string $types = '', array $values = [])
{
    global $db;
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    return $result === false ? true : $result;
}

/** One row, or null. */
function one(string $sql, string $types = '', array $values = []): ?array
{
    $result = q($sql, $types, $values);
    if ($result === true) {
        return null;
    }
    $row = $result->fetch_assoc();

    return $row === null ? null : $row;
}

/** Every row. */
function all(string $sql, string $types = '', array $values = []): array
{
    $result = q($sql, $types, $values);

    return $result === true ? [] : $result->fetch_all(MYSQLI_ASSOC);
}

/** Every person and organisation this test makes, removed children first. */
function removeWorld(): void
{
    global $db;
    $people = [AADMIN, BADMIN, GADMIN, LEAD_A, LEAD_B, HOST, HOST2, PLAIN, HOST3, RACER, HOST4, RACER2, RACER3, FORMER_LEAD, OFF_LEAD];
    // The trigger section F plants, in case a run was killed while it stood.
    $db->query('DROP TRIGGER IF EXISTS `p847_fail_usual_place`');
    $db->query('DELETE FROM `tblPublicDoorNames` WHERE `siteID` IN (' . ORG_A . ',' . ORG_B . ')');
    $list   = implode(',', $people);
    $db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'P847 %'");
    $db->query("DELETE FROM `tblEventSeries` WHERE `seriesSlug` LIKE 'p847-%'");
    $db->query("DELETE FROM `tblEmailLog` WHERE `subject` LIKE 'A meeting place of P847 %' OR `toRecipients` = 'p847.visitor@example.invalid'");
    $db->query("DELETE FROM `tblMailQueue` WHERE `subject` LIKE 'A meeting place of P847 %' OR `toRecipients` = 'p847.visitor@example.invalid'");
    $db->query("DELETE FROM `tblApiKeys` WHERE `name` LIKE 'P847 %'");
    $groups = implode(',', [GROUP_A, GROUP_B, GROUP_R, GROUP_F, GROUP_U, GROUP_G]);
    $db->query("DELETE FROM `tblSmallGroupMeetings` WHERE `groupID` IN ({$groups})");
    $db->query("DELETE FROM `tblSmallGroupPlaces` WHERE `groupID` IN ({$groups})");
    $db->query("DELETE FROM `tblSmallGroupMembers` WHERE `groupID` IN ({$groups})");
    $db->query("DELETE FROM `tblSmallGroups` WHERE `groupID` IN ({$groups})");
    $rs = $db->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'tblUsers'");
    while ($row = $rs->fetch_assoc()) {
        try {
            $db->query('DELETE FROM `' . $row['TABLE_NAME'] . '` WHERE `' . $row['COLUMN_NAME'] . "` IN ({$list})");
        } catch (Throwable $e) {
            // A table whose own children still name the row.
        }
    }
    $db->query("DELETE FROM `tblActivityLogs` WHERE `userID` IN ({$list})");
    $db->query("DELETE FROM `tblUsers` WHERE `userID` IN ({$list})");
    $sites = ORG_A . ',' . ORG_B;
    $rs = $db->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'tblSites'");
    while ($row = $rs->fetch_assoc()) {
        try {
            $db->query('DELETE FROM `' . $row['TABLE_NAME'] . '` WHERE `' . $row['COLUMN_NAME'] . "` IN ({$sites})");
        } catch (Throwable $e) {
        }
    }
    $db->query("DELETE FROM `tblSites` WHERE `siteID` IN ({$sites})");
}

function cleanup(): void
{
    global $state;
    if ($state['started'] === false || $state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
        $state['proc'] = null;
    }
    try {
        if ($state['mailJob'] === null || $state['mailJob']['jobLastRanAt'] === null) {
            q("UPDATE `tblMailRouteState` SET `jobLastRanAt` = NULL WHERE `routeKey` = 'installation'");
        } else {
            q("UPDATE `tblMailRouteState` SET `jobLastRanAt` = ? WHERE `routeKey` = 'installation'", 's', [(string) $state['mailJob']['jobLastRanAt']]);
        }
        foreach ($state['settings'] as $key => $before) {
            if ($before === null) {
                q('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [(string) $key]);
            } else {
                q('UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingID` = ?', 'si', [$before['settingValue'], (int) $before['settingID']]);
            }
        }
        removeWorld();
    } catch (Throwable $e) {
        echo 'FAIL — the clean-up could not put the database back: ' . $e->getMessage() . "\n";
    }
    $child = (string) $state['child'];
    if ($child !== '' && is_dir($child) === true && str_starts_with($child, sys_get_temp_dir()) === true) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $entry) {
            $entry->isDir() === true ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($child);
    }
}

/** Set an installation-wide setting, remembering what it was (once) so the clean-up can put it back. */
function setting(string $key, string $value, int $sensitive = 0): void
{
    global $state;
    if (array_key_exists($key, $state['settings']) === false) {
        $state['settings'][$key] = one('SELECT `settingID`, `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [$key]);
    }
    if ($state['settings'][$key] === null && one('SELECT 1 AS x FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [$key]) === null) {
        q('INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, ?, ?, ?, ?)', 'sssi', [$key, $value, $value, $sensitive]);
        return;
    }
    q('UPDATE `tblSettings` SET `settingValue` = ?, `isSensitive` = ? WHERE `settingKey` = ? AND `siteID` IS NULL', 'sis', [$value, $sensitive, $key]);
}

// ── Set-up ──────────────────────────────────────────────────────────────────
$state['started'] = true;
register_shutdown_function('cleanup');
removeWorld();
// Section F makes the portal believe its mail job is running, so a message
// that cannot go at once is KEPT (Mailer answers true): that is the only way
// to reach the "being e-mailed" wording without a mail server. Put back at the end.
$state['mailJob'] = one("SELECT `jobLastRanAt` FROM `tblMailRouteState` WHERE `routeKey` = 'installation'");

setting('multisite.enabled', 'true');
setting('multisite.detectionMode', 'session');
setting('small-groups.enabled', '1');
setting('offboarding.enabled', '1');
// The SMTP route with no server named: every attempt is recorded in the send
// log with its subject and recipient, and no connection is made to anything.
setting('mail.provider', 'smtp');
setting('mail.smtp.host', '');

q("INSERT INTO `tblSites` (`siteID`, `siteKey`, `siteName`, `isActive`, `timezone`) VALUES (?, 'selftest-p847-a', 'Selftest P847 Ay', 1, 'Europe/Paris')", 'i', [ORG_A]);
q("INSERT INTO `tblSites` (`siteID`, `siteKey`, `siteName`, `isActive`, `timezone`) VALUES (?, 'selftest-p847-b', 'Selftest P847 Bee', 1, 'Europe/Paris')", 'i', [ORG_B]);
$people = [
    AADMIN => ['aadmin', 0], BADMIN => ['badmin', 0], GADMIN => ['gadmin', 1], LEAD_A => ['leada', 0], LEAD_B => ['leadb', 0],
    HOST => ['host', 0], HOST2 => ['host2', 0], PLAIN => ['plain', 0], HOST3 => ['host3', 0], RACER => ['racer', 0],
    HOST4 => ['host4', 0], RACER2 => ['racer2', 0], RACER3 => ['racer3', 0], FORMER_LEAD => ['formerlead', 0], OFF_LEAD => ['offlead', 0],
];
$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
/** The address each test person has, so a check can look for it in the send log. */
function mailOf(int $id): string
{
    global $people;

    return 'p847.' . $people[$id][0] . '@selftest.invalid';
}
foreach ($people as $id => [$who, $root]) {
    q('INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `createdAt`) VALUES (?, ?, ?, 1, ?, ?, NOW())',
        'issii', [$id, 'P847 ' . ucfirst($who), mailOf($id), $root, $root]);
    q('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-p847-' . $who, $hash]);
}
foreach ([[AADMIN, ORG_A, 1], [BADMIN, ORG_B, 1], [GADMIN, ORG_A, 0], [GADMIN, ORG_B, 0], [LEAD_A, ORG_A, 0], [LEAD_B, ORG_B, 0],
          [HOST, ORG_A, 0], [HOST, ORG_B, 0], [HOST2, ORG_A, 0], [PLAIN, ORG_A, 0], [HOST3, ORG_A, 0], [HOST3, ORG_B, 0], [RACER, ORG_A, 0],
          [HOST4, ORG_A, 0], [RACER2, ORG_A, 0], [RACER3, ORG_A, 0], [FORMER_LEAD, ORG_A, 0], [OFF_LEAD, ORG_A, 0]] as [$user, $site, $admin]) {
    q('INSERT INTO `tblUserSites` (`userID`, `siteID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES (?, ?, 1, ?, ?)', 'iiii', [$user, $site, $admin, $admin]);
}

// ── A copy of web/, so a planted fault never touches the repository ─────────
$child = sys_get_temp_dir() . $sep . 'p847-' . bin2hex(random_bytes(6));
$state['child'] = $child;
mkdir($child, 0700, true);
$walk   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo . $sep . 'web', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
$copied = 0;
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
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\nreturn "
    . var_export(['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort], true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));
file_put_contents($child . $sep . 'router.php', <<<'ROUTER'
<?php
// Written by tools/series-suggestion-host-selftest.php for ONE run. Imitates .htaccess.
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

// ── The server and the requests ─────────────────────────────────────────────
$startServer = static function () use (&$state, $child, $sep, $port): void {
    $env = getenv();
    unset($env['PHP_CLI_SERVER_WORKERS']);
    $env['PORTAL_ENV'] = 'prod';
    $state['proc'] = proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log',
         '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'max_execution_time=120', '-d', 'opcache.enable=0',
         '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server.log', 'a'], 2 => ['file', $child . $sep . 'server.log', 'a']],
        $pipes,
        $child,
        $env
    );
    if (is_resource($state['proc']) === false) {
        refuse('could not start PHP\'s web server');
    }
};

/** A curl handle for one request; never follows a redirect. `$json` sends a raw JSON body instead of a form. */
function handle(string $jar, string $method, string $path, ?array $post = null, string $bearer = '', ?array $json = null): \CurlHandle
{
    global $port;
    $handle  = curl_init('http://127.0.0.1:' . $port . $path);
    $headers = ['Expect:', 'Accept-Language: en'];
    if ($bearer !== '') {
        $headers[] = 'Authorization: Bearer ' . $bearer;
    }
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => $headers]);
    if ($jar !== '') {
        curl_setopt($handle, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $jar);
    }
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $json !== null ? (string) json_encode($json) : http_build_query($post ?? []));
    }

    return $handle;
}

/** Read a finished handle's answer. @return array{status:int, location:string, body:string} */
function answerOf(\CurlHandle $handle, string|false|null $raw): array
{
    if ($raw === false || $raw === null) {
        return ['status' => 0, 'location' => '', 'body' => ''];
    }
    $size = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head = substr($raw, 0, $size);

    return ['status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
            'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $m) === 1 ? $m[1] : '',
            'body' => substr($raw, $size)];
}

/** One request, waited for. */
function req(string $jar, string $method, string $path, ?array $post = null, string $bearer = '', ?array $json = null): array
{
    $handle = handle($jar, $method, $path, $post, $bearer, $json);

    return answerOf($handle, curl_exec($handle));
}

function token(string $html): string
{
    if (preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1) {
        return $m[1];
    }

    return preg_match('/<meta name="csrf-token" content="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

/** POST a form with a fresh token read from $formPage, as a browser would. */
function post(string $jar, string $formPage, string $to, array $fields): array
{
    $fields['csrf_token'] = token(req($jar, 'GET', $formPage)['body']);

    return req($jar, 'POST', $to, $fields);
}

/** The words a page's flash message carries after a redirect (whitespace folded), or ''. */
function flashAfter(string $jar, array $answer): string
{
    if ($answer['location'] === '') {
        return '';
    }
    $path = (string) parse_url($answer['location'], PHP_URL_PATH) . (parse_url($answer['location'], PHP_URL_QUERY) !== null ? '?' . parse_url($answer['location'], PHP_URL_QUERY) : '');

    return squash(strip_tags(html_entity_decode(req($jar, 'GET', $path)['body'], ENT_QUOTES, 'UTF-8')));
}

/** The 400 characters of a page from where `$from` first appears (for a failure's detail), or its start. */
function aroundWords(string $words, string $from): string
{
    $at = strpos($words, $from);

    return substr($words, $at === false ? 0 : $at, 400);
}

/** Fold every run of whitespace into one space, so a sentence wrapped in the source reads as written. */
function squash(string $text): string
{
    return (string) preg_replace('/\s+/u', ' ', $text);
}

/** Which organisation a session is in, from /health. */
function orgSeen(string $jar): int
{
    $answer = req($jar, 'GET', '/health');
    $json   = json_decode($answer['body'], true);

    return ($answer['status'] === 200 && is_array($json) === true) ? (int) ($json['siteID'] ?? 0) : -$answer['status'];
}

/** Sign in through the real form, then choose an organisation. */
function signIn(int $id, int $site): string
{
    global $child, $sep, $password, $people;
    $who = $people[$id][0];
    $jar = $child . $sep . 'jar-' . $who . '-' . bin2hex(random_bytes(3)) . '.txt';
    $tok = token(req($jar, 'GET', '/login')['body']);
    $in  = req($jar, 'POST', '/login', ['csrf_token' => $tok, 'username' => 'selftest-p847-' . $who, 'password' => $password]);
    if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
        refuse("signing in as selftest-p847-{$who} did not work (HTTP {$in['status']} {$in['location']})");
    }
    if (orgSeen($jar) !== $site) {
        $sw = post($jar, '/site/choose', '/site/switch', ['site_id' => $site]);
        if (orgSeen($jar) !== $site) {
            refuse("could not switch selftest-p847-{$who} to organisation {$site} (switch answered {$sw['status']})");
        }
    }

    return $jar;
}

$startServer();
$up = false;
for ($i = 0; $i < 80; $i++) {
    if (req('', 'GET', '/login')['status'] > 0) {
        $up = true;
        break;
    }
    usleep(250000);
}
if ($up === false) {
    refuse('the test server never answered');
}

// =============================================================================
// W. #847, D-159: ONE FIXED WEBSITE WINDOW FOR A SERIES, SAID ON THE FORM
// =============================================================================
// The form's sentence, in two halves: the D-159 point (one fixed pair of dates
// for every date) and what that pair really DOES, which is judged against
// TODAY (round 1 of #847's check: the first sentence said a window ending
// before a later date "keeps that date off the website", which is untrue for
// every day the window is open). The second half is then DRIVEN, below.
const SERIES_SENTENCE = 'the same From and Until dates apply to every date in the series: they are not worked out again for each date';
/**
 * One of the door's own numbers, read from the COPY's `PublicDoor.php` at the
 * moment it is asked (so a planted change is seen), never typed in here.
 */
function doorConstant(string $name): int
{
    global $child, $sep;
    $src = (string) file_get_contents($child . $sep . 'web' . $sep . '_core' . $sep . 'PublicDoor.php');

    return preg_match('/public const ' . preg_quote($name, '/') . ' = (\d+);/', $src, $m) === 1 ? (int) $m[1] : -1;
}

/** The sentence's bracket, built from the door's ceiling as the form builds it. */
function seriesLimits(): string
{
    return '(A date you later make a draft, cancel or untick on its own is not shown, and a website is given at most '
        . doorConstant('MAX_LIMIT') . ' dates at a time, and fewer unless it asks for more.)';
}
const SERIES_TRUTH    = 'While today is between the two dates, every date of the series still to come is on the website, including those after the Until date; once the Until date has passed, none of them is, so a later date comes off the website before its day.';

/** A day relative to today on organisation A's own clock, as Y-m-d. */
function dayFromToday(int $days): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

/**
 * The event numbers organisation A's PUBLIC DOOR lists now (`/w/{name}/events.json`,
 * asked as nobody, exactly as another website asks), or null when the door did
 * not answer in its own format — so a "not listed" check can never pass on a
 * door that answered nothing. The door is opened for the one request and
 * closed again (public-website-tick-selftest.php does the same).
 *
 * @return list<int>|null
 */
function doorGet(string $surfaceAndQuery): array
{
    global $port;
    $name = bin2hex(random_bytes(12));
    q('DELETE FROM `tblPublicDoorNames` WHERE `siteID` = ?', 'i', [ORG_A]);
    q('INSERT INTO `tblPublicDoorNames` (`siteID`, `publicName`, `changedAtUtc`) VALUES (?, ?, UTC_TIMESTAMP())', 'is', [ORG_A, $name]);
    setting('site.url', 'http://127.0.0.1:' . $port);
    try {
        return req('', 'GET', '/w/' . $name . '/' . $surfaceAndQuery);
    } finally {
        q('DELETE FROM `tblPublicDoorNames` WHERE `siteID` = ?', 'i', [ORG_A]);
    }
}

/** How many events a door feed answer holds, or -1 when it is not a feed answer. */
function feedCount(array $r): int
{
    $j = json_decode($r['body'], true);

    return ($r['status'] === 200 && is_array($j) === true && is_array($j['events'] ?? null) === true) ? count($j['events']) : -1;
}

function doorListsNow(): ?array
{
    $r = doorGet('events.json?limit=50');
    $j = json_decode($r['body'], true);
    if ($r['status'] !== 200 || is_array($j) === false || ($j['format'] ?? null) !== 'webms-public-events' || is_array($j['events'] ?? null) === false) {
        return null;
    }
    $ids = [];
    foreach ($j['events'] as $e) {
        $row = one('SELECT `eventID` FROM `tblEvents` WHERE `siteID` = ? AND `eventSlug` = ?', 'is', [ORG_A, (string) ($e['id'] ?? '')]);
        if ($row !== null) {
            $ids[] = (int) $row['eventID'];
        }
    }

    return $ids;
}

$sectionW = static function (): void {
    global $db;
    head('W. #847, D-159: a series keeps one fixed public-website window, the form says so, and says truly what it does');
    $db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'P847 W%'");
    $db->query("DELETE FROM `tblEventSeries` WHERE `seriesSlug` LIKE 'p847-w%'");
    $jar = signIn(AADMIN, ORG_A);
    $new = req($jar, 'GET', '/calendar/manage');
    check('W00 premise: the Manage Events page answered with the new-event form', $new['status'] === 200 && str_contains($new['body'], 'id="websiteFrom-new"'), 'HTTP ' . $new['status']);
    check('W01 the new-event form says the same two dates apply to every date in a series', str_contains(squash($new['body']), SERIES_SENTENCE));
    check('W01b ...and says what they do: judged against today, so a later date comes off before its day', str_contains(squash($new['body']), SERIES_TRUTH));
    check('W01c ...and its two limits: a date taken off on its own, and how many dates the door gives at a time', str_contains(squash($new['body']), seriesLimits()));

    // Three weekly series, each made through the real form, each three dates
    // still to come (20, 27 and 34 days from now): one whose window is OPEN
    // today and ends long before its dates, one whose window has CLOSED, one
    // whose window has NOT YET OPENED.
    $make = static function (string $name, string $from, string $until) use ($jar): array {
        $start = dayFromToday(20);
        $made  = post($jar, '/calendar/manage', '/calendar/manage/save', [
            'action' => 'create', 'eventName' => $name, 'startDateTime' => $start . 'T19:00', 'endDateTime' => $start . 'T20:00',
            'timezone' => 'Europe/Paris', 'status' => 'published', 'isPublic' => '1',
            'websiteFields' => '1', 'showOnWebsite' => '1', 'websiteFrom' => $from, 'websiteUntil' => $until,
            'repeatFrequency' => 'weekly', 'repeatCount' => '3',
        ]);

        return ['status' => $made['status'], 'rows' => all("SELECT `eventID`, `seriesID`, LEFT(`startDateTime`, 10) AS `day`, `showOnWebsite`, "
            . "LEFT(`websiteFrom`, 10) AS `wf`, LEFT(`websiteUntil`, 10) AS `wu` FROM `tblEvents` WHERE `siteID` = ? AND `eventName` = ? AND `isDeleted` = 0 "
            . 'ORDER BY `startDateTime`', 'is', [ORG_A, $name])];
    };
    $open   = $make('P847 Weekly open window', dayFromToday(-1), dayFromToday(5));
    $closed = $make('P847 Weekly closed window', dayFromToday(-10), dayFromToday(-1));
    $later  = $make('P847 Weekly later window', dayFromToday(3), dayFromToday(40));
    $rows   = $open['rows'];
    check('W02 premise: each weekly series was made, three dates in one series each',
        count($rows) === 3 && count($closed['rows']) === 3 && count($later['rows']) === 3
        && count(array_unique(array_column($rows, 'seriesID'))) === 1 && $rows[0]['seriesID'] !== null,
        'HTTP ' . $open['status'] . '/' . $closed['status'] . '/' . $later['status'] . ', ' . json_encode($rows));
    $same = $rows !== [];
    foreach ($rows as $r) {
        $same = $same && $r['wf'] === dayFromToday(-1) && $r['wu'] === dayFromToday(5) && (int) $r['showOnWebsite'] === 1;
    }
    check('W03 D-159: every date of the series stores the SAME two dates, as typed', $same, json_encode($rows));

    $listed = doorListsNow();
    check('W05 premise: the public door answered in its own format', $listed !== null);
    $listed ??= [];
    $ids = static fn (array $made): array => array_map('intval', array_column($made['rows'], 'eventID'));
    check('W06 while today is inside the window, the door lists EVERY date still to come, all three after the Until date',
        $ids($open) !== [] && array_diff($ids($open), $listed) === [], 'listed ' . json_encode($listed) . ', open ' . json_encode($ids($open)));
    check('W07 once the Until date has passed, the door lists NONE of them, though every date is still to come',
        $ids($closed) !== [] && array_intersect($ids($closed), $listed) === [], 'listed ' . json_encode($listed));
    check('W08 before the From date, none is listed either', $ids($later) !== [] && array_intersect($ids($later), $listed) === [], 'listed ' . json_encode($listed));

    $edit = req($jar, 'GET', '/calendar/manage?edit=' . (int) ($rows[0]['eventID'] ?? 0));
    // THE BRACKET'S TRUE HALF, driven (round 3 of the check: it named the
    // feeds' default, 20, while the framed page and the pasted script show
    // 10). More dates than any limit, then every surface asked as a website
    // asks: each gives FEWER than the ceiling by default, and the ceiling only
    // when it asks for it.
    $db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'P847 W many %'");
    $max = doorConstant('MAX_LIMIT');
    for ($i = 1; $i <= $max + 5; $i++) {
        q("INSERT INTO `tblEvents` (`siteID`, `eventName`, `eventSlug`, `startDateTime`, `timezone`, `status`, `isPublic`, `showOnWebsite`) "
            . "VALUES (?, ?, ?, ?, 'Europe/Paris', 'published', 1, 1)", 'isss', [ORG_A, 'P847 W many ' . $i, 'p847-w-many-' . $i . '-' . bin2hex(random_bytes(3)), dayFromToday($i) . ' 10:00:00']);
    }
    $feedDefault = feedCount(doorGet('events.json'));
    check('W09 the feed with no limit gives its own default, FEWER than the ceiling', $feedDefault === doorConstant('DEFAULT_LIMIT') && $feedDefault < $max,
        "gave {$feedDefault}, default " . doorConstant('DEFAULT_LIMIT') . ", ceiling {$max}");
    check('W10 the feed gives the ceiling when it asks for it', feedCount(doorGet('events.json?limit=' . $max)) === $max);
    $over = doorGet('events.json?limit=' . ($max + 1));
    check('W11 and never more: asking for one past the ceiling is refused', $over['status'] !== 200 && feedCount($over) === -1, 'HTTP ' . $over['status']);
    $page  = doorGet('page.html');
    $shown = -1;
    if ($page['status'] === 200 && str_contains($page['body'], '<html') === true) {
        $shown = count(\Dom\HTMLDocument::createFromString($page['body'], LIBXML_NOERROR)->querySelectorAll('.door__title'));
    }
    check('W12 the framed page with no limit shows its own default, FEWER than the ceiling', $shown === doorConstant('PAGE_DEFAULT_LIMIT') && $shown < $max,
        "showed {$shown}, page default " . doorConstant('PAGE_DEFAULT_LIMIT') . ", ceiling {$max}");
    global $child, $sep;
    $script = (string) file_get_contents($child . $sep . 'web' . $sep . '_core' . $sep . 'data' . $sep . 'public-door.js');
    $scriptOk = preg_match("/wholeNumber\\(me\\.getAttribute\\('data-limit'\\),\\s*(\\d+),\\s*1,\\s*(\\d+)\\)/", $script, $sm) === 1;
    check('W13 the pasted script shows the page\'s default (fewer than the ceiling) unless told more, and never more than the ceiling',
        $scriptOk && (int) $sm[1] === doorConstant('PAGE_DEFAULT_LIMIT') && (int) $sm[1] < $max && (int) $sm[2] === $max,
        $scriptOk ? 'script default ' . $sm[1] . ', script ceiling ' . $sm[2] : 'the script\'s data-limit line was not found');
    $db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'P847 W many %'");

    check('W04 the EDIT form makes no series, and does not carry the series sentence', $edit['status'] === 200 && str_contains($edit['body'], 'id="websiteFrom-edit"') === true
        && str_contains(squash($edit['body']), SERIES_SENTENCE) === false, 'HTTP ' . $edit['status']);
};

// =============================================================================
// S. #965, D-169: A PENDING SUGGESTION CANNOT BE PUBLISHED BEFORE APPROVAL
// =============================================================================
/** A suggestion in the state the public form leaves it. Answers its number. */
function suggestion(int $site, string $name, ?int $seriesId = null): int
{
    global $db;
    q("INSERT INTO `tblEvents` (`siteID`, `eventName`, `eventSlug`, `startDateTime`, `endDateTime`, `timezone`, `status`, `isPublic`, "
        . "`seriesID`, `submissionStatus`, `submitterName`, `submitterEmail`, `submittedAt`) "
        . "VALUES (?, ?, ?, '2027-06-12 15:00:00', '2027-06-12 17:00:00', 'Europe/Paris', 'draft', 0, ?, 'pending', 'Pat Visitor', 'p847.visitor@example.invalid', NOW())",
        'issi', [$site, $name, 'p847-' . bin2hex(random_bytes(5)), $seriesId]);

    return (int) $db->insert_id;
}

function eventState(int $id): array
{
    return one('SELECT `eventName`, `status`, `submissionStatus` FROM `tblEvents` WHERE `eventID` = ?', 'i', [$id]) ?? [];
}

/** The Manage Events edit form's fields for one event, as a browser sends them. */
function editFields(int $id, string $status, string $name): array
{
    return ['action' => 'update', 'eventID' => (string) $id, 'eventName' => $name, 'startDateTime' => '2027-06-12T15:00',
        'endDateTime' => '2027-06-12T17:00', 'timezone' => 'Europe/Paris', 'status' => $status, 'cancelReason' => ''];
}

$sectionS = static function (): void {
    global $db;
    head('S. #965, D-169: a suggestion waiting for approval is refused by every status writer until it is approved');
    $db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'P847 S%'");
    $db->query("DELETE FROM `tblEventSeries` WHERE `seriesSlug` LIKE 'p847-s%'");
    $db->query("DELETE FROM `tblApiKeys` WHERE `name` LIKE 'P847 %'");
    $sA = suggestion(ORG_A, 'P847 S Bake sale');
    $sB = suggestion(ORG_B, 'P847 S Bee picnic');
    $jarA = signIn(AADMIN, ORG_A);
    $jarB = signIn(BADMIN, ORG_B);

    $form = req($jarA, 'GET', '/calendar/manage?edit=' . $sA);
    check('S00 the edit form of a pending suggestion says it stays a draft until approved', str_contains(squash($form['body']), 'This is a suggestion still waiting to be approved. It stays a draft until it is approved'));

    $r = post($jarA, '/calendar/manage', '/calendar/manage/save', editFields($sA, 'published', 'P847 S Bake sale'));
    $st = eventState($sA);
    check('S01 Manage Events: publishing a pending suggestion is refused, and it stays a pending draft', ($st['status'] ?? '') === 'draft' && ($st['submissionStatus'] ?? '') === 'pending', json_encode($st));
    check('S01b ...with the reason, and the way forward', str_contains(flashAfter($jarA, $r), 'Approve it first on Admin, Calendar, Moderation'));
    post($jarA, '/calendar/manage', '/calendar/manage/save', editFields($sA, 'cancelled', 'P847 S Bake sale'));
    $st = eventState($sA);
    check('S02 Manage Events: CANCELLING it is refused too (members are shown cancelled events)', ($st['status'] ?? '') === 'draft', json_encode($st));
    post($jarA, '/calendar/manage', '/calendar/manage/save', editFields($sA, 'draft', 'P847 S Bake sale, tidied'));
    $st = eventState($sA);
    check('S03 control: a change that keeps it a draft IS saved, and it is still waiting', ($st['eventName'] ?? '') === 'P847 S Bake sale, tidied' && ($st['submissionStatus'] ?? '') === 'pending', json_encode($st));

    // The events API, with a key of A that may write events.
    $key = 'wbms_' . bin2hex(random_bytes(16));
    q("INSERT INTO `tblApiKeys` (`siteID`, `name`, `keyHash`, `keyPrefix`, `scopes`, `isActive`) VALUES (?, 'P847 write key', ?, ?, 'events:read,events:write', 1)",
        'iss', [ORG_A, hash('sha256', $key), substr($key, 0, 12)]);
    $api = req('', 'POST', '/api/events/update', null, $key, ['eventID' => $sA, 'status' => 'published']);
    $st  = eventState($sA);
    check('S04 events API: publishing it answers 409 and nothing is saved', $api['status'] === 409 && ($st['status'] ?? '') === 'draft', 'HTTP ' . $api['status'] . ' ' . substr($api['body'], 0, 200));
    check('S04b ...and the answer says why', str_contains($api['body'], 'still waiting to be approved'));
    $api = req('', 'POST', '/api/events/update', null, $key, ['eventID' => $sA, 'status' => 'postponed']);
    check('S04c events API: postponing it is refused too', $api['status'] === 409 && (eventState($sA)['status'] ?? '') === 'draft', 'HTTP ' . $api['status']);
    $api = req('', 'POST', '/api/events/update', null, $key, ['eventID' => $sA, 'eventName' => 'P847 S Bake sale, by the API']);
    check('S05 control: an API update with no status is saved', $api['status'] === 200 && (eventState($sA)['eventName'] ?? '') === 'P847 S Bake sale, by the API', 'HTTP ' . $api['status'] . ' ' . substr($api['body'], 0, 200));

    // The series bulk edit: the suggestion and an ordinary date in one series.
    q("INSERT INTO `tblEventSeries` (`siteID`, `seriesName`, `seriesSlug`) VALUES (?, 'P847 S series', ?)", 'is', [ORG_A, 'p847-s-' . bin2hex(random_bytes(4))]);
    $series = (int) $db->insert_id;
    q('UPDATE `tblEvents` SET `seriesID` = ? WHERE `eventID` = ?', 'ii', [$series, $sA]);
    q("INSERT INTO `tblEvents` (`siteID`, `eventName`, `eventSlug`, `startDateTime`, `timezone`, `status`, `seriesID`) VALUES (?, 'P847 S ordinary date', ?, '2027-06-19 15:00:00', 'Europe/Paris', 'draft', ?)",
        'isi', [ORG_A, 'p847-' . bin2hex(random_bytes(5)), $series]);
    $plain = (int) $db->insert_id;
    $page  = '/calendar/manage/series-edit?seriesID=' . $series;
    $r = post($jarA, $page, $page, ['eventIDs' => [$sA, $plain], 'bulkStatus' => 'published']);
    check('S06 series bulk edit: publishing a set that holds the suggestion is refused, and NEITHER date changes',
        (eventState($sA)['status'] ?? '') === 'draft' && (eventState($plain)['status'] ?? '') === 'draft', json_encode([eventState($sA), eventState($plain)]));
    check('S06b ...saying one chosen date is waiting', str_contains(flashAfter($jarA, $r), 'One of the chosen dates is a suggestion still waiting to be approved'));
    post($jarA, $page, $page, ['eventIDs' => [$plain], 'bulkStatus' => 'published']);
    check('S07 control: publishing the ordinary date alone IS done', (eventState($plain)['status'] ?? '') === 'published');
    post($jarA, $page, $page, ['eventIDs' => [$sA, $plain], 'bulkStatus' => 'draft']);
    check('S07b control: a bulk change to Draft with the suggestion chosen IS done', (eventState($plain)['status'] ?? '') === 'draft' && (eventState($sA)['submissionStatus'] ?? '') === 'pending');

    // Organisation B: its own administrator is refused on its own suggestion,
    // and A's administrator cannot reach it at all.
    post($jarB, '/calendar/manage', '/calendar/manage/save', editFields($sB, 'published', 'P847 S Bee picnic'));
    check('S08 organisation B\'s administrator is refused on B\'s pending suggestion too', (eventState($sB)['status'] ?? '') === 'draft');
    post($jarA, '/calendar/manage', '/calendar/manage/save', editFields($sB, 'draft', 'P847 S renamed from A'));
    check('S08b A\'s administrator cannot change B\'s suggestion at all', (eventState($sB)['eventName'] ?? '') === 'P847 S Bee picnic');

    // After a REAL approval, the rule no longer applies.
    $mod = post($jarA, '/admin/calendar/moderation?status=pending', '/admin/calendar/moderate', ['eventID' => (string) $sA, 'action' => 'approve']);
    $st  = eventState($sA);
    check('S09 premise: approving through the moderation page publishes it', ($st['status'] ?? '') === 'published' && ($st['submissionStatus'] ?? '') === 'approved', 'HTTP ' . $mod['status'] . ' ' . json_encode($st));
    post($jarA, '/calendar/manage', '/calendar/manage/save', editFields($sA, 'cancelled', 'P847 S Bake sale, by the API'));
    check('S10 control: once approved, cancelling it on Manage Events IS saved', (eventState($sA)['status'] ?? '') === 'cancelled');
};

// =============================================================================
// H. #952, D-168: A HOST WHO LEAVES TAKES THEIR HOME OFF THE GROUP
// =============================================================================
/** A small group with its usual place (optionally a host's home). */
function group(int $id, int $site, string $name, ?int $usualHost): void
{
    q("INSERT INTO `tblSmallGroups` (`groupID`, `siteID`, `groupName`, `groupSlug`, `isActive`, `addressLine1`, `city`, `postcode`, `countryCode`, `latitude`, `longitude`, `hostUserID`, `placeMapAgreed`) "
        . "VALUES (?, ?, ?, ?, 1, ?, 'Hostville', 'HV1 1AA', 'GB', 52.2, 0.12, ?, ?)",
        'iisssii', [$id, $site, $name, 'p847-g' . $id, $usualHost === null ? null : '1 Usual Lane', $usualHost, $usualHost === null ? 0 : 1]);
}

function member(int $group, int $site, int $user, string $role = 'member'): int
{
    global $db;
    q("INSERT INTO `tblSmallGroupMembers` (`siteID`, `groupID`, `userID`, `memberRole`, `status`, `joinedAt`) VALUES (?, ?, ?, ?, 'active', NOW())", 'iiis', [$site, $group, $user, $role]);

    return (int) $db->insert_id;
}

function home(int $placeId, int $group, int $site, int $host, string $line1): void
{
    q("INSERT INTO `tblSmallGroupPlaces` (`placeID`, `siteID`, `groupID`, `placeLabel`, `addressLine1`, `city`, `countryCode`, `hostUserID`, `placeMapAgreed`) VALUES (?, ?, ?, ?, ?, 'Hostville', 'GB', ?, 1)",
        'iiissi', [$placeId, $site, $group, 'Home of ' . $host, $line1, $host]);
}

function placeRow(int $placeId): array
{
    return one('SELECT `isActive`, `addressLine1`, `placeLabel`, `hostUserID`, `placeMapAgreed` FROM `tblSmallGroupPlaces` WHERE `placeID` = ?', 'i', [$placeId]) ?? [];
}

/** Is this listed home emptied and off the list, exactly as a leader's Remove leaves it? */
function emptied(array $p): bool
{
    return (int) ($p['isActive'] ?? 1) === 0 && $p['addressLine1'] === null && $p['placeLabel'] === null && $p['hostUserID'] === null && (int) $p['placeMapAgreed'] === 0;
}

/** Is this listed home still exactly as set up (on the list, with its address and host)? */
function untouched(array $p, int $host): bool
{
    return (int) ($p['isActive'] ?? 0) === 1 && $p['addressLine1'] !== null && (int) $p['hostUserID'] === $host;
}

/** The addresses the send log holds a "meeting place needs replacing" attempt for, for one group's name. */
function leadersTold(string $groupName): array
{
    $to = array_column(all('SELECT `toRecipients` FROM `tblEmailLog` WHERE `subject` = ? ORDER BY `emailLogID`', 's', ['A meeting place of ' . $groupName . ' needs replacing']), 'toRecipients');
    sort($to);

    return $to;
}

function membershipStatus(int $group, int $user): string
{
    return (string) (one('SELECT `status` FROM `tblSmallGroupMembers` WHERE `groupID` = ? AND `userID` = ?', 'ii', [$group, $user])['status'] ?? '(none)');
}

$sectionH = static function (): void {
    global $db;
    head('H. #952, D-168: a host who leaves takes their home off the group, and the leaders are told');
    // A fresh world for this section.
    $db->query("DELETE FROM `tblEmailLog` WHERE `subject` LIKE 'A meeting place of P847 %'");
    $db->query('DELETE FROM `tblSmallGroupMeetings` WHERE `groupID` IN (' . GROUP_A . ',' . GROUP_B . ')');
    $db->query('DELETE FROM `tblSmallGroupPlaces` WHERE `groupID` IN (' . GROUP_A . ',' . GROUP_B . ')');
    $db->query('DELETE FROM `tblSmallGroupMembers` WHERE `groupID` IN (' . GROUP_A . ',' . GROUP_B . ')');
    $db->query('DELETE FROM `tblSmallGroups` WHERE `groupID` IN (' . GROUP_A . ',' . GROUP_B . ')');
    q('UPDATE `tblUsers` SET `isActive` = 1 WHERE `userID` = ?', 'i', [HOST3]);
    q('UPDATE `tblUserSites` SET `isActive` = 1 WHERE `userID` = ?', 'i', [HOST3]);
    group(GROUP_A, ORG_A, GROUP_A_NAME, HOST);
    group(GROUP_B, ORG_B, GROUP_B_NAME, null);
    member(GROUP_A, ORG_A, LEAD_A, 'leader');
    // Round 1 of #952's check: the rule that only CURRENT leaders are told
    // was pinned by nothing, because no former or switched-off leader stood
    // in this world. Each now has a person who holds that ground and no
    // other: a leader whose membership has ENDED (role still "leader"), and
    // an active co-leader whose ACCOUNT is switched off.
    $formerLead = member(GROUP_A, ORG_A, FORMER_LEAD, 'leader');
    q("UPDATE `tblSmallGroupMembers` SET `status` = 'ended', `endedAt` = NOW() WHERE `membershipID` = ?", 'i', [$formerLead]);
    member(GROUP_A, ORG_A, OFF_LEAD, 'co-leader');
    q('UPDATE `tblUsers` SET `isActive` = 0 WHERE `userID` = ?', 'i', [OFF_LEAD]);
    $hostA  = member(GROUP_A, ORG_A, HOST);
    $host2A = member(GROUP_A, ORG_A, HOST2);
    member(GROUP_A, ORG_A, PLAIN);
    member(GROUP_A, ORG_A, HOST3);
    member(GROUP_B, ORG_B, LEAD_B, 'leader');
    member(GROUP_B, ORG_B, HOST);
    member(GROUP_B, ORG_B, HOST3);
    home(PLACE_HOST_A, GROUP_A, ORG_A, HOST, '2 Host Street');
    home(PLACE_HOST2_A, GROUP_A, ORG_A, HOST2, '3 Other Street');
    home(PLACE_HOST_B, GROUP_B, ORG_B, HOST, '2 Host Street');
    home(PLACE_HOST3_A, GROUP_A, ORG_A, HOST3, '4 Third Street');
    home(PLACE_HOST3_B, GROUP_B, ORG_B, HOST3, '4 Third Street');
    // A planned meeting at HOST's listed home, and one at the usual place.
    q("INSERT INTO `tblSmallGroupMeetings` (`siteID`, `groupID`, `meetingDate`, `placeID`) VALUES (?, ?, DATE_ADD(CURDATE(), INTERVAL 20 DAY), ?)", 'iii', [ORG_A, GROUP_A, PLACE_HOST_A]);
    q("INSERT INTO `tblSmallGroupMeetings` (`siteID`, `groupID`, `meetingDate`, `placeID`) VALUES (?, ?, DATE_ADD(CURDATE(), INTERVAL 27 DAY), NULL)", 'ii', [ORG_A, GROUP_A]);

    // 1. A member with no home leaves: nothing about any place changes.
    $jarPlain = signIn(PLAIN, ORG_A);
    $groupPage = '/small-groups/group?id=' . GROUP_A;
    post($jarPlain, $groupPage, '/small-groups/join', ['groupID' => (string) GROUP_A, 'action' => 'leave']);
    check('H00 control: a member with no home leaves; their membership ends', membershipStatus(GROUP_A, PLAIN) === 'ended');
    check('H00b ...and no place changes and nobody is e-mailed', untouched(placeRow(PLACE_HOST_A), HOST) && untouched(placeRow(PLACE_HOST2_A), HOST2) && leadersTold(GROUP_A_NAME) === []);

    // 2. The host leaves A's group on their own.
    $jarHost = signIn(HOST, ORG_A);
    $left = post($jarHost, $groupPage, '/small-groups/join', ['groupID' => (string) GROUP_A, 'action' => 'leave']);
    check('H01 premise: the host leaves A\'s group', membershipStatus(GROUP_A, HOST) === 'ended', 'HTTP ' . $left['status']);
    check('H02 D-168: their listed home is emptied and off the list at once, exactly as a leader\'s Remove leaves it', emptied(placeRow(PLACE_HOST_A)), json_encode(placeRow(PLACE_HOST_A)));
    $usual = one('SELECT `addressLine1`, `postcode`, `latitude`, `hostUserID`, `placeMapAgreed`, `countryCode`, `groupName` FROM `tblSmallGroups` WHERE `groupID` = ?', 'i', [GROUP_A]) ?? [];
    check('H03 D-168: the group\'s usual place (their home) is emptied, its country kept and the group itself left alone',
        $usual['addressLine1'] === null && $usual['postcode'] === null && $usual['latitude'] === null && $usual['hostUserID'] === null
        && (int) $usual['placeMapAgreed'] === 0 && $usual['countryCode'] === 'GB' && $usual['groupName'] === GROUP_A_NAME, json_encode($usual));
    check('H04 another host\'s home in the same group is untouched', untouched(placeRow(PLACE_HOST2_A), HOST2), json_encode(placeRow(PLACE_HOST2_A)));
    check('H05 the SAME person\'s home in organisation B\'s group is untouched, and they are still a member there',
        untouched(placeRow(PLACE_HOST_B), HOST) && membershipStatus(GROUP_B, HOST) === 'active', json_encode(placeRow(PLACE_HOST_B)));
    check('H06 D-168: the group\'s leader (and only the leader) was e-mailed', leadersTold(GROUP_A_NAME) === [mailOf(LEAD_A)], json_encode(leadersTold(GROUP_A_NAME)));
    check('H06b B\'s leader was not', leadersTold(GROUP_B_NAME) === []);
    check('H06c a FORMER leader (membership ended, role still leader) was not e-mailed', in_array(mailOf(FORMER_LEAD), leadersTold(GROUP_A_NAME), true) === false);
    check('H06d a co-leader whose account is SWITCHED OFF was not e-mailed', in_array(mailOf(OFF_LEAD), leadersTold(GROUP_A_NAME), true) === false);
    $leftWords = flashAfter($jarHost, $left);
    check('H07 the leaver is told their home was removed', str_contains($leftWords, 'Your home is no longer one of its meeting places'));
    check('H07b ...and, with no mail route working here, that the leaders could not be e-mailed', str_contains($leftWords, 'No e-mail could be sent to the group\'s leaders, so please let them know.'));

    // 3. A leader removes the second host.
    $jarLead = signIn(LEAD_A, ORG_A);
    $membersPage = '/small-groups/members?id=' . GROUP_A;
    $removed = post($jarLead, $membersPage, '/small-groups/member-save', ['groupID' => (string) GROUP_A, 'action' => 'remove', 'membershipID' => (string) $host2A]);
    check('H08 a leader removes the second host: their home is emptied too', membershipStatus(GROUP_A, HOST2) === 'ended' && emptied(placeRow(PLACE_HOST2_A)), json_encode(placeRow(PLACE_HOST2_A)));
    check('H08b ...and the page says so', str_contains(flashAfter($jarLead, $removed), 'Their home is no longer one of the group\'s meeting places'));
    check('H08c the leaders were e-mailed a second time (one attempt each departure)', count(leadersTold(GROUP_A_NAME)) === 2);

    // 4. Offboarding a host who has homes in BOTH organisations' groups.
    $jarG = signIn(GADMIN, ORG_A);
    $off = post($jarG, '/admin/users', '/offboarding/do', ['userID' => (string) HOST3, 'reason' => 'P847 moved away', 'dataDisposition' => 'retain', 'effectiveDate' => date('Y-m-d')]);
    $offWords = flashAfter($jarG, $off);
    check('H14 the offboarding message says their home left 2 groups, and NAMES both groups whose leaders could not be e-mailed',
        str_contains($offWords, 'Their home was taken off 2 small groups as a meeting place. The leaders of ' . GROUP_A_NAME . ' and ' . GROUP_B_NAME
            . ' could not be e-mailed, so please tell them to choose a new place.'), aroundWords($offWords, 'Offboarded'));
    check('H09 premise: offboarding ended both of their memberships', membershipStatus(GROUP_A, HOST3) === 'ended' && membershipStatus(GROUP_B, HOST3) === 'ended', 'HTTP ' . $off['status']);
    check('H10 D-168: offboarding empties their homes in BOTH organisations\' groups', emptied(placeRow(PLACE_HOST3_A)) && emptied(placeRow(PLACE_HOST3_B)),
        json_encode([placeRow(PLACE_HOST3_A), placeRow(PLACE_HOST3_B)]));
    check('H11 ...and each group\'s own leader is e-mailed', count(leadersTold(GROUP_A_NAME)) === 3 && leadersTold(GROUP_B_NAME) === [mailOf(LEAD_B)], json_encode([leadersTold(GROUP_A_NAME), leadersTold(GROUP_B_NAME)]));
    check('H12 the other person\'s home in B (HOST, still a member there) is still untouched', untouched(placeRow(PLACE_HOST_B), HOST));
    $step = (string) (one('SELECT `stepsLog` FROM `tblOffboarding` WHERE `userID` = ? ORDER BY `offboardingID` DESC LIMIT 1', 'i', [HOST3])['stepsLog'] ?? '');
    // Decoded, never matched as text: the column is JSON, and MySQL stores it
    // in its own layout (keys reordered, a space after each colon).
    $released = array_values(array_filter((array) json_decode($step, true), static fn ($s): bool => is_array($s) && ($s['step'] ?? '') === 'release_small_group_homes'));
    check('H13 the offboarding record names the step, and it succeeded', count($released) === 1 && ($released[0]['ok'] ?? false) === true, substr($step, 0, 300));
};

// =============================================================================
// R. #952: A HOST NAMED WHILE THEIR DEPARTURE IS PART-WAY THROUGH
// =============================================================================
/**
 * Every field of the FIRST form on a page posting to `$actionEnd`, exactly as a
 * browser sends it untouched: inputs (a box or a choice only when ticked),
 * each select's selected option (else its first), and textareas. So a staged
 * save below changes only what the test says it changes.
 *
 * @return array<string,string>
 */
function formAsDrawn(string $html, string $actionEnd): array
{
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    foreach ($doc->getElementsByTagName('form') as $form) {
        if (str_ends_with((string) $form->getAttribute('action'), $actionEnd) === false) {
            continue;
        }
        $fields = [];
        foreach ($form->getElementsByTagName('input') as $in) {
            $type = strtolower((string) $in->getAttribute('type'));
            $name = (string) $in->getAttribute('name');
            if ($name === '' || in_array($type, ['submit', 'button', 'file', 'reset'], true) === true) {
                continue;
            }
            if (in_array($type, ['checkbox', 'radio'], true) === true && $in->hasAttribute('checked') === false) {
                continue;
            }
            $fields[$name] = (string) $in->getAttribute('value');
        }
        foreach ($form->getElementsByTagName('select') as $sel) {
            // A browser sends the selected option, or the first when none is.
            $chosen = null;
            foreach ($sel->getElementsByTagName('option') as $opt) {
                if ($opt->hasAttribute('selected') === true) {
                    $chosen = (string) $opt->getAttribute('value');
                    break;
                }
                $chosen ??= (string) $opt->getAttribute('value');
            }
            $fields[(string) $sel->getAttribute('name')] = (string) $chosen;
        }
        foreach ($form->getElementsByTagName('textarea') as $ta) {
            $fields[(string) $ta->getAttribute('name')] = (string) $ta->textContent;
        }

        return $fields;
    }

    return [];
}

/**
 * Stage a departure part-way through and send one request into it. This
 * connection stands in for `SmallGroups::endMembership()`: `$user`'s
 * membership of `$group` is locked and already ended, NOT committed. The
 * request is sent without waiting (curl_multi); after 2.5 seconds the test
 * asks whether it is still waiting (a sleep is a hope; this is the premise),
 * then commits the departure and collects the answer.
 *
 * @return array{waiting:bool, answer:array{status:int, location:string, body:string}}
 */
function stagedDeparture(int $group, int $user, \CurlHandle $handle): array
{
    global $dbHost, $dbUser, $dbPass, $dbName, $dbPort;
    $other = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $other->query('SET SESSION innodb_lock_wait_timeout = 30');
    $other->begin_transaction();
    $other->query('SELECT `membershipID` FROM `tblSmallGroupMembers` WHERE `groupID` = ' . $group . ' AND `userID` = ' . $user . ' FOR UPDATE');
    $other->query("UPDATE `tblSmallGroupMembers` SET `status` = 'ended', `endedAt` = NOW() WHERE `groupID` = " . $group . ' AND `userID` = ' . $user);

    $multi = curl_multi_init();
    curl_multi_add_handle($multi, $handle);
    $deadline = microtime(true) + 2.5;
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.1);
    } while ($running > 0 && microtime(true) < $deadline);
    $waiting = $running > 0;
    $other->commit();
    $other->close();
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.2);
    } while ($running > 0);
    $answer = answerOf($handle, curl_multi_getcontent($handle));
    curl_multi_remove_handle($multi, $handle);
    curl_multi_close($multi);

    return ['waiting' => $waiting, 'answer' => $answer];
}

/** The page a person sees next, as plain words (a flash message waits in the session for it). */
function nextPageWords(string $jar, string $path): string
{
    return squash(strip_tags(html_entity_decode(req($jar, 'GET', $path)['body'], ENT_QUOTES, 'UTF-8')));
}

/**
 * Stop one save at its WRITE and ask whether it still holds `$user`'s
 * membership row of `$group`. A second connection runs `$holdSql` (a
 * `FOR UPDATE` on the parent row the save's write must look up), the save is
 * sent without waiting, and after 2.5 seconds — if it is still waiting, the
 * premise — a THIRD connection tries to lock the membership row with a
 * one-second wait. Refused with "lock wait timeout" means the save holds it
 * (right); getting it means the save's lock had already gone (the fault).
 * Then everything is let go and the save finishes.
 *
 * @return array{blocked:bool, held:bool, why:string}
 */
function saveHeldAtItsWrite(string $holdSql, int $group, int $user, \CurlHandle $handle): array
{
    global $dbHost, $dbUser, $dbPass, $dbName, $dbPort;
    $holder = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $holder->begin_transaction();
    $holder->query($holdSql);

    $multi = curl_multi_init();
    curl_multi_add_handle($multi, $handle);
    $deadline = microtime(true) + 2.5;
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.1);
    } while ($running > 0 && microtime(true) < $deadline);
    $blocked = $running > 0;

    $prober = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $prober->query('SET SESSION innodb_lock_wait_timeout = 1');
    $prober->begin_transaction();
    $held = false;
    $why  = '';
    try {
        $prober->query('SELECT `membershipID` FROM `tblSmallGroupMembers` WHERE `groupID` = ' . $group . ' AND `userID` = ' . $user . ' FOR UPDATE');
        $why = 'the membership row was free while the save was still waiting to write: its lock had already been let go';
    } catch (mysqli_sql_exception $e) {
        $held = $e->getCode() === 1205;
        $why  = $held === true ? '' : 'unexpected error ' . $e->getCode();
    }
    $prober->rollback();
    $prober->close();
    $holder->commit();
    $holder->close();
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.2);
    } while ($running > 0);
    curl_multi_remove_handle($multi, $handle);
    curl_multi_close($multi);

    return ['blocked' => $blocked, 'held' => $held, 'why' => $why];
}

/** Make the portal believe its mail job ran just now (true), or has not run (false). */
function mailJobRunning(bool $running): void
{
    q("INSERT INTO `tblMailRouteState` (`routeKey`, `jobLastRanAt`) VALUES ('installation', " . ($running === true ? 'UTC_TIMESTAMP()' : 'NULL') . ') '
        . 'ON DUPLICATE KEY UPDATE `jobLastRanAt` = VALUES(`jobLastRanAt`)');
}

const HOST_REFUSAL = 'The host must be an active member of this group. Nothing was saved.';

$sectionR = static function (): void {
    global $db;
    head('R. #952: somebody named as a host while their departure is part-way through is refused, on all three saves');
    $groups = GROUP_R . ',' . GROUP_U;
    $db->query("DELETE FROM `tblSmallGroupPlaces` WHERE `groupID` IN ({$groups})");
    $db->query("DELETE FROM `tblSmallGroupMembers` WHERE `groupID` IN ({$groups})");
    $db->query("DELETE FROM `tblSmallGroups` WHERE `groupID` IN ({$groups})");
    group(GROUP_R, ORG_A, 'P847 Racing', null);
    member(GROUP_R, ORG_A, LEAD_A, 'leader');
    member(GROUP_R, ORG_A, RACER);
    member(GROUP_R, ORG_A, RACER3);
    // A listed home with NO host yet, which a leader then names RACER3 as host of.
    q("INSERT INTO `tblSmallGroupPlaces` (`placeID`, `siteID`, `groupID`, `placeLabel`, `addressLine1`, `city`, `countryCode`) VALUES (?, ?, ?, 'The annexe', '7 Annexe Way', 'Hostville', 'GB')",
        'iii', [PLACE_RACE, ORG_A, GROUP_R]);
    // A group whose usual place has an address and NO host yet, which a leader then names RACER2 as host of.
    group(GROUP_U, ORG_A, 'P847 Usual', null);
    q("UPDATE `tblSmallGroups` SET `addressLine1` = '5 Usual Road' WHERE `groupID` = ?", 'i', [GROUP_U]);
    member(GROUP_U, ORG_A, LEAD_A, 'leader');
    member(GROUP_U, ORG_A, RACER2);

    // 1. A member adding their OWN home (place-save, create).
    $jar  = signIn(RACER, ORG_A);
    $form = req($jar, 'GET', '/small-groups/place?id=' . GROUP_R);
    $tok  = token($form['body']);
    check('R00 premise: the racer may add their own home (the form is offered)', $form['status'] === 200 && $tok !== '', 'HTTP ' . $form['status']);
    $run = stagedDeparture(GROUP_R, RACER, handle($jar, 'POST', '/small-groups/place-save', ['csrf_token' => $tok, 'groupID' => (string) GROUP_R,
        'action' => 'create', 'addressLine1' => '9 Race Road', 'city' => 'Hostville', 'countryCode' => 'GB']));
    check('R01 premise: the add is still WAITING after 2.5 seconds (blocked on the membership row)', $run['waiting']);
    $stored = (int) (one('SELECT COUNT(*) AS n FROM `tblSmallGroupPlaces` WHERE `groupID` = ? AND `hostUserID` = ?', 'ii', [GROUP_R, RACER])['n'] ?? -1);
    check('R02 #952: once the departure commits, the add is REFUSED and no home is stored for somebody who has gone', $stored === 0, "stored {$stored}, HTTP {$run['answer']['status']}");
    // The refusal sends them back to the place form, which a non-member may no
    // longer open; the message waits in their session for the next page drawn.
    check('R02b ...and the racer is told why', str_contains(nextPageWords($jar, '/small-groups/group?id=' . GROUP_R), HOST_REFUSAL));

    // 2. A leader naming a member as host of a LISTED home (place-save, update).
    $lead   = signIn(LEAD_A, ORG_A);
    $page   = '/small-groups/place?id=' . GROUP_R . '&placeID=' . PLACE_RACE;
    $fields = formAsDrawn(req($lead, 'GET', $page)['body'], '/small-groups/place-save');
    check('R03 premise: the leader\'s edit form for the hostless home is drawn, with a host picker', ($fields['action'] ?? '') === 'update'
        && array_key_exists('hostUserID', $fields) && ($fields['csrf_token'] ?? '') !== '', json_encode(array_keys($fields)));
    $fields['hostUserID'] = (string) RACER3;
    $run = stagedDeparture(GROUP_R, RACER3, handle($lead, 'POST', '/small-groups/place-save', $fields));
    check('R04 premise: the save is still WAITING after 2.5 seconds (blocked on the new host\'s membership row)', $run['waiting']);
    $host = one('SELECT `hostUserID` FROM `tblSmallGroupPlaces` WHERE `placeID` = ?', 'i', [PLACE_RACE])['hostUserID'] ?? null;
    check('R05 #952: once the departure commits, the home is NOT handed to the person who has gone', $host === null, 'host ' . var_export($host, true) . ", HTTP {$run['answer']['status']}");
    check('R05b ...and the leader is told why', str_contains(nextPageWords($lead, $page), HOST_REFUSAL));

    // 3. A leader naming a member as host of the group's USUAL place (save.php).
    $page   = '/small-groups/manage?id=' . GROUP_U;
    $fields = formAsDrawn(req($lead, 'GET', $page)['body'], '/small-groups/save');
    check('R06 premise: the leader\'s group form is drawn, with a host picker', (int) ($fields['groupID'] ?? 0) === GROUP_U
        && array_key_exists('hostUserID', $fields) && ($fields['csrf_token'] ?? '') !== '', json_encode(array_keys($fields)));
    $fields['hostUserID'] = (string) RACER2;
    $run = stagedDeparture(GROUP_U, RACER2, handle($lead, 'POST', '/small-groups/save', $fields));
    check('R07 premise: the save is still WAITING after 2.5 seconds (blocked on the new host\'s membership row)', $run['waiting']);
    $usual = one('SELECT `hostUserID`, `addressLine1` FROM `tblSmallGroups` WHERE `groupID` = ?', 'i', [GROUP_U]) ?? [];
    // array_key_exists, not `??`: a stored NULL is the answer wanted here, and
    // `??` would read it as a missing row.
    check('R08 #952: once the departure commits, the usual place is NOT handed to the person who has gone',
        array_key_exists('hostUserID', $usual) === true && $usual['hostUserID'] === null
        && ($usual['addressLine1'] ?? '') === '5 Usual Road', json_encode($usual) . ", HTTP {$run['answer']['status']}");
    check('R08b ...and the leader is told why', str_contains(nextPageWords($lead, '/small-groups/group?id=' . GROUP_U), HOST_REFUSAL));

    // ── THE OTHER ORDER (round 2 of #952's check) ──────────────────────────
    // Above, the departure holds the membership row FIRST and each save waits.
    // In the other order the save checks "still a member" first; its lock on
    // the membership row must then last until the save COMMITS, or a departure
    // could end the membership, find no home to empty, and the save would then
    // store a home for somebody who has left (D-168 rules that out). A lock
    // lasts that long only inside a transaction (#697), and nothing pinned the
    // transaction: deleting it left this test green. So each save is stopped
    // at its WRITE, after its check, by this test holding a row that write must
    // wait for (the parent row its foreign key looks up); a third connection
    // then tries to take the membership row and must be made to WAIT.
    q("UPDATE `tblSmallGroupMembers` SET `status` = 'active', `endedAt` = NULL WHERE `groupID` = ? AND `userID` IN (?, ?)", 'iii', [GROUP_R, RACER, RACER3]);
    q("UPDATE `tblSmallGroupMembers` SET `status` = 'active', `endedAt` = NULL WHERE `groupID` = ? AND `userID` = ?", 'ii', [GROUP_U, RACER2]);
    q('DELETE FROM `tblSmallGroupPlaces` WHERE `groupID` = ? AND `hostUserID` = ?', 'ii', [GROUP_R, RACER]);

    // 1. Adding a home: its INSERT waits for the GROUP's row (groupID's key).
    $jar  = signIn(RACER, ORG_A);
    $tok  = token(req($jar, 'GET', '/small-groups/place?id=' . GROUP_R)['body']);
    $run  = saveHeldAtItsWrite('SELECT `groupID` FROM `tblSmallGroups` WHERE `groupID` = ' . GROUP_R . ' FOR UPDATE', GROUP_R, RACER,
        handle($jar, 'POST', '/small-groups/place-save', ['csrf_token' => $tok, 'groupID' => (string) GROUP_R, 'action' => 'create',
            'addressLine1' => '10 Race Road', 'city' => 'Hostville', 'countryCode' => 'GB']));
    check('R09 premise: adding a home is stopped at its write (after its membership check)', $run['blocked']);
    check('R10 adding a home HOLDS the member\'s membership row until it commits (a departure must wait)', $run['held'], $run['why']);

    // 2. Naming a listed home's host: its UPDATE waits for the HOST's account row (hostUserID's key).
    $lead   = signIn(LEAD_A, ORG_A);
    $fields = formAsDrawn(req($lead, 'GET', '/small-groups/place?id=' . GROUP_R . '&placeID=' . PLACE_RACE)['body'], '/small-groups/place-save');
    $fields['hostUserID'] = (string) RACER3;
    $run = saveHeldAtItsWrite('SELECT `userID` FROM `tblUsers` WHERE `userID` = ' . RACER3 . ' FOR UPDATE', GROUP_R, RACER3,
        handle($lead, 'POST', '/small-groups/place-save', $fields));
    check('R11 premise: naming a listed home\'s host is stopped at its write (after its membership check)', $run['blocked']);
    check('R12 naming a listed home\'s host HOLDS the new host\'s membership row until it commits', $run['held'], $run['why']);

    // 3. Naming the usual place's host: its UPDATE waits for the HOST's account row too.
    $fields = formAsDrawn(req($lead, 'GET', '/small-groups/manage?id=' . GROUP_U)['body'], '/small-groups/save');
    $fields['hostUserID'] = (string) RACER2;
    $run = saveHeldAtItsWrite('SELECT `userID` FROM `tblUsers` WHERE `userID` = ' . RACER2 . ' FOR UPDATE', GROUP_U, RACER2,
        handle($lead, 'POST', '/small-groups/save', $fields));
    check('R13 premise: naming the usual place\'s host is stopped at its write (after its membership check)', $run['blocked']);
    check('R14 naming the usual place\'s host HOLDS the new host\'s membership row until it commits', $run['held'], $run['why']);
};

// =============================================================================
// F. #952: OFFBOARDING'S HOME STEP IS ALL OR NOTHING
// =============================================================================
$sectionF = static function (): void {
    global $db;
    head('F. #952: when offboarding\'s home step fails part-way, none of it is kept and nobody is told a half-truth');
    $db->query('DROP TRIGGER IF EXISTS `p847_fail_usual_place`');
    $db->query('DELETE FROM `tblSmallGroupPlaces` WHERE `placeID` IN (' . PLACE_HOST4_A . ',' . PLACE_HOST4_G . ')');
    $db->query('DELETE FROM `tblSmallGroupMembers` WHERE `groupID` IN (' . GROUP_F . ',' . GROUP_G . ') OR `userID` = ' . HOST4);
    $db->query('DELETE FROM `tblSmallGroups` WHERE `groupID` IN (' . GROUP_F . ',' . GROUP_G . ')');
    $db->query("DELETE FROM `tblEmailLog` WHERE `subject` = 'A meeting place of P847 Failing needs replacing'");
    q('UPDATE `tblUsers` SET `isActive` = 1 WHERE `userID` = ?', 'i', [HOST4]);
    q('UPDATE `tblUserSites` SET `isActive` = 1 WHERE `userID` = ?', 'i', [HOST4]);
    // HOST4 hosts a listed home of group F and group F's usual place. The step
    // empties the listed home FIRST, then the usual place; a trigger makes the
    // second of those fail, so a step that is not all-or-nothing keeps the first.
    group(GROUP_F, ORG_A, 'P847 Failing', HOST4);
    member(GROUP_F, ORG_A, LEAD_A, 'leader');
    member(GROUP_F, ORG_A, HOST4);
    home(PLACE_HOST4_A, GROUP_F, ORG_A, HOST4, '6 Fourth Street');
    // Round 3 of the check: a group whose ONLY leader is the person being
    // offboarded, so there is nobody to e-mail and the page must not say
    // "tell them" about leaders who do not exist.
    group(GROUP_G, ORG_A, 'P847 Leaderless', null);
    member(GROUP_G, ORG_A, HOST4, 'leader');
    home(PLACE_HOST4_G, GROUP_G, ORG_A, HOST4, '6 Fourth Street');
    $db->query("CREATE TRIGGER `p847_fail_usual_place` BEFORE UPDATE ON `tblSmallGroups` FOR EACH ROW BEGIN "
        . 'IF OLD.`groupID` = ' . GROUP_F . " AND NEW.`hostUserID` IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'p847 planted failure'; END IF; END");
    try {
        $jarG = signIn(GADMIN, ORG_A);
        $off  = post($jarG, '/admin/users', '/offboarding/do', ['userID' => (string) HOST4, 'reason' => 'P847 failing step', 'dataDisposition' => 'retain', 'effectiveDate' => date('Y-m-d')]);
    } finally {
        $db->query('DROP TRIGGER IF EXISTS `p847_fail_usual_place`');
    }
    $acct = (int) (one('SELECT `isActive` FROM `tblUsers` WHERE `userID` = ?', 'i', [HOST4])['isActive'] ?? -1);
    check('F01 premise: the offboarding itself went through (the account is switched off)', $acct === 0, 'HTTP ' . $off['status'] . ', isActive ' . $acct);
    check('F02 the home the step had ALREADY emptied is put back: it is all or nothing', untouched(placeRow(PLACE_HOST4_A), HOST4), json_encode(placeRow(PLACE_HOST4_A)));
    $usual = one('SELECT `hostUserID`, `addressLine1` FROM `tblSmallGroups` WHERE `groupID` = ?', 'i', [GROUP_F]) ?? [];
    check('F03 the usual place is as it was', (int) ($usual['hostUserID'] ?? 0) === HOST4 && ($usual['addressLine1'] ?? null) !== null, json_encode($usual));
    check('F04 and no leader is told about homes that were not released', leadersTold('P847 Failing') === []);
    $step = (string) (one('SELECT `stepsLog` FROM `tblOffboarding` WHERE `userID` = ? ORDER BY `offboardingID` DESC LIMIT 1', 'i', [HOST4])['stepsLog'] ?? '');
    $rel  = array_values(array_filter((array) json_decode($step, true), static fn ($s): bool => is_array($s) && ($s['step'] ?? '') === 'release_small_group_homes'));
    check('F05 the step is recorded as FAILED, not as done', count($rel) === 1 && ($rel[0]['ok'] ?? true) === false, substr($step, 0, 300));
    $failWords = flashAfter($jarG, $off);
    check('F06 and the administrator is told that step failed', str_contains($failWords, 'removing their home from small groups\' meeting places'));
    check('F06b ...and is NOT told a home was taken off a group', str_contains($failWords, 'Their home was taken off') === false);
    // Offboarding again, with nothing failing and the mail job running (so the
    // leaders' message is kept to send): the home goes now, and the page says
    // the leaders are being e-mailed.
    // A route that IS set up (a server named, on a port nothing listens on) and
    // a mail job that ran just now: the send fails at once and the message is
    // KEPT for the job to retry, which is what Mailer answers true for. With no
    // server named at all nothing can be kept, and the page rightly says no
    // e-mail could be sent (H14 checks that wording).
    //
    // The password is SEALED with the copy's own key and marked secret, as the
    // portal stores one (rota-swaps-selftest.php's section M does the same):
    // an unsealed value counts as no password, and the route as not set up.
    global $child, $sep;
    $keyText = (string) file_get_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key');
    $nonce   = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $sealed  = base64_encode($nonce . sodium_crypto_secretbox('p847-' . bin2hex(random_bytes(6)), $nonce, sodium_hex2bin(hash('sha256', $keyText))));
    $server  = stream_socket_server('tcp://127.0.0.1:0');
    $free    = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
    fclose($server);
    setting('mail.smtp.host', '127.0.0.1');
    setting('mail.smtp.port', (string) $free);
    setting('mail.smtp.security', 'starttls');
    setting('mail.smtp.username', 'p847-test-mailbox');
    setting('mail.smtp.password', $sealed, 1);
    setting('mail.defaultFromAddress', 'p847.sender@selftest.invalid');
    setting('mail.limit.perHour', '');
    setting('mail.fallbackProvider', '');
    mailJobRunning(true);
    try {
        $again = post($jarG, '/admin/users', '/offboarding/do', ['userID' => (string) HOST4, 'reason' => 'P847 again', 'dataDisposition' => 'retain', 'effectiveDate' => date('Y-m-d')]);
        $againWords = flashAfter($jarG, $again);
    } finally {
        mailJobRunning(false);
        setting('mail.smtp.host', '');
    }
    check('F07 offboarding again with nothing failing takes the home off this time', emptied(placeRow(PLACE_HOST4_A)), json_encode(placeRow(PLACE_HOST4_A)));
    check('F07b ...from the leaderless group too', emptied(placeRow(PLACE_HOST4_G)), json_encode(placeRow(PLACE_HOST4_G)));
    check('F08 ...and NAMES the group whose leaders are being e-mailed, and the one with no other leader to tell',
        str_contains($againWords, 'Their home was taken off 2 small groups as a meeting place. The leaders of P847 Failing are being e-mailed to choose a new place. '
            . 'P847 Leaderless has no other current leader to tell, so please choose a new place for it or name a new leader.'), aroundWords($againWords, 'Offboarded'));
};

// =============================================================================
// O. #952: THE OFFBOARDING PAGE SAYS WHAT OFFBOARDING AND A REHIRE REALLY DO
// =============================================================================
$sectionO = static function (): void {
    head('O. #952: the offboarding page names the small-group steps, and what a rehire does NOT put back');
    $jarG  = signIn(GADMIN, ORG_A);
    $words = nextPageWords($jarG, '/offboarding/user?id=' . PLAIN);
    check('O00 premise: the offboarding page for a member was drawn', str_contains($words, 'Offboarding will:'));
    check('O01 it says small-group memberships end', str_contains($words, 'End their small-group memberships and join requests, in every organisation'));
    check('O02 it says their home leaves every group, the address deleted, and the leaders e-mailed', str_contains($words,
        'Take their home off every small group that meets there, in every organisation, deleting its address from the group at once'));
    check('O03 it names the remembered map position honestly', str_contains($words, 'a map position the portal looked up for it may be remembered until its keeping period ends'));
    check('O04 the rehire paragraph says only the account and organisation memberships come back', str_contains($words,
        'A rehire switches the account back on, restores its organisation memberships (every organisation for a global administrator, only this one for anybody else) and cancels any erasure request for them still waiting for review, even one they made themselves; NOTHING ELSE is put back'));
    check('O05 ...and that a deleted small-group home address is gone for good', str_contains($words, 'a small-group home address that was deleted is gone for good'));
};

// ── Run everything once ─────────────────────────────────────────────────────
$sectionW();
$sectionS();
$sectionH();
$sectionR();
$sectionF();
$sectionO();

// =============================================================================
// P. THE PLANTS
// =============================================================================
head('P. Each new guard broken in the copy, one at a time: the check meant to catch it goes red');
$web = $child . $sep . 'web' . $sep;
$plants = [
    ['P01', '_apps/calendar/manage/_event_form.php', 'they are not worked out again for each date', 'they are worked out again', $sectionW, 'W01', 'the series sentence reworded away'],
    ['P01b', '_apps/calendar/manage/_event_form.php', 'once the Until date has passed, none of them is, so a later date comes off the website before its', 'a later date stays off the website', $sectionW, 'W01b', 'the series sentence\'s true half reworded back to the untrue one'],
    ['P02', '_apps/calendar/manage/save.php', 'if ($pendingRefusal !== null) {', 'if ($pendingRefusal !== null && false) {', $sectionS, 'S01', 'Manage Events\' refusal switched off'],
    ['P03', '_apps/events/api/update.php', 'if ($pendingRefusal !== null) {', 'if ($pendingRefusal !== null && false) {', $sectionS, 'S04', 'the events API\'s refusal switched off'],
    ['P04', '_apps/calendar/manage/series-edit.php', 'if ($pendingAmongChosen > 0) {', 'if ($pendingAmongChosen > 0 && false) {', $sectionS, 'S06', 'the bulk edit\'s refusal switched off'],
    ['P05', '_core/EventSuggestions.php', "if (\$submissionStatus !== 'pending' || \$newStatus === self::STATUS_WHILE_PENDING) {", "if (\$submissionStatus !== 'pending' || \$newStatus !== 'published') {", $sectionS, 'S02', 'the rule narrowed to "published" only'],
    ['P06', '_core/SmallGroups.php', '? self::releaseHomesOf($db, $userId, $siteId, $groupId) : [];', '? [] : [];', $sectionH, 'H02', 'a departure releasing nothing'],
    ['P07', '_apps/offboarding/do.php', '$releasedHomes = \Portal\Core\SmallGroups::releaseHomesOf($db, $userId);', '$releasedHomes = [];', $sectionH, 'H10', 'offboarding releasing nothing'],
    ['P08', '_core/SmallGroups.php', "AND m.`memberRole` IN ('leader','co-leader') AND u.`isActive` = 1 AND m.`userID` <> ?", "AND m.`memberRole` IN ('none') AND u.`isActive` = 1 AND m.`userID` <> ?", $sectionH, 'H06', 'the leaders not told'],
    ['P10', '_core/SmallGroups.php', "AND m.`siteID` = ? AND m.`status` = 'active' \"", "AND m.`siteID` = ? \"", $sectionH, 'H06c', 'a former leader told too (membership status not asked)'],
    ['P11', '_core/SmallGroups.php', "AND u.`isActive` = 1 AND m.`userID` <> ?", "AND m.`userID` <> ?", $sectionH, 'H06d', 'a switched-off leader told too (account not asked)'],
    ['P09', '_apps/small-groups/place-save.php', 'if ($host !== null && SmallGroups::hostStillBelongs($db, $siteId, $groupId, $host) === false) {', 'if (false) {', $sectionR, 'R02', 'adding a home not asking under the lock'],
    ['P12', '_apps/small-groups/place-save.php', 'if ($host !== null && $host !== $storedHost && SmallGroups::hostStillBelongs($db, $siteId, $groupId, $host) === false) {', 'if (false) {', $sectionR, 'R05', 'naming a listed home\'s host not asking under the lock'],
    ['P13', '_apps/small-groups/save.php', "&& SmallGroups::hostStillBelongs(\$db, \$siteId, \$groupId, \$hostPick['host']) === false) {", '&& false) {', $sectionR, 'R08', 'naming the usual place\'s host not asking under the lock'],
    ['P14', '_apps/offboarding/do.php', "\$db->query('ROLLBACK TO SAVEPOINT `release_small_group_homes`');", '', $sectionF, 'F02', 'offboarding\'s home step not rolled back on a part-way failure'],
    ['P01c', '_apps/calendar/manage/_event_form.php', 'dates at a time, and fewer', 'dates at a time, or as many', $sectionW, 'W01c', 'the series sentence no longer saying the default is fewer'],
    ['P01d', '_apps/calendar/manage/_event_form.php', 'given at most <?php echo (int) \\Portal\\Core\\PublicDoor::MAX_LIMIT; ?> dates', 'given at most <?php echo (int) \\Portal\\Core\\PublicDoor::DEFAULT_LIMIT; ?> dates', $sectionW, 'W01c', 'the series sentence naming a default (20) as the ceiling'],
    ['P01e', '_core/PublicDoor.php', 'public const PAGE_DEFAULT_LIMIT = 10;', 'public const PAGE_DEFAULT_LIMIT = 50;', $sectionW, 'W12', 'the framed page showing the ceiling by default ("fewer" then untrue)'],
    ['P22', '_core/SmallGroups.php', "\$outcome['noLeader'][] = \$label;", "\$outcome['notSent'][] = \$label;", $sectionF, 'F08', 'a group with no other leader worded as leaders who could not be e-mailed'],
    ['P16', '_apps/small-groups/place-save.php', "//    first: a lock outside one is decoration (#697).\n    \$db->begin_transaction();", "//    first: a lock outside one is decoration (#697).\n", $sectionR, 'R10', 'adding a home with its transaction removed (the lock let go at once)'],
    ['P17', '_apps/small-groups/place-save.php', "\$db->begin_transaction();\ntry {\n    \$lock = \$db->prepare(", "try {\n    \$lock = \$db->prepare(", $sectionR, 'R12', 'naming a listed home\'s host with its transaction removed'],
    ['P18', '_apps/small-groups/save.php', "    \$db->begin_transaction();\n    try {\n        \$lock = \$db->prepare(", "    try {\n        \$lock = \$db->prepare(", $sectionR, 'R14', 'naming the usual place\'s host with its transaction removed'],
    ['P19', '_core/SmallGroups.php', "AND `userID` = ? AND `status` = 'active' FOR UPDATE\"", "AND `userID` = ? AND `status` = 'active'\"", $sectionR, 'R10', 'the membership check taking no lock at all'],
    ['P20', '_apps/offboarding/do.php', "has been recorded.' . \$erasureNote . \$homesNote;", "has been recorded.' . \$erasureNote;", $sectionH, 'H14', 'the offboarding message saying nothing about the homes'],
    ['P21', '_apps/offboarding/user.php', 'and cancels any erasure request for them still waiting for review', 'and', $sectionO, 'O04', 'the rehire paragraph not saying it cancels a waiting erasure request'],
    ['P15', '_apps/offboarding/user.php', '<li>Take their home off every small group that meets there', '<li>Take them off', $sectionO, 'O02', 'the offboarding page not saying their home leaves every group'],
];
foreach ($plants as [$id, $file, $find, $with, $section, $mustFail, $what]) {
    $path     = $web . str_replace('/', $sep, $file);
    $original = (string) file_get_contents($path);
    $count    = substr_count($original, $find);
    if ($count !== 1) {
        // A plant that cannot find its text has tested nothing. That is a
        // FAILURE of this run, never a skip, and it stops here so a red
        // reading cannot hide behind the rest (round 1 of #952's check).
        $failed += 1;
        echo "  ✗ {$id} plant could not be applied ({$what}): the text to replace appears {$count} times in {$file}\n";
        echo "\nFAIL — a planted fault could not be applied, so the run stops; {$passed} passed, {$failed} failed\n";
        exit(1);
    }
    file_put_contents($path, str_replace($find, $with, $original));
    $applied = str_contains((string) file_get_contents($path), $with) && substr_count((string) file_get_contents($path), $find) === 0;
    $quiet   = true;
    $results = [];
    try {
        $section();
    } finally {
        $quiet = false;
        file_put_contents($path, $original);
    }
    check("{$id} {$what}: {$mustFail} goes red", $applied === true && ($results[$mustFail] ?? true) === false,
        'applied: ' . var_export($applied, true) . ', ' . $mustFail . ' was ' . var_export($results[$mustFail] ?? null, true));
}

head('P99. Everything put back');
$same = true;
foreach (array_unique(array_column($plants, 1)) as $file) {
    $same = $same && hash_file('sha256', $web . str_replace('/', $sep, $file)) === hash_file('sha256', $repo . $sep . 'web' . $sep . str_replace('/', $sep, $file));
}
check('P99 every planted file in the copy is byte-for-byte the repository\'s again', $same);
$results = [];
$sectionW();
$sectionS();
$sectionH();
$sectionR();
$sectionF();
$sectionO();
check('P99b and every check is green again', array_filter($results, static fn (bool $ok): bool => $ok === false) === [],
    implode(', ', array_keys(array_filter($results, static fn (bool $ok): bool => $ok === false))));

$errors = is_file($child . $sep . 'php-errors.log') === true ? trim((string) file_get_contents($child . $sep . 'php-errors.log')) : '';
check('Z01 the server logged no PHP error in any run', $errors === '', substr($errors, 0, 600));

echo "\n" . ($failed === 0 ? 'PASS' : 'FAIL') . " — {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
