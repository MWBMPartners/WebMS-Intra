<?php
// Path: tools/come-back-addresses-selftest.php
/**
 * -----------------------------------------------------------------------------
 * "Come back to" addresses on three older pages — self-test 🔙🛡️ (#620)
 * -----------------------------------------------------------------------------
 * Three pages accepted an address to send a person on to, each with a judge
 * of its own, and every judge let something through:
 *
 *   - /payments/checkout  took "/<tab>/outside.example" (a browser removes the
 *     tab and reads "//outside.example", another site), and any path at all,
 *     another organisation's included;
 *   - /assets/event-assign took a ".." part that leaves the organisation;
 *   - /newsletter/track/click forwarded to ANY web address in its link.
 *
 * The first two now ask Auth::safeRedirectUrl(), the one judge every sign-in
 * page uses; the click counter forwards only to an address recorded with the
 * newsletter (tblNewsletterLink, migration 268). This file DRIVES the real
 * pages over real HTTP and reads where each one sends the browser.
 *
 * WHAT IT CHECKS (the issue's list, for each page)
 * ------------------------------------------------
 * The page's own address is accepted — with an organisation's part (the "first
 * part of the address" way of telling organisations apart) and without one (a
 * single organisation). Then, from a page inside organisation B and from the
 * default organisation with no part, and with one organisation only:
 * "//outside.example/x", "https://outside.example/", a backslash, a tab before
 * the slashes, a ".." part (also written "%2e%2e"), and another organisation's
 * address. Each must end on the organisation's own front page — EXCEPT on the
 * asset page, where a refused address ends on that asset's own page, which is
 * where the result of the assign/unassign that DID happen is printed (the
 * front page prints no message). Both are inside the organisation; that is
 * the property the issue asks for.
 *
 * With ONE organisation there is no "another organisation", so that case is
 * left out there; it is run where organisations are told apart by the first
 * part of the address, from inside one and from the default one.
 *
 * The newsletter: a real "Send" through the real page, with click counting on,
 * records the newsletter's two recordable links (their "&amp;" decoded) and
 * NOT a third that holds a space; the recipient's copy (read through a
 * test-only extra in the router, which calls the real
 * Newsletter::personalise() on the real rendered newsletter) carries the two
 * as counted links with their tokens and the third EXACTLY as written; a counted link
 * for one of them forwards to it and marks the recipient as having clicked;
 * and everything else gets the "this link cannot be used" page with 404 and
 * NO redirect, and counts nothing: an old-style link carrying the address
 * ("u="), each address in the issue's list in that form, a made-up token, a
 * token sent as a list, a token in capitals, a token from ANOTHER newsletter,
 * a stored address that is not a web address (a row put in by hand, as a
 * restore could), and a stored ftp:// address, which fails ONLY the
 * http-or-https rule — so the address is judged again whenever it is read.
 *
 * SAFETY
 * ------
 * - It writes rows, so it REFUSES unless the database's name starts with
 *   `selftest_`. Its people, organisations, assets and newsletters are
 *   numbered 962000 to 962999 and are removed at the start and the end.
 * - It changes installation-wide settings and puts each back as it was.
 *   Do not run it at the same moment as another self-test on the same
 *   database.
 * - Mail is pointed at an SMTP server with no name, so a newsletter "sent" by
 *   this test goes nowhere and fails at once: no network, no `sendmail`.
 * - The scratch copy of web/ is a NEW folder under SELFTEST_WEB_DIR (or the
 *   system's temporary folder), deleted at the end only while it still holds
 *   this run's marker. `web/_auth_keys` is never read or copied.
 *
 * WHAT THIS CANNOT PROVE
 * ----------------------
 * - A real browser: it reads the Location header the server sends. That a
 *   browser removes a tab, or reads "/\" as "//", is the WHATWG URL standard,
 *   not measured here.
 * - Apache: the router imitates .htaccess.
 * - The other two ways of telling organisations apart (the sign-in session, an
 *   address of each organisation's own): there the path says nothing about the
 *   organisation, and the judge's organisation rule does not run. They are
 *   covered for the sign-in pages by tools/site-addresses-selftest.php part N.
 * - That the DELIVERED e-mail carries exactly what D02b and D02c read. No
 *   e-mail is delivered here (the mail route has no server, so the send
 *   fails at once). D02b/D02c read the recipient's copy by calling the real
 *   Newsletter::personalise() on the real Newsletter::renderHtml() through a
 *   test-only extra in the router — the same two calls dispatch() makes — and
 *   D01 reads the rows the real Send wrote. That dispatch() hands that copy
 *   to the mail service unchanged is read from the code, not driven here.
 *
 * Run:  SELFTEST_DB_NAME=selftest_comeback SELFTEST_DB_PORT=… SELFTEST_DB_PASS=…
 *       SELFTEST_WEB_PORT=11320 php tools/come-back-addresses-selftest.php
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/620
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A run that makes fewer has skipped some, and fails. */
const EXPECTED = 74;

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
$sep       = DIRECTORY_SEPARATOR;
$repo      = (string) realpath(dirname(__DIR__));
$webSrc    = $repo . $sep . 'web';
$parentRaw = getenv('SELFTEST_WEB_DIR');
$parent    = realpath(($parentRaw === false || $parentRaw === '') ? sys_get_temp_dir() : (string) $parentRaw);
if ($parent === false || is_dir($parent) === false) {
    refuse('the scratch parent folder (SELFTEST_WEB_DIR) does not exist');
}
if (str_starts_with($repo . $sep, $parent . $sep) === true || str_starts_with($parent . $sep, $repo . $sep) === true) {
    refuse("the scratch parent folder '{$parent}' and the repository must not contain each other");
}
$portRaw = getenv('SELFTEST_WEB_PORT');
$port    = ($portRaw === false || $portRaw === '') ? 11320 : (int) $portRaw;
if ($port < 1024 || $port > 65535) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65535');
}
$dbHost = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
$dbUser = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
$dbPass = getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '';
$dbPort = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
$db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
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
const ORG_A   = 962001;
const ORG_B   = 962002;
const KEY_A   = 'selftest-cb-a';
const KEY_B   = 'selftest-cb-b';
const BADMIN  = 962011; // an administrator of organisation B only
const U1ADMIN = 962012; // an administrator of the default organisation (1) only
const MEMBER1 = 962013; // a member of the default organisation: the newsletter's recipient
const ASSET_B = 962021;
const ASSET_1 = 962022;
const NEWS_1  = 962031; // sent through the real page in this test
const NEWS_B  = 962032; // another newsletter, whose link row is put in directly
const B       = '/' . KEY_B;
const A       = '/' . KEY_A;
/** The link the other newsletter holds, and its token. */
const OTHER_TOKEN = '0123456789abcdef0123456789abcdef';
/** A row put in by hand whose address is not a web address. */
const BAD_ROW_TOKEN = 'fedcba9876543210fedcba9876543210';
/** A row put in by hand that fails ONLY the http-or-https rule: a well-formed ftp address with a host. */
const FTP_ROW_TOKEN = 'abcdefabcdefabcdefabcdefabcdef01';
/** A link in the newsletter that cannot be recorded (a space), so it must be sent exactly as written, uncounted. */
const UNRECORDABLE = 'https://example.org/has space';

/** Remove everything this test made. @return int rows of its own left afterwards (0 is right). */
function removeWorld(): int
{
    $columns = q(
        "SELECT TABLE_NAME AS t, COLUMN_NAME AS c, REFERENCED_TABLE_NAME AS r FROM information_schema.KEY_COLUMN_USAGE "
        . "WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IN ('tblUsers', 'tblSites') "
        . "UNION SELECT c.TABLE_NAME, c.COLUMN_NAME, IF(c.COLUMN_NAME = 'userID', 'tblUsers', 'tblSites') FROM information_schema.COLUMNS c "
        . "JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE' "
        . "WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME IN ('userID', 'siteID')"
    );
    $left = 0;
    q('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ([1, 2] as $round) {
            $own = [
                ['tblNewsletterLink', '`newsletterID` IN (' . NEWS_1 . ', ' . NEWS_B . ')'],
                ['tblNewsletterRecipient', '`newsletterID` IN (' . NEWS_1 . ', ' . NEWS_B . ')'],
                ['tblNewsletterContent', '`newsletterID` IN (' . NEWS_1 . ', ' . NEWS_B . ')'],
                ['tblNewsletter', '`newsletterID` IN (' . NEWS_1 . ', ' . NEWS_B . ')'],
                ['tblAssets', '`assetID` IN (' . ASSET_B . ', ' . ASSET_1 . ')'],
            ];
            foreach ($columns as $column) {
                $own[] = [$column['t'], $column['r'] === 'tblUsers'
                    ? '`' . $column['c'] . '` BETWEEN 962000 AND 962999'
                    : '`' . $column['c'] . '` IN (' . ORG_A . ', ' . ORG_B . ')'];
            }
            foreach ($own as [$table, $where]) {
                if ($round === 1) {
                    q('DELETE FROM `' . $table . '` WHERE ' . $where);
                } else {
                    $left += (int) (one('SELECT COUNT(*) AS n FROM `' . $table . '` WHERE ' . $where)['n'] ?? 0);
                }
            }
        }
    } finally {
        q('SET FOREIGN_KEY_CHECKS = 1');
    }

    return $left;
}

// =============================================================================
// ⚙️ Installation-wide settings: each changed one is remembered and put back
// =============================================================================
$state = ['proc' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'settings' => [], 'started' => false, 'cleaned' => false];

/** Set an installation-wide setting, remembering what it was (or that there was none). */
function setting(string $key, string $value): void
{
    global $state;
    $row = one('SELECT settingID, settingValue FROM tblSettings WHERE siteID IS NULL AND settingKey = ?', 's', [$key]);
    if (array_key_exists($key, $state['settings']) === false) {
        $state['settings'][$key] = $row === null ? null : (string) $row['settingValue'];
    }
    if ($row === null) {
        q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, ?, ?, ?, 0)', 'sss', [$key, $value, $value]);
    } else {
        q('UPDATE tblSettings SET settingValue = ? WHERE settingID = ?', 'si', [$value, (int) $row['settingID']]);
    }
}

function setWay(bool $on): void
{
    setting('multisite.enabled', $on === true ? 'true' : 'false');
    setting('multisite.detectionMode', 'path');
}

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
                q('DELETE FROM tblSettings WHERE siteID IS NULL AND settingKey = ?', 's', [$key]);
            } else {
                q('UPDATE tblSettings SET settingValue = ? WHERE siteID IS NULL AND settingKey = ?', 'ss', [$before, $key]);
            }
        }
        removeWorld();
    } catch (Throwable $e) {
        echo 'FAIL — the clean-up could not put the database back: ' . $e->getMessage() . "\n";
    }
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.come-back-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}

/**
 * One real HTTP request to this run's server. Redirects are never followed.
 * `$rawPath` is sent EXACTLY as given (a tab or a backslash in a query value
 * is percent-encoded by the caller), and an answer without this run's header
 * is returned as status -1.
 *
 * @param array<string,mixed>|null $post
 * @return array{status:int, location:string, body:string}
 */
function req(string $jar, string $method, string $rawPath, ?array $post = null): array
{
    global $port, $state;
    $handle = curl_init('http://127.0.0.1:' . $port . $rawPath);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => ['Expect:', 'Accept-Language: en'],
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
    ]);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post ?? [], '', '&', PHP_QUERY_RFC3986));
    }
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle)];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr((string) $raw, 0, $headSize);
    if (preg_match('/^X-Come-Back-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header'];
    }

    // The Location header taken EXACTLY, tabs included (only the line ending is cut).
    $location = '';
    foreach (explode("\n", $head) as $line) {
        if (stripos($line, 'Location:') === 0) {
            $location = rtrim(ltrim(substr($line, 9), ' '), "\r");
        }
    }

    return ['status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE), 'location' => $location, 'body' => substr((string) $raw, $headSize)];
}

/** The form token on a page. */
function token(string $html): string
{
    if (preg_match('/<meta name="csrf-token" content="([0-9a-f]{64})"/', $html, $m) === 1) {
        return $m[1];
    }

    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

function said(array $answer): string
{
    return $answer['status'] . ($answer['location'] === '' ? '' : ' -> ' . json_encode($answer['location']));
}

// =============================================================================
// 🏗️ Build the world, the scratch copy and the server
// =============================================================================
if (portAnswers($port) === true) {
    refuse("something already answers on port {$port}");
}
$state['started'] = true;
register_shutdown_function('cleanup');
removeWorld();

foreach ([[ORG_A, KEY_A, 'Come-back Selftest Alpha'], [ORG_B, KEY_B, 'Come-back Selftest Bravo']] as [$id, $key, $name]) {
    q('INSERT INTO tblSites (siteID, siteKey, siteName, isActive) VALUES (?, ?, ?, 1)', 'iss', [$id, $key, $name]);
}
$people = ['badmin' => BADMIN, 'u1admin' => U1ADMIN, 'member1' => MEMBER1];
foreach ($people as $who => $id) {
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [$id, 'Selftest ' . $who, $who . '.comeback@selftest.invalid']);
}
foreach ([[BADMIN, ORG_B, 1], [U1ADMIN, 1, 1], [MEMBER1, 1, 0]] as [$user, $site, $admin]) {
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, ?, 0)', 'iii', [$user, $site, $admin]);
}
$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
foreach ($people as $who => $id) {
    q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-cb-' . $who, $hash]);
}
foreach ([[ASSET_B, ORG_B], [ASSET_1, 1]] as [$asset, $site]) {
    q('INSERT INTO tblAssets (assetID, siteID, name, publicToken, `currency`) VALUES (?, ?, ?, ?, \'EUR\')', 'iiss', [$asset, $site, 'Selftest come-back asset', bin2hex(random_bytes(16))]);
}
// NEWS_1: two links, one with two query parts (so the HTML holds "&amp;"),
// one in a text block. NEWS_B: one link row, put in directly.
q("INSERT INTO tblNewsletter (newsletterID, siteID, title, subject, status, createdByID) VALUES (?, 1, 'Selftest come-back newsletter', 'Selftest come-back newsletter', 'draft', ?)", 'ii', [NEWS_1, U1ADMIN]);
q("INSERT INTO tblNewsletterContent (newsletterID, blockType, position, payload) VALUES (?, 'cta', 1, ?)", 'is', [NEWS_1, json_encode(['label' => 'Read it', 'url' => 'https://example.org/read?x=1&y=2'])]);
q("INSERT INTO tblNewsletterContent (newsletterID, blockType, position, payload) VALUES (?, 'text', 2, ?)", 'is', [NEWS_1, json_encode(['text' => 'See [more here](https://example.net/more) please.'])]);
// A link isForwardable() refuses (it holds a space) but the counting pattern
// still matches: it must go out exactly as written, with no counted link.
q("INSERT INTO tblNewsletterContent (newsletterID, blockType, position, payload) VALUES (?, 'cta', 3, ?)", 'is', [NEWS_1, json_encode(['label' => 'Odd link', 'url' => UNRECORDABLE])]);
q("INSERT INTO tblNewsletter (newsletterID, siteID, title, subject, status, createdByID) VALUES (?, ?, 'Selftest other newsletter', 'Selftest other newsletter', 'sent', ?)", 'iii', [NEWS_B, ORG_B, BADMIN]);
q('INSERT INTO tblNewsletterLink (newsletterID, linkToken, targetUrl, targetHash) VALUES (?, ?, ?, ?)', 'isss', [NEWS_B, OTHER_TOKEN, 'https://example.com/other', hash('sha256', 'https://example.com/other')]);
// A row nobody's send could write: its address is not a web address.
q('INSERT INTO tblNewsletterLink (newsletterID, linkToken, targetUrl, targetHash) VALUES (?, ?, ?, ?)', 'isss', [NEWS_1, BAD_ROW_TOKEN, 'javascript:alert(1)', hash('sha256', 'javascript:alert(1)')]);
// …and one that fails ONLY the http-or-https rule ("javascript:" above fails three rules at once,
// so it could not show that this one rule is in force on its own).
q('INSERT INTO tblNewsletterLink (newsletterID, linkToken, targetUrl, targetHash) VALUES (?, ?, ?, ?)', 'isss', [NEWS_1, FTP_ROW_TOKEN, 'ftp://example.org/file', hash('sha256', 'ftp://example.org/file')]);

setting('payments.enabled', '1');
setting('newsletter.enabled', '1');
setting('newsletter.trackClicks', '1');
setting('assets.enabled', 'true');
setting('mail.provider', 'smtp');
setting('mail.smtp.host', '');
setting('site.url', 'http://127.0.0.1:' . $port);
setWay(true);

// The scratch copy of web/, with a throwaway connection file and key.
$child = $parent . $sep . 'come-back-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.come-back-selftest-owner', $state['token']); // the marker FIRST
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
$creds = ['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort];
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\n// Written by tools/come-back-addresses-selftest.php for one run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/come-back-addresses-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Come-Back-Selftest: __TOKEN__');
$root = (string) realpath(__DIR__ . '/web/public_html');
// TEST-ONLY EXTRA: what Newsletter::personalise() makes of a newsletter's real
// rendered HTML — the exact text a recipient's copy carries. dispatch() calls
// the same two methods; the e-mail itself is never delivered here, so this is
// how the links IN it are read. Answers only a request carrying this run's
// token; nothing in the portal knows about this header.
if (($_SERVER['HTTP_X_COME_BACK_PERSONALISE'] ?? '') === '__TOKEN__') {
    define('PORTAL_WEBROOT', $root);
    require_once __DIR__ . '/web/_core/bootstrap.php';
    $n = (int) ($_GET['n'] ?? 0); $r = (int) ($_GET['r'] ?? 0); $site = (int) ($_GET['site'] ?? 1);
    header('Content-Type: text/plain; charset=utf-8');
    echo \Portal\Core\Newsletter::personalise(\Portal\Core\Newsletter::renderHtml($n, $site), $n, $r, 'selftest', $site);
    return true;
}
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
$state['proc']     = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1',
        '-d', 'display_errors=0', '-d', 'max_execution_time=120', '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
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

echo "\"Come back to\" addresses on three older pages — self-test (#620)\n";
echo 'port ' . $port . ', PHP ' . PHP_VERSION . ', database ' . (string) one('SELECT VERSION() AS v')['v'] . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";

/** Sign one account in through the real sign-in page, under `$prefix`; returns its cookie jar. */
function signIn(string $who, string $prefix): string
{
    global $child, $sep, $password;
    $jar    = $child . $sep . 'jar-' . $who . '-' . bin2hex(random_bytes(3)) . '.txt';
    $tok    = token(req($jar, 'GET', $prefix . '/login')['body']);
    $answer = req($jar, 'POST', $prefix . '/login', ['csrf_token' => $tok, 'username' => 'selftest-cb-' . $who, 'password' => $password]);
    if ($answer['status'] !== 302 || str_contains($answer['location'], '/login') === true) {
        refuse("signing in as selftest-cb-{$who} did not work (" . said($answer) . ')');
    }

    return $jar;
}

/** A fresh form token: Auth::verifyCsrf() replaces it on every accepted post. */
function freshToken(string $jar, string $prefix): string
{
    $tok = token(req($jar, 'GET', $prefix . '/assets/item?id=' . ($prefix === B ? ASSET_B : ASSET_1))['body']);
    if ($tok === '') {
        refuse('no form token on the asset page under "' . $prefix . '/"');
    }

    return $tok;
}

/** Where /payments/checkout sends somebody whose amount is too small, for one return_to (null = none sent). */
function checkoutGoesTo(string $jar, string $prefix, ?string $returnTo): array
{
    $post = ['csrf_token' => freshToken($jar, $prefix), 'amount' => '0', 'purpose' => 'giving'];
    if ($returnTo !== null) {
        $post['return_to'] = $returnTo;
    }

    return req($jar, 'POST', $prefix . '/payments/checkout', $post);
}

/** Where /assets/event-assign sends somebody after an unassign, for one returnTo (null = none sent). */
function assignGoesTo(string $jar, string $prefix, int $asset, ?string $returnTo): array
{
    $post = ['csrf_token' => freshToken($jar, $prefix), 'assetID' => (string) $asset, 'action' => 'unassign', 'assignmentID' => '0'];
    if ($returnTo !== null) {
        $post['returnTo'] = $returnTo;
    }

    return req($jar, 'POST', $prefix . '/assets/event-assign', $post);
}

/**
 * The issue's list of addresses that must NOT be followed, written from inside
 * the organisation whose part is `$prefix` ('' for none). `$other` is another
 * organisation's address, or null where there is no other organisation.
 *
 * @return array<string,string>
 */
function hostile(string $prefix, ?string $other): array
{
    $list = [
        '"//outside.example/x"'                  => '//outside.example/x',
        '"https://outside.example/"'             => 'https://outside.example/',
        'a backslash ("/\\outside.example")'     => '/\\outside.example',
        'a tab before the slashes'               => "/\t/outside.example",
        'a ".." part leaving the organisation'   => $prefix . '/assets/../../outside',
        'a ".." part written "%2e%2e"'           => $prefix . '/assets/%2e%2e/%2e%2e/outside',
    ];
    if ($other !== null) {
        $list['another organisation\'s address (' . $other . ')'] = $other;
    }

    return $list;
}

// =============================================================================
// A. Organisations told apart by the first part of the address: inside B
// =============================================================================
head('A. Inside organisation B ("' . B . '/...")');
$jarB  = signIn('badmin', B);
$frontB = B . '/';
$got   = checkoutGoesTo($jarB, B, B . '/giving/give');
ok('A01 checkout: the page\'s own address WITH the organisation\'s part is followed', $got['status'] === 302 && $got['location'] === B . '/giving/give', said($got));
$got = checkoutGoesTo($jarB, B, null);
ok('A02 checkout: with no address, the organisation\'s own front page', $got['status'] === 302 && $got['location'] === $frontB, said($got));
foreach (hostile(B, A . '/giving/give') as $label => $address) {
    $got = checkoutGoesTo($jarB, B, $address);
    ok('A03 checkout refuses ' . $label . ': ends on B\'s own front page', $got['status'] === 302 && $got['location'] === $frontB, said($got));
}
$got = checkoutGoesTo($jarB, B, '/giving/give');
ok('A04 checkout refuses an address with NO organisation\'s part from inside B (the default organisation\'s page)', $got['status'] === 302 && $got['location'] === $frontB, said($got));

$itemB = B . '/assets/item?id=' . ASSET_B;
$got   = assignGoesTo($jarB, B, ASSET_B, B . '/calendar/event?slug=x');
ok('A05 asset page: the calendar event page WITH the organisation\'s part is followed', $got['status'] === 302 && $got['location'] === B . '/calendar/event?slug=x', said($got));
$got = assignGoesTo($jarB, B, ASSET_B, null);
ok('A06 asset page: with no address, this asset\'s own page', $got['status'] === 302 && $got['location'] === $itemB, said($got));
foreach (hostile(B, A . '/assets') as $label => $address) {
    $got = assignGoesTo($jarB, B, ASSET_B, $address);
    ok('A07 asset page refuses ' . $label . ': ends on the asset\'s own page in B', $got['status'] === 302 && $got['location'] === $itemB, said($got));
}

// =============================================================================
// B. Organisations told apart by the first part: the DEFAULT organisation
// =============================================================================
head('B. The default organisation, with no part of its own');
$jar1 = signIn('u1admin', '');
$got  = checkoutGoesTo($jar1, '', '/giving/give');
ok('B01 checkout: the page\'s own address WITHOUT an organisation\'s part is followed', $got['status'] === 302 && $got['location'] === '/giving/give', said($got));
foreach (hostile('', B . '/giving/give') as $label => $address) {
    $got = checkoutGoesTo($jar1, '', $address);
    ok('B02 checkout refuses ' . $label . ': ends on the front page', $got['status'] === 302 && $got['location'] === '/', said($got));
}
$item1 = '/assets/item?id=' . ASSET_1;
$got   = assignGoesTo($jar1, '', ASSET_1, '/calendar/event?slug=y');
ok('B03 asset page: the calendar event page WITHOUT an organisation\'s part is followed', $got['status'] === 302 && $got['location'] === '/calendar/event?slug=y', said($got));
foreach (hostile('', B . '/assets') as $label => $address) {
    $got = assignGoesTo($jar1, '', ASSET_1, $address);
    ok('B04 asset page refuses ' . $label . ': ends on the asset\'s own page', $got['status'] === 302 && $got['location'] === $item1, said($got));
}

// =============================================================================
// C. ONE organisation (no first parts in use)
// =============================================================================
head('C. One organisation only');
setWay(false);
$jarS = signIn('u1admin', '');
$got  = checkoutGoesTo($jarS, '', '/projects/my-pledges');
ok('C01 checkout: the page\'s own address is followed', $got['status'] === 302 && $got['location'] === '/projects/my-pledges', said($got));
foreach (hostile('', null) as $label => $address) {
    $got = checkoutGoesTo($jarS, '', $address);
    ok('C02 checkout refuses ' . $label . ': ends on the front page', $got['status'] === 302 && $got['location'] === '/', said($got));
}
$got = assignGoesTo($jarS, '', ASSET_1, '/calendar/event?slug=z');
ok('C03 asset page: the calendar event page is followed', $got['status'] === 302 && $got['location'] === '/calendar/event?slug=z', said($got));
foreach (hostile('', null) as $label => $address) {
    $got = assignGoesTo($jarS, '', ASSET_1, $address);
    ok('C04 asset page refuses ' . $label . ': ends on the asset\'s own page', $got['status'] === 302 && $got['location'] === $item1, said($got));
}

// =============================================================================
// D. The newsletter's click counter
// =============================================================================
head('D. The newsletter click counter (one organisation)');
$edit = req($jarS, 'GET', '/newsletter/edit?id=' . NEWS_1);
$send = req($jarS, 'POST', '/newsletter/send', ['csrf_token' => token($edit['body']), 'newsletterID' => (string) NEWS_1]);
$rows = q('SELECT linkToken, targetUrl FROM tblNewsletterLink WHERE newsletterID = ? AND linkToken NOT IN (?, ?) ORDER BY targetUrl', 'iss', [NEWS_1, BAD_ROW_TOKEN, FTP_ROW_TOKEN]);
$recipient = one('SELECT recipientID, clickedAt FROM tblNewsletterRecipient WHERE newsletterID = ? AND userID = ?', 'ii', [NEWS_1, MEMBER1]);
ok(
    'D01 a real Send, with click counting on, recorded the newsletter\'s two recordable links (and NOT the one holding a space), with "&amp;" decoded to "&"',
    array_column($rows, 'targetUrl') === ['https://example.net/more', 'https://example.org/read?x=1&y=2']
        && count(array_filter(array_column($rows, 'linkToken'), static fn (string $t): bool => preg_match('/^[0-9a-f]{32}$/', $t) === 1)) === 2,
    'send answered ' . said($send) . '; rows: ' . json_encode($rows)
);
ok('D02 the member was locked in as a recipient, and has not clicked', $recipient !== null && $recipient['clickedAt'] === null, json_encode($recipient));
// What the recipient's copy really carries (see the router's test-only extra).
$copy = '';
$handle = curl_init('http://127.0.0.1:' . $port . '/?n=' . NEWS_1 . '&r=' . (int) ($recipient['recipientID'] ?? 0) . '&site=1');
curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => ['X-Come-Back-Personalise: ' . $state['token']]]);
$copy = (string) curl_exec($handle);
$hrefs = preg_match_all('/href="([^"]*)"/', $copy, $mm) > 0 ? array_map(static fn (string $h): string => html_entity_decode($h, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $mm[1]) : [];
$counted = array_values(array_filter($hrefs, static fn (string $h): bool => str_contains($h, '/newsletter/track/click') === true));
$wantL   = array_map(static fn (array $row): string => (string) $row['linkToken'], $rows);
$gotL    = array_map(static fn (string $h): string => (string) (preg_match('/[?&]l=([^&]*)/', $h, $lm) === 1 ? $lm[1] : '(none)'), $counted);
sort($wantL);
sort($gotL);
ok(
    'D02b the copy\'s two recordable links are counted links carrying their recorded tokens, and none carries the address ("u=")',
    count($counted) === 2 && $gotL === $wantL && array_filter($counted, static fn (string $h): bool => str_contains($h, 'u=') === true) === [],
    'hrefs: ' . json_encode($hrefs)
);
ok(
    'D02c the link that cannot be recorded goes out EXACTLY as written, not counted and not turned into an empty counted link',
    in_array(UNRECORDABLE, $hrefs, true) === true && count($hrefs) === 4,
    'hrefs: ' . json_encode($hrefs)
);
$rid   = (int) ($recipient['recipientID'] ?? 0);
$token = (string) ($rows[1]['linkToken'] ?? '');
$click = static fn (string $query): array => req($jarS, 'GET', '/newsletter/track/click?' . $query);
$refused = static fn (array $a): bool => $a['status'] === 404 && $a['location'] === ''
    && str_contains($a['body'], 'This link cannot be used') === true && str_contains($a['body'], 'This link is no longer valid') === true;

$old = ['n' => NEWS_1, 'r' => $rid];
foreach (hostile('', B . '/') + ['the newsletter\'s OWN link, but in the old "u=" form' => 'https://example.org/read?x=1&y=2'] as $label => $address) {
    $got = $click(http_build_query($old + ['u' => $address], '', '&', PHP_QUERY_RFC3986));
    ok('D03 an old-style link carrying ' . $label . ' is refused: "no longer valid", 404, no redirect', $refused($got) === true, said($got));
}
foreach ([
    'a made-up token'                         => 'n=' . NEWS_1 . '&r=' . $rid . '&l=' . str_repeat('a', 32),
    'the token sent as a list (l[]=…)'        => 'n=' . NEWS_1 . '&r=' . $rid . '&l%5B%5D=' . $token,
    'the token in capitals'                   => 'n=' . NEWS_1 . '&r=' . $rid . '&l=' . strtoupper($token),
    'a token from ANOTHER newsletter'         => 'n=' . NEWS_1 . '&r=' . $rid . '&l=' . OTHER_TOKEN,
    'its own token under another newsletter'  => 'n=' . NEWS_B . '&r=' . $rid . '&l=' . $token,
    'a stored address that is not a web address (a row put in by hand)' => 'n=' . NEWS_1 . '&r=' . $rid . '&l=' . BAD_ROW_TOKEN,
    'a stored address failing ONLY the http-or-https rule (ftp://, by hand)' => 'n=' . NEWS_1 . '&r=' . $rid . '&l=' . FTP_ROW_TOKEN,
    'no token at all'                         => 'n=' . NEWS_1 . '&r=' . $rid,
] as $label => $query) {
    $got = $click($query);
    ok('D04 ' . $label . ' is refused: "no longer valid", 404, no redirect', $refused($got) === true, said($got));
}
$after = one('SELECT clickedAt FROM tblNewsletterRecipient WHERE recipientID = ?', 'i', [$rid]);
ok('D05 no refused link counted a click', $after !== null && $after['clickedAt'] === null, json_encode($after));
$got = $click('n=' . NEWS_1 . '&r=' . $rid . '&l=' . $token);
ok('D06 the newsletter\'s own link forwards to exactly its address, "&" intact', $got['status'] === 302 && $got['location'] === 'https://example.org/read?x=1&y=2', said($got));
$after = one('SELECT clickedAt FROM tblNewsletterRecipient WHERE recipientID = ?', 'i', [$rid]);
ok('D07 and that click is counted', $after !== null && $after['clickedAt'] !== null, json_encode($after));

head('E. The click counter with an organisation\'s part');
setWay(true);
$got = req($jarB, 'GET', B . '/newsletter/track/click?n=' . NEWS_B . '&r=0&l=' . OTHER_TOKEN);
ok('E01 under "' . B . '/", a newsletter\'s own link forwards to its address', $got['status'] === 302 && $got['location'] === 'https://example.com/other', said($got));
$got = req($jarB, 'GET', B . '/newsletter/track/click?n=' . NEWS_B . '&r=0&u=' . rawurlencode('https://outside.example/'));
ok('E02 under "' . B . '/", an old-style link naming an address is refused', $refused($got) === true, said($got));

// =============================================================================
head('Summary');
$total = $pass + $fail;
echo "{$pass} passed, {$fail} failed, of {$total}.\n";
if ($total !== EXPECTED) {
    echo "FAIL — {$total} checks ran where " . EXPECTED . " were expected: a part was skipped, or the list changed without this number.\n";
    exit(1);
}
cleanup();
$left = removeWorld();
if ($left !== 0) {
    echo "FAIL — {$left} row(s) of this test's world were left behind.\n";
    exit(1);
}
exit($fail === 0 ? 0 : 1);
