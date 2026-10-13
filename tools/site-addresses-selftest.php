<?php
// Path: tools/site-addresses-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Addresses that stay inside the organisation, on the REAL pages — self-test 🔗🏢
 * (#536, the link sweep)
 * -----------------------------------------------------------------------------
 * One installation can serve several organisations, and one way of telling
 * them apart is by the first part of the address: "/north/calendar" is the
 * North organisation's calendar. Every link, form target, redirect and script
 * request the portal writes then has to carry that first part, or the person
 * who follows it lands in the DEFAULT organisation.
 *
 * `tools/audit-checks/check_site_aware_addresses.py` reads the code as text.
 * Text cannot see an address joined from pieces, kept in a variable or read
 * from the database. This file runs the portal and reads what it ANSWERS.
 *
 * It uses the page-driving harness of `tools/site-modes-selftest.php`: it
 * copies `web/` into a NEW scratch folder, gives the copy a throwaway
 * connection file, starts PHP's built-in web server on it behind a small
 * router that imitates `.htaccess`, signs real accounts in through the real
 * sign-in page, and sends real requests.
 *
 * WHAT IT CHECKS (a full run)
 * ---------------------------
 * With two made-up organisations told apart by the first part of the address:
 *   F. The shared page furniture. On the front page, every address in the
 *      page head, the menu and the footer keeps the organisation's part, or
 *      is a real file the web server answers itself; none carries the part
 *      twice, another organisation's part, or a part in front of a real file.
 *      The header prints the organisation's front address once, for the
 *      script files; a page with no organisation's part prints no such tag.
 *      A global administrator's menu offers the organisations page under
 *      the same part. An organisation's part typed in capitals reaches the
 *      same page, and still stops somebody who is not a member.
 *   S. The sign-in flow. A signed-out visitor at "/b/account" is sent to
 *      "/b/login" and is back at "/b/account" after signing in; with nowhere
 *      to go back to they land on the organisation's own front page. Then
 *      every way a "come back to" address could lead away is tried through
 *      the real sign-in page, and each must end at the organisation's own
 *      front page: another site, "//host", a backslash, a tab, a scheme,
 *      another organisation's part, no part at all, and ".." written three
 *      ways. From the default organisation's sign-in page an address with
 *      another organisation's part is refused too. Signing out goes to the
 *      organisation's own front page in all three of the page's ways out.
 *      A sign-in through Microsoft that fails goes back to the
 *      organisation's own sign-in page.
 *   E. The error pages (404, 403, 500, through the page router and as the
 *      web server's own error page), the plain page shown to somebody the
 *      members-only gate stops, and the "closed for maintenance" page all
 *      keep the organisation's part.
 *   J. Scripts. The two inline scripts the shared templates print ask the
 *      organisation's own addresses. Four script FILES are read to see
 *      that every address they ask for is built by Portal.url():
 *      portal-tour.js, report-builder.js, event-hub-upload.js and
 *      push-subscribe.js. (This used to say "the script FILES call
 *      Portal.url()" while the last two held four bare requests that
 *      nothing here read.) NOT ALL OF THEM DO: livechat-widget.js, the
 *      chat box on /live, joins its four addresses onto the address of the
 *      page it runs on. Where first parts are in use that asks the DEFAULT
 *      organisation, and nothing here reads that file (found 13 October
 *      2026, not fixed yet). portal.js is RUN (in Node.js) to
 *      see what Portal.url() gives with and without the header's tag, and
 *      that it refuses a tag or a route a browser would read as another
 *      site.
 *   K. A hostile organisation key. Keys are held to a-z, 0-9 and "-" when
 *      an organisation is made, so this one is put straight into the
 *      database: it holds both quotes, an angle bracket, an ampersand and a
 *      backtick. Every copy of it that a page prints must be escaped whole
 *      for where it sits: for HTML in the page, for a script inside a
 *      <script> block. Nothing fed such a key through the pages before
 *      (13 October 2026), so taking the escaping out of
 *      Router::urlForScript() went unnoticed.
 *   C. The crawl. Signed in as an administrator of organisation B with every
 *      app switched on, it asks for every registered page address under
 *      "/b/" and reads every link, form target, picture or script address and
 *      redirect in the answers. On a page whose folder is still on the
 *      check's pending list (`site_aware_addresses_pending.txt`) only the
 *      shared furniture is judged; on every other page, everything is. Then
 *      the same crawl with no first parts in use: no address may hold one.
 * With no first parts in use (the sign-in session, an address of its own
 * each, and one organisation only):
 *   N. The sign-in redirects are what they always were, and a "come back
 *      to" address is still refused when it leads to another site.
 *
 * FOR ONE SLICE OF THE SWEEP:  --only _apps/venues _apps/calendar
 * ---------------------------------------------------------------
 * Runs part C alone, for the pages whose files are in the places named, and
 * judges EVERYTHING on them, pending list or not. That is the proof a sweep
 * of those folders needs. Places are written from `web/`, exactly as for the
 * check. It says plainly that it is not a full run.
 *
 * WHAT COUNTS AS "KEEPS THE ORGANISATION'S PART"
 * ----------------------------------------------
 * The same answer the check gives, asked of the check itself so the two
 * cannot disagree: an address the web server answers itself (a real file or
 * folder in the web folder, and the three fixed by web standards) takes no
 * part (`--answers`); and an address a line of code excuses with
 * "address-check: allow" is accepted here too (`--allowed`), but ONLY
 * inside the text that very line prints. It used to be accepted wherever it
 * turned up, on every page: one line marking "/dashboard" as sample text
 * would have excused every real bare link to "/dashboard" in the portal.
 * See printedBy() for how a line's printed text is told, and for the one
 * kind of line it cannot follow (a line of PHP that prints nothing as
 * written: an address it excuses is not excused here at all).
 *
 * SAFETY
 * ------
 * - It writes rows, so it REFUSES unless the database's name starts with
 *   `selftest_`. Every account it makes is numbered 919000 to 919999 and its
 *   three organisations are its own; they are removed at the START (in case an
 *   earlier run was interrupted) and at the END, together with any row a
 *   page made for them while it was being read.
 * - It changes three portal-wide settings (`multisite.enabled`,
 *   `multisite.detectionMode`, `portal.maintenance.active`) and puts them
 *   back. So do NOT run it at the same moment as another self-test on the
 *   same database.
 * - THE CRAWL SENDS A PLAIN REQUEST TO EVERY REGISTERED ADDRESS. It follows
 *   no link and posts no form, and it leaves out "logout" and the scheduled
 *   jobs under "cron/". But if some page acts on a plain request, it acts:
 *   that is the other reason for a throwaway database.
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
 * - Apache: the router only imitates `.htaccess`, and it imitates the web
 *   server's own error page by loading `error.php` for the address asked for.
 * - A real browser. Nothing is clicked and no script runs in a page; an
 *   address a script builds while the page runs is not seen. Portal.url() is
 *   run in Node.js with a made-up page, not in a browser.
 * - A page that answers only a form being posted, or only with an "id" that
 *   exists: the crawl asks each address once, plainly, in an organisation
 *   that holds no records. What such a page prints when it has something to
 *   show is not read.
 * - An address inside an e-mail, a notification or a downloaded file.
 * - Sign-in through Microsoft, Google or a passkey: they need outside
 *   servers. So the two redirects to the two-step sign-in page after a
 *   Microsoft or Google sign-in are NOT run here; only the check that
 *   reads the code (C10) holds them. The redirect after one FAILS is run.
 * - That an address in an attribute a browser runs as a script (onclick
 *   and the like) is safe with a hostile organisation key. Part K reads
 *   <script> blocks and the rest of the page, not such attributes.
 * - That a page is byte for byte unchanged with no first parts in use: that
 *   needs the code from BEFORE the change beside it, so it is a separate
 *   comparison, not part of this file.
 * - MariaDB.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             SELFTEST_WEB_DIR=/a/quiet/folder php tools/site-addresses-selftest.php
 *                 [--only <place> [<place> …]] [--no-crawl]
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default
 *         root), SELFTEST_WEB_PORT (default 9650). The database must already
 *         hold `web/_sql/full_schema.sql`. Needs PHP's curl extension,
 *         `proc_open()`, python3 (for the check) and, for a full run, Node.js.
 *         --no-crawl leaves part C out, for trying one fault quickly. It
 *         says plainly that it is not a full run.
 * Exit:   0 only when every check that was meant to run ran and passed; 1
 *         otherwise, including every refusal to run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/536
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks each kind of run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_FULL     = 103;
const EXPECTED_NO_CRAWL = 93;
const EXPECTED_ONLY     = 11;

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

// What was asked.
$arguments = array_slice($argv, 1);
$noCrawl   = in_array('--no-crawl', $arguments, true);
$only      = [];
if (in_array('--only', $arguments, true) === true) {
    foreach (array_slice($arguments, (int) array_search('--only', $arguments, true) + 1) as $word) {
        if (str_starts_with($word, '--') === true) {
            break;
        }
        $only[] = trim($word, '/');
    }
    if ($only === []) {
        refuse('--only needs at least one place, written from web/ (for example _apps/venues)');
    }
    if ($noCrawl === true) {
        refuse('--only runs the crawl and nothing else, so it cannot be given with --no-crawl');
    }
}
$expected = $only !== [] ? EXPECTED_ONLY : ($noCrawl === true ? EXPECTED_NO_CRAWL : EXPECTED_FULL);

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
$checkFile = $repo . $sep . 'tools' . $sep . 'audit-checks' . $sep . 'check_site_aware_addresses.py';
$parentRaw = getenv('SELFTEST_WEB_DIR');
$parent    = realpath(($parentRaw === false || $parentRaw === '') ? sys_get_temp_dir() : (string) $parentRaw);
if ($parent === false || is_dir($parent) === false) {
    refuse('the scratch parent folder (SELFTEST_WEB_DIR) does not exist');
}
if (str_starts_with($repo . $sep, $parent . $sep) === true || str_starts_with($parent . $sep, $repo . $sep) === true) {
    refuse("the scratch parent folder '{$parent}' and the repository must not contain each other; choose another with SELFTEST_WEB_DIR");
}
$portRaw = getenv('SELFTEST_WEB_PORT');
$port    = ($portRaw === false || $portRaw === '') ? 9650 : (int) $portRaw;
if ($port < 1024 || $port > 65535) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65535');
}
foreach ($only as $place) {
    if (str_starts_with($place, '_apps/') === false || is_dir($webSrc . $sep . str_replace('/', $sep, $place)) === false) {
        refuse("--only: '{$place}' is not a folder under web/_apps");
    }
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
const ORG_A  = 919001;
const ORG_B  = 919002;
const ORG_C  = 919003; // made, used and removed inside part K only
const KEY_A  = 'selftest-addr-a';
const KEY_B  = 'selftest-addr-b';
/** The hostile key of part K: both quotes, an angle bracket each way, an ampersand and a backtick. */
const KEY_C  = 'st"c\'<i>&`z';
const HOST_A = 'a.addresses.selftest.invalid';
const HOST_B = 'b.addresses.selftest.invalid';
const NAME_A = 'Addresses Selftest Alpha';
const NAME_B = 'Addresses Selftest Bravo';
const BADMIN  = 919011; // an administrator of organisation B, and a member of nothing else: the crawler
const BMEMBER = 919012; // an ordinary member of B
const AMEMBER = 919013; // a member of A only: the members-only gate stops them at B's addresses
const U1      = 919014; // a member of the default organisation (number 1) only
const ROOT    = 919015; // a GLOBAL administrator, and a member of B: the only one the menu offers the organisations page
/** B's first part of the address, as every address in B must begin. */
const B = '/' . KEY_B;

/**
 * Remove everything this test made, and anything a page made for its
 * accounts or organisations while it was being read (a "seen this" row, a
 * log line). Such rows hang off the account or the organisation, so they
 * are found by asking the database which columns point at those two tables,
 * and by the two column names every table of the portal uses for them.
 * Links between those tables are not checked while it runs, so the order
 * does not matter; this is a throwaway database.
 *
 * @return int How many rows of its own are left afterwards (0 is right).
 */
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
            foreach ($columns as $column) {
                $where = $column['r'] === 'tblUsers' ? '`' . $column['c'] . '` BETWEEN 919000 AND 919999' : '`' . $column['c'] . '` IN (' . ORG_A . ', ' . ORG_B . ', ' . ORG_C . ')';
                if ($round === 1) {
                    q('DELETE FROM `' . $column['t'] . '` WHERE ' . $where);
                } else {
                    $left += (int) (one('SELECT COUNT(*) AS n FROM `' . $column['t'] . '` WHERE ' . $where)['n'] ?? 0);
                }
            }
        }
    } finally {
        q('SET FOREIGN_KEY_CHECKS = 1');
    }

    return $left;
}

/** One organisation's own value for a setting (replacing any it had). */
function siteSetting(int $site, string $key, string $value): void
{
    q('DELETE FROM tblSettings WHERE siteID = ? AND settingKey = ?', 'is', [$site, $key]);
    q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, ?, ?, ?, 0)', 'isss', [$site, $key, $value, $value]);
}

/** Set how organisations are told apart, straight in the two portal-wide rows. */
function setWay(bool $on, string $way): void
{
    q("UPDATE tblSettings SET settingValue = ? WHERE settingKey = 'multisite.enabled' AND siteID IS NULL", 's', [$on === true ? 'true' : 'false']);
    q("UPDATE tblSettings SET settingValue = ? WHERE settingKey = 'multisite.detectionMode' AND siteID IS NULL", 's', [$way]);
}

/** Switch "closed for maintenance" on or off, in its portal-wide row. */
function setMaintenance(bool $on): void
{
    q("UPDATE tblSettings SET settingValue = ? WHERE settingKey = 'portal.maintenance.active' AND siteID IS NULL", 's', [$on === true ? '1' : '0']);
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
        foreach ($state['settings'] as $before) {
            q('UPDATE tblSettings SET settingValue = ? WHERE settingID = ?', 'si', [$before['settingValue'], (int) $before['settingID']]);
        }
        removeWorld();
    } catch (Throwable $e) {
        echo 'FAIL — the clean-up could not put the database back: ' . $e->getMessage() . "\n";
    }
    // The scratch child: only the one this run made, and only while it still holds this run's marker.
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.site-addresses-selftest-owner') === $state['token']) {
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
 * `$o['host']` sends that host name instead of the server's own (how a
 * request to an organisation's own address arrives). `$o['errorDocument']`
 * asks the router for its test-only extra (see the router): the web server's
 * own error page for that status, for the address asked for.
 *
 * @param array<string,mixed>|null $post
 * @param array{host?:string, errorDocument?:int} $o
 *
 * @return array{status:int, location:string, body:string, head:string}
 */
function req(string $jar, string $method, string $path, ?array $post = null, array $o = []): array
{
    global $port, $state;
    $handle  = curl_init('http://127.0.0.1:' . $port . $path);
    $headers = ['Expect:', 'Accept-Language: en'];
    if (($o['host'] ?? '') !== '') {
        $headers[] = 'Host: ' . $o['host'];
    }
    if (($o['errorDocument'] ?? 0) !== 0) {
        $headers[] = 'X-Site-Addresses-Error-Document: ' . $state['token'] . ' ' . (int) $o['errorDocument'];
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 150,
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
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle), 'head' => ''];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr((string) $raw, 0, $headSize);
    if (preg_match('/^X-Site-Addresses-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start', 'head' => ''];
    }

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S*)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'body'     => substr((string) $raw, $headSize),
        'head'     => $head,
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

/** "status location" of an answer, for failure messages. */
function said(array $answer): string
{
    return $answer['status'] . ($answer['location'] === '' ? '' : ' -> ' . $answer['location']);
}

// =============================================================================
// 🔎 Reading the addresses out of an answer, and judging them
// =============================================================================

/**
 * Every address a piece of HTML points at: attributes that take an address,
 * a "refresh" tag, and the requests and redirects written in its scripts.
 * Written to match what `check_site_aware_addresses.py` looks for in the code
 * (rule 1 there), so the two read the same kinds of place.
 *
 * @return list<string>
 */
function addressesIn(string $html): array
{
    global $excusedLines;
    // What a line marked "address-check: allow" prints is taken out FIRST, so
    // that its address is not read at all. The same address anywhere else on
    // the page is still read, and judged like any other.
    foreach ($excusedLines ?? [] as $excusedLine) {
        $html = preg_replace_callback($excusedLine['pattern'], static fn (array $hit): string => str_contains($hit[0], $excusedLine['address']) === true ? '' : $hit[0], $html) ?? $html;
    }
    $found = [];
    preg_match_all('/(?<![\w-])(?:href|action|formaction|src|poster|data-url|data-href|hx-(?:get|post|put|patch|delete))\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $m, PREG_SET_ORDER);
    foreach ($m as $hit) {
        $found[] = html_entity_decode($hit[1] !== '' ? $hit[1] : ($hit[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    preg_match_all('/http-equiv="refresh"\s+content="[^"]*?url=([^"]*)"/i', $html, $m);
    foreach ($m[1] as $hit) {
        $found[] = html_entity_decode($hit, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    preg_match_all('/(?:\b(?:fetch|sendBeacon|EventSource)\(|\.open\(\s*["\'][A-Za-z]+["\']\s*,|\blocation(?:\.href)?\s*=|\blocation\.(?:assign|replace)\()\s*(["\'`])(\/[^"\'`]*)\1/', $html, $m);
    foreach ($m[2] as $hit) {
        $found[] = $hit;
    }

    return $found;
}

/** The three pieces of a page that every page shares: its head, its menu and its footer (to the end). */
function furniture(string $html): string
{
    $pieces = '';
    if (preg_match('#<head[\s>].*?</head>#s', $html, $m) === 1) {
        $pieces .= $m[0];
    }
    if (preg_match('#<nav class="navbar.*?</nav>#s', $html, $m) === 1) {
        $pieces .= $m[0];
    }
    $footer = strpos($html, '<footer class="portal-footer"');

    return $pieces . ($footer === false ? '' : substr($html, $footer));
}

/**
 * What the check says about addresses, asked once and kept: which ones the
 * web server answers itself, and which ones a line of code excuses.
 *
 * @param list<string> $addresses
 *
 * @return array<string, bool> address => does the web server answer it itself?
 */
function webServerAnswers(array $addresses): array
{
    global $checkFile;
    static $known = [];
    // Callers ask about the path only: what follows "?" or "#" never decides
    // the answer, and it changes from page to page (a form token, an id).
    $ask = array_values(array_diff(array_unique($addresses), array_keys($known)));
    foreach (array_chunk($ask, 200) as $chunk) {
        $process = proc_open(array_merge(['python3', $checkFile, '--answers'], $chunk), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out     = is_resource($process) === true ? (string) stream_get_contents($pipes[1]) : '';
        $code    = is_resource($process) === true ? proc_close($process) : 1;
        if ($code !== 0) {
            refuse('the check (check_site_aware_addresses.py --answers) could not be run; is python3 installed?');
        }
        foreach (explode("\n", trim($out)) as $row) {
            $cells = explode("\t", $row, 2);
            if (count($cells) === 2) {
                $known[$cells[1]] = $cells[0] === 'web-server';
            }
        }
    }

    return $known;
}

/**
 * What one line of a PHP file prints, as a pattern to look for in a page:
 * the page text on that line exactly as it is written, with "whatever its
 * PHP prints" in between. PHP's own reader says which is which, because the
 * same characters are page text in one file and code in the next.
 *
 *     <link rel="manifest" href="<?php echo '/manifest.json'; // … ?>">
 *     gives   <link rel="manifest" href="   anything but < or >   ">
 *
 * WHAT IT CANNOT DO: a line that is all PHP (`$icon = '/icon.svg'; // …`)
 * prints nothing as written, so there is nothing to look for and it
 * returns null. It also cannot know what the PHP on the line prints, only
 * that it stays inside the tag the line is writing (no "<" or ">").
 */
function printedBy(string $file, int $lineNumber): ?string
{
    $parts = []; // each: the page text as written (string), or null for "whatever the PHP here prints"
    $line  = 1;
    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        $isPageText = is_array($token) === true && $token[0] === T_INLINE_HTML;
        // One token may run over several lines: take the piece of it on the line asked for.
        foreach (explode("\n", is_array($token) === true ? $token[1] : $token) as $index => $piece) {
            $line += $index > 0 ? 1 : 0;
            if ($line !== $lineNumber || $piece === '') {
                continue;
            }
            if ($isPageText === true) {
                $parts[] = $piece;
            } elseif ($parts === [] || end($parts) !== null) {
                $parts[] = null;
            }
        }
        if ($line > $lineNumber) {
            break;
        }
    }
    $written = array_filter($parts, 'is_string');
    if (trim(implode('', $written)) === '') {
        return null;
    }
    // The line's indent and its end are not part of what is looked for.
    $first = array_key_first($written);
    $last  = array_key_last($written);
    $parts[$first] = ltrim((string) $parts[$first]);
    $parts[$last]  = rtrim((string) $parts[$last]);

    return '~' . implode('', array_map(static fn (?string $part): string => $part === null ? '[^<>]*?' : preg_quote($part, '~'), $parts)) . '~';
}

/**
 * The lines of code that excuse an address with "address-check: allow",
 * each with the pattern of what it prints. An address is excused in a page
 * only inside a match of its own line's pattern (see addressesIn()).
 *
 * @return list<array{address:string, pattern:string, where:string}>
 */
function excusedLines(): array
{
    global $checkFile, $repo;
    $process = proc_open(['python3', $checkFile, '--allowed'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out     = is_resource($process) === true ? (string) stream_get_contents($pipes[1]) : '';
    if (is_resource($process) === true) {
        proc_close($process);
    }
    $lines = [];
    foreach (explode("\n", trim($out)) as $row) {
        $cells = explode("\t", $row);
        if (count($cells) !== 4) {
            continue;
        }
        $pattern = printedBy($cells[0], (int) $cells[1]);
        if ($pattern !== null) {
            $lines[] = ['address' => $cells[2], 'pattern' => $pattern, 'where' => substr($cells[0], strlen($repo) + 1) . ':' . $cells[1]];
        }
    }

    return $lines;
}

/**
 * Judge a list of addresses read from one answer.
 *
 * `$prefix` is the first part every address must begin with ("/key"), or ''
 * when no first parts are in use, in which case no address may hold one.
 *
 * @param list<string> $addresses
 *
 * @return array{judged:int, bare:list<string>, doubled:list<string>, other:list<string>, file:list<string>, leaked:list<string>}
 *         bare     no organisation's part, and not the web server's own
 *         doubled  the part twice
 *         other    another organisation's part
 *         file     a real file of the web folder with a part in front
 *         leaked   (no first parts in use) an address that holds one
 */
function judge(array $addresses, string $prefix): array
{
    $verdict = ['judged' => 0, 'bare' => [], 'doubled' => [], 'other' => [], 'file' => [], 'leaked' => []];
    $local   = [];
    foreach ($addresses as $address) {
        // Only an address of this portal that starts at the top is judged: not "#top", "?page=2", a
        // relative name, another site ("https://…", "//…") or "mailto:" and the like.
        if (str_starts_with($address, '/') === true && str_starts_with($address, '//') === false) {
            $local[] = $address;
        }
    }
    $path = static fn (string $a): string => (string) preg_split('/[?#]/', $a)[0];
    // Asked about each address, and about each address with the part taken off the front (is THAT a real file?).
    $own = webServerAnswers(array_merge(array_map($path, $local), array_map(static fn (string $a): string => (string) substr($path($a), strlen($prefix)), array_filter($local, static fn (string $a): bool => $prefix !== '' && str_starts_with($a, $prefix . '/')))));
    foreach ($local as $address) {
        $verdict['judged']++;
        foreach ([KEY_A, KEY_B] as $key) {
            if ($prefix === '' && (str_starts_with($address, '/' . $key . '/') === true || $address === '/' . $key)) {
                $verdict['leaked'][] = $address;
            }
        }
        if ($prefix === '') {
            continue;
        }
        if ($address === $prefix || str_starts_with($address, $prefix . '/') === true || str_starts_with($address, $prefix . '?') === true) {
            $rest = (string) substr($path($address), strlen($prefix));
            if (str_starts_with($rest, $prefix . '/') === true || $rest === $prefix) {
                $verdict['doubled'][] = $address;
            } elseif ($rest !== '' && ($own[$rest] ?? false) === true) {
                $verdict['file'][] = $address;
            }
        } elseif (str_starts_with($address, '/' . KEY_A . '/') === true || $address === '/' . KEY_A) {
            $verdict['other'][] = $address;
        } elseif (($own[$path($address)] ?? false) === false) {
            // (An address a line of code excuses never gets here: addressesIn() left it out, on that line only.)
            $verdict['bare'][] = $address;
        }
    }

    return $verdict;
}

/** Everything wrong in a verdict, in words ('' when nothing is). */
function wrong(array $verdict): string
{
    $said = [];
    foreach (['bare' => 'no organisation\'s part', 'doubled' => 'the part twice', 'other' => 'another organisation\'s part', 'file' => 'a part in front of a real file', 'leaked' => 'a first part where none is in use'] as $kind => $words) {
        if ($verdict[$kind] !== []) {
            $said[] = $words . ': ' . implode(', ', array_slice(array_values(array_unique($verdict[$kind])), 0, 8)) . (count(array_unique($verdict[$kind])) > 8 ? ' …' : '');
        }
    }

    return implode('; ', $said);
}

// =============================================================================
// 🏗️ Set-up
// =============================================================================
if (portAnswers($port) === true) {
    refuse("something already answers on port {$port}; this test never drives a server it did not start");
}
$defaultOrg = one('SELECT hostPattern, isActive FROM tblSites WHERE siteID = 1');
if ($defaultOrg === null || (int) $defaultOrg['isActive'] !== 1 || $defaultOrg['hostPattern'] !== null) {
    refuse('the database must hold an active default organisation (number 1) with no address of its own, as a new install does');
}
foreach (['multisite.enabled', 'multisite.detectionMode', 'portal.maintenance.active'] as $key) {
    $row = one('SELECT settingID, settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]);
    if ($row === null) {
        refuse("the portal-wide setting {$key} is not in this database");
    }
    $state['settings'][$key] = $row;
}
$before           = $state['settings'];
$state['started'] = true;
register_shutdown_function('cleanup');
removeWorld();

foreach ([[ORG_A, KEY_A, NAME_A, HOST_A], [ORG_B, KEY_B, NAME_B, HOST_B]] as [$id, $key, $name, $host]) {
    q('INSERT INTO tblSites (siteID, siteKey, siteName, hostPattern, isActive) VALUES (?, ?, ?, ?, 1)', 'isss', [$id, $key, $name, $host]);
}
$people = ['badmin' => BADMIN, 'bmember' => BMEMBER, 'amember' => AMEMBER, 'u1' => U1, 'root' => ROOT];
foreach ($people as $who => $id) {
    // Only "root" is a global administrator. The crawler ("badmin") stays an
    // administrator of B alone, so the crawl meets the pages as before.
    $global = $id === ROOT ? 1 : 0;
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, ?, ?)', 'issii', [$id, 'Selftest ' . $who, $who . '.addresses@selftest.invalid', $global, $global]);
}
foreach ([[BADMIN, ORG_B, 1], [BMEMBER, ORG_B, 0], [AMEMBER, ORG_A, 0], [U1, 1, 0], [ROOT, ORG_B, 1]] as [$user, $site, $admin]) {
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, ?, 0)', 'iii', [$user, $site, $admin]);
}
// Every app switched on in organisation B, so the menu and the crawl meet as many pages as there are.
$apps = array_column(q("SELECT settingKey FROM tblSettings WHERE siteID IS NULL AND settingKey REGEXP '^[a-z0-9-]+\\\\.enabled$' AND settingKey <> 'multisite.enabled'"), 'settingKey');
foreach ($apps as $app) {
    siteSetting(ORG_B, (string) $app, 'true');
}
$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
foreach ($people as $who => $id) {
    q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-addr-' . $who, $hash]);
}

// The scratch copy of web/, with a throwaway connection file and key.
$child = $parent . $sep . 'site-addresses-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.site-addresses-selftest-owner', $state['token']); // the marker FIRST
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
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\n// Written by tools/site-addresses-selftest.php for one run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/site-addresses-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Site-Addresses-Selftest: __TOKEN__');
$root = (string) realpath(__DIR__ . '/web/public_html');
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

// TEST-ONLY EXTRA: the web server's own error page. Apache, told
// "ErrorDocument 404 /error.php?code=404", answers a failed request by running
// error.php while the address asked for stays what the visitor typed. PHP's
// built-in server has no such rule, so a request carrying this run's token in
// the header below is answered that way here. Nothing in the portal knows
// about this header.
$asked = explode(' ', (string) ($_SERVER['HTTP_X_SITE_ADDRESSES_ERROR_DOCUMENT'] ?? ''), 2);
if (count($asked) === 2 && $asked[0] === '__TOKEN__') {
    $_GET['code'] = (string) (int) $asked[1];
    chdir($root);
    require $root . '/error.php';
    return true;
}
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

// The server: its command as a LIST (no shell in between), ONE process, so
// nothing can outlive it. A page is given two minutes, not PHP's usual 30
// seconds: on a busy machine a slow page would otherwise be cut short and
// read as a fault of the page.
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

echo "Addresses that stay inside the organisation, on the real pages — self-test (#536)\n";
echo 'server process ' . (int) proc_get_status($state['proc'])['pid'] . ', port ' . $port . ', PHP ' . PHP_VERSION . ', database ' . (string) one('SELECT VERSION() AS v')['v'] . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";
if ($only !== []) {
    echo "NOT A FULL RUN: --only " . implode(' ', $only) . " — the crawl of those places and nothing else.\n";
} elseif ($noCrawl === true) {
    echo "NOT A FULL RUN: --no-crawl — every part but the crawl.\n";
}
$excusedLines = excusedLines();
echo count($excusedLines) . ' line(s) of code excuse an address the pages print; each is excused only inside what its own line prints: '
    . implode(', ', array_map(static fn (array $l): string => $l['where'] . ' (' . $l['address'] . ')', $excusedLines)) . "\n";

/**
 * Sign one account in through the real sign-in page; returns its cookie jar.
 * `$prefix` is an organisation's first part of the address ("/key"), `$host`
 * an organisation's own address, and `$query` anything to put after
 * "/login" (a "?redirect=…"). The answer to the sign-in itself is left in
 * `$lastSignIn`.
 */
function signIn(string $who, string $prefix = '', string $host = '', string $query = ''): string
{
    global $child, $sep, $password, $lastSignIn;
    $file       = $child . $sep . 'jar-' . $who . '-' . bin2hex(random_bytes(3)) . '.txt';
    $tok        = token(req($file, 'GET', $prefix . '/login' . $query, null, ['host' => $host])['body']);
    $lastSignIn = req($file, 'POST', $prefix . '/login' . $query, ['csrf_token' => $tok, 'username' => 'selftest-addr-' . $who, 'password' => $password], ['host' => $host]);
    if ($lastSignIn['status'] !== 302 || str_contains($lastSignIn['location'], '/login') === true) {
        refuse("signing in as selftest-addr-{$who} did not work (HTTP {$lastSignIn['status']} {$lastSignIn['location']})");
    }

    return $file;
}

/**
 * Where the real sign-in page sends an ALREADY signed-in person who arrives
 * with "?redirect=…". It is the same judgement as after a sign-in
 * (Auth::safeRedirectUrl()), without signing in again for every case.
 */
function sentTo(string $jar, string $prefix, string $redirect, string $host = ''): string
{
    $answer = req($jar, 'GET', $prefix . '/login?redirect=' . rawurlencode($redirect), null, ['host' => $host]);

    return $answer['status'] === 302 ? $answer['location'] : 'HTTP ' . $answer['status'];
}

$nobody = $child . $sep . 'jar-nobody.txt';
setWay(true, 'path');

/**
 * The crawl: ask for every registered page address as `$jar`, read every
 * address in the answers, and judge it.
 *
 * @param list<array{routeKey:string, targetFile:string}> $routes
 * @param list<string> $furnitureOnly  Places (from web/) whose pages are judged for their shared furniture only.
 *
 * @return array{asked:int, unanswered:list<string>, full:int, part:int, judged:int, wrong:array<string,string>, tagged:int, statuses:array<int,int>, failed:list<string>}
 */
function crawl(string $jar, string $prefix, array $routes, array $furnitureOnly): array
{
    $result = ['asked' => 0, 'unanswered' => [], 'full' => 0, 'part' => 0, 'judged' => 0, 'wrong' => [], 'tagged' => 0, 'statuses' => [], 'failed' => []];
    foreach ($routes as $route) {
        $answer = req($jar, 'GET', $prefix . '/' . $route['routeKey']);
        $result['asked']++;
        if ($answer['status'] <= 0) {
            $result['unanswered'][] = $route['routeKey'] . ' (' . $answer['body'] . ')';
            continue;
        }
        $result['statuses'][$answer['status']] = ($result['statuses'][$answer['status']] ?? 0) + 1;
        if ($answer['status'] >= 500) {
            $result['failed'][] = '/' . $route['routeKey'] . ' (' . $answer['status'] . ')';
        }
        $place   = '_apps/' . $route['targetFile'];
        $partial = false;
        foreach ($furnitureOnly as $pending) {
            if ($place === $pending || str_starts_with($place, $pending . '/') === true) {
                $partial = true;
            }
        }
        $isPage    = str_contains($answer['head'], 'text/html') === true;
        $addresses = $isPage === true ? addressesIn($partial === true ? furniture($answer['body']) : $answer['body']) : [];
        if ($partial === false && $answer['location'] !== '') {
            $addresses[] = $answer['location'];
        }
        $partial === true ? $result['part']++ : $result['full']++;
        $verdict            = judge($addresses, $prefix);
        $result['judged'] += $verdict['judged'];
        $result['tagged'] += str_contains($answer['body'], '<meta name="portal-base"') === true ? 1 : 0;
        if (wrong($verdict) !== '') {
            $result['wrong'][$route['routeKey']] = wrong($verdict);
        }
    }

    return $result;
}

/**
 * How a crawl's answers divide by HTTP status, for the output: "284 x 200,
 * 250 x 302", and which addresses answered with a server's own failure
 * (500 and up). Those are named and not judged as a fault of the links: a
 * page that fails in a made-up organisation with no records is that page's
 * matter, and the same page fails the same way with no first parts in use.
 */
function statuses(array $result): string
{
    ksort($result['statuses']);

    return implode(', ', array_map(static fn (int $status, int $count): string => $count . ' x ' . $status, array_keys($result['statuses']), $result['statuses']))
        . ($result['failed'] === [] ? '' : '; answered with a failure: ' . implode(', ', $result['failed']));
}

/** A crawl's faults, shortened for a failure message. */
function faults(array $result): string
{
    $lines = [];
    foreach (array_slice($result['wrong'], 0, 12, true) as $address => $what) {
        $lines[] = '/' . $address . ' — ' . $what;
    }

    return count($result['wrong']) . ' page(s): ' . implode(' | ', $lines) . (count($result['wrong']) > 12 ? ' | …' : '');
}

// The addresses to crawl: every registered one but "logout" (it would end the crawler's own session; part S
// reads the sign-out page) and the scheduled jobs under "cron/" (they answer a token, not a person).
$routes = array_values(array_filter(
    q('SELECT routeKey, targetFile FROM tblRoutes ORDER BY routeKey'),
    static fn (array $r): bool => $r['routeKey'] !== 'logout' && str_starts_with((string) $r['routeKey'], 'cron/') === false
));
// The places not swept yet, read from the same list the check reads.
$pendingPlaces = [];
foreach (file($repo . $sep . 'tools' . $sep . 'audit-checks' . $sep . 'site_aware_addresses_pending.txt', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line !== '' && str_starts_with($line, '#') === false) {
        $pendingPlaces[] = trim($line, '/');
    }
}

if ($only === []) {
    // =========================================================================
    // F. THE SHARED PAGE FURNITURE
    // =========================================================================
    head('F. The shared page furniture, in organisation B (first part of the address)');
    $jarAdmin = signIn('badmin', B);
    $front    = req($jarAdmin, 'GET', B . '/');
    ok('F01 control: the administrator of B gets B\'s front page at its own address', $front['status'] === 200 && str_contains($front['body'], NAME_B) === true, said($front));
    preg_match('#<nav class="navbar.*?</nav>#s', $front['body'], $menu);
    $menuLinks = addressesIn($menu[0] ?? '');
    $verdict   = judge($menuLinks, B);
    ok('F02 every link in the menu keeps the organisation\'s part (' . $verdict['judged'] . ' addresses read)', $verdict['judged'] >= 25 && wrong($verdict) === '', $verdict['judged'] . ' read; ' . wrong($verdict));
    $expected20 = ['', 'admin', 'admin/errors', 'admin/activity', 'admin/audit', 'admin/users', 'settings', 'admin/apps', 'admin/reports', 'admin/workflows',
        'admin/sms', 'admin/transcription', 'admin/safeguarding/dbs', 'admin/decision-cards', 'account', 'logout'];
    $missing = array_filter($expected20, static fn (string $page): bool => in_array(B . '/' . $page, $menuLinks, true) === false);
    ok('F03 the logo, the dashboard, the administration menu, "My account" and "Sign out" are each there under the organisation\'s part', $missing === [], 'not found: ' . implode(', ', array_map(static fn (string $p): string => B . '/' . $p, $missing)));
    // Migrations is for a GLOBAL administrator only (#499, 13 October 2026): the page refuses everybody else,
    // so the menu must not offer it to an administrator of one organisation, which is what 'badmin' is. It was
    // in F03's list until then. F12b holds the other half: a global administrator IS offered it.
    ok('F03b an administrator of ONE organisation is not offered Migrations in the menu: the page would refuse them',
        in_array(B . '/admin/migrations', $menuLinks, true) === false && in_array('/admin/migrations', $menuLinks, true) === false,
        'the menu holds: ' . implode(', ', array_filter($menuLinks, static fn (string $a): bool => str_contains($a, 'migrations') === true)));
    ok('F04 the menu holds no bare address at all, not even one the check would excuse', array_filter($menuLinks, static fn (string $a): bool => str_starts_with($a, '/') === true && str_starts_with($a, B . '/') === false && str_starts_with($a, '/assets/') === false) === [],
        implode(', ', array_filter($menuLinks, static fn (string $a): bool => str_starts_with($a, '/') === true && str_starts_with($a, B . '/') === false)));
    $verdict = judge(addressesIn(furniture($front['body'])), B);
    ok('F05 every address in the page head, the menu and the footer keeps the part, or is a real file with none (' . $verdict['judged'] . ' read)', $verdict['judged'] >= 40 && wrong($verdict) === '', $verdict['judged'] . ' read; ' . wrong($verdict));
    ok('F06 the header prints the organisation\'s front address once, for the script files', substr_count($front['body'], '<meta name="portal-base" content="' . B . '/">') === 1 && substr_count($front['body'], 'name="portal-base"') === 1,
        'found ' . substr_count($front['body'], 'name="portal-base"') . ' such tag(s)');
    ok('F07 the files the web server answers itself keep their plain addresses (the stylesheet, portal.js, the service worker)', str_contains($front['body'], '"/assets/js/portal-confirm.js"') === true
        && str_contains($front['body'], "register('/sw.js'") === true && str_contains($front['body'], B . '/assets/') === false && str_contains($front['body'], B . '/sw.js') === false);
    $manifest = '<link rel="manifest" href="/manifest.json">';
    ok('F08 the installable-app description keeps its one plain address, as the header\'s note says', substr_count($front['body'], $manifest) === 1 && in_array('/manifest.json', array_column($excusedLines, 'address'), true) === true
        && in_array('/manifest.json', addressesIn($front['body']), true) === false, 'printed ' . substr_count($front['body'], $manifest) . ' time(s)');
    $elsewhere = '<a href="/manifest.json">a link written somewhere else</a>';
    ok('F09 that address is excused ONLY inside what its own line prints: the same address in another link is still reported',
        judge(addressesIn($manifest), B)['bare'] === [] && judge(addressesIn($elsewhere), B)['bare'] === ['/manifest.json'] && judge(addressesIn($manifest . $elsewhere), B)['bare'] === ['/manifest.json'],
        'alone: ' . implode(' ', judge(addressesIn($manifest), B)['bare']) . ' | elsewhere: ' . implode(' ', judge(addressesIn($elsewhere), B)['bare']) . ' | both: ' . implode(' ', judge(addressesIn($manifest . $elsewhere), B)['bare']));

    $jarOne   = signIn('u1');
    $frontOne = req($jarOne, 'GET', '/');
    preg_match('#<nav class="navbar.*?</nav>#s', $frontOne['body'], $menuOne);
    ok('F10 in the default organisation, which has no first part, the same menu is plain and no tag is printed', $frontOne['status'] === 200 && in_array('/account', addressesIn($menuOne[0] ?? ''), true) === true
        && str_contains($frontOne['body'], 'portal-base') === false && str_contains($frontOne['body'], KEY_B) === false, said($frontOne));
    $signedOut = req($nobody, 'GET', B . '/no-such-page');
    preg_match('#<nav class="navbar.*?</nav>#s', $signedOut['body'], $menuOut);
    ok('F11 a signed-out visitor in B is offered B\'s own sign-in page', in_array(B . '/login', addressesIn($menuOut[0] ?? ''), true) === true, implode(', ', addressesIn($menuOut[0] ?? '')));

    // The organisations page is offered to a GLOBAL administrator only, so the crawler's menu never draws it.
    $jarRoot   = signIn('root', B);
    $frontRoot = req($jarRoot, 'GET', B . '/');
    preg_match('#<nav class="navbar.*?</nav>#s', $frontRoot['body'], $menuRoot);
    $rootLinks = addressesIn($menuRoot[0] ?? '');
    // A global administrator belongs to every organisation, so their menu also draws the organisation
    // switcher: one link to the front page of each OTHER organisation. Those two are meant to lead away.
    $switcher = ['/' . KEY_A . '/', '/' . (string) (one('SELECT siteKey FROM tblSites WHERE siteID = 1')['siteKey'] ?? '') . '/'];
    $ownLinks = array_values(array_diff($rootLinks, $switcher));
    ok('F12b a global administrator IS offered Migrations in the menu, under B\'s part',
        in_array(B . '/admin/migrations', $rootLinks, true) === true,
        'the menu holds: ' . implode(', ', array_filter($rootLinks, static fn (string $a): bool => str_contains($a, 'migrations') === true)));
    ok('F12 a global administrator\'s menu offers the organisations page under B\'s part; apart from the switcher\'s two links to the other organisations it holds no address without that part',
        $frontRoot['status'] === 200 && in_array(B . '/admin/sites', $rootLinks, true) === true && in_array(B . '/admin/sites', $menuLinks, true) === false
        && count($rootLinks) - count($ownLinks) === 2 && wrong(judge($ownLinks, B)) === '', said($frontRoot) . ' ' . wrong(judge($ownLinks, B)) . ' menu: ' . implode(' ', $rootLinks));

    // The database finds an organisation whatever capitals its part is typed in. The page router has to agree.
    $typed = '/' . strtoupper(KEY_B);
    $page  = req($jarAdmin, 'GET', $typed . '/account');
    preg_match('#<nav class="navbar.*?</nav>#s', $page['body'], $menuTyped);
    ok('F13 B\'s part typed in capitals (' . $typed . '/account) reaches the same page of B, and its links keep the spelling the person came in on',
        $page['status'] === 200 && str_contains($page['body'], NAME_B) === true && in_array($typed . '/account', addressesIn($menuTyped[0] ?? ''), true) === true, said($page));
    $page = req($jarAdmin, 'GET', $typed);
    ok('F14 and typed in capitals with nothing after it, it reaches B\'s front page', $page['status'] === 200 && str_contains($page['body'], NAME_B) === true, said($page));
    // Reaching the page in capitals must not be a way round the members-only gate.
    $jarNotB = signIn('amember', '/' . KEY_A);
    $usual   = req($jarNotB, 'GET', B . '/account');
    $capital = req($jarNotB, 'GET', $typed . '/account');
    ok('F15 somebody who is not a member of B is stopped at the capitals spelling exactly as at the usual one (403 both)', $usual['status'] === 403 && $capital['status'] === 403
        && str_contains($capital['body'], 'portal-navbar') === false, 'usual: ' . said($usual) . '; capitals: ' . said($capital));

    // =========================================================================
    // S. THE SIGN-IN FLOW
    // =========================================================================
    head('S. The sign-in flow, in organisation B');
    $asked = req($nobody, 'GET', B . '/account');
    $want  = B . '/login?redirect=' . urlencode(B . '/account');
    ok('S01 signed out at ' . B . '/account, a visitor is sent to B\'s sign-in page, with the address to come back to', $asked['status'] === 302 && $asked['location'] === $want, said($asked) . ' (wanted ' . $want . ')');
    $jarMember = signIn('bmember', B, '', '?redirect=' . urlencode(B . '/account'));
    ok('S02 after signing in there, they are sent back to ' . B . '/account', $lastSignIn['location'] === B . '/account', said($lastSignIn));
    $page = req($jarMember, 'GET', B . '/account');
    ok('S03 and that page answers, in organisation B', $page['status'] === 200 && str_contains($page['body'], NAME_B) === true, said($page));
    signIn('bmember', B);
    ok('S04 with nowhere to go back to, a sign-in in B ends on B\'s own front page, not the default organisation\'s', $lastSignIn['location'] === B . '/', said($lastSignIn));
    $asked = req($nobody, 'GET', B . '/account?tab=security&x=a%20b');
    ok('S05 the address to come back to is kept whole, query and all', $asked['location'] === B . '/login?redirect=' . urlencode(B . '/account?tab=security&x=a%20b'), said($asked));
    $asked = req($nobody, 'GET', '/account');
    ok('S06 control: in the default organisation the same redirect has no first part, exactly as before', $asked['status'] === 302 && $asked['location'] === '/login?redirect=%2Faccount', said($asked));

    // Every way a "come back to" address could lead away. Each is tried twice: after a real sign-in for the
    // three that matter most, and for all of them through the sign-in page's answer to somebody already
    // signed in, which is the same judgement.
    signIn('bmember', B, '', '?redirect=' . rawurlencode('https://outside.selftest.invalid/x'));
    ok('S07 after a sign-in, an address on ANOTHER SITE is refused: the person lands on B\'s front page', $lastSignIn['location'] === B . '/', said($lastSignIn));
    signIn('bmember', B, '', '?redirect=' . rawurlencode('/' . KEY_A . '/account'));
    ok('S08 after a sign-in, an address with ANOTHER ORGANISATION\'S part is refused the same way', $lastSignIn['location'] === B . '/', said($lastSignIn));
    signIn('bmember', B, '', '?redirect=' . rawurlencode("/\t/outside.selftest.invalid"));
    ok('S09 after a sign-in, "/<tab>/host" (which a browser reads as another site) is refused the same way', $lastSignIn['location'] === B . '/', said($lastSignIn));

    $refused = [
        'S10 another site, written in full'                         => 'https://outside.selftest.invalid/x',
        'S11 another site, written "//host"'                        => '//outside.selftest.invalid/x',
        'S12 a backslash: "/\\host"'                                => '/\\outside.selftest.invalid',
        'S13 a backslash, encoded'                                  => '/%5Coutside.selftest.invalid',
        'S14 a tab between two slashes'                             => "/\t/outside.selftest.invalid",
        'S15 a tab, encoded, between two slashes'                   => '/%09/outside.selftest.invalid',
        'S16 a line break between two slashes'                      => "/\n/outside.selftest.invalid",
        'S17 a scheme: "javascript:"'                               => 'javascript:alert(1)',
        'S18 another organisation\'s part'                          => '/' . KEY_A . '/account',
        'S19 another organisation\'s front page'                    => '/' . KEY_A,
        'S20 no organisation\'s part at all (the default one)'      => '/account',
        'S21 the top address "/" (the default organisation\'s)'     => '/',
        'S22 ".." to climb out: own part, then another\'s'          => B . '/../' . KEY_A . '/account',
        'S23 ".." encoded'                                          => B . '/%2e%2e/' . KEY_A . '/account',
        'S24 ".." with the slash after it encoded'                  => B . '/..%2f' . KEY_A . '/account',
        'S25 the organisation\'s part in capitals (spelt another way than the request)' => '/' . strtoupper(KEY_B) . '/account',
        'S26 the organisation\'s part as the start of a longer word' => B . 'x/account',
    ];
    foreach ($refused as $label => $address) {
        $went = sentTo($jarMember, B, $address);
        ok($label . ' is refused: B\'s front page instead', $went === B . '/', 'sent to ' . $went);
    }
    $kept = [
        'S27 control: a page of B, with a query' => B . '/calendar?view=list',
        'S28 control: B\'s front page'            => B . '/',
        'S29 control: B\'s front page, no slash'  => B,
    ];
    foreach ($kept as $label => $address) {
        $went = sentTo($jarMember, B, $address);
        ok($label . ' is kept exactly', $went === $address, 'sent to ' . $went);
    }
    // From the default organisation's sign-in page, which has no first part of its own.
    ok('S30 from the default organisation\'s sign-in page, an address with B\'s part is refused', sentTo($jarOne, '', B . '/account') === '/', 'sent to ' . sentTo($jarOne, '', B . '/account'));
    ok('S31 and with B\'s part in capitals, which the database reads as the same organisation', sentTo($jarOne, '', '/' . strtoupper(KEY_B) . '/account') === '/', 'sent to ' . sentTo($jarOne, '', '/' . strtoupper(KEY_B) . '/account'));
    ok('S32 control: from there, a plain address of the default organisation is kept', sentTo($jarOne, '', '/account?tab=1') === '/account?tab=1', 'sent to ' . sentTo($jarOne, '', '/account?tab=1'));

    $out = req($jarMember, 'GET', B . '/logout');
    ok('S33 signing out of B ends on B\'s front page in all three ways: the script, the fallback without scripts, and the link',
        $out['status'] === 200 && str_contains($out['body'], 'window.location.replace("' . B . '/")') === true
        && str_contains($out['body'], '<meta http-equiv="refresh" content="0;url=' . B . '/">') === true && str_contains($out['body'], '<a href="' . B . '/">Continue</a>') === true,
        said($out) . ' ' . implode(' ', addressesIn($out['body'])));
    $after = req($jarMember, 'GET', B . '/account');
    ok('S34 control: that session really ended', $after['status'] === 302 && str_starts_with($after['location'], B . '/login') === true, said($after));

    // A sign-in through Microsoft that fails. No outside server is needed to fail: an answer with no "state" is refused at once.
    $failed = req($nobody, 'GET', B . '/login/ms365/callback');
    ok('S35 a sign-in through Microsoft that fails, in B, goes back to B\'s own sign-in page', $failed['status'] === 302 && $failed['location'] === B . '/login', said($failed));
    $failed = req($nobody, 'GET', '/login/google/callback?error=access_denied');
    ok('S36 control: with no first part, a failed sign-in through Google goes back to "/login", as before', $failed['status'] === 302 && $failed['location'] === '/login', said($failed));

    // =========================================================================
    // E. ERROR PAGES AND THE HOLDING PAGE
    // =========================================================================
    head('E. The error pages and the holding page, in organisation B');
    $homeLink = '<a href="' . B . '/" class="btn btn-primary">';
    $page     = req($jarAdmin, 'GET', B . '/no-such-page');
    ok('E01 "page not found" (404) links back to B\'s front page', $page['status'] === 404 && str_contains($page['body'], $homeLink) === true && wrong(judge(addressesIn($page['body']), B)) === '', said($page) . ' ' . wrong(judge(addressesIn($page['body']), B)));
    $jarPlain = signIn('bmember', B);
    $page     = req($jarPlain, 'GET', B . '/admin/errors');
    ok('E02 "access denied" (403), from a page that refuses an ordinary member, does too', $page['status'] === 403 && str_contains($page['body'], $homeLink) === true, said($page));
    foreach ([404 => 'E03', 403 => 'E04', 500 => 'E05'] as $code => $label) {
        $page = req($jarAdmin, 'GET', B . '/anything/at-all', null, ['errorDocument' => $code]);
        ok($label . ' the web server\'s own error page for ' . $code . ', asked for under B\'s part, links back to B\'s front page', $page['status'] === $code && str_contains($page['body'], $homeLink) === true
            && wrong(judge(addressesIn(furniture($page['body'])), B)) === '', said($page) . ' ' . wrong(judge(addressesIn(furniture($page['body'])), B)));
    }
    $jarOther = signIn('amember', '/' . KEY_A);
    $page     = req($jarOther, 'GET', B . '/anything/at-all', null, ['errorDocument' => 404]);
    ok('E06 the PLAIN error page, shown to somebody the members-only gate stops, links to B\'s front page (where the gate then says why)', $page['status'] === 404 && str_contains($page['body'], 'portal-navbar') === false
        && str_contains($page['body'], '<a href="' . B . '/">&larr; Return to Portal</a>') === true, said($page) . ' ' . implode(' ', addressesIn($page['body'])));
    $page = req($nobody, 'GET', '/anything/at-all', null, ['errorDocument' => 404]);
    ok('E07 control: with no first part in the address, the error page links to "/"', $page['status'] === 404 && str_contains($page['body'], '<a href="/" class="btn btn-primary">') === true, said($page));

    setMaintenance(true);
    $page = req($nobody, 'GET', B . '/calendar');
    ok('E08 the "closed for maintenance" page, in B, offers B\'s own sign-in page', $page['status'] === 503 && str_contains($page['body'], '<a href="' . B . '/login">sign in</a>') === true, said($page) . ' ' . implode(' ', addressesIn($page['body'])));
    $page = req($nobody, 'GET', B . '/login');
    ok('E09 and that sign-in page is let through while the portal is closed', $page['status'] === 200 && str_contains($page['body'], 'name="username"') === true, said($page));
    $page = req($nobody, 'GET', '/calendar');
    ok('E10 control: with no first part, the holding page offers "/login"', $page['status'] === 503 && str_contains($page['body'], '<a href="/login">sign in</a>') === true, said($page));
    setMaintenance(false);

    // =========================================================================
    // J. SCRIPTS
    // =========================================================================
    head('J. Scripts');
    $page = req($nobody, 'GET', B . '/no-such-page');
    ok('J01 the cookie banner\'s script sends the choice to B\'s own address', str_contains($page['body'], "fetch('" . B . "/privacy/consent', {") === true, 'the page holds: ' . implode(' ', array_filter(addressesIn($page['body']), static fn (string $a): bool => str_contains($a, 'consent'))));
    $page = req($jarAdmin, 'GET', B . '/admin/settings/organisation');
    ok('J02 the map script asks B\'s own addresses for a place and for three-word suggestions', $page['status'] === 200 && str_contains($page['body'], "fetch('" . B . "/geo/lookup', {") === true
        && str_contains($page['body'], "fetch('" . B . "/geo/w3w-suggest', {") === true, said($page) . ' ' . implode(' ', array_filter(addressesIn($page['body']), static fn (string $a): bool => str_contains($a, 'geo/'))));
    $page = req($nobody, 'GET', '/no-such-page');
    ok('J03 control: with no first part, the banner\'s script is exactly what it always was', str_contains($page['body'], "fetch('/privacy/consent', { method: 'POST', body: fd, credentials: 'same-origin' })") === true);
    $tour    = req($nobody, 'GET', '/assets/js/portal-tour.js')['body'];
    $builder = req($nobody, 'GET', '/assets/js/report-builder.js')['body'];
    ok('J04 the tour script file builds both its addresses with Portal.url(), and holds no bare request', substr_count($tour, "fetch(window.Portal.url('api/tours/") === 2 && preg_match('/fetch\(\s*[\'"`]\//', $tour) === 0);
    ok('J05 the report builder script file builds its address with Portal.url(), and holds no bare request', str_contains($builder, "fetch(window.Portal.url('admin/reports/builder/preview')") === true && preg_match('/fetch\(\s*[\'"`]\//', $builder) === 0);
    // These two send their requests through a helper of their own, so "no bare request" has to mean
    // "no quoted address handed to ANY function", not only to fetch().
    $bareArgument = '/[(,]\s*[\'"`]\/[A-Za-z0-9_]/';
    $upload       = req($nobody, 'GET', '/assets/js/event-hub-upload.js')['body'];
    $push         = req($nobody, 'GET', '/assets/js/push-subscribe.js')['body'];
    ok('J06 the event upload script file builds both its addresses with Portal.url(), and hands no bare address to any function',
        str_contains($upload, "postForm(window.Portal.url('calendar/event/hub/video-status'), {") === true && str_contains($upload, "postForm(window.Portal.url('calendar/event/hub/upload-url'), {") === true
        && substr_count($upload, 'postForm(') === 3 && preg_match($bareArgument, $upload) === 0, substr_count($upload, 'postForm(') . ' postForm( in the file');
    ok('J07 the notifications script file builds both its addresses with Portal.url(), and hands no bare address to any function',
        str_contains($push, "postJson(window.Portal.url('api/push/subscribe'), {") === true && str_contains($push, "postJson(window.Portal.url('api/push/unsubscribe'), {") === true
        && substr_count($push, 'postJson(') === 3 && preg_match($bareArgument, $push) === 0, substr_count($push, 'postJson(') . ' postJson( in the file');

    // portal.js and portal-tour.js are RUN, in Node.js, against a made-up page: the header's tag present or
    // absent, and a `fetch` that only writes down what it was asked for.
    $harness = <<<'JS'
    const fs = require('fs');
    function run(base, signedIn) {
        const asked = [];
        const tag = base === null ? null : { getAttribute: () => base };
        const body = { getAttribute: () => (signedIn ? '1' : '0') };
        const document = { readyState: 'complete', documentElement: { setAttribute() {} }, body,
            querySelector: (s) => (s === 'meta[name="portal-base"]' ? tag : null), querySelectorAll: () => [], addEventListener() {}, getElementById: () => null };
        const window = { matchMedia: null, addEventListener() {} };
        const fetch = (address) => { asked.push(address); return Promise.resolve({ ok: false, json: () => null }); };
        const load = (file) => new Function('window', 'document', 'localStorage', 'fetch', fs.readFileSync(file, 'utf8'))(window, document, { getItem: () => null, setItem() {} }, fetch);
        load(process.argv[2]);
        const built = [window.Portal.url('api/tours/active'), window.Portal.url('/admin/reports/builder/preview'), window.Portal.url('calendar?view=list'), window.Portal.url('')];
        // Routes a browser would read as another site once a "/" stands in front: a backslash, a tab, a line break.
        const refused = ['\\outside.invalid', '/\\outside.invalid', '\t/outside.invalid', '//\n/outside.invalid', '\u007f/outside.invalid'].map((route) => window.Portal.url(route));
        load(process.argv[3]);
        return { built, asked, refused };
    }
    // Tags that must not be believed: each would turn every address built from it into one on another site.
    const badTags = ['//outside.invalid/', '/\\outside.invalid/', '/\t/outside.invalid/', 'outside.invalid/', 'https://outside.invalid/', '\\\\outside.invalid/'].map((tag) => run(tag, true).built[0]);
    console.log(JSON.stringify({ prefixed: run('__BASE__', true), plain: run(null, true), badTags }));
    JS;
    file_put_contents($child . $sep . 'run-portal-js.js', str_replace('__BASE__', B . '/', $harness));
    $process = proc_open(['node', $child . $sep . 'run-portal-js.js', $child . $sep . 'web' . $sep . 'public_html' . $sep . 'assets' . $sep . 'js' . $sep . 'portal.js',
        $child . $sep . 'web' . $sep . 'public_html' . $sep . 'assets' . $sep . 'js' . $sep . 'portal-tour.js'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $ran    = is_resource($process) === true ? (string) stream_get_contents($pipes[1]) : '';
    $errors = is_resource($process) === true ? (string) stream_get_contents($pipes[2]) : 'Node.js could not be started: is it installed?';
    if (is_resource($process) === true) {
        proc_close($process);
    }
    $js = json_decode($ran, true);
    ok('J08 portal.js, run with the header\'s tag saying "' . B . '/": Portal.url() puts B\'s part on, with one "/" between',
        is_array($js) === true && $js['prefixed']['built'] === [B . '/api/tours/active', B . '/admin/reports/builder/preview', B . '/calendar?view=list', B . '/'], 'Node.js said: ' . substr($ran . ' ' . $errors, 0, 300));
    ok('J09 run with no tag (an ordinary installation): Portal.url() gives the plain address', is_array($js) === true && $js['plain']['built'] === ['/api/tours/active', '/admin/reports/builder/preview', '/calendar?view=list', '/'], 'Node.js said: ' . substr($ran . ' ' . $errors, 0, 300));
    ok('J10 the tour script, run on a signed-in page of B, asks B\'s own address for the tour; with no tag, the plain one',
        is_array($js) === true && $js['prefixed']['asked'] === [B . '/api/tours/active'] && $js['plain']['asked'] === ['/api/tours/active'], 'Node.js said: ' . substr($ran . ' ' . $errors, 0, 300));
    ok('J11 Portal.url() refuses a route that starts with a backslash, a tab or a line break (5 tried): B\'s front page instead, and "/" with no tag',
        is_array($js) === true && $js['prefixed']['refused'] === array_fill(0, 5, B . '/') && $js['plain']['refused'] === array_fill(0, 5, '/'), 'Node.js said: ' . substr($ran . ' ' . $errors, 0, 400));
    ok('J12 Portal.url() does not believe a tag that does not start with exactly one "/" (6 tried): the plain address instead',
        is_array($js) === true && $js['badTags'] === array_fill(0, 6, '/api/tours/active'), 'Node.js said: ' . substr($ran . ' ' . $errors, 0, 400));

    // =========================================================================
    // K. A HOSTILE ORGANISATION KEY
    // =========================================================================
    head('K. An organisation key that holds quotes, an angle bracket, an ampersand and a backtick');
    // The organisation is made here and removed at the end of this part, so that the rest of the run
    // (the crawl above all) meets the same two organisations it always did.
    q('INSERT INTO tblSites (siteID, siteKey, siteName, hostPattern, isActive) VALUES (?, ?, ?, NULL, 1)', 'iss', [ORG_C, KEY_C, 'Addresses Selftest Charlie']);
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 1, 0)', 'ii', [ROOT, ORG_C]);
    $forHtml   = htmlspecialchars(KEY_C, ENT_QUOTES, 'UTF-8');
    $forScript = substr((string) json_encode(KEY_C, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), 1, -1);
    // Every way the key could be printed: each of its five dangerous characters raw, escaped for HTML, or
    // escaped for a script, in any mixture. (A copy encoded for a query, "%22" and so on, is harmless
    // and is not looked for.) A copy is right only when it is one of the two whole forms above.
    $anyCopy = '~st(?:"|&quot;|&\#0*34;|\\\\u0022)c(?:\'|&\#0*39;|&apos;|\\\\u0027)(?:<|&lt;|\\\\u003c)i(?:>|&gt;|\\\\u003e)(?:&amp;|\\\\u0026|&)`z~i';
    /** @return array{html:int, script:int, wrong:list<string>} How the key is printed in one page: right for HTML, right for a script, and every copy that is neither. */
    $copies = static function (string $body) use ($anyCopy, $forHtml, $forScript): array {
        $count = ['html' => 0, 'script' => 0, 'wrong' => []];
        // The page is cut into what is inside <script> blocks and what is outside them.
        foreach (preg_split('~(<script\b[^>]*>.*?</script>)~is', $body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $index => $piece) {
            $inScript = $index % 2 === 1;
            preg_match_all($anyCopy, $piece, $found);
            foreach ($found[0] as $copy) {
                if ($inScript === false && $copy === $forHtml) {
                    $count['html']++;
                } elseif ($inScript === true && $copy === $forScript) {
                    $count['script']++;
                } else {
                    $count['wrong'][] = ($inScript === true ? 'in a script: ' : 'in the page: ') . $copy;
                }
            }
        }

        return $count;
    };
    $hostile = '/' . KEY_C;
    $page    = req($nobody, 'GET', $hostile . '/no-such-page');
    $printed = $copies($page['body']);
    ok('K01 control: a request under the hostile key is answered by that organisation, and the page prints the key (' . $printed['html'] . ' time(s) in the page, ' . $printed['script'] . ' in a script)',
        $page['status'] === 404 && $printed['html'] >= 2 && $printed['script'] >= 1, said($page) . '; in the page ' . $printed['html'] . ', in a script ' . $printed['script']);
    ok('K02 signed out: no copy of the key is raw or half-escaped, in the page or in a script block', $printed['wrong'] === [] && str_contains($page['body'], KEY_C) === false, implode(' | ', array_slice($printed['wrong'], 0, 4)));
    ok('K03 the banner\'s script holds the address escaped for a script, so its quotes cannot end the text they sit in', str_contains($page['body'], "fetch('/" . $forScript . "/privacy/consent', {") === true);
    $jarHostile = signIn('root', $hostile);
    $page       = req($jarHostile, 'GET', $hostile . '/');
    $printed    = $copies($page['body']);
    ok('K04 signed in as an administrator there: the front page prints the key in every menu link (' . $printed['html'] . ' time(s)), and no copy is raw or half-escaped',
        $page['status'] === 200 && $printed['html'] >= 20 && $printed['wrong'] === [] && str_contains($page['body'], KEY_C) === false, said($page) . '; in the page ' . $printed['html'] . '; ' . implode(' | ', array_slice($printed['wrong'], 0, 4)));
    ok('K05 the tag for script files holds the key escaped for HTML', str_contains($page['body'], '<meta name="portal-base" content="/' . $forHtml . '/">') === true);
    q('DELETE FROM tblUserSites WHERE siteID = ?', 'i', [ORG_C]);
    q('DELETE FROM tblSettings WHERE siteID = ?', 'i', [ORG_C]);
    q('DELETE FROM tblSites WHERE siteID = ?', 'i', [ORG_C]);

    // =========================================================================
    // N. NO FIRST PARTS IN USE
    // =========================================================================
    head('N. No first parts in use: the sign-in redirects are what they always were');
    setWay(true, 'session');
    $asked = req($nobody, 'GET', '/account');
    ok('N01 sign-in session: signed out at /account, sent to /login with the address to come back to', $asked['status'] === 302 && $asked['location'] === '/login?redirect=%2Faccount', said($asked));
    $jarSession = signIn('bmember', '', '', '?redirect=%2Faccount');
    ok('N02 sign-in session: and back to /account after signing in', $lastSignIn['location'] === '/account', said($lastSignIn));
    ok('N03 sign-in session: an address that only LOOKS like another organisation\'s part is an ordinary path here, and is kept', sentTo($jarSession, '', '/' . KEY_A . '/account') === '/' . KEY_A . '/account', 'sent to ' . sentTo($jarSession, '', '/' . KEY_A . '/account'));
    ok('N04 sign-in session: another site is refused, to "/"', sentTo($jarSession, '', 'https://outside.selftest.invalid/x') === '/' && sentTo($jarSession, '', '//outside.selftest.invalid/x') === '/'
        && sentTo($jarSession, '', '/\\outside.selftest.invalid') === '/', 'sent to ' . sentTo($jarSession, '', 'https://outside.selftest.invalid/x'));
    ok('N05 sign-in session: "/<tab>/host" is refused here too (new with this change, in every set-up)', sentTo($jarSession, '', "/\t/outside.selftest.invalid") === '/' && sentTo($jarSession, '', '/%09/outside.selftest.invalid') === '/',
        'sent to ' . sentTo($jarSession, '', "/\t/outside.selftest.invalid"));
    $out = req($jarSession, 'GET', '/logout');
    ok('N06 sign-in session: signing out ends at "/", exactly as before', str_contains($out['body'], 'window.location.replace("/")') === true && str_contains($out['body'], '<a href="/">Continue</a>') === true
        && str_contains($out['body'], 'content="0;url=/"') === true);

    setWay(true, 'subdomain');
    $asked = req($nobody, 'GET', '/account', null, ['host' => HOST_B]);
    ok('N07 an address of its own each: signed out at /account on B\'s address, sent to /login there', $asked['status'] === 302 && $asked['location'] === '/login?redirect=%2Faccount', said($asked));
    $jarHost = signIn('bmember', '', HOST_B, '?redirect=%2Faccount');
    ok('N08 an address of its own each: and back to /account after signing in', $lastSignIn['location'] === '/account', said($lastSignIn));
    ok('N09 an address of its own each: the OTHER organisation\'s address is another host, and is refused', sentTo($jarHost, '', 'https://' . HOST_A . '/account', HOST_B) === '/' && sentTo($jarHost, '', '//' . HOST_A . '/account', HOST_B) === '/',
        'sent to ' . sentTo($jarHost, '', 'https://' . HOST_A . '/account', HOST_B));

    setWay(false, 'session');
    $jarSingle = signIn('u1', '', '', '?redirect=%2Faccount%3Ftab%3D2');
    ok('N10 one organisation only: back to the address asked for after signing in, and nowhere else for another site', $lastSignIn['location'] === '/account?tab=2' && sentTo($jarSingle, '', 'https://outside.selftest.invalid/') === '/', said($lastSignIn));
    $page = req($jarSingle, 'GET', '/');
    preg_match('#<nav class="navbar.*?</nav>#s', $page['body'], $menuSingle);
    $plainMenu = addressesIn($menuSingle[0] ?? '');
    ok('N11 one organisation only: no page prints the tag for script files, and the menu is plain', $page['status'] === 200 && str_contains($page['body'], 'portal-base') === false
        && in_array('/', $plainMenu, true) === true && in_array('/account', $plainMenu, true) === true && in_array('/logout', $plainMenu, true) === true, said($page) . ' menu: ' . implode(' ', $plainMenu));
    setWay(true, 'path');
}

// =============================================================================
// C. THE CRAWL
// =============================================================================
if ($noCrawl === false) {
    head('C. The crawl' . ($only !== [] ? ', for ' . implode(', ', $only) : ''));
    $mine = $routes;
    if ($only !== []) {
        // Only the pages whose files are in the places named, and everything on them is judged.
        $mine = array_values(array_filter($routes, static function (array $r) use ($only): bool {
            foreach ($only as $place) {
                if (str_starts_with('_apps/' . $r['targetFile'], $place . '/') === true) {
                    return true;
                }
            }

            return false;
        }));
        $pendingPlaces = [];
    }
    $jarCrawl = signIn('badmin', B);
    $started  = microtime(true);
    $with     = crawl($jarCrawl, B, $mine, $pendingPlaces);
    echo '        (asked ' . $with['asked'] . ' addresses under ' . B . '/ in ' . (int) (microtime(true) - $started) . ' s: ' . $with['full'] . ' judged in full, ' . $with['part']
        . ' for their shared furniture only; ' . $with['judged'] . ' addresses read; answers: ' . statuses($with) . ")\n";
    $floor = $only !== [] ? 1 : 400;
    ok('C01 the crawl asked for every registered page address under B\'s part, and each one answered (' . $with['asked'] . ')', $with['asked'] >= $floor && $with['unanswered'] === [],
        $with['asked'] . ' asked; no answer from: ' . implode(', ', array_slice($with['unanswered'], 0, 6)));
    ok('C02 it read a real number of addresses, so "none wrong" cannot mean "none read" (' . $with['judged'] . ')', $with['judged'] >= ($only !== [] ? $with['asked'] : 10000), $with['judged'] . ' read');
    ok('C03 every address it judged keeps B\'s part, or is a real file with none', array_filter($with['wrong'], static fn (string $w): bool => str_contains($w, 'no organisation\'s part')) === [], faults($with));
    ok('C04 none carries the part twice, another organisation\'s part, or a part in front of a real file',
        array_filter($with['wrong'], static fn (string $w): bool => preg_match('/twice|another organisation|real file/', $w) === 1) === [], faults($with));
    ok('C05 ' . ($only !== [] ? 'every one of those pages was judged in full' : 'pages of places already swept were judged in full, the rest for their shared furniture (' . $with['full'] . ' and ' . $with['part'] . ')'),
        // While places were still on the pending list this asked for "300 or more pages judged only in part".
        // The link sweep's second part (13 October 2026, #536) emptied the list, so that number became 0 and the
        // check failed on a tree that was right. With the list empty every page must be judged in full; if a
        // place is ever put back on the list, some are judged in part again and the older test applies.
        ($only !== [] || $pendingPlaces === []) ? ($with['part'] === 0 && $with['full'] === $with['asked']) : ($with['full'] >= 10 && $with['part'] >= 1), $with['full'] . ' in full, ' . $with['part'] . ' in part');

    setWay(true, 'session');
    $jarPlainCrawl = signIn('badmin');
    $started       = microtime(true);
    $without       = crawl($jarPlainCrawl, '', $mine, $pendingPlaces);
    echo '        (asked ' . $without['asked'] . ' addresses with no first part in ' . (int) (microtime(true) - $started) . ' s; ' . $without['judged'] . ' addresses read; answers: ' . statuses($without) . ")\n";
    ok('C06 the same crawl with no first parts in use: every address answered', $without['asked'] === $with['asked'] && $without['unanswered'] === [], $without['asked'] . ' asked; no answer from: ' . implode(', ', array_slice($without['unanswered'], 0, 6)));
    ok('C07 and no address anywhere holds an organisation\'s first part (' . $without['judged'] . ' read)', $without['judged'] >= ($only !== [] ? $without['asked'] : 10000) && $without['wrong'] === [], faults($without));
    ok('C08 and no page prints the tag for script files', $without['tagged'] === 0, $without['tagged'] . ' page(s) did');
    ok('C09 control: under B\'s part, the pages drawn with the shared header did print it (' . $with['tagged'] . ')', $only !== [] ? $with['tagged'] >= 0 : $with['tagged'] >= 200, $with['tagged'] . ' page(s)');
    if ($only === []) {
        $check = proc_open(['python3', $checkFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $text  = is_resource($check) === true ? (string) stream_get_contents($pipes[1]) : '';
        $code  = is_resource($check) === true ? proc_close($check) : -1;
        ok('C10 the check that reads the code agrees: no bare address outside its pending list', $code === 0, 'exit ' . $code . ': ' . substr($text, -400));
    }
    setWay(true, 'path');
}

// =============================================================================
// 🧹 Errors, and the clean-up
// =============================================================================
head('Clean-up');
cleanup();
$left         = removeWorld();
$settingsBack = true;
foreach ($before as $key => $row) {
    $settingsBack = $settingsBack && (one('SELECT settingID, settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [(string) $key]) === $row);
}
ok('clean-up: the made-up world is gone, and the three portal-wide settings are as they were', $left === 0 && $settingsBack === true && one('SELECT COUNT(*) AS n FROM tblSites WHERE siteID IN (?, ?, ?)', 'iii', [ORG_A, ORG_B, ORG_C])['n'] === 0,
    'rows left: ' . $left . '; settings back: ' . ($settingsBack === true ? 'yes' : 'NO'));
ok('clean-up: the server is stopped and the scratch folder is deleted', portAnswers($port) === false && file_exists($child) === false);

echo "\n{$pass} passed, {$fail} failed, " . ($pass + $fail) . ' ran of ' . $expected . " expected.\n";
if (($pass + $fail) !== $expected) {
    echo "FAIL — the number of checks that ran is not the number this file expects. A block was skipped or ran twice,\n";
    echo "or a check was added without updating the number at the top.\n";
    exit(1);
}
if ($fail > 0) {
    echo "FAIL — {$fail} check(s) failed. Read the FAIL lines above before releasing.\n";
    exit(1);
}
if ($only !== [] || $noCrawl === true) {
    echo "PASS — all {$pass} checks of this PART run passed, and it reached its end. It is NOT a full run: see the top of the output.\n";
    exit(0);
}
echo "PASS — all {$pass} checks passed. The run reached its end.\n";
exit(0);
