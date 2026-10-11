<?php
// Path: tools/access-pages-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Switched-off apps and own expense claims, on the REAL pages — self-test 🚪💷
 * (#478, #570, #545)
 * -----------------------------------------------------------------------------
 * `tools/app-switch-selftest.php` and `tools/expense-rules-selftest.php`
 * prove the rules and read the code as text. Reading text cannot see a
 * condition somebody later wraps round a call: with the words
 * `$isAdmin === false &&` put in front of the own-claim test, or the app
 * gate skipped whenever a request carried an API key, both of those files
 * still passed (found by the independent check of 11 October 2026). This
 * file is what fails then. It runs the portal and asks it.
 *
 * It is a trimmed copy of the page proofs written while roll-out package B
 * was built, kept as a test so the proof can be run again.
 *
 * WHAT IT DOES
 * ------------
 * It copies `web/` into a NEW scratch folder, gives the copy a throwaway
 * connection file, starts PHP's built-in web server on it behind a small
 * router that imitates `.htaccess`, signs real accounts in through the real
 * sign-in page, and sends real requests. Then it checks:
 *
 *   A. #478, one row per `api` folder: with ONLY that folder's app switched
 *      off, one of its endpoints answers 403 naming THAT app; with every app
 *      on, none is refused as switched off. The app each folder belongs to
 *      is written out by hand here, so a folder tied to the wrong app fails.
 *   B. #478, API keys: a key is judged by its OWN organisation's switch,
 *      whatever address it is sent to; a made-up key is never a way round.
 *   C. #478, a folder with no line in `AppRegistry::API_FOLDER_APPS` is
 *      refused and its handler never runs (the folder is made in the scratch
 *      copy only), while the same handler in a listed folder does run.
 *   D. #478 and #570: a switched-off app's stray pages show the "disabled"
 *      page, which names the right app and keeps its links inside the
 *      organisation's address; `/unsubscribe` answers whatever the
 *      Newsletter switch says.
 *   E. #545, through the real handlers: nobody approves, rejects or pays
 *      their own claim — a lead, a treasurer and an ADMINISTRATOR are each
 *      tried — and nothing is recorded; somebody else can; a claim with a
 *      required approver still needs them; the claim's own page tells such
 *      a claimant who decides it.
 *
 * SAFETY
 * ------
 * - It writes rows, so it REFUSES unless the database's name starts with
 *   `selftest_`. Every row it makes is numbered 916000 to 916999 or belongs
 *   to one of its two made-up organisations; they are removed at the START
 *   (in case an earlier run was interrupted) and at the END.
 * - It sets two portal-wide settings for the run (`multisite.enabled`,
 *   `multisite.detectionMode`), so the two organisations are told apart by
 *   the first part of the address, and puts them back. So do NOT run it at
 *   the same moment as `tools/feed-admin-selftest.php` on the same database:
 *   that test changes the same two settings.
 * - The scratch folder is a NEW child of `SELFTEST_WEB_DIR` (or the system's
 *   temporary folder). It refuses if that folder contains the repository or
 *   is inside it. The child is deleted at the end only if it still holds
 *   this run's marker. `web/_auth_keys` is never read or copied.
 * - The server is started with its command as a list (no shell), so the
 *   process number is the server's own, and it is stopped by that number,
 *   never by a name. It refuses to start if anything already answers on its
 *   port. Every answer must carry this run's header.
 * - The clean-up also runs from a shutdown function, so a crash part way
 *   still puts things back. WHAT IT CANNOT SURVIVE: being killed outright.
 *
 * WHAT THIS CANNOT PROVE
 * ----------------------
 * - Apache: the router only imitates `.htaccess`.
 * - Organisations told apart any other way than the first part of the
 *   address.
 * - MariaDB and MySQL 8.4: it has been run on MySQL 8.0.36 only.
 * - An API key whose organisation has NO row for an app's switch, anywhere:
 *   that needs a portal-wide row deleted, so it is checked instead inside a
 *   rolled-back transaction by `tools/app-switch-selftest.php` (part 9).
 * - That mail is sent, or what the dashboard shows.
 * - Two people deciding at the same moment.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             SELFTEST_WEB_DIR=/a/quiet/folder php tools/access-pages-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default
 *         root), SELFTEST_WEB_PORT (default 9068). The database must already
 *         hold `web/_sql/full_schema.sql`. Needs PHP's curl extension and
 *         `proc_open()`.
 * Exit:   0 only when every check ran and passed; 1 otherwise, including
 *         every refusal to run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/478
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/545
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/570
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 94;

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows");
}
foreach (['curl_init', 'proc_open'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this PHP has no {$needed}()");
    }
}
$repo      = (string) realpath(dirname(__DIR__));
$webSrc    = $repo . DIRECTORY_SEPARATOR . 'web';
$parentRaw = getenv('SELFTEST_WEB_DIR');
$parent    = realpath(($parentRaw === false || $parentRaw === '') ? sys_get_temp_dir() : (string) $parentRaw);
if ($parent === false || is_dir($parent) === false) {
    refuse('the scratch parent folder (SELFTEST_WEB_DIR) does not exist');
}
if (str_starts_with($repo . DIRECTORY_SEPARATOR, $parent . DIRECTORY_SEPARATOR) === true
    || str_starts_with($parent . DIRECTORY_SEPARATOR, $repo . DIRECTORY_SEPARATOR) === true) {
    refuse("the scratch parent folder '{$parent}' and the repository must not contain each other; choose another with SELFTEST_WEB_DIR");
}
$portRaw = getenv('SELFTEST_WEB_PORT');
$port    = ($portRaw === false || $portRaw === '') ? 9068 : (int) $portRaw;
if ($port < 1024 || $port > 65535) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65535');
}

// The app list, read from the real files: used only for each app's display
// name and setting key. WHICH app a folder belongs to is written out by hand
// further down, never taken from the class being tested.
define('PORTAL_CORE', $webSrc . DIRECTORY_SEPARATOR . '_core');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'App.php';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'AppRegistry.php';
$apps = Portal\Core\AppRegistry::all();

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
$db = new mysqli(
    getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    $dbName,
    getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
);
$db->set_charset('utf8mb4');

$pass = 0;
$fail = 0;

/** ✅ Report one check. `$detail` is shown only on failure. */
function ok(string $label, bool $passed, string $detail = ''): void
{
    global $pass, $fail;
    if ($passed === true) {
        $pass++;
        echo 'PASS — ' . $label . "\n";

        return;
    }
    $fail++;
    echo 'FAIL — ' . $label . ($detail === '' ? '' : "\n        " . $detail) . "\n";
}

function head(string $text): void
{
    echo "\n=== " . $text . " ===\n";
}

/** @param list<mixed> $params @return list<array<string,mixed>> */
function q(string $sql, string $types = '', array $params = []): array
{
    global $db;
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows   = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/** @param list<mixed> $params */
function one(string $sql, string $types = '', array $params = []): ?array
{
    return q($sql, $types, $params)[0] ?? null;
}

// =============================================================================
// 🗺️ The made-up world
// =============================================================================
const ORG_A  = 916001;
const ORG_B  = 916002;
const ADDR_A = '/selftest-access-a';
const ADDR_B = '/selftest-access-b';
const LEAD = 916011; // member of A; the ONLY lead of department DEPT; also a treasurer of A
const APPR = 916012; // member of A; a plain approver of DEPT
const ADM  = 916013; // a site administrator of A
const TRS  = 916014; // member of A; a treasurer of A
const MEM  = 916015; // member of A; in DEPT with no flag
const DEPT = 916101;
/** The made-up folder and handler part C plants in the scratch copy. */
const UNLISTED_FOLDER = 'zz-selftest-unlisted';
const PLANTED_ACTION  = 'zz-selftest-ping';
const PLANTED_MARK    = 'ZZ-SELFTEST-HANDLER-RAN';

function removeWorld(): void
{
    q('DELETE p FROM tblExpenseClaimPayments p JOIN tblExpenseClaims c ON c.claimID = p.claimID WHERE c.siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE a FROM tblExpenseClaimApprovals a JOIN tblExpenseClaims c ON c.claimID = a.claimID WHERE c.siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblExpenseClaims WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblApiKeys WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblUserRoles WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblRoles WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblUserDepts WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblDepts WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblActivityLogs WHERE userID BETWEEN 916000 AND 916999 OR siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblErrors WHERE userID BETWEEN 916000 AND 916999 OR siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblUserSites WHERE siteID IN (?, ?) OR userID BETWEEN 916000 AND 916999', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblUsers WHERE userID BETWEEN 916000 AND 916999');
    q('DELETE FROM tblSettings WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    // The two per-endpoint switches part C adds for its planted handler.
    q("DELETE FROM tblSettings WHERE siteID IS NULL AND settingKey IN ('api." . UNLISTED_FOLDER . '.' . PLANTED_ACTION . ".enabled', 'api.tours." . PLANTED_ACTION . ".enabled')");
    q('DELETE FROM tblSites WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
}

/** One organisation's own value for a setting (replacing any it had). */
function siteSetting(int $site, string $key, string $value): void
{
    q('DELETE FROM tblSettings WHERE siteID = ? AND settingKey = ?', 'is', [$site, $key]);
    q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, ?, ?, ?, 0)', 'isss', [$site, $key, $value, $value]);
}

function claim(int $id, int $by, string $title): void
{
    q(
        "INSERT INTO tblExpenseClaims (claimID, siteID, userID, deptID, claimTitle, claimDate, totalAmount, status) VALUES (?, ?, ?, ?, ?, CURDATE(), 12.50, 'Pending')",
        'iiiis', [$id, ORG_A, $by, DEPT, $title]
    );
}

/** A claim's status and how many approvals and payments are recorded against it. */
function claimState(int $id): string
{
    $row = one(
        'SELECT c.status, (SELECT COUNT(*) FROM tblExpenseClaimApprovals a WHERE a.claimID = c.claimID) AS approvals, '
        . '(SELECT COUNT(*) FROM tblExpenseClaimPayments p WHERE p.claimID = c.claimID) AS payments FROM tblExpenseClaims c WHERE c.claimID = ?',
        'i', [$id]
    );

    return $row === null ? 'missing' : $row['status'] . ', ' . $row['approvals'] . ' approval(s), ' . $row['payments'] . ' payment(s)';
}

// =============================================================================
// 🧰 The server, and one request
// =============================================================================
$state = ['proc' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'settings' => [], 'started' => false, 'cleaned' => false];

function portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);

        return true;
    }

    return false;
}

/** Put back everything this run changed, ONCE. Also registered as a shutdown function. */
function cleanup(): void
{
    global $state;
    if ($state['started'] === false || $state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
    // The server, by the process proc_open() gave — never by a name.
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        $until = microtime(true) + 5;
        while (proc_get_status($state['proc'])['running'] === true && microtime(true) < $until) {
            usleep(50000);
        }
        if (proc_get_status($state['proc'])['running'] === true) {
            proc_terminate($state['proc'], 9);
        }
        proc_close($state['proc']);
        $state['proc'] = null;
    }
    try {
        foreach ($state['settings'] as $key => $before) {
            if ($before === null) {
                q('DELETE FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [(string) $key]);
            } else {
                q('UPDATE tblSettings SET settingValue = ? WHERE settingID = ?', 'si', [$before['settingValue'], (int) $before['settingID']]);
            }
        }
        removeWorld();
    } catch (Throwable $e) {
        echo 'FAIL — the clean-up could not put the database back: ' . $e->getMessage() . "\n";
    }
    // The scratch child: only the one this run made, and only while it still holds this run's marker.
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.access-pages-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}

/**
 * One real HTTP request to the server this run started. Redirects are never
 * followed. An answer without this run's header came from somewhere else
 * and is returned as status -1, which no check expects.
 *
 * @param array<string,mixed>|null $post
 *
 * @return array{status:int, location:string, body:string}
 */
function req(string $jar, string $method, string $path, ?array $post = null, string $bearer = ''): array
{
    global $port, $state;
    $handle  = curl_init('http://127.0.0.1:' . $port . $path);
    $headers = ['Expect:'];
    if ($bearer !== '') {
        $headers[] = 'Authorization: Bearer ' . $bearer;
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($jar !== '') {
        curl_setopt($handle, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $jar);
    }
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post ?? []));
    }
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle)];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr((string) $raw, 0, $headSize);
    if (preg_match('/^X-Access-Pages-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start'];
    }

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'body'     => substr((string) $raw, $headSize),
    ];
}

/** The form token on a page. */
function token(string $html): string
{
    if (preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1) {
        return $m[1];
    }

    return preg_match('/<meta name="csrf-token" content="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

/** POST a form with a fresh token read from `$formPage`, as a browser would. */
function post(string $jar, string $formPage, string $to, array $fields): array
{
    $fields['csrf_token'] = token(req($jar, 'GET', $formPage)['body']);

    return req($jar, 'POST', $to, $fields);
}

/** "status: message" of a JSON answer (or the start of the page's text). */
function said(array $answer): string
{
    $json = json_decode($answer['body'], true);

    return $answer['status'] . ': ' . (is_array($json) === true ? (string) ($json['message'] ?? '') : mb_substr(trim(strip_tags($answer['body'])), 0, 80));
}

/** The page's top heading, as text. */
function heading(array $answer): string
{
    return preg_match('#<h1[^>]*>(.*?)</h1>#s', $answer['body'], $h) === 1 ? trim(strip_tags($h[1])) : '(no heading)';
}

// =============================================================================
// 🏗️ Set-up
// =============================================================================
if (portAnswers($port) === true) {
    refuse("something already answers on port {$port}; this test never drives a server it did not start");
}
$state['started'] = true;
register_shutdown_function('cleanup');
removeWorld();

q("INSERT INTO tblSites (siteID, siteKey, siteName, isActive) VALUES (?, 'selftest-access-a', 'Selftest access A', 1), (?, 'selftest-access-b', 'Selftest access B', 1)", 'ii', [ORG_A, ORG_B]);
foreach ([LEAD => 'Lead', APPR => 'Approver', ADM => 'Admin', TRS => 'Treasurer', MEM => 'Member'] as $id => $name) {
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [$id, 'Selftest access ' . $name, 'u' . $id . '.access@selftest.invalid']);
}
foreach ([[LEAD, 0], [APPR, 0], [ADM, 1], [TRS, 0], [MEM, 0]] as [$user, $admin]) {
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, ?, 0)', 'iii', [$user, ORG_A, $admin]);
}
q("INSERT INTO tblDepts (deptID, siteID, deptName, deptCode, isActive) VALUES (?, ?, 'Selftest access dept', 'SAD', 1)", 'ii', [DEPT, ORG_A]);
foreach ([[LEAD, 1, 0], [APPR, 0, 1], [MEM, 0, 0], [ADM, 0, 0]] as [$user, $lead, $approver]) {
    q('INSERT INTO tblUserDepts (userID, deptID, siteID, isDeptLead, isApprover, isMandatoryApprover) VALUES (?, ?, ?, ?, ?, 0)', 'iiiii', [$user, DEPT, ORG_A, $lead, $approver]);
}
q("INSERT INTO tblRoles (roleID, siteID, roleKey, roleName, isStandard) VALUES (916201, ?, 'treasurer', 'Treasurer', 1)", 'i', [ORG_A]);
q('INSERT INTO tblUserRoles (userID, roleID, siteID) VALUES (?, 916201, ?), (?, 916201, ?)', 'iiii', [LEAD, ORG_A, TRS, ORG_A]);
claim(916301, LEAD, 'ACCESS lead own claim');
claim(916302, MEM, 'ACCESS member claim');
claim(916303, ADM, 'ACCESS admin own claim');
claim(916304, MEM, 'ACCESS claim to reject');
claim(916305, LEAD, 'ACCESS lead second claim');
// One API key belonging to each organisation (the length ApiKey::findByPlaintext() accepts).
$keyA = 'wbms_' . bin2hex(random_bytes(16));
$keyB = 'wbms_' . bin2hex(random_bytes(16));
foreach ([[ORG_A, $keyA], [ORG_B, $keyB]] as [$site, $plain]) {
    q(
        "INSERT INTO tblApiKeys (siteID, name, keyHash, keyPrefix, scopes, isActive, createdByID) VALUES (?, 'Selftest access key', ?, ?, 'assets:read', 1, NULL)",
        'iss', [$site, hash('sha256', $plain), substr($plain, 0, 12)]
    );
}
// Organisations told apart by the first part of the address: recorded first, put back by the clean-up.
foreach (['multisite.enabled' => 'true', 'multisite.detectionMode' => 'path'] as $key => $value) {
    $row                     = one('SELECT settingID, settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]);
    $state['settings'][$key] = $row;
    if ($row === null) {
        q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, ?, ?, ?, 0)', 'sss', [$key, $value, $value]);
    } else {
        q('UPDATE tblSettings SET settingValue = ? WHERE settingID = ?', 'si', [$value, (int) $row['settingID']]);
    }
}

// 📋 WHICH APP EACH `api` FOLDER BELONGS TO — written out by hand, NOT read
//    from AppRegistry::API_FOLDER_APPS: taking the expected answers from the
//    list being checked would agree with it even when it was wrong. The
//    second value is the endpoint asked (a read wherever the folder has one,
//    so nothing is written when the app is on). null = belongs to the portal
//    itself, never refused.
$folders = [
    'announcements'   => ['announcements', 'list'],
    'assets'          => ['assets', 'list'],
    'attendance'      => ['attendance', 'list'],
    'calendar'        => ['calendar', 'hub-resources'],
    'documents'       => ['documents', 'list'],
    'events'          => ['calendar', 'list'],
    'expenses'        => ['expenses', 'list'],
    'leadership'      => ['leadership', 'list'],
    'livechat'        => ['cop-live-chat', 'list'],
    'livestream'      => ['livestream', 'ping'],
    'noticeboard'     => ['noticeboard', 'list'],
    'prayer-requests' => ['prayer-requests', 'list'],
    'push'            => [null, 'subscribe'],
    'service-plans'   => ['service-plans', 'hymn-search'],
    'tasks'           => ['tasks', 'list'],
    'tours'           => [null, 'active'],
    'users'           => [null, 'list'],
    'venues'          => ['venues', 'availability'],
    'worship'         => ['worship', 'state'],
];
$switchable = [];
foreach ($folders as [$slug]) {
    if ($slug !== null) {
        $switchable[$slug] = (string) ($apps[$slug]['settingKey'] ?? '');
    }
}
/** Every app in the table switched on for an organisation. */
$allOn = static function (int $site) use ($switchable): void {
    foreach ($switchable as $settingKey) {
        siteSetting($site, $settingKey, 'true');
    }
};
$allOn(ORG_A);
$allOn(ORG_B);
siteSetting(ORG_A, 'expenses.enabled', 'true');

$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
foreach (['lead' => LEAD, 'appr' => APPR, 'adm' => ADM, 'trs' => TRS, 'mem' => MEM] as $who => $id) {
    q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-access-' . $who, $hash]);
}

// The scratch copy of web/, with a throwaway connection file and key.
$sep   = DIRECTORY_SEPARATOR;
$child = $parent . $sep . 'access-pages-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.access-pages-selftest-owner', $state['token']); // the marker FIRST
mkdir($child . $sep . 'web', 0700);
$copied = 0;
$tree   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($webSrc, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($tree as $path => $info) {
    $rel = substr((string) $path, strlen($webSrc) + 1);
    if (in_array(explode($sep, $rel)[0], ['_auth_keys', '_uploads', '_backups', '_libraries'], true) === true) {
        continue;
    }
    if ($info->isLink() === true) {
        refuse('web/ holds a link (' . $rel . '); refusing to copy it');
    }
    if ($info->isDir() === true) {
        mkdir($child . $sep . 'web' . $sep . $rel, 0700);
    } else {
        copy((string) $path, $child . $sep . 'web' . $sep . $rel);
        $copied++;
    }
}
if ($copied < 100) {
    refuse("the copy of web/ holds only {$copied} files");
}
mkdir($child . $sep . 'web' . $sep . '_auth_keys', 0700);
mkdir($child . $sep . 'sessions', 0700);
$creds = [
    'db_host' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    'db_user' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    'db_pass' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    'db_name' => $dbName,
    'db_port' => getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306,
];
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\n// Written by tools/access-pages-selftest.php for one run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// 🧪 Part C's planted handler, in the SCRATCH COPY only: once in a folder that
//    has no line in the list, once in a listed folder (tours, which belongs
//    to no app), each with its per-endpoint switch on.
$planted = "<?php\n// Planted by tools/access-pages-selftest.php in its scratch copy for one run.\ndeclare(strict_types=1);\n\\Portal\\Core\\ApiResponse::success(['mark' => '" . PLANTED_MARK . "']);\n";
mkdir($child . $sep . 'web' . $sep . '_apps' . $sep . UNLISTED_FOLDER . $sep . 'api', 0700, true);
file_put_contents($child . $sep . 'web' . $sep . '_apps' . $sep . UNLISTED_FOLDER . $sep . 'api' . $sep . PLANTED_ACTION . '.php', $planted);
file_put_contents($child . $sep . 'web' . $sep . '_apps' . $sep . 'tours' . $sep . 'api' . $sep . PLANTED_ACTION . '.php', $planted);
foreach ([UNLISTED_FOLDER, 'tours'] as $folder) {
    q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, ?, 'true', 'true', 0)", 's', ['api.' . $folder . '.' . PLANTED_ACTION . '.enabled']);
}

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/access-pages-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Access-Pages-Selftest: __TOKEN__');
$root = (string) realpath(__DIR__ . '/web/public_html');
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#(^|/)\.(?!well-known)#', $uri) === 1) { http_response_code(403); exit('403'); }
if (preg_match('#\.(php\d?|phtml|phar)$#i', $uri) === 1 && in_array($uri, ['/index.php', '/api-docs/index.php', '/error.php'], true) === false) { http_response_code(404); exit('404'); }
// Required at the TOP LEVEL, never inside a function: the portal's start-up
// sets $mysqli and $SETTINGS as globals.
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
file_put_contents($child . $sep . 'router.php', str_replace('__TOKEN__', $state['token'], $router));

// The server: its command as a LIST (no shell in between), ONE process (no
// PHP_CLI_SERVER_WORKERS), so nothing can outlive it.
$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$state['proc']     = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1',
        '-d', 'display_errors=0', '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server-output.log', 'a'], 2 => ['file', $child . $sep . 'server-output.log', 'a']],
    $pipes,
    $child,
    $env
);
if (is_resource($state['proc']) === false) {
    refuse('the web server could not be started');
}
$deadline = microtime(true) + 10;
while (portAnswers($port) === false && microtime(true) < $deadline) {
    usleep(100000);
}
if (portAnswers($port) === false) {
    refuse('the web server never answered on port ' . $port);
}

echo "Switched-off apps and own expense claims, on the real pages — self-test (#478, #570, #545)\n";
echo 'server process ' . (int) proc_get_status($state['proc'])['pid'] . ', port ' . $port . ', PHP ' . PHP_VERSION . ', database ' . (string) one('SELECT VERSION() AS v')['v'] . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";

$jar = [];
foreach (['lead', 'appr', 'adm', 'trs', 'mem'] as $who) {
    $jar[$who] = $child . $sep . 'jar-' . $who . '.txt';
    $tok       = token(req($jar[$who], 'GET', ADDR_A . '/login')['body']);
    $in        = req($jar[$who], 'POST', ADDR_A . '/login', ['csrf_token' => $tok, 'username' => 'selftest-access-' . $who, 'password' => $password]);
    if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
        refuse("signing in as selftest-access-{$who} did not work (HTTP {$in['status']} {$in['location']})");
    }
}
$nobody = $child . $sep . 'jar-nobody.txt';

// =============================================================================
head('A. #478 — one row per api folder: only that folder\'s app off, then every app on');
// =============================================================================
ok('the table below has a row for each of the 19 api folders on disk, and no other', (static function () use ($folders, $webSrc): bool {
    $onDisk = array_map(static fn (string $dir): string => basename(dirname($dir)), (array) glob($webSrc . '/_apps/*/api', GLOB_ONLYDIR));
    sort($onDisk);
    $rows = array_keys($folders);
    sort($rows);

    return $onDisk === $rows && count($rows) === 19;
})(), 'A new api folder needs a row here as well as a line in AppRegistry::API_FOLDER_APPS.');
foreach ($folders as $folder => [$slug, $action]) {
    $path = ADDR_A . '/api/' . $folder . '/' . $action;
    if ($slug === null) {
        // Belongs to the portal itself: never refused as switched off, even with EVERY app off.
        foreach ($switchable as $settingKey) {
            siteSetting(ORG_A, $settingKey, 'false');
        }
        $answer = req($jar['adm'], 'GET', $path);
        $allOn(ORG_A);
        ok(
            sprintf('api/%s belongs to no app: with EVERY app switched off it is still not refused as switched off', $folder),
            $answer['status'] > 0 && str_contains($answer['body'], 'switched off') === false && str_contains($answer['body'], 'This API endpoint is disabled') === false,
            said($answer)
        );
        continue;
    }
    $name = (string) ($apps[$slug]['name'] ?? '(app not registered)');
    siteSetting(ORG_A, $switchable[$slug], 'false');
    $off = req($jar['adm'], 'GET', $path);
    siteSetting(ORG_A, $switchable[$slug], 'true');
    $on = req($jar['adm'], 'GET', $path);
    ok(
        sprintf('api/%s: with ONLY %s off, /api/%s/%s answers 403 "The %s app is switched off for this organisation."', $folder, $name, $folder, $action, $name),
        said($off) === '403: The ' . $name . ' app is switched off for this organisation.',
        said($off)
    );
    ok(
        sprintf('api/%s: with every app on, the same address is not refused as switched off', $folder),
        $on['status'] > 0 && str_contains($on['body'], 'switched off') === false,
        said($on)
    );
}
siteSetting(ORG_A, $switchable['assets'], 'false');
$answer = req($jar['adm'], 'GET', ADDR_A . '/api/v1/assets');
ok('the second way in, /api/v1/assets, is refused the same way with Asset Tracker off', said($answer) === '403: The Asset Tracker app is switched off for this organisation.', said($answer));
$answer = req($jar['adm'], 'GET', ADDR_A . '/api/assets/no-such-endpoint');
ok('an endpoint that does not exist is still "not found" (404) first, switched off or not', $answer['status'] === 404, said($answer));

// =============================================================================
head('B. #478 — an API key is judged by its OWN organisation\'s switch (Asset Tracker: off in A, on in B)');
// =============================================================================
$offSentence = '403: The Asset Tracker app is switched off for this organisation.';
$answer = req('', 'GET', ADDR_B . '/api/assets/list', null, $keyA);
ok('A\'s key sent to B\'s address (where the app is ON): refused, 403 switched off', said($answer) === $offSentence, said($answer));
$answer = req('', 'GET', ADDR_A . '/api/assets/list', null, $keyA);
ok('A\'s key sent to A\'s own address: refused, 403 switched off', said($answer) === $offSentence, said($answer));
$answer = req('', 'GET', ADDR_B . '/api/v1/assets', null, $keyA);
ok('A\'s key through /api/v1/assets on B\'s address: refused, 403 switched off', said($answer) === $offSentence, said($answer));
$answer = req('', 'GET', ADDR_A . '/api/assets/list', null, $keyB);
ok('B\'s key sent to A\'s address (where the app is OFF): answered 200, because B has it on', $answer['status'] === 200, said($answer));
$answer = req('', 'GET', ADDR_A . '/api/assets/list', null, 'wbms_' . bin2hex(random_bytes(16)));
ok('a made-up key sent to A\'s address is no way round: refused, 403 switched off', said($answer) === $offSentence, said($answer));
$answer = req('', 'GET', ADDR_A . '/api/assets/list', null, 'not-a-portal-key');
ok('…and neither is any other "Authorization" header', said($answer) === $offSentence, said($answer));
siteSetting(ORG_A, $switchable['assets'], 'true');
$answer = req('', 'GET', ADDR_B . '/api/assets/list', null, $keyA);
ok('Asset Tracker switched back on in A: A\'s key is answered 200', $answer['status'] === 200, said($answer));
siteSetting(ORG_B, $switchable['assets'], 'false');
$answer = req('', 'GET', ADDR_A . '/api/assets/list', null, $keyB);
ok('…and with it now off in B instead, B\'s key is refused at A\'s address (where it is on)', said($answer) === $offSentence, said($answer));
siteSetting(ORG_B, $switchable['assets'], 'true');

// =============================================================================
head('C. #478 — an api folder with no line in the list is refused, and its handler never runs');
// =============================================================================
$answer = req($jar['adm'], 'GET', ADDR_A . '/api/tours/' . PLANTED_ACTION);
ok('control: the planted handler DOES run from a listed folder (api/tours)', $answer['status'] === 200 && str_contains($answer['body'], PLANTED_MARK) === true, said($answer));
$answer = req($jar['adm'], 'GET', ADDR_A . '/api/' . UNLISTED_FOLDER . '/' . PLANTED_ACTION);
ok('the same handler in a folder with no line: 403 "This API endpoint is disabled", and it did not run', said($answer) === '403: This API endpoint is disabled' && str_contains($answer['body'], PLANTED_MARK) === false, said($answer));
$answer = req('', 'GET', ADDR_A . '/api/' . UNLISTED_FOLDER . '/' . PLANTED_ACTION, null, $keyA);
ok('…and the same for an API key', $answer['status'] === 403 && str_contains($answer['body'], PLANTED_MARK) === false, said($answer));

// =============================================================================
head('D. #478 and #570 — a switched-off app\'s stray pages; the "disabled" page names the right app');
// =============================================================================
siteSetting(ORG_A, 'calendar.enabled', 'false');
siteSetting(ORG_A, 'newsletter.enabled', 'false');
$answer = req($nobody, 'GET', ADDR_A . '/attend');
ok('Calendar off: /attend, signed out, is the "disabled" page (403) and its heading says "Calendar is disabled"', $answer['status'] === 403 && heading($answer) === 'Calendar is disabled', $answer['status'] . ' heading: ' . heading($answer));
$answer = req($nobody, 'GET', ADDR_A . '/calendar.ics');
ok('/calendar.ics is the "disabled" page too, not the feed page\'s own "Invalid token."', $answer['status'] === 403 && heading($answer) === 'Calendar is disabled' && str_contains($answer['body'], 'Invalid token.') === false, $answer['status'] . ' heading: ' . heading($answer));
$answer = req($jar['adm'], 'GET', ADDR_A . '/account/calendar-feed');
ok('/account/calendar-feed (the page that hands out the feed link) is the "disabled" page', $answer['status'] === 403 && heading($answer) === 'Calendar is disabled', $answer['status'] . ' heading: ' . heading($answer));
$answer = req($jar['adm'], 'GET', ADDR_A . '/calendar');
ok('#570: /calendar, signed in with a full menu drawn, says "Calendar is disabled" and not another app\'s name', $answer['status'] === 403 && heading($answer) === 'Calendar is disabled', $answer['status'] . ' heading: ' . heading($answer));
ok('#570: both links on that page stay inside the organisation\'s own address', str_contains($answer['body'], 'href="' . ADDR_A . '/admin/apps"') === true && str_contains($answer['body'], 'href="' . ADDR_A . '/" class="btn btn-primary"') === true);
ok('#570: the description under the heading is Calendar\'s own', str_contains($answer['body'], htmlspecialchars((string) ($apps['calendar']['description'] ?? '(none)'), ENT_QUOTES, 'UTF-8')) === true);
$answer = req($nobody, 'GET', ADDR_A . '/unsubscribe');
ok('Newsletter off: /unsubscribe is NOT the "disabled" page — people can always stop mail', $answer['status'] > 0 && $answer['status'] !== 403 && str_contains($answer['body'], ' is disabled</h1>') === false, 'HTTP ' . $answer['status'] . ' heading: ' . heading($answer));
siteSetting(ORG_A, 'calendar.enabled', 'true');
$answer = req($nobody, 'GET', ADDR_A . '/attend');
ok('Calendar back on: /attend is no longer the "disabled" page', $answer['status'] > 0 && $answer['status'] !== 403 && str_contains($answer['body'], ' is disabled</h1>') === false, 'HTTP ' . $answer['status']);
$answer = req($nobody, 'GET', ADDR_A . '/calendar.ics');
ok('…and /calendar.ics is answered by the feed page itself again ("Invalid token." without a personal token)', $answer['status'] === 403 && trim($answer['body']) === 'Invalid token.', 'HTTP ' . $answer['status'] . ' ' . mb_substr(trim(strip_tags($answer['body'])), 0, 60));

// =============================================================================
// E. #545 — through the real handlers
// =============================================================================
// The two sentences, written out by hand (not read from ExpenseRules).
$whoDecides  = 'It must be decided by the other leads and required approvers of its department or, when there are none, by another approver or an administrator.';
$ownDecision = 'You cannot approve or reject your own expense claim. ' . $whoDecides;
$ownPayment  = 'You cannot record payment of your own expense claim. Another treasurer or an administrator must pay it.';
$ownPageNote = 'This is your own claim, so you cannot decide it yourself. ' . $whoDecides;
$approve     = ADDR_A . '/expenses/approve';
$treasury    = ADDR_A . '/expenses/treasury';
$untouched   = 'Pending, 0 approval(s), 0 payment(s)';

head('E1. #545 — a lead cannot approve or reject their own claim; somebody else can');
$list = req($jar['lead'], 'GET', $approve);
ok('the lead opens the approvals page (200)', $list['status'] === 200, 'HTTP ' . $list['status']);
ok('their own two claims are NOT listed there', str_contains($list['body'], 'ACCESS lead own claim') === false && str_contains($list['body'], 'ACCESS lead second claim') === false);
ok('…a member\'s claim in their department IS (so the list still works)', str_contains($list['body'], 'ACCESS member claim') === true);
ok('…and the page says why, in one line', str_contains($list['body'], 'Your own claims are not listed here: somebody else must decide them.') === true);
$answer = post($jar['lead'], $approve, $approve . '/save', ['claimID' => 916301, 'decision' => 'Approved', 'comments' => '']);
ok('the lead POSTs an approval of their OWN claim to the real handler: redirected, nothing recorded', $answer['status'] === 302 && claimState(916301) === $untouched, 'HTTP ' . $answer['status'] . '; claim: ' . claimState(916301));
ok('…and the next page shows the refusal sentence', str_contains(req($jar['lead'], 'GET', $approve)['body'], $ownDecision) === true);
post($jar['lead'], $approve, $approve . '/save', ['claimID' => 916301, 'decision' => 'Rejected', 'comments' => '']);
ok('the lead cannot REJECT their own claim either: nothing recorded', claimState(916301) === $untouched, claimState(916301));
req($jar['lead'], 'GET', $approve); // show, and so clear, that message
post($jar['appr'], $approve, $approve . '/save', ['claimID' => 916301, 'decision' => 'Approved', 'comments' => 'ok']);
ok('ANOTHER approver of the department approves it: Approved on that one approval (its only lead is the claimant)', claimState(916301) === 'Approved, 1 approval(s), 0 payment(s)', claimState(916301));
ok('…recorded as that approver\'s decision', (one('SELECT userID, decision, approverRole FROM tblExpenseClaimApprovals WHERE claimID = 916301') ?? []) === ['userID' => APPR, 'decision' => 'Approved', 'approverRole' => 'dept_approver']);

head('E2. #545 — the claimant, who is also a treasurer, cannot pay it; another treasurer can');
$list = req($jar['lead'], 'GET', $treasury);
ok('the claimant opens the treasury page (200: they hold the Treasurer role)', $list['status'] === 200, 'HTTP ' . $list['status']);
ok('their own approved claim is NOT listed there, and the page says why', str_contains($list['body'], 'ACCESS lead own claim') === false && str_contains($list['body'], 'Your own claims are not listed here: somebody else must pay them.') === true);
$answer = post($jar['lead'], $treasury, $treasury . '/save', ['claimID' => 916301, 'payRef' => ['SELF-PAY-1'], 'comments' => '']);
ok('the claimant POSTs a payment of their OWN claim to the real handler: redirected, nothing recorded', $answer['status'] === 302 && claimState(916301) === 'Approved, 1 approval(s), 0 payment(s)', 'HTTP ' . $answer['status'] . '; claim: ' . claimState(916301));
ok('…and the next page shows the refusal sentence', str_contains(req($jar['lead'], 'GET', $treasury)['body'], $ownPayment) === true);
ok('ANOTHER treasurer sees that claim in their list', str_contains(req($jar['trs'], 'GET', $treasury)['body'], 'ACCESS lead own claim') === true);
post($jar['trs'], $treasury, $treasury . '/save', ['claimID' => 916301, 'payRef' => ['BACS-1'], 'comments' => '']);
ok('…and pays it: Reimbursed, with one payment recorded as theirs', claimState(916301) === 'Reimbursed, 1 approval(s), 1 payment(s)' && (int) (one('SELECT paidByID FROM tblExpenseClaimPayments WHERE claimID = 916301')['paidByID'] ?? 0) === TRS, claimState(916301));

head('E3. #545 — an ADMINISTRATOR who is the claimant is refused too');
$list = req($jar['adm'], 'GET', $approve);
ok('the administrator\'s own claim is NOT in their approvals list, though the lead\'s second claim is', str_contains($list['body'], 'ACCESS admin own claim') === false && str_contains($list['body'], 'ACCESS lead second claim') === true);
$answer = post($jar['adm'], $approve, $approve . '/save', ['claimID' => 916303, 'decision' => 'Approved', 'comments' => '']);
ok('the administrator POSTs an approval of their OWN claim: redirected, nothing recorded', $answer['status'] === 302 && claimState(916303) === $untouched, 'HTTP ' . $answer['status'] . '; claim: ' . claimState(916303));
ok('…and is shown the refusal sentence', str_contains(req($jar['adm'], 'GET', $approve)['body'], $ownDecision) === true);
post($jar['adm'], $approve, $approve . '/save', ['claimID' => 916303, 'decision' => 'Rejected', 'comments' => '']);
ok('the administrator cannot REJECT their own claim either: nothing recorded', claimState(916303) === $untouched, claimState(916303));
req($jar['adm'], 'GET', $approve);
post($jar['lead'], $approve, $approve . '/save', ['claimID' => 916303, 'decision' => 'Approved', 'comments' => '']);
ok('the department\'s lead approves it: Approved', claimState(916303) === 'Approved, 1 approval(s), 0 payment(s)', claimState(916303));
post($jar['adm'], $treasury, $treasury . '/save', ['claimID' => 916303, 'payRef' => ['SELF-PAY-2'], 'comments' => '']);
ok('the administrator POSTs a payment of their OWN claim: nothing recorded', claimState(916303) === 'Approved, 1 approval(s), 0 payment(s)', claimState(916303));
ok('…and is shown the refusal sentence', str_contains(req($jar['adm'], 'GET', $treasury)['body'], $ownPayment) === true);
post($jar['trs'], $treasury, $treasury . '/save', ['claimID' => 916303, 'payRef' => ['BACS-3'], 'comments' => '']);
ok('a treasurer pays it: Reimbursed', claimState(916303) === 'Reimbursed, 1 approval(s), 1 payment(s)', claimState(916303));

head('E4. #545 — what must not break');
post($jar['appr'], $approve, $approve . '/save', ['claimID' => 916302, 'decision' => 'Approved', 'comments' => '']);
ok('a member\'s claim, approved by the plain approver: still Pending (its lead has not approved)', claimState(916302) === 'Pending, 1 approval(s), 0 payment(s)', claimState(916302));
post($jar['adm'], $approve, $approve . '/save', ['claimID' => 916302, 'decision' => 'Approved', 'comments' => '']);
ok('…approved by an administrator as well: STILL Pending (an administrator never stands in for the lead)', claimState(916302) === 'Pending, 2 approval(s), 0 payment(s)', claimState(916302));
post($jar['lead'], $approve, $approve . '/save', ['claimID' => 916302, 'decision' => 'Approved', 'comments' => '']);
ok('…approved by the lead: Approved', claimState(916302) === 'Approved, 3 approval(s), 0 payment(s)', claimState(916302));
post($jar['trs'], $treasury, $treasury . '/save', ['claimID' => 916302, 'payRef' => ['BACS-2'], 'comments' => '']);
ok('a treasurer pays somebody else\'s approved claim as before: Reimbursed', claimState(916302) === 'Reimbursed, 3 approval(s), 1 payment(s)', claimState(916302));
post($jar['appr'], $approve, $approve . '/save', ['claimID' => 916304, 'decision' => 'Rejected', 'comments' => 'no receipt']);
ok('rejecting somebody else\'s claim works as before: Rejected at once', claimState(916304) === 'Rejected, 1 approval(s), 0 payment(s)', claimState(916304));
$answer = post($jar['mem'], $approve, $approve . '/save', ['claimID' => 916305, 'decision' => 'Approved', 'comments' => '']);
ok('somebody with no authority at all is still refused, with the old sentence, and nothing is recorded', $answer['status'] === 302 && claimState(916305) === $untouched && str_contains(req($jar['mem'], 'GET', $approve)['body'], 'Access denied — Approver or Admin role required.') === true, claimState(916305));

head('E5. #545 — the claim\'s own page');
$own   = req($jar['lead'], 'GET', ADDR_A . '/expenses/view?id=916305')['body'];
$other = req($jar['appr'], 'GET', ADDR_A . '/expenses/view?id=916305')['body'];
ok('another approver is offered "Go to Approvals" on the lead\'s pending claim, and is told nothing about "your own claim"', str_contains($other, 'Go to Approvals') === true && str_contains($other, 'This is your own claim') === false);
ok('the claimant (a lead) is NOT offered "Go to Approvals" on it', str_contains($own, 'ACCESS lead second claim') === true && str_contains($own, 'Go to Approvals') === false);
ok('…and is told, in one sentence, who decides it', str_contains($own, $ownPageNote) === true);
claim(916306, MEM, 'ACCESS member second claim');
$plain = req($jar['mem'], 'GET', ADDR_A . '/expenses/view?id=916306')['body'];
ok('an ordinary claimant, who never had the button, is shown neither the button nor the sentence', str_contains($plain, 'ACCESS member second claim') === true && str_contains($plain, 'Go to Approvals') === false && str_contains($plain, 'This is your own claim') === false);

// =============================================================================
head('Nothing went wrong behind the pages, and everything is put back');
// =============================================================================
$log = (string) @file_get_contents($child . $sep . 'php-errors.log');
$bad = array_values(array_filter((array) preg_split('/\R/', $log), static fn (string $line): bool => preg_match('/PHP (Fatal|Parse|Warning|Deprecated|Notice)|Uncaught/', $line) === 1));
ok('the server\'s PHP error log holds no warning, notice or fatal error', $bad === [], count($bad) . ' found: ' . implode(' | ', array_map(static fn (string $line): string => mb_substr($line, 0, 220), array_slice($bad, 0, 4))));

$before = $state['settings'];
cleanup();
$left = (int) (one(
    'SELECT (SELECT COUNT(*) FROM tblSites WHERE siteID IN (?, ?)) + (SELECT COUNT(*) FROM tblUsers WHERE userID BETWEEN 916000 AND 916999) '
    . "+ (SELECT COUNT(*) FROM tblExpenseClaims WHERE claimID BETWEEN 916000 AND 916999) + (SELECT COUNT(*) FROM tblSettings WHERE settingKey LIKE '%zz-selftest%') AS n",
    'ii', [ORG_A, ORG_B]
)['n'] ?? -1);
$settingsBack = true;
foreach ($before as $key => $row) {
    $settingsBack = $settingsBack && (one('SELECT settingID, settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [(string) $key]) === $row);
}
ok('clean-up: the made-up world is gone and the two portal-wide settings are as they were', $left === 0 && $settingsBack === true, 'rows left: ' . $left);
ok('clean-up: the server is stopped and the scratch folder is deleted', portAnswers($port) === false && file_exists($child) === false);

echo "\n{$pass} passed, {$fail} failed, " . ($pass + $fail) . ' ran of ' . EXPECTED_CHECKS . " expected.\n";
if (($pass + $fail) !== EXPECTED_CHECKS) {
    echo "FAIL — the number of checks that ran is not the number this file expects. A block was skipped or ran twice,\n";
    echo "or a check was added without updating the number at the top.\n";
    exit(1);
}
if ($fail > 0) {
    echo "FAIL — {$fail} check(s) failed. Read the FAIL lines above before releasing.\n";
    exit(1);
}
echo "PASS — all {$pass} checks passed.\n";
exit(0);
