<?php
// Path: tools/registration-retention-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The optional ceiling on how long registrations are kept, over real HTTP 🧒🛑
 * -----------------------------------------------------------------------------
 * #816, the owner's decision D-074 (following D-032).
 *
 * It drives the REAL pages — `/admin/settings/registrations`, its handler, the
 * general settings editor at `/settings`, and the events API — with a real
 * sign-in, and reads every result back out of the database rather than
 * believing a message.
 *
 * WHAT IT COVERS
 *   A  with NO ceiling nothing changes: any period may be stored, "keep
 *      indefinitely" (0) included, and an empty box follows the installation
 *   B  the general settings editor refuses both keys — to change, to add and
 *      to delete — for an organisation's administrator AND a global one
 *   C  gate 2: a ceiling below a period somebody already holds is REFUSED, and
 *      the installation's administrator is told who (an organisation, an
 *      event, the installation's own period); the activity log gets counts
 *      and no names
 *   D  the ceiling is set, and the installation's own period is held to it
 *   E  gate 1: an organisation's period above the ceiling is REFUSED with the
 *      reason, never clamped, never naming who set the ceiling, and recorded
 *   F  an untouched Save passes over a period above the ceiling (which only SQL
 *      or a restore can produce), and the page says the longer period is kept
 *   G  the events API is NOT a writer of an event's own period
 *   H  a member who is not an administrator is refused the page and the handler
 *   I  THE LOCK: a ceiling being changed in another, uncommitted transaction
 *      makes a save WAIT, and the save then judges the new ceiling. Driven with
 *      a second PROCESS, because the test server runs one worker and two
 *      requests from one process can never overlap
 *   J  THE REAL CLEAR-OUT, run over HTTP at `/cron/retention-sweep`: a stored
 *      period is read exactly as before #816 (empty and non-numeric text keep
 *      registrations indefinitely; negative and missing are 90 days), and the
 *      job does NOT apply the ceiling (D-074 following D-032)
 *   K  with the clear-out switched off (`events.registrationRetentionRun`), the
 *      job, this page and the Retention card all say what really happens
 *   R  THE REVERSE LOCK: an organisation's save in flight makes a ceiling save
 *      WAIT, and the ceiling save then sees the longer period and refuses
 *   S  a ceiling below a held period is refused whether it goes UP or DOWN
 *      (D-032's rule), and removing the ceiling altogether is always allowed
 *   P  the plants: each guard above is taken out of a COPY of the portal and
 *      the scenario that should catch it is run again, and must go red.
 *      Count them from the `$plant(` calls, never from a comment.
 *
 * WHAT IT CANNOT DO, said plainly
 *   - It runs ONE engine at a time, whichever the caller points it at.
 *   - Section I proves the lock serialises ONE pair; #687's 116-pair
 *     measurement is the precedent for many, and was done by hand.
 *   - Section J drives the real clear-out for ONE organisation and events on
 *     UTC only; the per-event clock and the clock-change rules are
 *     `tools/retention-caps-selftest.php` section G's.
 *   - It drives the page and the job; it does not prove the customer's
 *     scheduler really calls the job every night.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_regret SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         [SELFTEST_WEB_PORT=9816] php tools/registration-retention-selftest.php
 *
 *         The database must already hold `web/_sql/full_schema.sql`. Needs
 *         PHP's curl extension and `proc_open()`. It REFUSES rather than
 *         skipping when anything is missing, because a skipped test reads
 *         exactly like a passing one.
 *
 * Exit:   0 only when every check ran and passed AND the number of checks is
 *         the number expected; 1 otherwise; 2 when it refused to run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/816
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const RR_EXPECTED_CHECKS = 95;

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
    $db     = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $locker = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
} catch (Throwable $e) {
    refuse("could not reach {$dbName} on {$dbHost}:{$dbPort}");
}
$tables = (int) ($db->query(
    'SELECT COUNT(*) AS `n` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE()'
)->fetch_assoc()['n'] ?? 0);
if ($tables < 100) {
    refuse("the database holds only {$tables} tables — load web/_sql/full_schema.sql first");
}
$seeded = $db->query(
    "SELECT COUNT(*) AS `n` FROM `tblSettings` WHERE `settingKey` = 'events.registrationRetentionMaxDays' AND `siteID` IS NULL"
)->fetch_assoc();
if ((int) ($seeded['n'] ?? 0) !== 1) {
    refuse('the ceiling setting is not seeded — this database was not built from the current install script (migration 257)');
}

// ── A copy of web/, so a planted fault never touches the repository ─────────
$child = sys_get_temp_dir() . $sep . 'regret-http-' . bin2hex(random_bytes(6));
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
    "<?php\n// Written by tools/registration-retention-selftest.php for ONE run.\nreturn "
    . var_export(['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort], true)
    . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// The router imitates web/public_html/.htaccess, as the other page tests do.
file_put_contents($child . $sep . 'router.php', <<<'ROUTER'
<?php
// Written by tools/registration-retention-selftest.php for ONE run. Test only.
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

// The second process section I uses: one POST with the cookie jar it is given.
file_put_contents($child . $sep . 'one-post.php', <<<'POST'
<?php
// Written by tools/registration-retention-selftest.php for ONE run. Test only.
$h = curl_init($argv[1]);
curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $argv[3],
    CURLOPT_COOKIEFILE => $argv[2], CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_TIMEOUT => 90]);
curl_exec($h);
echo curl_getinfo($h, CURLINFO_HTTP_CODE);
POST);

// SELFTEST_WEB_PORT when the runner or a workflow chooses one (so two tests
// running side by side never share a port); otherwise one picked at random.
$port = getenv('SELFTEST_WEB_PORT') !== false ? (int) getenv('SELFTEST_WEB_PORT') : 8960 + random_int(0, 30);
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
$http = static function (string $method, string $path, array|string $post = [], array $headers = []) use ($port, $jar): array {
    $handle = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => $headers,
    ]);
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
        if ($http('GET', '/login')['status'] > 0) {
            return;
        }
        usleep(250000);
    }
    refuse('the test server never answered');
};

// ── The people ──────────────────────────────────────────────────────────────
$siteOne = (int) ($db->query('SELECT MIN(`siteID`) AS `s` FROM `tblSites`')->fetch_assoc()['s'] ?? 0);
if ($siteOne <= 0) {
    refuse('the database has no organisation');
}
$siteOneName = (string) $db->query("SELECT `siteName` FROM `tblSites` WHERE `siteID` = {$siteOne}")->fetch_assoc()['siteName'];

/** Remove a person and everything naming them, children first (the i18n test's lesson). */
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

$people = [];
foreach (['orgadmin' => [1, 0], 'globaladmin' => [1, 1], 'member' => [0, 0]] as $role => [$isAdmin, $isRoot]) {
    $email = "regret.{$role}@selftest.invalid";
    $old   = $db->prepare('SELECT `userID` FROM `tblUsers` WHERE `emailAddress` = ?');
    $old->bind_param('s', $email);
    $old->execute();
    foreach ($old->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $removeMember((int) $row['userID']);
    }
    $old->close();
    $ins = $db->prepare(
        'INSERT INTO `tblUsers` (`fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `createdAt`) VALUES (?, ?, 1, ?, ?, NOW())'
    );
    $name = 'Selftest ' . $role;
    $ins->bind_param('ssii', $name, $email, $isAdmin, $isRoot);
    $ins->execute();
    $id = (int) $db->insert_id;
    $ins->close();
    $db->query("INSERT IGNORE INTO `tblUserSites` (`siteID`, `userID`, `isActive`) VALUES ({$siteOne}, {$id}, 1)");
    $password = 'Sel' . bin2hex(random_bytes(8)) . '!9x';
    $hash     = password_hash($password, PASSWORD_DEFAULT);
    $login    = 'selftest-regret-' . $role;
    $db->query("DELETE FROM `tblLocalAccounts` WHERE `username` = '{$login}'");
    $acc = $db->prepare('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)');
    $acc->bind_param('iss', $id, $login, $hash);
    $acc->execute();
    $acc->close();
    $people[$role] = ['id' => $id, 'login' => $login, 'password' => $password];
}

// A second organisation, so a refusal has more than one name to give.
$db->query("DELETE FROM `tblSettings` WHERE `siteID` IN (SELECT `siteID` FROM `tblSites` WHERE `siteKey` = 'selftest-regret-two')");
$db->query("DELETE FROM `tblSites` WHERE `siteKey` = 'selftest-regret-two'");
$db->query("INSERT INTO `tblSites` (`siteKey`, `siteName`, `timezone`, `isActive`) VALUES ('selftest-regret-two', 'Selftest Regret Two', 'UTC', 1)");
$siteTwo = (int) $db->insert_id;

// One API key for the events API, as the time-zones test makes one.
$apiKey = 'wbms_' . bin2hex(random_bytes(16));
$apiPrefix = substr($apiKey, 0, 12);
$apiHash   = hash('sha256', $apiKey);
$ak = $db->prepare("INSERT INTO `tblApiKeys` (`siteID`, `name`, `keyHash`, `keyPrefix`, `scopes`, `isActive`, `createdByID`) VALUES (?, 'Selftest regret key', ?, ?, 'events:read,events:write', 1, NULL)");
$ak->bind_param('iss', $siteOne, $apiHash, $apiPrefix);
$ak->execute();
$apiKeyId = (int) $db->insert_id;
$ak->close();

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
const RR_DAYS = 'events.registrationRetentionDays';
const RR_MAX  = 'events.registrationRetentionMaxDays';
/** The clear-out's switch. A class constant so the closures above can name it. */
final class RegistrationRetentionRunKey
{
    public const KEY = 'events.registrationRetentionRun';
}

/** A settings row's value, or null when there is none. `$site` null = installation-wide. */
$setting = static function (string $key, ?int $site) use ($db): ?string {
    $stmt = $db->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` ' . ($site === null ? 'IS NULL' : '= ?'));
    $site === null ? $stmt->bind_param('s', $key) : $stmt->bind_param('si', $key, $site);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row === null ? null : (string) $row['settingValue'];
};
/** Put a row in by SQL — the only way to reach the state some sections are about. null value = delete it. */
$put = static function (string $key, ?int $site, ?string $value) use ($db): void {
    $del = $db->prepare('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` ' . ($site === null ? 'IS NULL' : '= ?'));
    $site === null ? $del->bind_param('s', $key) : $del->bind_param('si', $key, $site);
    $del->execute();
    $del->close();
    if ($value !== null) {
        $ins = $db->prepare("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, ?, ?, '', 0)");
        $ins->bind_param('iss', $site, $key, $value);
        $ins->execute();
        $ins->close();
    }
};
$settingId = static function (string $key, ?int $site) use ($db): int {
    $stmt = $db->prepare('SELECT `settingID` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` ' . ($site === null ? 'IS NULL' : '= ?'));
    $site === null ? $stmt->bind_param('s', $key) : $stmt->bind_param('si', $key, $site);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['settingID'] ?? 0);
};
$activityCount = static function (string $type) use ($db): int {
    $stmt = $db->prepare('SELECT COUNT(*) AS `n` FROM `tblActivityLogs` WHERE `activityType` = ?');
    $stmt->bind_param('s', $type);
    $stmt->execute();
    $n = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
    $stmt->close();
    return $n;
};
$lastActivity = static function (string $type) use ($db): string {
    $stmt = $db->prepare('SELECT `activityDescription` FROM `tblActivityLogs` WHERE `activityType` = ? ORDER BY `logID` DESC LIMIT 1');
    $stmt->bind_param('s', $type);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (string) ($row['activityDescription'] ?? '');
};
/** An event with a period of its own, put in by SQL — no page writes one (see section G). */
$eventWithPeriod = static function (string $name, ?int $days) use ($db, $siteOne): int {
    $db->query("DELETE FROM `tblEvents` WHERE `eventSlug` LIKE 'selftest-regret-%'");
    if ($days === null) {
        return 0;
    }
    $slug = 'selftest-regret-' . bin2hex(random_bytes(3));
    $stmt = $db->prepare(
        "INSERT INTO `tblEvents` (`siteID`, `eventName`, `eventSlug`, `startDateTime`, `endDateTime`, `timezone`, `registrationRetentionDays`) "
        . "VALUES (?, ?, ?, '2026-01-10 10:00:00', '2026-01-10 12:00:00', 'UTC', ?)"
    );
    $stmt->bind_param('issi', $siteOne, $name, $slug, $days);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};
/** The installation's two rows and both organisations' own rows, set in one go. */
$world = static function (?string $instDays, string $ceiling, ?string $orgOne, ?string $orgTwo) use ($put, $siteOne, $siteTwo): void {
    $put(RR_DAYS, null, $instDays);
    $put(RR_MAX, null, $ceiling);
    $put(RR_DAYS, $siteOne, $orgOne);
    $put(RR_DAYS, $siteTwo, $orgTwo);
};

// ── Page helpers ────────────────────────────────────────────────────────────
$page = static fn (): array => $http('GET', '/admin/settings/registrations');
/** Post the page's own form with the page's own token, then read the flash it leaves. */
$save = static function (array $fields) use ($http, $tokenOn, $page): array {
    $token = $tokenOn($page()['body']);
    $answer = $http('POST', '/admin/settings/registrations/save', ['csrf_token' => $token] + $fields);
    $after  = $page();
    $flash  = ['type' => '', 'text' => ''];
    if (preg_match('#<div class="alert alert-(\w+)" role="status">\s*(.*?)\s*</div>#s', $after['body'], $m) === 1) {
        $flash = ['type' => $m[1], 'text' => html_entity_decode(trim($m[2]), ENT_QUOTES, 'UTF-8')];
    }
    return ['status' => $answer['status'], 'flash' => $flash, 'page' => $after['body']];
};

$startServer();
$waitForServer();

// ═════════════════════════════════════════════════════════════════════════════
// The scenarios that the plants in section P re-run. Each answers TRUE when the
// guard it is about did its job, so a plant must make it answer FALSE.
// ═════════════════════════════════════════════════════════════════════════════

/** Gate 1: under a ceiling of 30, an organisation asking for 31 is refused and nothing is stored. */
$scnGateOne = static function () use ($world, $signIn, $save, $setting, $siteOne): bool {
    $world('30', '30', null, null);
    $signIn('orgadmin');
    $r = $save(['action' => 'organisation', 'registrationDays' => '31']);
    return $setting(RR_DAYS, $siteOne) === null && $r['flash']['type'] === 'danger';
};
/** Gate 2: a ceiling of 100 is refused while organisation one keeps 400 days. */
$scnGateTwo = static function () use ($world, $signIn, $save, $setting, $eventWithPeriod): bool {
    $eventWithPeriod('', null);
    $world('90', '0', '400', null);
    $signIn('globaladmin');
    $save(['action' => 'installation', 'installationDays' => '90', 'maxDays' => '100']);
    return $setting(RR_MAX, null) === '0';
};
/** An untouched Save over a period above the ceiling passes and says nothing changed. */
$scnUntouched = static function () use ($world, $signIn, $save, $setting, $siteOne): bool {
    $world('30', '30', '400', null);
    $signIn('orgadmin');
    $r = $save(['action' => 'organisation', 'registrationDays' => '400']);
    return $setting(RR_DAYS, $siteOne) === '400' && str_contains($r['flash']['text'], 'Nothing was different') === true;
};
/** The general editor refuses to change an organisation's own period. */
$scnEditorLocked = static function () use ($world, $signIn, $http, $tokenOn, $setting, $settingId, $siteOne): bool {
    $world('90', '0', '50', null);
    $signIn('orgadmin');
    $token = $tokenOn($http('GET', '/settings')['body']);
    $http('POST', '/settings/save', ['csrf_token' => $token, 'settingID' => (string) $settingId(RR_DAYS, $siteOne), 'settingKey' => RR_DAYS, 'settingValue' => '999']);
    return $setting(RR_DAYS, $siteOne) === '50';
};
/**
 * THE LOCK. A second connection changes the ceiling to 10 and does not commit;
 * a save of 25 is started in a SECOND PROCESS and must WAIT, then judge the
 * ceiling of 10 it can now see, and refuse. Answers [blocked, refused].
 */
$scnLock = static function () use ($world, $signIn, $tokenOn, $page, $locker, $setting, $siteOne, $child, $sep, $port, $jar): array {
    $world('30', '30', '20', null);
    $signIn('orgadmin');
    $token = $tokenOn($page()['body']);
    $locker->begin_transaction();
    $locker->query("UPDATE `tblSettings` SET `settingValue` = '10' WHERE `settingKey` = 'events.registrationRetentionMaxDays' AND `siteID` IS NULL");
    $proc = proc_open(
        [PHP_BINARY, $child . $sep . 'one-post.php', 'http://127.0.0.1:' . $port . '/admin/settings/registrations/save', $jar,
            http_build_query(['csrf_token' => $token, 'action' => 'organisation', 'registrationDays' => '25'])],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']],
        $pipes
    );
    // ⚠️ A SLEEP IS A HOPE, a status is a premise: assert the worker really is
    //    still waiting, not merely that time passed (#735's lesson).
    usleep(2500000);
    $blocked = proc_get_status($proc)['running'] === true;
    $locker->commit();
    $deadline = microtime(true) + 60;
    while (proc_get_status($proc)['running'] === true && microtime(true) < $deadline) {
        usleep(100000);
    }
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($proc);
    return [$blocked, $setting(RR_DAYS, $siteOne) === '20'];
};

// ═════════════════════════════════════════════════════════════════════════════

/**
 * THE REVERSE LOCK (#816 round 1). An organisation's save is "in flight": a
 * second connection holds the ceiling's row, exactly as saveForSite() does,
 * and raises organisation one from 20 to 400 days without committing. A global
 * administrator's ceiling of 100 is posted from a SECOND PROCESS and must WAIT,
 * then see the 400 and refuse. Without `saveInstallation()`'s own lock the
 * independent check measured a ceiling of 100 stored beside 400 days.
 * Answers [blocked, refused].
 */
$scnReverseLock = static function () use ($world, $signIn, $tokenOn, $page, $locker, $setting, $siteOne, $child, $sep, $port, $jar): array {
    $world('30', '0', '20', null);
    $signIn('globaladmin');
    $token = $tokenOn($page()['body']);
    $locker->begin_transaction();
    $locker->query("SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'events.registrationRetentionMaxDays' AND `siteID` IS NULL FOR UPDATE")->fetch_all();
    $locker->query("UPDATE `tblSettings` SET `settingValue` = '400' WHERE `settingKey` = 'events.registrationRetentionDays' AND `siteID` = {$siteOne}");
    $proc = proc_open(
        [PHP_BINARY, $child . $sep . 'one-post.php', 'http://127.0.0.1:' . $port . '/admin/settings/registrations/save', $jar,
            http_build_query(['csrf_token' => $token, 'action' => 'installation', 'installationDays' => '30', 'maxDays' => '100'])],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']],
        $pipes
    );
    usleep(2500000);
    $blocked = proc_get_status($proc)['running'] === true;
    $locker->commit();
    $deadline = microtime(true) + 60;
    while (proc_get_status($proc)['running'] === true && microtime(true) < $deadline) {
        usleep(100000);
    }
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($proc);
    return [$blocked, $setting(RR_MAX, null) === '0' && $setting(RR_DAYS, $siteOne) === '400'];
};

// ── THE REAL CLEAR-OUT (#816 round 1) ───────────────────────────────────────
// The scheduled job itself, over HTTP, at `/cron/retention-sweep`, against real
// registrations — not the class on its own. Two promises are only true if the
// JOB keeps them: that a stored period is read exactly as it was before #816
// (an EMPTY value means "keep indefinitely"), and that the ceiling is NOT
// applied by the job (D-074, following D-032).
$cronToken = bin2hex(random_bytes(16));
$put('maintenance.cronToken', null, $cronToken);
/**
 * Organisation one's events that ended `$ages` days ago, each with one
 * registration and NO period of its own; run the real clear-out; answer which
 * registrations survived, keyed by age.
 */
$sweepWith = static function (?string $instStored, string $ceiling, ?string $orgStored, array $ages) use ($db, $world, $http, $cronToken, $siteOne): array {
    $db->query("DELETE `r` FROM `tblEventRegistrations` AS `r` INNER JOIN `tblEvents` AS `e` ON `e`.`eventID` = `r`.`eventID` WHERE `e`.`eventSlug` LIKE 'selftest-regret-%'");
    $db->query("DELETE FROM `tblEvents` WHERE `eventSlug` LIKE 'selftest-regret-%'");
    $world($instStored, $ceiling, $orgStored, null);
    $made = [];
    foreach ($ages as $age) {
        $end   = gmdate('Y-m-d H:i:s', time() - (int) $age * 86400);
        $start = gmdate('Y-m-d H:i:s', time() - (int) $age * 86400 - 7200);
        $slug  = 'selftest-regret-sweep-' . $age . '-' . bin2hex(random_bytes(2));
        $name  = 'Selftest Regret sweep ' . $age;
        $ev = $db->prepare("INSERT INTO `tblEvents` (`siteID`, `eventName`, `eventSlug`, `startDateTime`, `endDateTime`, `timezone`) VALUES (?, ?, ?, ?, ?, 'UTC')");
        $ev->bind_param('issss', $siteOne, $name, $slug, $start, $end);
        $ev->execute();
        $eventId = (int) $db->insert_id;
        $ev->close();
        $who = 'Child of sweep ' . $age;
        $rg  = $db->prepare('INSERT INTO `tblEventRegistrations` (`eventID`, `fullName`) VALUES (?, ?)');
        $rg->bind_param('is', $eventId, $who);
        $rg->execute();
        $made[$age] = (int) $db->insert_id;
        $rg->close();
    }
    $ran = $http('GET', '/cron/retention-sweep?token=' . $cronToken);
    if ($ran['status'] !== 200 || str_contains($ran['body'], '"status":"ok"') === false) {
        return ['the job did not run' => $ran['status'] . ' ' . substr($ran['body'], 0, 120)];
    }
    $out = [];
    foreach ($made as $age => $id) {
        $out[$age] = (int) ($db->query("SELECT COUNT(*) AS `n` FROM `tblEventRegistrations` WHERE `registrationID` = {$id}")->fetch_assoc()['n'] ?? 0) === 1;
    }
    return $out;
};
/** An EMPTY stored period keeps registrations indefinitely, as it always has. */
$scnEmptyKept   = static fn (): bool => $sweepWith('90', '0', '', [150]) === [150 => true];
/** Text that is not a number reads as 0 too — PHP's `(int)` — so it keeps them. */
$scnTextKept    = static fn (): bool => $sweepWith('90', '0', 'abc', [150]) === [150 => true];
/** The job does NOT apply the ceiling: 400 days stored under a ceiling of 30 keeps a 150-day-old registration. */
$scnJobUncapped = static fn (): bool => $sweepWith('30', '30', '400', [150, 450]) === [150 => true, 450 => false];
/** The clear-out switched off: the page, the Retention card and the job all agree. */
$scnRunOff = static function () use ($put, $sweepWith, $signIn, $page, $http): array {
    $put(RegistrationRetentionRunKey::KEY, null, 'false');
    $survived = $sweepWith('90', '0', '20', [150]);
    $signIn('globaladmin');
    $own  = $page()['body'];
    $card = $http('GET', '/admin/maintenance/retention')['body'];
    $put(RegistrationRetentionRunKey::KEY, null, 'true');
    return [
        'job keeps it'      => $survived === [150 => true],
        'page says off'     => str_contains($own, 'data-registration-clear-out-off="1"'),
        'page claims none'  => str_contains($own, 'Registrations are') === false,
        'card says off'     => str_contains($card, 'data-registration-clear-out-off="1"'),
    ];
};

/** Gate 2 going UP: 30 -> 60 is refused while an event keeps indefinitely (only SQL can put it there). */
$scnRaiseRefused = static function () use ($world, $eventWithPeriod, $signIn, $save, $setting): bool {
    $world('30', '30', null, null);
    $eventWithPeriod('Selftest Regret Forever Event', 0);
    $signIn('globaladmin');
    $r = $save(['action' => 'installation', 'installationDays' => '30', 'maxDays' => '60']);
    return $setting(RR_MAX, null) === '30' && str_contains($r['flash']['text'], 'Selftest Regret Forever Event') === true;
};
/**
 * The switch read EXACTLY: `True` and ` true` are OFF (nothing deleted), and
 * no row at all is ON (the 90-day period deletes). An "ignore case" tidy-up
 * would start deleting the first two organisations' registrations the next
 * night, so each is pinned by the real job rather than by a comment.
 */
$scnRunExact = static function () use ($put, $sweepWith, $siteOne): array {
    $out = [];
    foreach (['True' => 'True', 'space' => ' true'] as $label => $stored) {
        $put(RegistrationRetentionRunKey::KEY, null, $stored);
        $out[$label] = $sweepWith('90', '0', null, [150]) === [150 => true];
    }
    $put(RegistrationRetentionRunKey::KEY, null, null);
    $put(RegistrationRetentionRunKey::KEY, $siteOne, null);
    $out['no row'] = $sweepWith('90', '0', null, [150]) === [150 => false];
    $put(RegistrationRetentionRunKey::KEY, null, 'true');
    return $out;
};

echo "A. With no ceiling, nothing changes\n";
$eventWithPeriod('', null);
$world('90', '0', null, null);
$signIn('orgadmin');
$p = $page();
check('A1 the page answers 200', $p['status'], 200);
check('A2 ...and finished drawing (a page that throws part-way still answers 200)', str_contains($p['body'], '</html>'), true);
check('A3 an organisation\'s administrator is NOT shown the installation\'s card', str_contains($p['body'], 'data-registration-installation-card'), false);
check('A4 ...and the box is EMPTY, because this organisation has chosen nothing (#674)',
    preg_match('/id="registrationDays" name="registrationDays"\s+value=""/', $p['body']), 1);
$save(['action' => 'organisation', 'registrationDays' => '400']);
check('A5 400 days is stored as the organisation\'s own row', $setting(RR_DAYS, $siteOne), '400');
$save(['action' => 'organisation', 'registrationDays' => '0']);
check('A6 "keep indefinitely" (0) is stored, as it always could be with no ceiling', $setting(RR_DAYS, $siteOne), '0');
$r = $save(['action' => 'organisation', 'registrationDays' => '']);
check('A7 an EMPTY box removes the own row: the organisation follows the installation', $setting(RR_DAYS, $siteOne), null);
check('A8 ...and the page says so', str_contains($r['flash']['text'], 'follows the period this installation sets'), true);
$before = $activityCount('RegistrationRetentionRefused');
$r = $save(['action' => 'installation', 'installationDays' => '10', 'maxDays' => '10']);
check('A9 an organisation\'s administrator posting the installation\'s form changes NEITHER row',
    [$setting(RR_DAYS, null), $setting(RR_MAX, null)], ['90', '0']);
check('A10 ...and the refusal is RECORDED', $activityCount('RegistrationRetentionRefused'), $before + 1);
$r = $http('POST', '/admin/settings/registrations/save', ['csrf_token' => 'not-the-token', 'action' => 'organisation', 'registrationDays' => '7']);
check('A11 a post with a wrong form token stores nothing', $setting(RR_DAYS, $siteOne), null);

echo "B. The general settings editor refuses both keys, for everybody\n";
// 🎯 THE PREMISE FIRST. A first version of this section posted without the
//    `settingKey` field, which the editor demands before anything else — so
//    every "refused" check below passed on a request refused for a DIFFERENT
//    reason, and the plant in P4 stayed green. This edits an ordinary
//    organisation row through the SAME request shape and requires it to land.
$put('selftest.regret.ordinary', $siteOne, 'before');
$signIn('orgadmin');
$token = $tokenOn($http('GET', '/settings')['body']);
$http('POST', '/settings/save', ['csrf_token' => $token, 'settingID' => (string) $settingId('selftest.regret.ordinary', $siteOne),
    'settingKey' => 'selftest.regret.ordinary', 'settingValue' => 'after']);
check('B0 PREMISE: the same request shape DOES change an ordinary organisation row', $setting('selftest.regret.ordinary', $siteOne), 'after');
$put('selftest.regret.ordinary', $siteOne, null);
check('B1 an organisation\'s administrator cannot change the organisation\'s own period there', $scnEditorLocked(), true);
check('B2 ...and that refusal is recorded', str_contains($lastActivity('SettingsUpdateRefused'), 'event registrations are kept'), true);
$signIn('globaladmin');
$token = $tokenOn($http('GET', '/settings')['body']);
$http('POST', '/settings/save', ['csrf_token' => $token, 'settingID' => (string) $settingId(RR_MAX, null), 'settingKey' => RR_MAX, 'settingValue' => '5']);
check('B3 a GLOBAL administrator cannot change the ceiling there', $setting(RR_MAX, null), '0');
$token = $tokenOn($http('GET', '/settings')['body']);
$http('POST', '/settings/save', ['csrf_token' => $token, 'settingID' => (string) $settingId(RR_DAYS, null), 'settingKey' => RR_DAYS, 'settingValue' => '5000']);
check('B4 ...nor the installation\'s period', $setting(RR_DAYS, null), '90');
$token = $tokenOn($http('GET', '/settings')['body']);
$http('POST', '/settings/save', ['csrf_token' => $token, 'settingID' => '0', 'settingKey' => RR_MAX, 'settingValue' => '5']);
// ⚠️ B5 CANNOT TELL WHICH REFUSAL FIRED, said rather than implied: the editor
//    also refuses to add a row whose name the installation already holds, and
//    both keys are seeded installation-wide, so this passes with the add-path
//    lock removed too. The lock is there for the day that duplicate rule
//    changes; it is not what this check proves.
check('B5 ...nor ADD an organisation row of the ceiling there (by either of two refusals)', $setting(RR_MAX, $siteOne), null);
$token = $tokenOn($http('GET', '/settings')['body']);
$http('POST', '/settings', ['csrf_token' => $token, 'action' => 'delete', 'settingID' => (string) $settingId(RR_MAX, null)]);
check('B6 ...nor DELETE the ceiling\'s row there (that would remove the ceiling unchecked)', $setting(RR_MAX, null), '0');
$token = $tokenOn($http('GET', '/settings')['body']);
$http('POST', '/settings', ['csrf_token' => $token, 'action' => 'delete', 'settingID' => (string) $settingId(RR_DAYS, null)]);
check('B7 ...nor DELETE the installation\'s period there', $setting(RR_DAYS, null), '90');
$listing = $http('GET', '/settings')['body'];
check('B8 the editor points at the page that does write them', str_contains($listing, 'Changed at Admin, Registration retention'), true);

echo "C. Gate 2: a ceiling below a period somebody holds is refused, and they are named\n";
$eventId = $eventWithPeriod('Selftest Regret Summer Camp', 500);
$world('90', '0', '400', '0');
$signIn('globaladmin');
$p = $page();
check('C1 a global administrator IS shown the installation\'s card', str_contains($p['body'], 'data-registration-installation-card'), true);
check('C2 ...whose ceiling box is EMPTY, because there is no ceiling (read live, not merged)',
    preg_match('/id="maxDays" name="maxDays"\s+value=""/', $p['body']), 1);
$before = $activityCount('RegistrationRetentionRefused');
$r = $save(['action' => 'installation', 'installationDays' => '90', 'maxDays' => '30']);
check('C3 a ceiling of 30 is REFUSED, and the ceiling stays off', $setting(RR_MAX, null), '0');
check('C4 ...the refusal names organisation one and its 400 days', str_contains($r['flash']['text'], $siteOneName . ' (400 days)'), true);
check('C5 ...and organisation two, which keeps indefinitely (0 is the LONGEST period)',
    str_contains($r['flash']['text'], 'Selftest Regret Two (kept indefinitely)'), true);
check('C6 ...and the event with a period of its own', str_contains($r['flash']['text'], 'Selftest Regret Summer Camp'), true);
check('C7 ...and the installation\'s own 90 days', str_contains($r['flash']['text'], 'this installation\'s own period (90 days)'), true);
check('C8 ...and it is shown as a refusal', $r['flash']['type'], 'danger');
check('C9 the refusal is RECORDED', $activityCount('RegistrationRetentionRefused'), $before + 1);
$logged = $lastActivity('RegistrationRetentionRefused');
check('C10 ...with COUNTS, and no organisation\'s name (the log is read later, by others)',
    str_contains($logged, '2 organisation(s)') && str_contains($logged, '1 event(s)') && str_contains($logged, 'Selftest Regret Two') === false, true);
$r = $save(['action' => 'installation', 'installationDays' => '90', 'maxDays' => '']);
check('C11 an untouched Save of the installation\'s form passes and changes nothing',
    [$setting(RR_DAYS, null), $setting(RR_MAX, null), str_contains($r['flash']['text'], 'Nothing was different')], ['90', '0', true]);
check('C12 (scenario) gate 2 refuses a ceiling below one organisation\'s period', $scnGateTwo(), true);
$world('90', '0', null, null);
$eventWithPeriod('Selftest Regret Only Event', 0);
$save(['action' => 'installation', 'installationDays' => '90', 'maxDays' => '120']);
check('C13 an EVENT\'s own "indefinitely" alone blocks a ceiling', $setting(RR_MAX, null), '0');

echo "D. The ceiling is set, and the installation's own period is held to it\n";
$eventWithPeriod('', null);
$world('90', '0', null, null);
$r = $save(['action' => 'installation', 'installationDays' => '90', 'maxDays' => '30']);
check('D1 with only the installation\'s 90 days in the way, the ceiling is still refused', $setting(RR_MAX, null), '0');
check('D2 ...and only that is named', str_contains($r['flash']['text'], 'this installation\'s own period (90 days)')
    && str_contains($r['flash']['text'], 'Selftest Regret Two') === false, true);
$before = $activityCount('RegistrationRetentionSaved');
$r = $save(['action' => 'installation', 'installationDays' => '30', 'maxDays' => '30']);
check('D3 lowering the period and setting the ceiling in ONE save works', [$setting(RR_DAYS, null), $setting(RR_MAX, null)], ['30', '30']);
check('D4 ...and is recorded', $activityCount('RegistrationRetentionSaved'), $before + 1);
$save(['action' => 'installation', 'installationDays' => '60', 'maxDays' => '30']);
check('D5 the installation\'s period above the ceiling is refused', $setting(RR_DAYS, null), '30');
$save(['action' => 'installation', 'installationDays' => '0', 'maxDays' => '30']);
check('D6 ...and so is "indefinitely" while a ceiling is in force', $setting(RR_DAYS, null), '30');
$save(['action' => 'installation', 'installationDays' => '30']);
check('D7 a post with NO ceiling field leaves the ceiling alone (#677)', $setting(RR_MAX, null), '30');

echo "E. Gate 1: an organisation's period above the ceiling is refused\n";
$signIn('orgadmin');
$p = $page();
check('E1 the organisation\'s page says a ceiling of 30 days is in force', str_contains($p['body'], 'A ceiling of <strong>30 days</strong> is in force'), true);
check('E2 (scenario) 31 days is REFUSED and nothing is stored', $scnGateOne(), true);
$before = $activityCount('RegistrationRetentionRefused');
$r = $save(['action' => 'organisation', 'registrationDays' => '31']);
check('E3 ...with the reason', str_contains($r['flash']['text'], 'allows at most 30 days'), true);
check('E4 ...never saying WHO set it (the levels may be different parties)',
    str_contains(strtolower($r['flash']['text']), 'global administrator'), false);
check('E5 ...and recorded', $activityCount('RegistrationRetentionRefused'), $before + 1);
$r = $save(['action' => 'organisation', 'registrationDays' => '0']);
check('E6 "keep indefinitely" is refused under a ceiling', [$setting(RR_DAYS, $siteOne), $r['flash']['type']], [null, 'danger']);
$save(['action' => 'organisation', 'registrationDays' => '25']);
check('E7 25 days, within the ceiling, is stored AS TYPED — refused, never clamped', $setting(RR_DAYS, $siteOne), '25');
$r = $save(['action' => 'organisation', 'registrationDays' => '999999']);
check('E8 a number past the hard maximum is refused in its own words', [$setting(RR_DAYS, $siteOne), str_contains($r['flash']['text'], 'at most 36500 days')], ['25', true]);
$r = $save(['action' => 'organisation', 'registrationDays' => 'ten']);
check('E9 text that is not a number is refused', [$setting(RR_DAYS, $siteOne), $r['flash']['type']], ['25', 'danger']);
$token = $tokenOn($page()['body']);
$http('POST', '/admin/settings/registrations/save', ['csrf_token' => $token, 'action' => 'organisation']);
check('E10 a post with NO period field changes nothing (#677)', $setting(RR_DAYS, $siteOne), '25');

echo "F. An untouched Save passes over a period above the ceiling\n";
$world('30', '30', '400', null);
$p = $page();
check('F1 the page shows the stored 400, not a number it was altered to', preg_match('/name="registrationDays"\s+value="400"/', $p['body']), 1);
check('F2 ...in a box whose max allows it, so the browser will submit it (#668)',
    preg_match('/max="(\d+)"[^>]*id="registrationDays"/', $p['body'], $mx) === 1 && (int) $mx[1] >= 400, true);
check('F3 ...and says the longer period is kept rather than cut short', str_contains($p['body'], 'data-registration-above-ceiling="1"'), true);
check('F4 (scenario) an untouched Save of 400 passes and stores 400', $scnUntouched(), true);
$save(['action' => 'organisation', 'registrationDays' => '300']);
check('F5 CHANGING it to another number above the ceiling is still refused', $setting(RR_DAYS, $siteOne), '400');

echo "G. The events API does not write an event's own period\n";
$created = $http('POST', '/api/events/create', (string) json_encode([
    'eventName' => 'Selftest Regret API', 'startDateTime' => '2027-03-01T10:00:00', 'endDateTime' => '2027-03-01T11:00:00',
    'status' => 'published', 'registrationRetentionDays' => 5000,
]), ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json', 'Accept: application/json']);
$apiEvent = $db->query("SELECT `eventID`, `registrationRetentionDays` AS `d` FROM `tblEvents` WHERE `eventName` = 'Selftest Regret API' ORDER BY `eventID` DESC LIMIT 1")->fetch_assoc();
check('G1 PREMISE: the API really created the event', $apiEvent !== null, true);
// ⚠️ `array_key_exists`, never `??`: the value being checked IS null, and
//    `null ?? 'no row'` answers 'no row' — a first version failed on exactly that.
check('G2 ...and ignored the period it was sent', is_array($apiEvent) === true && array_key_exists('d', $apiEvent) === true ? $apiEvent['d'] : 'no row', null);
$http('POST', '/api/events/update?id=' . (int) ($apiEvent['eventID'] ?? 0), (string) json_encode([
    'eventID' => (int) ($apiEvent['eventID'] ?? 0), 'registrationRetentionDays' => 5000, 'eventName' => 'Selftest Regret API',
]), ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json', 'Accept: application/json']);
$after = $db->query('SELECT `registrationRetentionDays` AS `d` FROM `tblEvents` WHERE `eventID` = ' . (int) ($apiEvent['eventID'] ?? 0))->fetch_assoc();
check('G3 ...and so did an update', is_array($after) === true && array_key_exists('d', $after) === true ? $after['d'] : 'no row', null);
$db->query("DELETE FROM `tblEvents` WHERE `eventName` = 'Selftest Regret API'");

echo "H. Somebody who is not an administrator is refused\n";
$signIn('member');
check('H1 the page answers 403 to a member', $page()['status'], 403);
$http('POST', '/admin/settings/registrations/save', ['csrf_token' => $tokenOn($http('GET', '/account')['body']), 'action' => 'organisation', 'registrationDays' => '7']);
check('H2 ...and the handler stores nothing for one', $setting(RR_DAYS, $siteOne), '400');

echo "I. The lock: a save waits for a ceiling being changed, then judges it\n";
[$blocked, $refused] = $scnLock();
check('I1 the save in the second process was really WAITING (not merely slow)', $blocked, true);
check('I2 ...and, once the ceiling of 10 was committed, it refused 25 days', $refused, true);
check('I3 ...leaving the ceiling the other transaction set', $setting(RR_MAX, null), '10');

// ═════════════════════════════════════════════════════════════════════════════
echo "J. The REAL clear-out reads a stored period exactly as before #816, and does not apply the ceiling\n";
check('J1 an EMPTY stored period keeps a 150-day-old registration (empty has always meant "indefinitely")', $scnEmptyKept(), true);
check('J2 ...and so does text that is not a number', $scnTextKept(), true);
check('J3 a NEGATIVE period reads as 90 days: 150 days old goes, 60 days old stays',
    $sweepWith('90', '0', '-5', [150, 60]), [150 => false, 60 => true]);
check('J4 no organisation row follows the installation\'s 90 days', $sweepWith('90', '0', null, [150, 60]), [150 => false, 60 => true]);
check('J5 no row at ALL is 90 days', $sweepWith(null, '0', null, [150, 60]), [150 => false, 60 => true]);
check('J6 400 days stored under a ceiling of 30 (only SQL or a restore can do that) is OBEYED: 150 days old stays, 450 goes',
    $scnJobUncapped(), true);
check('J7 "indefinitely" stored under a ceiling of 30 keeps them too', $sweepWith('30', '30', '0', [150]), [150 => true]);
$world('90', '0', null, null);

echo "K. With the clear-out switched off, the job, the page and the Retention card all say so\n";
check('K1 switched off: the job, the page and the card agree', $scnRunOff(),
    ['job keeps it' => true, 'page says off' => true, 'page claims none' => true, 'card says off' => true]);
check('K2 the switch is read EXACTLY: "True" and " true" are OFF, and no row at all is ON', $scnRunExact(),
    ['True' => true, 'space' => true, 'no row' => true]);
$signIn('orgadmin');
check('K3 switched back on, the page no longer says it is off', str_contains($page()['body'], 'data-registration-clear-out-off'), false);

echo "R. The reverse lock: a ceiling save waits for an organisation's save in flight\n";
[$rBlocked, $rRefused] = $scnReverseLock();
check('R1 the ceiling save in the second process was really WAITING', $rBlocked, true);
check('R2 ...and, once the 400 days was committed, it refused a ceiling of 100', $rRefused, true);

echo "S. A ceiling below a held period is refused going UP as well as down; removing it is always allowed\n";
check('S1 (scenario) RAISING 30 -> 60 is refused while an event (by SQL) keeps indefinitely, as the giving ceiling refuses it',
    $scnRaiseRefused(), true);
$save(['action' => 'installation', 'installationDays' => '30', 'maxDays' => '20']);
check('S2 ...and so is LOWERING it', $setting(RR_MAX, null), '30');
$save(['action' => 'installation', 'installationDays' => '30', 'maxDays' => '']);
check('S3 REMOVING the ceiling is allowed, which is the way out of that state', $setting(RR_MAX, null), '0');
$eventWithPeriod('', null);

echo "P. The plants: each guard taken out of a COPY, and its scenario goes red\n";
$plant = static function (string $relPath, string $from, string $to, callable $scenario) use ($child, $sep, $startServer, $waitForServer): array {
    $path   = $child . $sep . 'web' . $sep . str_replace('/', $sep, $relPath);
    $before = (string) file_get_contents($path);
    $count  = substr_count($before, $from);
    if ($count !== 1) {
        return ['applied' => false, 'result' => null];
    }
    file_put_contents($path, str_replace($from, $to, $before));
    $startServer();
    $waitForServer();
    $result = $scenario();
    file_put_contents($path, $before);
    $startServer();
    $waitForServer();
    return ['applied' => true, 'result' => $result];
};

$p1 = $plant('_core/RegistrationRetention.php', '$reason = self::whyDaysNotAllowed($days, $ceilingStored);', '$reason = \'\';', $scnGateOne);
check('P1 with gate 1 removed, the plant applied AND the 31-days scenario goes red', [$p1['applied'], $p1['result']], [true, false]);
$p2 = $plant('_core/RegistrationRetention.php', '$above    = self::periodsAboveCeiling($db, $newCeiling);',
    '$above    = [\'organisations\' => [], \'events\' => [], \'eventCount\' => 0];', $scnGateTwo);
check('P2 with gate 2 removed, the ceiling-below-a-period scenario goes red', [$p2['applied'], $p2['result']], [true, false]);
$p3 = $plant('_core/RegistrationRetention.php', 'if ($days === $currentDays) {', 'if (false) {', $scnUntouched);
check('P3 with "judge only what changed" removed, the untouched-Save scenario goes red', [$p3['applied'], $p3['result']], [true, false]);
$p4 = $plant('_apps/settings/save.php', 'if (\Portal\Core\RegistrationRetention::lockedInGeneralEditor($storedKey) === true) {', 'if (false) {', $scnEditorLocked);
check('P4 with the general editor\'s refusal removed, the editor scenario goes red', [$p4['applied'], $p4['result']], [true, false]);
$p5 = $plant('_core/RegistrationRetention.php', ". (\$lock === true ? ' FOR UPDATE' : '')", ". ''", $scnLock);
check('P5 with the lock removed, the save no longer waits', [$p5['applied'], $p5['result'][0] ?? null], [true, false]);
check('P6 ...and stores 25 days under a ceiling of 10 — the fault the lock exists to stop',
    $p5['result'][1] ?? null, false);
$p7 = $plant('_apps/settings/index.php',
    "if (\$lockedKey !== null && \\Portal\\Core\\RegistrationRetention::lockedInGeneralEditor((string) \$lockedKey['settingKey']) === true) {",
    'if (false) {',
    static function () use ($world, $signIn, $http, $tokenOn, $settingId, $setting): bool {
        $world('90', '0', null, null);
        $signIn('globaladmin');
        $token = $tokenOn($http('GET', '/settings')['body']);
        $http('POST', '/settings', ['csrf_token' => $token, 'action' => 'delete', 'settingID' => (string) $settingId(RR_MAX, null)]);
        return $setting(RR_MAX, null) === '0';
    });
check('P7 with the delete refusal removed, a global administrator CAN delete the ceiling\'s row', [$p7['applied'], $p7['result']], [true, false]);
$pa = $plant('_core/RegistrationRetention.php', '$days = (int) (string) $stored;',
    '$days = trim((string) $stored) === \'\' ? self::DEFAULT_DAYS : (int) (string) $stored;', $scnEmptyKept);
check('Pa with an EMPTY value read as 90 days (a "tidy-up"), the real clear-out deletes what it kept', [$pa['applied'], $pa['result']], [true, false]);
$pb = $plant('_core/RegistrationRetention.php', '$days = (int) (string) $stored;',
    '$days = is_numeric(trim((string) $stored)) === true ? (int) (string) $stored : self::DEFAULT_DAYS;', $scnTextKept);
check('Pb with non-numeric text read as 90 days, the same', [$pb['applied'], $pb['result']], [true, false]);
$pc = $plant('_apps/cron/_retention-sweep.php', '$out[$siteId] = RegistrationRetention::daysForSite($siteId);',
    '$out[$siteId] = (static function (int $d): int { $c = RegistrationRetention::ceilingNow(App::db()); '
    . 'return ($c > 0 && ($d <= 0 || $d > $c)) ? $c : $d; })(RegistrationRetention::daysForSite($siteId));', $scnJobUncapped);
check('Pc with the JOB applying the ceiling (the dangerous direction), the 150-day registration is deleted', [$pc['applied'], $pc['result']], [true, false]);
$pd = $plant('_core/RegistrationRetention.php', '$ceilingStored  = self::storedValue($db, self::KEY_MAX_DAYS, null, true);',
    '$ceilingStored  = self::storedValue($db, self::KEY_MAX_DAYS, null, false);', $scnReverseLock);
check('Pd with saveInstallation()\'s lock alone removed, a ceiling of 100 is stored beside 400 days',
    [$pd['applied'], $pd['result'][1] ?? null], [true, false]);
$pe = $plant('_core/RegistrationRetention.php', "return (string) (App::settingForSite(self::KEY_RUN, \$siteId) ?? 'true') === 'true';",
    'return true;', $scnRunOff);
check('Pe with the switch ignored, the job deletes and the page claims a period',
    [$pe['applied'], ($pe['result']['job keeps it'] ?? null), ($pe['result']['page says off'] ?? null)], [true, false, false]);
$pf = $plant('_core/RegistrationRetention.php', 'if ($ceilingChanged === true && $newCeiling !== self::NO_CAP) {',
    'if ($ceilingChanged === true && $newCeiling !== self::NO_CAP && ($currentCeiling === self::NO_CAP || $newCeiling <= $currentCeiling)) {',
    $scnRaiseRefused);
check('Pf with a "raising is fine" exception put back, the raise is let through', [$pf['applied'], $pf['result']], [true, false]);
$pg = $plant('_core/RegistrationRetention.php', "return (string) (App::settingForSite(self::KEY_RUN, \$siteId) ?? 'true') === 'true';",
    "return strtolower(trim((string) (App::settingForSite(self::KEY_RUN, \$siteId) ?? 'true'))) === 'true';", $scnRunExact);
check('Pg with the switch read ignoring case and spaces, "True" and " true" start DELETING',
    [$pg['applied'], $pg['result']['True'] ?? null, $pg['result']['space'] ?? null], [true, false, false]);
$ph = $plant('_core/RegistrationRetention.php', "return (string) (App::settingForSite(self::KEY_RUN, \$siteId) ?? 'true') === 'true';",
    "return (string) (App::settingForSite(self::KEY_RUN, \$siteId) ?? 'false') === 'true';", $scnRunExact);
check('Ph with no row read as OFF, the job stops deleting', [$ph['applied'], $ph['result']['no row'] ?? null], [true, false]);
check('P8 with every plant removed again, gate 1 is green once more', $scnGateOne(), true);

// 🧾 The server's own error log. A page that throws part-way can still answer
//    200, so the log is read as well: anything naming this package's files,
//    or any fatal error at all, fails the run.
$serverLog = (string) @file_get_contents($child . $sep . 'php-errors.log');
$ours      = [];
foreach (preg_split('/\R/', $serverLog) ?: [] as $line) {
    if (stripos($line, 'Fatal') !== false || stripos($line, 'RegistrationRetention') !== false
        || stripos($line, 'settings/registrations') !== false || stripos($line, 'Uncaught') !== false) {
        $ours[] = $line;
    }
}
check('Z1 the server logged no fatal error and nothing about this package', $ours, []);

// ── Tidy up the rows this test made ─────────────────────────────────────────
$eventWithPeriod('', null);
$world('90', '0', null, null);
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
echo "\n{$total} checks: {$passed} passed, {$failed} failed (expected " . RR_EXPECTED_CHECKS . ").\n";
echo "It drives the REAL pages over HTTP and reads every result back from the database.\n";
echo "Section I proves ONE pair of overlapping saves is serialised; it runs one engine.\n";
exit($failed === 0 && $total === RR_EXPECTED_CHECKS ? 0 : 1);
