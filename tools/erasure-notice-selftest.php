<?php
// Path: tools/erasure-notice-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Telling administrators an erasure request is waiting, and reaching offboarding
 * — self-test on the REAL pages 📣🚪 (#1136)
 * -----------------------------------------------------------------------------
 * WHAT IT PROVES
 *   A. A member follows the e-mailed confirmation link, signed out: the
 *      administrators of the REQUEST's organisation (not the address's) each
 *      have one message queued, equal to ImportNotifier::recipients(); the body
 *      carries the due date and the requests page address, and neither the
 *      member's name nor their e-mail address. The premise is checked first:
 *      the name and address are non-empty and differ.
 *   B. "Delete my account" over HTTP: the same.
 *   C. The three ADMINISTRATOR routes (confirm on a person's behalf, offboarding
 *      with erasure, a request for a visitor's address) each reach a waiting
 *      request and send NOTHING.
 *   D. The same link twice tells once; and a request that has moved on between
 *      the change and the notice (a database trigger makes that happen, the
 *      only way a one-worker harness can) is not announced as waiting.
 *   E. The member's page is word for word what it was.
 *   F. The Users page shows Offboard on an ordinary member's line, not on an
 *      erased one, not on a global administrator's line, and not at all while
 *      the Offboarding app is off; the link it draws opens. The Offboarding
 *      page no longer says "click the user".
 *   G. The Admin menu and the dashboard carry the new links only for those
 *      the pages let in; the Backups card is a global administrator's.
 *   H. An organisation with no administrator falls back to the global ones;
 *      with nobody to tell, the filing is untouched; with the mail route down
 *      or not set up the member's page is the same.
 *   I. Plants: each new check is shown able to go red, and every planted file is
 *      put back byte for byte (compared by hash).
 *
 * WHAT IT CANNOT PROVE: that a mail server delivers; that a real browser draws
 * the button; two requests at once (one worker). The test sets up SMTP at a
 * port nothing listens on, so a message is KEPT in the queue, where its text is
 * read back (decrypted with the test's own key).
 *
 * SAFETY: like erasure-pages-selftest.php. Needs a database named selftest_*,
 * numbers 917400-917599 and three made-up organisations; its own scratch copy
 * of web/; its own server, stopped by process handle.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             SELFTEST_WEB_PORT=… php tools/erasure-notice-selftest.php
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes and erases rows");
}
foreach (['curl_init', 'proc_open', 'sodium_crypto_secretbox'] as $needed) {
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
    refuse('the scratch parent folder and the repository must not contain each other');
}
$portRaw = getenv('SELFTEST_WEB_PORT');
$port    = ($portRaw === false || $portRaw === '') ? 9087 : (int) $portRaw;
if ($port < 1024 || $port > 65535) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65535');
}

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

function ok(string $label, bool $passed, string $detail = ''): void
{
    global $pass, $fail;
    if ($passed === true) {
        $pass++;
        echo 'PASS — ' . $label . "\n";

        return;
    }
    $fail++;
    echo 'FAIL — ' . $label . ($detail === '' ? '' : "\n        " . mb_substr($detail, 0, 900)) . "\n";
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
const ORG_A = 917401; // where most things happen; has two administrators
const ORG_B = 917402; // another organisation with its own administrator
const ORG_Z = 917403; // an organisation with NO administrator of its own
const KEY_A = 'selftest-notice-a';
const KEY_B = 'selftest-notice-b';
const KEY_Z = 'selftest-notice-z';

const ADM  = 917411; // an administrator of A
const ADM2 = 917412; // another administrator of A
const ROOT = 917413; // a global administrator who is a member of A
const BADM = 917414; // an administrator of B
const MEL  = 917421; // a member of A: the person whose request is announced (a distinctive name)
const NED  = 917422; // a member of A: a spare for the link twice
const OLI  = 917423; // a member of A: presses "Delete my account"
const PAM  = 917424; // a member of A: an administrator confirms on her behalf
const QUE  = 917425; // a member of A: offboarded with erasure
const ERZ  = 917426; // a member of A, already erased on request
const ZEE  = 917431; // a member of Z
const BEN  = 917432; // a member of B: his link is opened at A's address
const RAY  = 917433; // a member of A for the trigger test
const SAM  = 917434; // a member of A for the plants

const MEL_NAME  = 'Marigold Quillfeather';
const MEL_EMAIL = 'marigold.quillfeather.917421@selftest.invalid';

const SUBJECT_PREFIX = 'An erasure request is waiting in ';

function addr(string $key): string
{
    return '/' . $key;
}

function removeWorld(): void
{
    global $db;
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    $sites = ORG_A . ',' . ORG_B . ',' . ORG_Z;
    $db->query('DROP TRIGGER IF EXISTS `selftest_notice_race`');
    $db->query("DELETE FROM tblErasureAudit WHERE requestID IN (SELECT requestID FROM tblErasureRequest WHERE siteID IN ({$sites}))");
    $db->query("DELETE FROM tblErasureRequest WHERE siteID IN ({$sites})");
    $db->query("DELETE FROM tblMailQueue WHERE siteID IN ({$sites})");
    $db->query("DELETE FROM tblEmailLog WHERE toRecipients LIKE '%.notice917%'");
    $db->query("DELETE FROM tblActivityLogs WHERE activityDescription LIKE 'Erasure request #%is waiting;%' OR userID BETWEEN 917400 AND 917499");
    $db->query('DELETE FROM tblOffboarding WHERE userID BETWEEN 917400 AND 917499');
    $db->query('DELETE FROM tblLocalAccounts WHERE userID BETWEEN 917400 AND 917499');
    $db->query('DELETE FROM tblUserSites WHERE userID BETWEEN 917400 AND 917499');
    $db->query("DELETE FROM tblSettings WHERE siteID IN ({$sites})");
    $db->query('DELETE FROM tblUsers WHERE userID BETWEEN 917400 AND 917499');
    $db->query("DELETE FROM tblSites WHERE siteID IN ({$sites})");
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
}

function setting(string $key, string $value, int $secret = 0): void
{
    global $state;
    $row = one('SELECT settingID, settingValue, isSensitive FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]);
    if (array_key_exists($key, $state['settings']) === false) {
        $state['settings'][$key] = $row;
    }
    if ($row === null) {
        q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, ?, ?, ?, ?)', 'sssi', [$key, $value, $value, $secret]);
    } else {
        q('UPDATE tblSettings SET settingValue = ?, isSensitive = ? WHERE settingID = ?', 'sii', [$value, $secret, (int) $row['settingID']]);
    }
}

function siteSetting(int $site, string $key, string $value): void
{
    q('DELETE FROM tblSettings WHERE siteID = ? AND settingKey = ?', 'is', [$site, $key]);
    q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, ?, ?, ?, 0)', 'isss', [$site, $key, $value, $value]);
}

// =============================================================================
// 🧰 The server, and one request
// =============================================================================
$state = ['proc' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'settings' => [], 'started' => false, 'cleaned' => false, 'planted' => []];

function portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);

        return true;
    }

    return false;
}

function cleanup(): void
{
    global $state;
    if ($state['started'] === false || $state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
    // A planted file is put back first, from the copy taken before planting.
    foreach ($state['planted'] as $path => $original) {
        file_put_contents((string) $path, (string) $original);
    }
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
                q('UPDATE tblSettings SET settingValue = ?, isSensitive = ? WHERE settingID = ?', 'sii', [$before['settingValue'], (int) $before['isSensitive'], (int) $before['settingID']]);
            }
        }
        removeWorld();
    } catch (Throwable $e) {
        echo 'FAIL — the clean-up could not put the database back: ' . $e->getMessage() . "\n";
    }
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.erasure-notice-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}

/** @param array<string,mixed>|null $post @return array{status:int, location:string, body:string} */
function req(string $jar, string $method, string $path, ?array $post = null, string $address = ''): array
{
    global $port, $state;
    $address = $address === '' ? addr(KEY_A) : $address;
    $handle  = curl_init('http://127.0.0.1:' . $port . $address . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => ['Expect:'],
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
    ]);
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
    if (preg_match('/^X-Notice-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header'];
    }

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'body'     => substr((string) $raw, $headSize),
    ];
}

function token(string $html): string
{
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

function text(string $html): string
{
    return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
}

/** What a person reads on a page: the body only, tags and scripts gone. */
function bodyText(string $html): string
{
    $body = preg_match('/<body[^>]*>(.*)<\/body>/s', $html, $m) === 1 ? $m[1] : $html;
    $body = (string) preg_replace('#<(script|style)\b.*?</\1>#s', '', $body);

    return text($body);
}

/** Encrypt/decrypt the way the portal does for settings and queued mail, with this run's key. */
function sealed(string $plain, string $keyText): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, sodium_hex2bin(hash('sha256', $keyText))));
}

function unsealed(string $encoded, string $keyText): string
{
    $bin = base64_decode($encoded, true);
    if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return '';
    }
    $plain = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), sodium_hex2bin(hash('sha256', $keyText)));

    return $plain === false ? '' : $plain;
}

function closedPort(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $free   = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
    fclose($server);

    return $free;
}

/** @return list<array<string,mixed>> The notice messages kept so far, oldest first. */
function notices(): array
{
    return q(
        "SELECT * FROM tblMailQueue WHERE subject LIKE CONCAT(?, '%') ORDER BY queueID",
        's',
        [SUBJECT_PREFIX]
    );
}

function noticeCount(): int
{
    return count(notices());
}

/** Make ONE request waiting for its e-mailed link, with a token the test knows. Returns [requestID, token]. */
function seedConfirmable(int $site, int $user): array
{
    $token = bin2hex(random_bytes(32));
    $mail  = (string) one('SELECT emailAddress AS e FROM tblUsers WHERE userID = ?', 'i', [$user])['e'];
    $name  = (string) one('SELECT fullName AS n FROM tblUsers WHERE userID = ?', 'i', [$user])['n'];
    q(
        "INSERT INTO tblErasureRequest (siteID, userID, subjectEmail, subjectName, confirmToken, status, dueBy) VALUES (?, ?, ?, ?, ?, 'pending_confirmation', DATE_ADD(NOW(), INTERVAL 1 MONTH))",
        'iisss',
        [$site, $user, $mail, $name, $token]
    );

    return [(int) one('SELECT MAX(requestID) AS m FROM tblErasureRequest WHERE userID = ?', 'i', [$user])['m'], $token];
}

function statusOf(int $requestId): string
{
    return (string) (one('SELECT status FROM tblErasureRequest WHERE requestID = ?', 'i', [$requestId])['status'] ?? 'none');
}

// =============================================================================
// 🏗️ Set-up
// =============================================================================
if (portAnswers($port) === true) {
    refuse("something already answers on port {$port}; this test never drives a server it did not start");
}
$state['started'] = true;
register_shutdown_function('cleanup');
if (function_exists('pcntl_async_signals') === true) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM] as $signal) {
        pcntl_signal($signal, static function (int $signal): void {
            echo "\nFAIL — stopped by signal {$signal}; cleaning up before exiting.\n";
            exit(1);
        });
    }
}
removeWorld();

q("INSERT INTO tblSites (siteID, siteKey, siteName, isActive) VALUES (?, ?, 'Selftest notice A', 1), (?, ?, 'Selftest notice B', 1), (?, ?, 'Selftest notice Z', 1)", 'isisis', [ORG_A, KEY_A, ORG_B, KEY_B, ORG_Z, KEY_Z]);
$people = [
    ADM => ['Ada Admin', 0], ADM2 => ['Abe Admin', 0], ROOT => ['Rae Root', 1], BADM => ['Bea Admin', 0],
    MEL => [MEL_NAME, 0], NED => ['Ned Member', 0], OLI => ['Oli Member', 0], PAM => ['Pam Member', 0], QUE => ['Que Member', 0],
    ZEE => ['Zee Member', 0], BEN => ['Ben Member', 0], RAY => ['Ray Member', 0], SAM => ['Sam Member', 0],
];
foreach ($people as $id => [$name, $root]) {
    $email = $id === MEL ? MEL_EMAIL : 'u' . $id . '.notice917@selftest.invalid';
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, ?)', 'issi', [$id, $name, $email, $root]);
}
// An account already erased on request: the Users page must not offer Offboard on it.
q("INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, '[Deleted User]', 'deleted-917426@example.invalid', 0, 0, 0)", 'i', [ERZ]);
foreach ([
    [ADM, ORG_A, 1], [ADM2, ORG_A, 1], [ROOT, ORG_A, 0], [BADM, ORG_B, 1], [MEL, ORG_A, 0], [NED, ORG_A, 0], [OLI, ORG_A, 0],
    [PAM, ORG_A, 0], [QUE, ORG_A, 0], [ERZ, ORG_A, 0], [ZEE, ORG_Z, 0], [BEN, ORG_B, 0], [RAY, ORG_A, 0], [SAM, ORG_A, 0],
] as [$user, $site, $admin]) {
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, ?, 0)', 'iii', [$user, $site, $admin]);
}

foreach (['multisite.enabled' => 'true', 'multisite.detectionMode' => 'path'] as $key => $value) {
    setting($key, $value);
}
// The Offboarding app ships switched off; this test switches it on for A and off again in section F.
siteSetting(ORG_A, 'offboarding.enabled', 'true');
// An organisation's name in the e-mail is its `site.name` setting (Mailer::organisationNameFor), the same words as its footer.
foreach ([ORG_A => 'Selftest notice A', ORG_B => 'Selftest notice B', ORG_Z => 'Selftest notice Z'] as $siteNo => $siteWords) {
    siteSetting($siteNo, 'site.name', $siteWords);
}
siteSetting(ORG_Z, 'offboarding.enabled', 'true');

// Mail: SMTP aimed at a port nothing listens on, so a message is KEPT in the queue and can be read back.
$keyText      = bin2hex(random_bytes(32));
$mailPassword = 'Pw' . bin2hex(random_bytes(8));
setting('mail.provider', 'smtp');
setting('mail.smtp.host', 'localhost');
setting('mail.smtp.port', (string) closedPort());
setting('mail.smtp.security', 'starttls');
setting('mail.smtp.username', 'selftest-notice-mailbox');
setting('mail.smtp.password', sealed($mailPassword, $keyText), 1);
setting('mail.defaultFromAddress', 'office@selftest.invalid');
setting('mail.defaultFromName', 'Selftest Notice Office');
setting('mail.fallbackProvider', '');
setting('mail.limit.perHour', '');
setting('site.url', 'http://127.0.0.1:' . $port);

$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
$logins   = ['adm' => ADM, 'root' => ROOT, 'mel' => MEL, 'oli' => OLI, 'badm' => BADM];
foreach ($logins as $who => $id) {
    q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-notice-' . $who, $hash]);
}

$sep   = DIRECTORY_SEPARATOR;
$child = $parent . $sep . 'erasure-notice-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.erasure-notice-selftest-owner', $state['token']);
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
mkdir($child . $sep . 'web' . $sep . '_uploads', 0700);
mkdir($child . $sep . 'sessions', 0700);
$creds = [
    'db_host' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    'db_user' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    'db_pass' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    'db_name' => $dbName,
    'db_port' => getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306,
];
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\n// Written by tools/erasure-notice-selftest.php for one run.\nreturn " . var_export($creds, true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', $keyText);

$router = <<<'ROUTER'
<?php
// Written by tools/erasure-notice-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Notice-Selftest: __TOKEN__');
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
file_put_contents($child . $sep . 'router.php', str_replace('__TOKEN__', $state['token'], $router));

$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$state['proc'] = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1',
        '-d', 'display_errors=0', '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
        '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
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
echo "The erasure notice and the way to offboarding, on the real pages — self-test (#1136)\n";
echo 'server process ' . (int) proc_get_status($state['proc'])['pid'] . ', port ' . $port . ', PHP ' . PHP_VERSION . ', database ' . (string) one('SELECT VERSION() AS v')['v'] . "\n";

$jar    = [];
$signIn = static function (string $who, string $key = KEY_A) use (&$jar, $child, $sep, $password): array {
    $jar[$who] = $child . $sep . 'jar-' . $who . '.txt';
    $tok       = token(req($jar[$who], 'GET', '/login', null, addr($key))['body']);

    return req($jar[$who], 'POST', '/login', ['csrf_token' => $tok, 'username' => 'selftest-notice-' . $who, 'password' => $password], addr($key));
};
foreach ($logins as $who => $id) {
    $in = $signIn($who, $who === 'badm' ? KEY_B : KEY_A);
    if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
        refuse("signing in as selftest-notice-{$who} did not work (HTTP {$in['status']} {$in['location']})");
    }
}

// The recipients the portal's own rule gives, asked of the REAL class (it needs nothing from the portal's start-up).
require_once $webSrc . $sep . '_core' . $sep . 'ImportNotifier.php';
$expectedFor = static function (int $site) use ($db): array {
    $emails = array_map(static fn (array $p): string => strtolower($p['email']), \Portal\Core\ImportNotifier::recipients($db, $site));
    sort($emails);

    return array_values(array_unique($emails));
};
$queuedTo = static function (array $rows): array {
    $to = [];
    foreach ($rows as $row) {
        foreach (explode(',', (string) $row['toRecipients']) as $one) {
            $to[] = strtolower(trim($one));
        }
    }
    sort($to);

    return $to;
};
$confirmPage = static fn (string $tok, string $key = KEY_A): array => req($child . $sep . 'jar-anon.txt', 'GET', '/account/erasure-confirm?token=' . $tok, null, addr($key));
/** @return array<string,string> */
$words = static function () use ($keyText): array {
    $out = [];
    foreach (notices() as $row) {
        $out[(string) $row['queueID']] = (string) $row['subject'] . "\n" . unsealed((string) $row['bodyText'], $keyText) . "\n" . unsealed((string) $row['bodyHtml'], $keyText);
    }

    return $out;
};

// =============================================================================
head('Premise');
// =============================================================================
ok('the member\'s name and address are non-empty and differ', MEL_NAME !== '' && MEL_EMAIL !== '' && MEL_NAME !== MEL_EMAIL
    && (string) one('SELECT fullName AS n FROM tblUsers WHERE userID = ?', 'i', [MEL])['n'] === MEL_NAME);
$expectA = $expectedFor(ORG_A);
ok('A has three people to tell (two site administrators and the global administrator who is a member)', count($expectA) === 3, json_encode($expectA));
ok('no notice exists yet', noticeCount() === 0);

// =============================================================================
head('G (first half). The dashboard and the menu, before any request waits');
// =============================================================================
$dashAdm  = req($jar['adm'], 'GET', '/admin');
$dashRoot = req($jar['root'], 'GET', '/admin');
$tile     = static fn (string $html, string $marker): bool => str_contains($html, 'data-admin-setup-tile="' . $marker . '"');
ok('the dashboard opens for an administrator', $dashAdm['status'] === 200 && $dashRoot['status'] === 200, $dashAdm['status'] . '/' . $dashRoot['status']);
ok('G1 an administrator sees the erasure requests, health, check and offboarding cards',
    $tile($dashAdm['body'], 'erasure-requests') && $tile($dashAdm['body'], 'health') && $tile($dashAdm['body'], 'check') && $tile($dashAdm['body'], 'offboarding'));
ok('G2 the Backups card is a global administrator\'s: absent for an organisation administrator, present for the global one',
    $tile($dashAdm['body'], 'backups') === false && $tile($dashRoot['body'], 'backups') === true);
ok('G3 the erasure card says "None waiting." while none waits', str_contains(text($dashAdm['body']), 'None waiting.'));
$menuPage = req($jar['adm'], 'GET', '/admin/users');
ok('G4 the Admin menu carries the erasure requests address for an administrator', str_contains($menuPage['body'], addr(KEY_A) . '/admin/erasure-requests'));
$memberPage = req($jar['mel'], 'GET', '/account/my-data');
ok('G5 an ordinary member\'s pages carry no such link and the requests page refuses them',
    str_contains($memberPage['body'], '/admin/erasure-requests') === false && req($jar['mel'], 'GET', '/admin/erasure-requests')['status'] === 403);

// =============================================================================
head('A. A member follows the e-mailed link; the organisation\'s administrators are told');
// =============================================================================
[$melReq, $melToken] = seedConfirmable(ORG_A, MEL);
$page = $confirmPage($melToken);
ok('A1 the link works (the request is waiting for an administrator)', $page['status'] === 200 && statusOf($melReq) === 'pending_review', $page['status'] . ' ' . statusOf($melReq));
$rowsA = notices();
ok('A2 exactly one message is queued per recipient, to exactly ImportNotifier::recipients()', count($rowsA) === count($expectA) && $queuedTo($rowsA) === $expectA, json_encode($queuedTo($rowsA)) . ' vs ' . json_encode($expectA));
$dueText = (string) one("SELECT DATE_FORMAT(dueBy, '%e %M %Y') AS d FROM tblErasureRequest WHERE requestID = ?", 'i', [$melReq])['d'];
$all     = $words();
$first   = (string) (array_values($all)[0] ?? '');
ok('A3 the text carries the due date as stored and the requests page address', str_contains($first, $dueText) && str_contains($first, 'admin/erasure-requests'), $first);
ok('A4 the subject names the organisation', str_contains($first, SUBJECT_PREFIX . 'Selftest notice A'));
$leaks = [];
foreach ($all as $text) {
    foreach (['Marigold', 'Quillfeather', 'marigold', 'quillfeather', 'selftest.invalid'] as $needle) {
        if (str_contains($text, $needle) === true && $needle !== 'selftest.invalid') {
            $leaks[] = $needle;
        }
    }
    if (str_contains($text, MEL_EMAIL) === true) {
        $leaks[] = 'the address';
    }
}
ok('A5 neither the member\'s name nor their e-mail address is in any message', $leaks === [], implode(',', $leaks));
$useBy = (string) $rowsA[0]['expiresAt'];
ok('A6 each message lapses at the due date (about a month away, in UTC), not sooner',
    (int) one('SELECT TIMESTAMPDIFF(DAY, UTC_TIMESTAMP(), ?) AS d', 's', [$useBy])['d'] >= 27, $useBy);
ok('A7 one activity line records the numbers, naming nobody',
    (int) one("SELECT COUNT(*) AS n FROM tblActivityLogs WHERE activityDescription LIKE ? AND activityDescription NOT LIKE '%Quillfeather%'", 's', ['Erasure request #' . $melReq . ' is waiting; ' . count($expectA) . ' administrator%'])['n'] === 1);

// =============================================================================
head('D. The same link twice tells once; a request that has moved on is not announced');
// =============================================================================
$beforeTwice = noticeCount();
$again       = $confirmPage($melToken);
ok('D1 following the link again tells nobody more, and says so to the member', noticeCount() === $beforeTwice && str_contains(bodyText($again['body']), 'Link expired'));
// A trigger moves the request on the moment it reaches "waiting", which is the only way one worker can reproduce a request that
// changed between the page's change and the notice. The page's own change still reports one row changed.
[$rayReq, $rayToken] = seedConfirmable(ORG_A, RAY);
$db->query("CREATE TRIGGER `selftest_notice_race` BEFORE UPDATE ON `tblErasureRequest` FOR EACH ROW SET NEW.status = IF(NEW.status = 'pending_review' AND NEW.requestID = {$rayReq}, 'cancelled', NEW.status)");
$beforeRace = noticeCount();
$racePage   = $confirmPage($rayToken);
$db->query('DROP TRIGGER IF EXISTS `selftest_notice_race`');
ok('D2 premise: the page\'s change was stored and the request is no longer waiting', $racePage['status'] === 200 && statusOf($rayReq) === 'cancelled', $racePage['status'] . ' ' . statusOf($rayReq));
ok('D3 a request no longer waiting is NOT announced as waiting', noticeCount() === $beforeRace);

// =============================================================================
head('B. "Delete my account"');
// =============================================================================
$beforeB  = noticeCount();
$deletePg = req($jar['oli'], 'GET', '/account/delete');
$sent     = req($jar['oli'], 'POST', '/account/delete/confirm', ['csrf_token' => token($deletePg['body']), 'confirm_phrase' => 'DELETE MY ACCOUNT']);
$oliReq   = (int) (one("SELECT MAX(requestID) AS m FROM tblErasureRequest WHERE userID = ?", 'i', [OLI])['m'] ?? 0);
ok('B1 premise: the press filed a request that is waiting for an administrator and the member goes on to My data',
    $sent['status'] === 302 && str_contains($sent['location'], 'my-data') && $oliReq > 0 && statusOf($oliReq) === 'pending_review', $sent['status'] . ' ' . $sent['location']);
$newB = array_slice(notices(), $beforeB);
ok('B2 the same people are told, once each', count($newB) === count($expectA) && $queuedTo($newB) === $expectA, json_encode($queuedTo($newB)));
$bText = unsealed((string) ($newB[0]['bodyText'] ?? ''), $keyText);
ok('B3 and the text names nobody (Oli, or the address)', str_contains($bText, 'Oli') === false && str_contains($bText, 'u' . OLI . '.notice917') === false && $bText !== '');
$secondPress = req($jar['oli'], 'POST', '/account/delete/confirm', ['csrf_token' => token(req($jar['oli'], 'GET', '/account/delete')['body']), 'confirm_phrase' => 'DELETE MY ACCOUNT']);
ok('B4 pressing again ("already recorded") tells nobody more', count(notices()) === $beforeB + count($expectA) && $secondPress['status'] === 302);

// =============================================================================
head('C. The three administrator routes tell nobody');
// =============================================================================
$beforeC = noticeCount();
[$pamReq] = seedConfirmable(ORG_A, PAM);
$list  = req($jar['adm'], 'GET', '/admin/erasure-requests');
req($jar['adm'], 'POST', '/admin/erasure-requests/process', ['csrf_token' => token($list['body']), 'requestID' => $pamReq, 'action' => 'confirm']);
ok('C1 premise: the administrator\'s confirmation moved the request to waiting', statusOf($pamReq) === 'pending_review', statusOf($pamReq));
$tok = token(req($jar['adm'], 'GET', '/admin/users')['body']);
req($jar['adm'], 'POST', '/offboarding/do', ['csrf_token' => $tok, 'userID' => QUE, 'reason' => 'Selftest', 'dataDisposition' => 'erase', 'effectiveDate' => gmdate('Y-m-d')]);
$queReq = (int) (one('SELECT MAX(requestID) AS m FROM tblErasureRequest WHERE userID = ?', 'i', [QUE])['m'] ?? 0);
ok('C2 premise: offboarding with erasure filed a waiting request', $queReq > 0 && statusOf($queReq) === 'pending_review', (string) $queReq);
$list = req($jar['adm'], 'GET', '/admin/erasure-requests');
req($jar['adm'], 'POST', '/admin/erasure-requests/process', ['csrf_token' => token($list['body']), 'action' => 'file_visitor', 'visitorEmail' => 'visitor.notice917@selftest.invalid', 'checkedOffPortal' => '1', 'howChecked' => 'Selftest']);
$visitorReq = (int) (one("SELECT MAX(requestID) AS m FROM tblErasureRequest WHERE siteID = ? AND subjectKind = 'visitor_address'", 'i', [ORG_A])['m'] ?? 0);
ok('C3 premise: the visitor-address request exists and is waiting', $visitorReq > 0 && statusOf($visitorReq) === 'pending_review', (string) $visitorReq);
ok('C4 none of the three routes queued a notice', noticeCount() === $beforeC, noticeCount() . ' vs ' . $beforeC);

// =============================================================================
head('A (continued). The request\'s own organisation is told, whatever address the link is opened at');
// =============================================================================
$expectB = $expectedFor(ORG_B);
[$benReq, $benToken] = seedConfirmable(ORG_B, BEN);
$beforeBen = noticeCount();
$confirmPage($benToken, KEY_A); // opened at A's address on purpose
$newBen = array_slice(notices(), $beforeBen);
ok('A8 B\'s request opened at A\'s address tells B\'s administrator only, under B\'s name',
    $queuedTo($newBen) === $expectB && count($expectB) === 1 && (int) $newBen[0]['siteID'] === ORG_B && str_contains((string) $newBen[0]['subject'], 'Selftest notice B'),
    json_encode($queuedTo($newBen)));

// =============================================================================
head('H. No administrator of its own; nobody to tell; the mail route down');
// =============================================================================
$expectZ = $expectedFor(ORG_Z);
[$zeeReq, $zeeToken] = seedConfirmable(ORG_Z, ZEE);
$beforeZ = noticeCount();
$confirmPage($zeeToken, KEY_Z);
$newZ = array_slice(notices(), $beforeZ);
ok('H1 an organisation with no administrator: the global administrators are told', $queuedTo($newZ) === $expectZ && $expectZ !== [] && in_array(strtolower((string) one('SELECT emailAddress AS e FROM tblUsers WHERE userID = ?', 'i', [ROOT])['e']), $expectZ, true), json_encode($queuedTo($newZ)));
// Nobody can be e-mailed: every global administrator's address blanked for a moment.
$rootEmail = (string) one('SELECT emailAddress AS e FROM tblUsers WHERE isRootAdmin = 1 AND userID = ?', 'i', [ROOT])['e'];
$otherRoots = q('SELECT userID, emailAddress FROM tblUsers WHERE isRootAdmin = 1 AND isActive = 1');
foreach ($otherRoots as $r) {
    q("UPDATE tblUsers SET emailAddress = '' WHERE userID = ?", 'i', [(int) $r['userID']]);
}
try {
    [$zoeReq, $zoeToken] = seedConfirmable(ORG_Z, ZEE);
    $beforeNobody = noticeCount();
    $nobodyPage   = $confirmPage($zoeToken, KEY_Z);
    ok('H2 nobody to tell: the member\'s page and the request are exactly as without a notice', $nobodyPage['status'] === 200 && statusOf($zoeReq) === 'pending_review' && noticeCount() === $beforeNobody
        && str_contains(bodyText($nobodyPage['body']), 'Request confirmed'));
} finally {
    foreach ($otherRoots as $r) {
        q('UPDATE tblUsers SET emailAddress = ? WHERE userID = ?', 'si', [(string) $r['emailAddress'], (int) $r['userID']]);
    }
}
// The mail route not set up at all: nothing can be queued, and the member's page must not change.
$providerWas = (string) one("SELECT settingValue AS v FROM tblSettings WHERE settingKey = 'mail.provider' AND siteID IS NULL")['v'];
setting('mail.provider', 'ms365');
setting('mail.smtp.host', '');
[$notReq, $notToken] = seedConfirmable(ORG_A, SAM);
$beforeNot = noticeCount();
$notPage   = $confirmPage($notToken);
ok('H3 a mail route that is not set up: the request is confirmed and the page is unchanged', $notPage['status'] === 200 && statusOf($notReq) === 'pending_review' && noticeCount() === $beforeNot);
setting('mail.provider', $providerWas);
setting('mail.smtp.host', 'localhost');

// =============================================================================
head('E. The member\'s page is word for word what it was');
// =============================================================================
$pin = 'Request confirmed Your data erasure request has been confirmed. An administrator will review it and carry it out within one month. Until then your account works exactly as it does now. Return to the portal';
ok('E1 the confirmation page (taken at 60bb6a13) is unchanged', bodyText($page['body']) === $pin, bodyText($page['body']));
ok('E2 and so is the same page when nobody could be told', bodyText($notPage['body']) === $pin);

// =============================================================================
head('F. The Users page: Offboard where the offboarding page opens');
// =============================================================================
/** The chunk of the Users page for one person, from their name to the next row. */
$rowOf = static function (string $html, string $name): string {
    $pos = strpos($html, htmlspecialchars($name, ENT_QUOTES, 'UTF-8'));
    if ($pos === false) {
        return '';
    }
    $end = strpos($html, 'portal-data-row', $pos + 10);

    return substr($html, $pos, $end === false ? 6000 : $end - $pos);
};
$users = req($jar['adm'], 'GET', '/admin/users');
$melRow = $rowOf($users['body'], MEL_NAME);
ok('F1 premise: the Users page lists the member and the erased account', $melRow !== '' && $rowOf($users['body'], '[Deleted User]') !== '');
$expectHref = addr(KEY_A) . '/offboarding/user?id=' . MEL;
ok('F2 the member\'s line shows Offboard, to the offboarding page for that account', str_contains($melRow, 'data-offboard-link') && str_contains($melRow, $expectHref), substr($melRow, 0, 300));
ok('F3 the erased account\'s line shows no Offboard', str_contains($rowOf($users['body'], '[Deleted User]'), 'data-offboard-link') === false);
ok('F4 a global administrator\'s line shows none to an organisation administrator (they cannot offboard it)', str_contains($rowOf($users['body'], 'Rae Root'), 'data-offboard-link') === false);
ok('F5 the link opens', req($jar['adm'], 'GET', '/offboarding/user?id=' . MEL)['status'] === 200);
$offIndex = req($jar['adm'], 'GET', '/offboarding');
$offText  = text($offIndex['body']);
ok('F6 the Offboarding page no longer says to "click the user", and says where the button is',
    $offIndex['status'] === 200 && str_contains($offText, 'click the user') === false && str_contains($offText, 'press Offboard on their line'), substr($offText, 0, 300));
siteSetting(ORG_A, 'offboarding.enabled', 'false');
$usersOff = req($jar['adm'], 'GET', '/admin/users');
ok('F7 with the Offboarding app off: no Offboard button, and the page it would open does not open',
    str_contains($usersOff['body'], 'data-offboard-link') === false && req($jar['adm'], 'GET', '/offboarding/user?id=' . MEL)['status'] !== 200);
$dashOff = req($jar['adm'], 'GET', '/admin');
ok('F8 and the dashboard\'s Offboarding card is gone too', $tile($dashOff['body'], 'offboarding') === false && $tile($dashOff['body'], 'erasure-requests') === true);
siteSetting(ORG_A, 'offboarding.enabled', 'true');

// =============================================================================
head('G (second half). The dashboard with requests waiting');
// =============================================================================
$waiting = (int) one("SELECT COUNT(*) AS n FROM tblErasureRequest WHERE siteID = ? AND status = 'pending_review'", 'i', [ORG_A])['n'];
$first   = (string) one("SELECT DATE_FORMAT(MIN(dueBy), '%e %M %Y') AS d FROM tblErasureRequest WHERE siteID = ? AND status = 'pending_review'", 'i', [ORG_A])['d'];
$dashNow = req($jar['adm'], 'GET', '/admin');
ok('G6 the erasure card counts the waiting requests and gives the earliest due date', str_contains(text($dashNow['body']), $waiting . ' waiting; the first is due by ' . $first . '.') && $waiting >= 4, $waiting . ' / ' . $first);

// =============================================================================
head('I. Planted faults: each new check can go red, and every file is put back byte for byte');
// =============================================================================
/** Change one file in the scratch copy, run $probe, put the file back, and prove it is back (hash). */
$plant = static function (string $label, string $relative, string $from, string $to, callable $probe) use ($child, $sep, &$state): void {
    $file     = $child . $sep . 'web' . $sep . str_replace('/', $sep, $relative);
    $original = (string) file_get_contents($file);
    $hash     = md5($original);
    if (substr_count($original, $from) !== 1) {
        ok($label . ': the planted text occurs exactly once', false, 'found ' . substr_count($original, $from));

        return;
    }
    $state['planted'][$file] = $original;
    file_put_contents($file, str_replace($from, $to, $original));
    try {
        $redWhenPlanted = $probe() === false;
    } finally {
        file_put_contents($file, $original);
        unset($state['planted'][$file]);
    }
    ok($label . ': red when planted', $redWhenPlanted);
    ok($label . ': the file is back byte for byte', md5((string) file_get_contents($file)) === $hash);
};

// Probe for A: a fresh member follows a fresh link; it passes when the right people are told once and the member is not named.
$probeA = static function () use ($expectA, $confirmPage, $queuedTo): bool {
    [$id, $tok] = seedConfirmable(ORG_A, SAM);
    q("UPDATE tblErasureRequest SET status = 'cancelled' WHERE userID = ? AND requestID <> ?", 'ii', [SAM, $id]);
    $before = count(notices());
    $confirmPage($tok);

    return count(array_slice(notices(), $before)) === count($expectA);
};
ok('I0 the probe for A passes unplanted', $probeA() === true);
$plant('I1 the call in erasure-confirm.php removed', '_apps/account/erasure-confirm.php', 'ErasureNotice::requestWaiting($db, $requestSite, $requestId);', '$requestSite = $requestSite;', $probeA);

$probeName = static function () use ($confirmPage, $keyText): bool {
    $mel = one('SELECT fullName AS n FROM tblUsers WHERE userID = ?', 'i', [SAM])['n'];
    [$id, $tok] = seedConfirmable(ORG_A, SAM);
    $before = count(notices());
    $confirmPage($tok);
    $new = array_slice(notices(), $before);
    foreach ($new as $row) {
        if (str_contains(unsealed((string) $row['bodyText'], $keyText), (string) $mel) === true) {
            return false;
        }
    }

    return $new !== [];
};
ok('I2 the probe for the body passes unplanted', $probeName() === true);
$plant(
    'I3 the member\'s name put into the text',
    '_core/ErasureNotice.php',
    "\$body = 'Somebody in ' . \$name . ' has asked",
    "\$body = (string) (\$db->query('SELECT subjectName AS n FROM tblErasureRequest WHERE requestID = ' . \$requestId)->fetch_assoc()['n'] ?? '') . ' in ' . \$name . ' has asked",
    $probeName
);

$probeAdminRoute = static function () use ($jar): bool {
    $before = count(notices());
    [$id] = seedConfirmable(ORG_A, SAM);
    $list = req($jar['adm'], 'GET', '/admin/erasure-requests');
    req($jar['adm'], 'POST', '/admin/erasure-requests/process', ['csrf_token' => token($list['body']), 'requestID' => $id, 'action' => 'confirm']);
    q("UPDATE tblErasureRequest SET status = 'cancelled' WHERE requestID = ?", 'i', [$id]);

    return count(notices()) === $before;
};
ok('I4 the probe for an administrator route passes unplanted', $probeAdminRoute() === true);
$plant(
    'I5 a notice sent on an administrator route too',
    '_apps/admin/erasure/process.php',
    '$result = GdprEraser::confirmOnBehalf($requestId, $siteId, $adminId);',
    '$result = GdprEraser::confirmOnBehalf($requestId, $siteId, $adminId); \Portal\Core\ErasureNotice::requestWaiting(\Portal\Core\App::db(), $siteId, $requestId);',
    $probeAdminRoute
);

$probeRace = static function () use ($db, $confirmPage): bool {
    [$id, $tok] = seedConfirmable(ORG_A, SAM);
    $db->query("CREATE TRIGGER `selftest_notice_race` BEFORE UPDATE ON `tblErasureRequest` FOR EACH ROW SET NEW.status = IF(NEW.status = 'pending_review' AND NEW.requestID = {$id}, 'cancelled', NEW.status)");
    $before = count(notices());
    $confirmPage($tok);
    $db->query('DROP TRIGGER IF EXISTS `selftest_notice_race`');

    return count(notices()) === $before;
};
ok('I6 the probe for a request that moved on passes unplanted', $probeRace() === true);
$plant(
    'I7 the "still waiting" re-read removed',
    '_core/ErasureNotice.php',
    " AND `status` = \\'pending_review\\' LIMIT 1'\n            );\n            if (\$stmt === false) {\n                return 0;",
    " LIMIT 1'\n            );\n            if (\$stmt === false) {\n                return 0;",
    $probeRace
);

$probeOffboard = static function () use ($jar): bool {
    siteSetting(ORG_A, 'offboarding.enabled', 'false');
    $html = req($jar['adm'], 'GET', '/admin/users')['body'];
    siteSetting(ORG_A, 'offboarding.enabled', 'true');

    return str_contains($html, 'data-offboard-link') === false;
};
ok('I8 the probe for the app being off passes unplanted', $probeOffboard() === true);
$plant('I9 Offboard drawn whatever the app switch says', '_apps/admin/users/index.php', '<?php if ($offboardingOpens === true): ?>', '<?php if (true): ?>', $probeOffboard);

$probeBackups = static function () use ($jar, $tile): bool {
    return $tile(req($jar['adm'], 'GET', '/admin')['body'], 'backups') === false;
};
ok('I10 the probe for the Backups card passes unplanted', $probeBackups() === true);
$plant('I11 the Backups card shown to every administrator', '_apps/admin/index.php', "App::isAdmin() === true && App::isRootAdmin() === true, 'colour' => 'secondary', 'icon' => 'fa-floppy-disk'", "App::isAdmin() === true, 'colour' => 'secondary', 'icon' => 'fa-floppy-disk'", $probeBackups);

$probeMenu = static function () use ($jar): bool {
    return str_contains(req($jar['adm'], 'GET', '/admin/users')['body'], addr(KEY_A) . '/admin/erasure-requests');
};
ok('I12 the probe for the menu link passes unplanted', $probeMenu() === true);
$plant('I13 the menu link removed', '_core/templates/nav.php', "Site::url('admin/erasure-requests')", "Site::url('admin/zzz-erasure-requests')", $probeMenu);

// =============================================================================
echo "\n" . ($fail === 0 ? 'ALL PASSED' : 'FAILED') . " — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
