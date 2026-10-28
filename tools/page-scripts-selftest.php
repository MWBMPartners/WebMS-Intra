<?php
// Path: tools/page-scripts-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Scripts written into the real pages carry the page's nonce — self-test 🧾 (#625)
 * -----------------------------------------------------------------------------
 * WHAT WENT WRONG. Since 3 June 2026 (#144) the page header tells browsers to
 * run only the scripts that carry a one-time mark, a nonce. A browser then
 * refuses every <script> written into the page without it, and every handler
 * written on an element (onclick="…", onchange="…"). On 14 October 2026 that
 * was found on 43 files: Edit boxes opened empty, an expense claim could not be
 * filed, sixteen filters did nothing. Every test that asked the SERVER passed,
 * because the server sends such a page perfectly well.
 *
 * WHAT THIS TEST DOES. It is the run-time twin of
 * tools/audit-checks/check_inline_scripts_have_nonce.py. That check reads the
 * code; this asks the real pages and reads what they SEND, so it also sees a tag
 * printed by `echo`, drawn by a partial, or printed only when there is data.
 *
 * It makes a scratch copy of web/ with a throwaway connection file, starts
 * PHP's own web server behind a small router, signs two made-up people in
 * through the real sign-in page (an administrator and a member), and then:
 *
 *   A. proves its own reader on planted text, before trusting it;
 *   B. signs in, and finds the standard rule and its nonce on a real page;
 *   C. asks for each page this fault was found on (#625), with made-up rows so
 *      that the control is really drawn, and requires of each: it answers under
 *      the standard rule, the control is on the page, EVERY inline script
 *      carries the nonce named in THAT answer's own header, and NO element
 *      carries an on<event>= attribute;
 *   D. asks for every registered page address as each of the two people and
 *      requires the same of every answer that carries the standard rule;
 *   E. plants faults in the scratch copy, one at a time, and requires each to
 *      be caught, and the page to be clean again when the fault is taken out.
 *
 * WHICH PAGES IT JUDGES. Only an answer whose own Content-Security-Policy
 * header names a nonce for scripts. A page that sends its own rule or none
 * (sign-in, the public forms, the print pages, /api-docs) is counted and left
 * alone: its scripts run. The list of those is beside the check.
 *
 * WHAT IT CANNOT PROVE — read this before trusting a pass
 *   - IT RUNS NO SCRIPT, and it is not a browser. It proves a script is ALLOWED
 *     to run, not that it works. A page can pass everything here and still not
 *     work: on 14 October 2026 the Account page's script carried its nonce and
 *     its "Register New Passkey" button still did nothing, because the script,
 *     now allowed, stopped on its fifth line (it used Bootstrap before Bootstrap
 *     had loaded). Only pressing the button in a real browser showed that. Keep
 *     doing that before a release.
 *   - It sees what the server sends. A tag or a handler that a script adds to
 *     the page afterwards is not seen.
 *   - It sees each page as two made-up people, in one organisation, with a
 *     handful of made-up rows. A branch of a page drawn for somebody else, or
 *     for other data, is not seen. Part C names the pages it could not reach.
 *   - It asks for every address with GET and nothing after the address, so a
 *     page that needs a number in its address answers "not found" in part D;
 *     those on the affected list are asked for properly in part C.
 *   - It reads each page with PHP's own HTML reader (DOMDocument). If that
 *     reader lost part of a page, tags there would be missed; so a page under
 *     the standard rule in which it finds fewer than two marked scripts is
 *     reported, not passed.
 *   - Scripts and styles a browser refuses for other reasons (a script file
 *     from an address the rule does not name, `eval`) are not looked for.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             SELFTEST_WEB_DIR=/a/quiet/folder php tools/page-scripts-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default
 *         root), SELFTEST_WEB_PORT (default 9770). The database must already
 *         hold `web/_sql/full_schema.sql`. Needs PHP's curl and dom extensions,
 *         `proc_open()` and python3 (for the check).
 * Exit:   0 only when every check that was meant to run ran and passed; 1
 *         otherwise, including every refusal to run.
 *
 * WHAT IT CHANGES, AND PUTS BACK. In the database: two made-up people and a few
 * made-up rows, every one with a number from 925000 to 925999, removed by that
 * number; and the settings that switch apps on, each put back to what it was.
 * On disk: one scratch folder of its own, removed while it still holds this
 * run's marker. It never opens web/_auth_keys.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/625
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED = 88;

/** Every row this test inserts has this number, or the next ones. */
const BASE   = 925001;
const ADMIN  = 925001; // a global administrator, and an administrator of the organisation
const MEMBER = 925002; // an ordinary member

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
if (class_exists('DOMDocument') === false) {
    refuse('this PHP has no dom extension, which reads the pages');
}
$sep       = DIRECTORY_SEPARATOR;
$repo      = (string) realpath(dirname(__DIR__));
$webSrc    = $repo . $sep . 'web';
$checkFile = $repo . $sep . 'tools' . $sep . 'audit-checks' . $sep . 'check_inline_scripts_have_nonce.py';
$parentRaw = getenv('SELFTEST_WEB_DIR');
$parent    = realpath(($parentRaw === false || $parentRaw === '') ? sys_get_temp_dir() : (string) $parentRaw);
if ($parent === false || is_dir($parent) === false) {
    refuse('the scratch parent folder (SELFTEST_WEB_DIR) does not exist');
}
if (str_starts_with($repo . $sep, $parent . $sep) === true || str_starts_with($parent . $sep, $repo . $sep) === true) {
    refuse("the scratch parent folder '{$parent}' and the repository must not contain each other; choose another with SELFTEST_WEB_DIR");
}
$portRaw = getenv('SELFTEST_WEB_PORT');
// 🔌 9651, not 9770. 9770 is the port the activity-log self-test is given in
//    `portal-selftests.yml`, and both run in the same job — so if this test's
//    SELFTEST_WEB_PORT line were ever dropped, the default would silently
//    collide with a test running beside it. Found by an independent check on
//    28 October 2026, while this test was being wired into that workflow for
//    the first time (#646). A default that clashes with a neighbour is a trap
//    that only shows up when somebody tidies the environment block.
$port    = ($portRaw === false || $portRaw === '') ? 9651 : (int) $portRaw;
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
// 🔎 The reader: what one answer holds, and what is wrong with it
// =============================================================================

/**
 * Judge one answer.
 *
 * `rule` is 'standard' when the answer's own Content-Security-Policy header
 * names a nonce for scripts, and 'other' when it sends another rule or none.
 * Only a 'standard' answer is held to anything. For such an answer `wrong`
 * lists, in words:
 *   - a page that stops before its end;
 *   - an inline script with no nonce, or with a nonce that is not the one in
 *     THIS answer's header (a nonce copied from somewhere else is as useless
 *     to a browser as none);
 *   - an element with an on<event>= attribute;
 *   - an address that starts "javascript:".
 * `marked` is how many inline scripts carry the right nonce: the caller uses
 * it to tell "nothing wrong" from "nothing read".
 *
 * A script with src= is a file and needs no nonce here; a data block
 * (application/json, application/ld+json) is not a script.
 *
 * The page is read by PHP's own HTML reader and not by patterns, because a
 * pattern cannot tell an attribute NAMED onclick from the word "onclick="
 * inside another attribute's value, or a tag from the same letters inside a
 * script. LIBXML_NONET stops the reader fetching anything.
 *
 * @return array{rule:string, nonce:string, marked:int, inline:int, title:string, wrong:list<string>}
 */
function judge(string $head, string $body): array
{
    $verdict = ['rule' => 'other', 'nonce' => '', 'marked' => 0, 'inline' => 0, 'title' => '', 'wrong' => []];
    if (preg_match("/^Content-Security-Policy:[^\r\n]*?script-src[^;\r\n]*'nonce-([A-Za-z0-9+\/=_-]+)'/mi", $head, $m) !== 1) {
        return $verdict;
    }
    $verdict['rule']  = 'standard';
    $verdict['nonce'] = $m[1];
    if (trim($body) === '') {
        $verdict['wrong'][] = 'the answer has no page in it';

        return $verdict;
    }
    // A page that dies part-way still answers 200, because its header has already gone: what arrives is the top
    // of a page with nothing wrong IN IT. That must not read as clean. (The first run of this test planted a fault
    // that broke a partial instead; the page stopped there, and was judged clean.) The standard footer ends the page.
    if (stripos($body, '</html>') === false) {
        $verdict['wrong'][] = 'the page stops before its end (no </html>): it failed part-way, so what follows was never sent';
    }
    $before = libxml_use_internal_errors(true);
    $doc    = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($before);

    $titles           = $doc->getElementsByTagName('title');
    $verdict['title'] = $titles->length > 0 ? trim((string) $titles->item(0)->textContent) : '';
    foreach ($doc->getElementsByTagName('*') as $element) {
        $tag = strtolower($element->nodeName);
        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->nodeName);
            if (preg_match('/^on[a-z]+$/', $name) === 1) {
                $verdict['wrong'][] = "a handler on an element: <{$tag} {$name}=…>";
            }
            if (in_array($name, ['href', 'src', 'action', 'formaction'], true) === true
                && preg_match('/^\s*javascript:/i', (string) $attribute->nodeValue) === 1) {
                $verdict['wrong'][] = "an address that starts \"javascript:\" on <{$tag} {$name}=…>";
            }
        }
        if ($tag !== 'script' || $element->hasAttribute('src') === true) {
            continue;
        }
        if (preg_match('#^\s*application/(ld\+)?json\b#i', (string) $element->getAttribute('type')) === 1) {
            continue;
        }
        $verdict['inline']++;
        $start = trim((string) preg_replace('/\s+/', ' ', substr((string) $element->textContent, 0, 60)));
        if ($element->hasAttribute('nonce') === false) {
            $verdict['wrong'][] = 'a script with no nonce, which starts: ' . $start;
        } elseif (hash_equals($verdict['nonce'], (string) $element->getAttribute('nonce')) === false) {
            $verdict['wrong'][] = 'a script whose nonce is not the one in this answer\'s header, which starts: ' . $start;
        } else {
            $verdict['marked']++;
        }
    }

    return $verdict;
}

// =============================================================================
// 🗺️ The made-up world, and the settings
// =============================================================================

/** Every table this test inserts into, with the column its numbers go in. */
const WORLD = [
    ['tblSmallGroupMembers', 'membershipID'], ['tblSmallGroups', 'groupID'], ['tblFormFields', 'fieldID'], ['tblForms', 'formID'],
    ['tblVenueRooms', 'roomID'], ['tblVenues', 'venueID'], ['tblServicePlanItems', 'itemID'], ['tblServicePlans', 'planID'],
    ['tblServicePlan', 'planID'], ['tblLivestreamSchedule', 'scheduleID'], ['tblLivestreamChannel', 'channelID'],
    ['tblGivingCategory', 'categoryID'], ['tblPathways', 'pathwayID'], ['tblEvents', 'eventID'], ['tblEventSeries', 'seriesID'],
    ['tblLocalAccounts', 'userID'], ['tblUserSites', 'userID'], ['tblUsers', 'userID'],
];

/**
 * Remove every row with one of this test's numbers, and every row the PORTAL wrote about its two people. Returns how
 * many such rows are LEFT afterwards, which must be none.
 *
 * The second half matters: signing in and opening pages makes the portal write rows of its own that point at the
 * person (the activity log, for one), and the database then refuses to remove the person. The first draft removed
 * only this test's own tables and left both people behind. So, as tools/site-addresses-selftest.php does, the
 * database is asked for every column that points at a person, and each is cleared of this test's numbers. The link
 * checks are off only while that happens, and are put back whatever happens.
 */
function removeWorld(): int
{
    $columns = q(
        "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE "
        . "WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'tblUsers' "
        . "UNION SELECT c.TABLE_NAME, c.COLUMN_NAME FROM information_schema.COLUMNS c "
        . "JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE' "
        . "WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'userID'"
    );
    foreach (WORLD as [$table, $column]) {
        $columns[] = ['t' => $table, 'c' => $column];
    }
    $left = 0;
    q('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ([1, 2] as $round) {
            foreach ($columns as $column) {
                $where = '`' . $column['c'] . '` BETWEEN 925000 AND 925999';
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

function portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);

        return true;
    }

    return false;
}

$state = ['started' => false, 'cleaned' => false, 'proc' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'settings' => [], 'left' => -1];

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
        $state['left'] = removeWorld();
    } catch (Throwable $e) {
        echo 'FAIL — the clean-up could not put the database back: ' . $e->getMessage() . "\n";
    }
    // The scratch child: only the one this run made, and only while it still holds this run's marker.
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.page-scripts-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}

/**
 * One real HTTP request to the server this run started. Redirects are never
 * followed. An answer without this run's header came from somewhere else and
 * is returned as status -1, which no check expects.
 *
 * @param array<string,mixed>|null $post
 *
 * @return array{status:int, location:string, body:string, head:string}
 */
function req(string $jar, string $method, string $path, ?array $post = null): array
{
    global $port, $state;
    $handle = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 150,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => ['Expect:', 'Accept-Language: en'],
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
    // Headers and page are taken apart by the size curl itself reports, never by looking for a blank line.
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr((string) $raw, 0, $headSize);
    if (preg_match('/^X-Page-Scripts-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
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
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

/** What is wrong with an answer, in a few words, for a failure message. */
function faults(array $verdict): string
{
    return count($verdict['wrong']) . ' thing(s): ' . implode(' | ', array_slice($verdict['wrong'], 0, 3));
}

register_shutdown_function('cleanup');

// =============================================================================
// A. THE READER, PROVED ON PLANTED TEXT (no server yet)
// =============================================================================
head('A. The reader finds what it must, and nothing it must not');
$rule  = "HTTP/1.1 200 OK\r\nContent-Security-Policy: default-src 'self'; script-src 'self' 'nonce-abc123' 'unsafe-inline' https://cdn.example.invalid; style-src 'self'\r\n\r\n";
$page  = static fn (string $inside): string => '<!doctype html><html><head><title>T</title></head><body>' . $inside . '</body></html>';
$cases = [
    'A01 a script with this answer\'s nonce is right'                          => ['<script nonce="abc123">a();</script>', 0, 1],
    'A02 a script with no nonce is reported'                                    => ['<script>a();</script>', 1, 0],
    'A03 a script with another nonce is reported: it is not this answer\'s'      => ['<script nonce="zzz999">a();</script>', 1, 0],
    'A04 a script file needs no nonce here'                                     => ['<script src="/assets/js/portal.js" defer></script>', 0, 0],
    'A05 a JSON data block is not a script'                                     => ['<script type="application/json" id="d">{"a":"<script>"}</script>', 0, 0],
    'A06 a search-engine data block is not a script'                            => ['<script type="application/ld+json">{"a":1}</script>', 0, 0],
    'A07 a handler on an element is reported'                                   => ['<button type="button" onclick="go()">Go</button>', 1, 0],
    'A08 a handler in capitals, with no quotes, is reported'                    => ['<select ONCHANGE=this.form.submit()><option>a</option></select>', 1, 0],
    'A09 two handlers on one element are two faults'                            => ['<div onclick="a()" onkeydown="b()">x</div>', 2, 0],
    'A10 the WORD onclick= inside another attribute\'s value is not a handler'   => ['<button data-confirm="Never write onclick=&quot;x&quot; here">x</button><input value="onchange=1">', 0, 0],
    'A11 a tag written inside a script\'s own text is not a tag'                 => ['<script nonce="abc123">var h = "<button onclick=\\"x()\\">";</script>', 0, 1],
    'A12 a ">" inside a value does not end the tag, and what follows is read'   => ['<button data-claim=\'{"a":">"}\' onclick="x()">x</button>', 1, 0],
    'A13 a data- attribute named like a handler is not one'                     => ['<select data-portal-submit-on-change data-onchange="x"><option>a</option></select>', 0, 0],
    'A14 a javascript: address is reported'                                     => ['<a href="javascript:window.close()">Close</a>', 1, 0],
    'A15 a bare script AFTER a marked one is still found'                       => ['<script nonce="abc123">a();</script><p>x</p><script>b();</script>', 1, 1],
];
foreach ($cases as $label => [$inside, $wantWrong, $wantMarked]) {
    $verdict = judge($rule, $page($inside));
    ok($label, $verdict['rule'] === 'standard' && count($verdict['wrong']) === $wantWrong && $verdict['marked'] === $wantMarked,
        'expected ' . $wantWrong . ' fault(s) and ' . $wantMarked . ' marked; got ' . faults($verdict) . ', ' . $verdict['marked'] . ' marked');
}
$other = judge("HTTP/1.1 200 OK\r\nContent-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'\r\n\r\n", $page('<script>a();</script><button onclick="b()">x</button>'));
ok('A16 an answer whose own rule names no nonce is left alone: its scripts run', $other['rule'] === 'other' && $other['wrong'] === []);
$none = judge("HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n", $page('<script>a();</script>'));
ok('A17 so is an answer that sends no rule at all', $none['rule'] === 'other' && $none['wrong'] === []);
$styleOnly = judge("HTTP/1.1 200 OK\r\nContent-Security-Policy: default-src 'self'; style-src 'nonce-abc123'; script-src 'self'\r\n\r\n", $page('<script>a();</script>'));
ok('A18 a nonce for STYLES only is not a nonce for scripts', $styleOnly['rule'] === 'other');
$empty = judge($rule, '   ');
ok('A19 an empty page under the standard rule is a fault, not a pass', $empty['rule'] === 'standard' && count($empty['wrong']) === 1);
$cutOff = judge($rule, '<!doctype html><html><head><title>T</title></head><body><script nonce="abc123">a();</script><p>the page died here');
ok('A20 a page that stops before its end is a fault, however clean the part that arrived', count($cutOff['wrong']) === 1 && str_contains($cutOff['wrong'][0], 'stops before its end') === true, faults($cutOff));

// =============================================================================
// 🏗️ The world, the scratch copy and the server
// =============================================================================
$state['started'] = true;
if (removeWorld() > 0) {
    refuse('rows with this test\'s numbers (925000 to 925999) are in the database and could not be removed');
}
$site = (int) (one('SELECT MIN(siteID) AS s FROM tblSites WHERE isActive = 1')['s'] ?? 0);
if ($site <= 0) {
    refuse('the database has no organisation: load web/_sql/full_schema.sql first');
}
// The organisation's own zone, for the made-up rows that need one. A test must not choose a zone of its own.
$zone = (string) (one('SELECT timezone FROM tblSites WHERE siteID = ?', 'i', [$site])['timezone'] ?? '');
if ($zone === '' || in_array($zone, DateTimeZone::listIdentifiers(), true) === false) {
    refuse("the organisation's time zone ('{$zone}') is not one PHP knows");
}

// Every app switched on for the run, each switch put back afterwards. A fresh install has most apps off, and a page
// of an app that is off answers "App disabled". The switches are the installation's own rows named "<app>.enabled";
// "[^.]+" and not "[a-z0-9-]+", because three apps have an underscore in their name (service_plans, reading_plans,
// ai_assist) and the narrower pattern silently left them off. Part C fails if a page it needs is still disabled.
// Left alone: multisite.enabled (not an app: it changes how addresses work) and ai_assist.enabled (its pages can
// reach a service outside this machine).
$switches = q("SELECT settingID, settingKey, settingValue FROM tblSettings WHERE siteID IS NULL AND settingKey REGEXP '^[^.]+\\\\.enabled$' "
    . "AND settingKey NOT IN ('multisite.enabled', 'ai_assist.enabled')");
foreach ($switches as $row) {
    $on = in_array((string) $row['settingValue'], ['0', '1'], true) === true ? '1' : 'true'; // each row keeps its own spelling
    if ((string) $row['settingValue'] !== $on) {
        $state['settings'][] = $row;
        q('UPDATE tblSettings SET settingValue = ? WHERE settingID = ?', 'si', [$on, (int) $row['settingID']]);
    }
}
// Two more, so that the public-link boxes are drawn (they carried a handler).
foreach (['forms.allowPublic', 'service_plans.public_share.enabled'] as $key) {
    $row = one('SELECT settingID, settingKey, settingValue FROM tblSettings WHERE siteID IS NULL AND settingKey = ?', 's', [$key]);
    if ($row === null) {
        refuse("the database has no setting '{$key}'");
    }
    if ((string) $row['settingValue'] !== 'true') {
        $state['settings'][] = $row;
        q('UPDATE tblSettings SET settingValue = ? WHERE settingID = ?', 'si', ['true', (int) $row['settingID']]);
    }
}

// The two people.
$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
foreach ([[ADMIN, 'admin', 1], [MEMBER, 'member', 0]] as [$id, $who, $admin]) {
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, ?, ?)', 'issii',
        [$id, 'Page Scripts Selftest ' . ucfirst($who), "selftest-scripts-{$who}@page-scripts.selftest.invalid", $admin, $admin]);
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, ?, ?)', 'iiii', [$id, $site, $admin, $admin]);
    q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-scripts-' . $who, $hash]);
}

// The rows a page needs before it will open, or before it draws the control that was broken. Every date is worked
// out from today, never written down: a fixed date goes stale and the page stops drawing what is looked for.
$run      = substr($state['token'], 0, 8);
$now      = new DateTimeImmutable('now', new DateTimeZone($zone));
$start    = $now->modify('+2 days')->setTime(10, 0)->format('Y-m-d H:i:s'); // a reading of the event's own clock, in $zone
$end      = $now->modify('+2 days')->setTime(12, 0)->format('Y-m-d H:i:s');
$tomorrow = (int) $now->modify('+1 day')->format('w');                      // 0 is Sunday, as Livestream reads it
$n        = BASE;
q('INSERT INTO tblEventSeries (seriesID, siteID, seriesName, seriesSlug, isActive) VALUES (?, ?, ?, ?, 1)', 'iiss', [$n, $site, 'Page Scripts Selftest Series', 'page-scripts-series-' . $run]);
q('INSERT INTO tblEvents (eventID, siteID, seriesID, eventName, eventSlug, startDateTime, endDateTime, timezone, status, isPublic, isDeleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'draft\', 0, 0)',
    'iiisssss', [$n, $site, $n, 'Page Scripts Selftest Event', 'page-scripts-event-' . $run, $start, $end, $zone]);
q('INSERT INTO tblServicePlans (planID, siteID, name, isActive, displayToken) VALUES (?, ?, ?, 1, ?)', 'iiss', [$n, $site, 'Page Scripts Selftest Worship Plan', bin2hex(random_bytes(32))]);
q('INSERT INTO tblServicePlanItems (itemID, planID, sortOrder, itemType, slideTitle, slideBody) VALUES (?, ?, 1, \'text\', ?, ?)', 'iiss', [$n, $n, 'Selftest slide', 'Made-up words.']);
q('INSERT INTO tblServicePlan (planID, siteID, title, serviceDate, status, publicToken, isPublicShared) VALUES (?, ?, ?, ?, \'draft\', ?, 1)', 'iisss',
    [$n, $site, 'Page Scripts Selftest Run Sheet', $now->modify('+3 days')->format('Y-m-d'), bin2hex(random_bytes(16))]);
q('INSERT INTO tblSmallGroups (groupID, siteID, groupName, groupSlug, groupType, isOpenEnrolment, isActive) VALUES (?, ?, ?, ?, \'small-group\', 1, 1)', 'iiss', [$n, $site, 'Page Scripts Selftest Group', 'page-scripts-group-' . $run]);
q('INSERT INTO tblSmallGroups (groupID, siteID, groupName, groupSlug, groupType, isOpenEnrolment, isActive) VALUES (?, ?, ?, ?, \'small-group\', 1, 1)', 'iiss', [$n + 1, $site, 'Page Scripts Selftest Group Two', 'page-scripts-group2-' . $run]);
q('INSERT INTO tblSmallGroupMembers (membershipID, siteID, groupID, userID, memberRole, status) VALUES (?, ?, ?, ?, \'member\', \'active\')', 'iiii', [$n, $site, $n, MEMBER]);
q('INSERT INTO tblForms (formID, siteID, title, status, audience, publicToken, allowMultiple) VALUES (?, ?, ?, \'published\', \'public\', ?, 1)', 'iiss', [$n, $site, 'Page Scripts Selftest Form', bin2hex(random_bytes(16))]);
q('INSERT INTO tblFormFields (fieldID, formID, fieldKey, label, fieldType) VALUES (?, ?, \'your_name\', \'Your name\', \'text\')', 'ii', [$n, $n]);
q('INSERT INTO tblVenues (venueID, siteID, venueName, timezone, isActive) VALUES (?, ?, ?, ?, 1)', 'iiss', [$n, $site, 'Page Scripts Selftest Venue', $zone]);
q('INSERT INTO tblVenueRooms (roomID, siteID, venueID, roomName, isActive) VALUES (?, ?, ?, ?, 1)', 'iiis', [$n, $site, $n, 'Page Scripts Selftest Room']);
q('INSERT INTO tblPathways (pathwayID, siteID, name, isActive) VALUES (?, ?, ?, 1)', 'iis', [$n, $site, 'Page Scripts Selftest Pathway']);
q('INSERT INTO tblGivingCategory (categoryID, siteID, name, isActive) VALUES (?, ?, ?, 1)', 'iis', [$n, $site, 'Page Scripts Selftest Fund']);
q('INSERT INTO tblLivestreamChannel (channelID, siteID, name, platform, channelOrVideoId) VALUES (?, ?, ?, \'youtube\', \'selftestvideo\')', 'iis', [$n, $site, 'Page Scripts Selftest Channel']);
q('INSERT INTO tblLivestreamSchedule (scheduleID, channelID, dayOfWeek, startTime, endTime, timezone, isActive) VALUES (?, ?, ?, \'10:00:00\', \'12:00:00\', ?, 1)', 'iiis', [$n, $n, $tomorrow, $zone]);

// The scratch copy of web/, with a throwaway connection file and key.
$child = $parent . $sep . 'page-scripts-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.page-scripts-selftest-owner', $state['token']); // the marker FIRST
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
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\n// Written by tools/page-scripts-selftest.php for one run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/page-scripts-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Page-Scripts-Selftest: __TOKEN__');
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

// The server: its command as a LIST (no shell in between), ONE process, so nothing can outlive it. A page is given
// two minutes, not PHP's usual 30 seconds: on a busy machine a slow page would otherwise be cut short and read as a
// fault of the page. No stored copies of compiled pages: part E changes files and must be served the changed ones.
$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$state['proc']     = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1',
        '-d', 'display_errors=0', '-d', 'max_execution_time=120', '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
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

echo "\nScripts written into the real pages carry the page's nonce — self-test (#625)\n";
echo 'server process ' . (int) proc_get_status($state['proc'])['pid'] . ', port ' . $port . ', PHP ' . PHP_VERSION . ', database ' . (string) one('SELECT VERSION() AS v')['v'] . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";
echo count($state['settings']) . " setting(s) switched on for this run and put back at its end; organisation {$site}, zone {$zone}.\n";

/** Sign one made-up person in through the real sign-in page; returns their cookie jar. */
function signIn(string $who): string
{
    global $child, $sep, $password;
    $file   = $child . $sep . 'jar-' . $who . '-' . bin2hex(random_bytes(3)) . '.txt';
    $tok    = token(req($file, 'GET', '/login')['body']);
    $answer = req($file, 'POST', '/login', ['csrf_token' => $tok, 'username' => 'selftest-scripts-' . $who, 'password' => $password]);
    if ($answer['status'] !== 302 || str_contains($answer['location'], '/login') === true) {
        refuse("signing in as selftest-scripts-{$who} did not work (HTTP {$answer['status']} {$answer['location']})");
    }

    return $file;
}

// =============================================================================
// B. SIGNED IN, AND THE STANDARD RULE IS WHERE IT SHOULD BE
// =============================================================================
head('B. Two made-up people sign in, and the standard rule is found on a real page');
$jars   = ['admin' => signIn('admin'), 'member' => signIn('member')];
$home   = req($jars['admin'], 'GET', '/');
$atHome = judge($home['head'], $home['body']);
ok('B01 the administrator\'s dashboard answers under the standard rule, with a nonce in its own header', $home['status'] === 200 && $atHome['rule'] === 'standard' && strlen($atHome['nonce']) >= 16,
    'HTTP ' . $home['status'] . ', rule ' . $atHome['rule']);
ok('B02 the rule itself has not been weakened: it names the nonce, our own address, and only the two outside addresses for script files',
    preg_match("#script-src 'self' 'nonce-[0-9a-f]+' 'unsafe-inline' https://cdn\.jsdelivr\.net https://challenges\.cloudflare\.com;#", $home['head']) === 1,
    (string) (preg_match('/script-src[^;]*/', $home['head'], $m) === 1 ? $m[0] : 'no script-src in the header'));
ok('B03 every script written into the dashboard carries that nonce, and no element carries a handler (' . $atHome['marked'] . ' scripts)', $atHome['wrong'] === [] && $atHome['marked'] >= 2, faults($atHome));
ok('B04 the dashboard loads the shared script file for "send on change" and "select on click"', str_contains($home['body'], 'src="/assets/js/portal-actions.js"') === true);
$file = req('', 'GET', '/assets/js/portal-actions.js');
ok('B05 and that file is really there, and holds both attributes', $file['status'] === 200 && str_contains($file['body'], 'data-portal-submit-on-change') === true && str_contains($file['body'], 'data-portal-select-on-click') === true,
    'HTTP ' . $file['status']);
$login   = req('', 'GET', '/login');
$atLogin = judge($login['head'], $login['body']);
ok('B06 control: the sign-in page sends no script rule, so it is counted and left alone', $login['status'] === 200 && $atLogin['rule'] === 'other', 'rule ' . $atLogin['rule']);
$memberHome = req($jars['member'], 'GET', '/');
$atMember   = judge($memberHome['head'], $memberHome['body']);
ok('B07 the member\'s dashboard is right too, and its nonce is not the administrator\'s: a nonce is made for one answer',
    $memberHome['status'] === 200 && $atMember['wrong'] === [] && $atMember['marked'] >= 2 && $atMember['nonce'] !== $atHome['nonce'], faults($atMember));

// =============================================================================
// C. THE PAGES THE FAULT WAS FOUND ON (#625)
// =============================================================================
head('C. Each page the fault was found on: reached, the control is drawn, and nothing a browser would refuse');
$id = (string) BASE;
// [address, who, what must be on the page (proof that the control or script this fault broke is really drawn)]
$affected = [
    ['/admin/settings', 'admin', 'id="settingsSearch"'], ['/admin/users', 'admin', 'id="rolesModal"'], ['/admin/roles', 'admin', 'id="editRoleModal"'],
    ['/admin/departments', 'admin', 'id="editDeptModal"'], ['/admin/groups', 'admin', 'id="editGroupModal"'], ['/admin/sites', 'admin', 'data-site-edit="'],
    ['/admin/captcha', 'admin', 'id="captchaPriorityList"'], ['/admin/hymns', 'admin', 'id="publicShareEnabled"'],
    ['/admin/discipleship/pathways/edit?id=' . $id, 'admin', 'discipleship-autorule'], ['/admin/host-console/event?id=' . $id, 'admin', 'id="hostPromptForm"'],
    ['/admin/livestream/channels', 'admin', 'id="golive-form"'], ['/admin/live/chat', 'admin', 'js-mod-btn'],
    ['/attendance/record', 'admin', 'id="addCountRow"'], ['/calendar/manage', 'admin', 'id="venueCheckSelect-new"'], ['/calendar/manage/series', 'admin', 'id="editSeriesModal"'],
    ['/calendar/manage/series-edit?seriesID=' . $id, 'admin', 'id="selectAllEvents"'], ['/calendar/event/hub?eventID=' . $id, 'admin', 'data-hub-resource-type'],
    ['/documents', 'admin', 'id="docEditModal"'], ['/expenses/approve', 'admin', 'id="approveModal"'], ['/expenses/treasury', 'admin', 'id="payModal"'],
    ['/forms/edit?id=' . $id, 'admin', 'data-portal-select-on-click'], ['/forms/manage', 'admin', 'data-portal-select-on-click'],
    ['/giving/count', 'admin', 'data-portal-submit-on-change'], ['/giving/statements', 'admin', 'id="stmt-uk-tax-year"'], ['/leadership/assign', 'admin', 'id="transitionCard"'],
    ['/service-plans/edit?id=' . $id, 'admin', 'data-portal-select-on-click'], ['/small-groups/members?id=' . $id, 'admin', 'data-portal-submit-on-change'],
    ['/small-groups/report?id=' . $id, 'admin', 'data-portal-submit-on-change'], ['/venues?venue=' . $id, 'admin', 'id="rejected"'],
    ['/venues/agreements', 'admin', 'data-portal-submit-on-change'], ['/venues/invoices', 'admin', 'data-portal-submit-on-change'],
    ['/venues/generate', 'admin', 'data-portal-submit-on-change'], ['/venues/booking?venue=' . $id, 'admin', 'id="timeFields"'],
    ['/worship/plan?id=' . $id, 'admin', 'id="planItemList"'], ['/worship/present?planID=' . $id, 'admin', 'id="copyDisplayUrl"'],
    ['/account', 'member', 'id="btnRegisterPasskey"'], ['/expenses/submit', 'member', 'id="filesDropzone"'], ['/giving/give', 'member', 'portal-give-quick'],
    ['/live', 'member', 'id="ls-countdown"'], ['/calendar', 'member', 'id="cal-date-jump"'],
];
$notReached = [];
foreach ($affected as $index => [$address, $who, $marker]) {
    $answer  = req($jars[$who], 'GET', $address);
    $verdict = judge($answer['head'], $answer['body']);
    $reached = $answer['status'] === 200 && $verdict['rule'] === 'standard' && str_contains($answer['body'], $marker) === true;
    if ($reached === false) {
        $notReached[] = $address . ' (HTTP ' . $answer['status'] . ', "' . $verdict['title'] . '", ' . (str_contains($answer['body'], $marker) === true ? 'control drawn' : 'control NOT drawn: ' . $marker) . ')';
    }
    ok(sprintf('C%02d %s, as the %s: reached, the control is drawn, %d inline script(s) all with this answer\'s nonce, no handler', $index + 1, $address, $who === 'admin' ? 'administrator' : 'member', $verdict['marked']),
        $reached === true && $verdict['wrong'] === [] && $verdict['marked'] >= 2,
        $reached === false ? 'not reached: HTTP ' . $answer['status'] . ' ' . $answer['location'] . ', title "' . $verdict['title'] . '", looking for ' . $marker : faults($verdict));
}
echo '        (pages on the affected list not reached: ' . ($notReached === [] ? 'none' : implode('; ', $notReached)) . ")\n";

// =============================================================================
// D. EVERY REGISTERED PAGE ADDRESS, AS EACH PERSON
// =============================================================================
head('D. Every registered page address, as the administrator and as the member');
// Not asked for: "logout" (the rest of the crawl would run signed out, and judge nothing) and the scheduled jobs.
$routes = array_values(array_filter(
    q('SELECT routeKey, targetFile FROM tblRoutes ORDER BY routeKey'),
    static fn (array $r): bool => $r['routeKey'] !== 'logout' && str_starts_with((string) $r['routeKey'], 'cron/') === false
));

/**
 * Ask for every address as one person and judge each answer.
 *
 * @param list<array{routeKey:string, targetFile:string}> $routes
 *
 * @return array{asked:int, unanswered:list<string>, standard:int, other:int, marked:int, wrong:array<string,string>, statuses:array<int,int>, disabled:list<string>, blind:list<string>, failed:list<string>}
 */
function crawl(string $jar, array $routes): array
{
    $result = ['asked' => 0, 'unanswered' => [], 'standard' => 0, 'other' => 0, 'marked' => 0, 'wrong' => [], 'statuses' => [], 'disabled' => [], 'blind' => [], 'failed' => []];
    foreach ($routes as $route) {
        $answer = req($jar, 'GET', '/' . $route['routeKey']);
        $result['asked']++;
        if ($answer['status'] <= 0) {
            $result['unanswered'][] = $route['routeKey'] . ' (' . $answer['body'] . ')';
            continue;
        }
        $result['statuses'][$answer['status']] = ($result['statuses'][$answer['status']] ?? 0) + 1;
        if ($answer['status'] >= 500) {
            // Named in the output and judged no further: it is neither clean nor at fault here.
            $result['failed'][] = '/' . $route['routeKey'] . ' (' . $answer['status'] . ')';
            $result['other']++;
            continue;
        }
        $verdict = judge($answer['head'], $answer['body']);
        if ($verdict['rule'] !== 'standard') {
            $result['other']++;
            continue;
        }
        $result['standard']++;
        $result['marked'] += $verdict['marked'];
        if ($verdict['wrong'] !== []) {
            $result['wrong'][$route['routeKey']] = faults($verdict);
        }
        // "Nothing wrong" must not mean "nothing read": every page drawn with the standard header prints at least
        // two scripts of its own (the theme script and the fallback listener) and a title.
        if ($verdict['marked'] < 2 || $verdict['title'] === '') {
            $result['blind'][] = $route['routeKey'] . ' (' . $verdict['marked'] . ' marked, title "' . $verdict['title'] . '")';
        }
        if (str_starts_with($verdict['title'], 'App disabled') === true) {
            $result['disabled'][] = $route['routeKey'];
        }
    }

    return $result;
}

foreach (['admin' => 'D0', 'member' => 'D1'] as $who => $prefix) {
    $name    = $who === 'admin' ? 'the administrator' : 'the member';
    $jar     = signIn($who);
    $started = microtime(true);
    $result  = crawl($jar, $routes);
    ksort($result['statuses']);
    echo '        (as ' . $name . ': asked ' . $result['asked'] . ' addresses in ' . (int) (microtime(true) - $started) . ' s; ' . $result['standard'] . ' answered under the standard rule, '
        . $result['other'] . ' under another rule or none (redirects, files, stand-alone pages); ' . $result['marked'] . ' inline scripts read; answers: '
        . implode(', ', array_map(static fn (int $status, int $count): string => $count . ' x ' . $status, array_keys($result['statuses']), $result['statuses'])) . ")\n";
    if ($result['failed'] !== []) {
        // Named, and not counted as a fault of this test's subject: a page that fails by itself, with made-up
        // people and almost no records, is that page's own matter. It is not counted among the pages judged.
        // (A page that answers 200 and then stops part-way IS reported, by check 3 below.)
        echo '        (answered with a failure of the server\'s own, 500 or above: ' . implode(', ', array_slice($result['failed'], 0, 8)) . ")\n";
    }
    if ($result['disabled'] !== []) {
        echo '        (answered "App disabled", so the page itself was not seen: ' . implode(', ', array_slice($result['disabled'], 0, 12)) . (count($result['disabled']) > 12 ? ' and ' . (count($result['disabled']) - 12) . ' more' : '') . ")\n";
    }
    ok($prefix . '1 as ' . $name . ', every registered page address was asked for and each one answered (' . $result['asked'] . ')', $result['asked'] >= 400 && $result['unanswered'] === [],
        $result['asked'] . ' asked; no answer from: ' . implode(', ', array_slice($result['unanswered'], 0, 6)));
    ok($prefix . '2 a real number of them answered under the standard rule, so "none wrong" cannot mean "none read" (' . $result['standard'] . ' pages, ' . $result['marked'] . ' scripts)',
        $result['standard'] >= ($who === 'admin' ? 250 : 150) && $result['marked'] >= 2 * $result['standard'], $result['standard'] . ' pages, ' . $result['marked'] . ' scripts');
    ok($prefix . '3 on every one of them, each inline script carries that answer\'s own nonce and no element carries a handler', $result['wrong'] === [],
        count($result['wrong']) . ' page(s): ' . implode('; ', array_map(static fn (string $k, string $v): string => '/' . $k . ' — ' . $v, array_slice(array_keys($result['wrong']), 0, 4), array_slice($result['wrong'], 0, 4))));
    ok($prefix . '4 and on none of them could the reader have been blind (each has a title and at least two marked scripts)', $result['blind'] === [], implode(', ', array_slice($result['blind'], 0, 6)));
    $still = req($jar, 'GET', '/');
    ok($prefix . '5 ' . $name . ' was still signed in when the crawl ended, so it did not run signed out part of the way', $still['status'] === 200, 'HTTP ' . $still['status'] . ' ' . $still['location']);
}
$check = proc_open(['python3', $checkFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$text  = is_resource($check) === true ? (string) stream_get_contents($pipes[1]) : '';
$code  = is_resource($check) === true ? proc_close($check) : -1;
ok('D20 the check that reads the code agrees: no script without a nonce and no handler outside its exemption list', $code === 0, 'exit ' . $code . ': ' . substr($text, -400));

// =============================================================================
// E. PLANTED FAULTS, IN THE SCRATCH COPY, ONE AT A TIME
// =============================================================================
head('E. Each planted fault is caught, and the page is clean again when it is taken out');
$apps = $child . $sep . 'web' . $sep . '_apps' . $sep;
$core = $child . $sep . 'web' . $sep . '_core' . $sep;
$foot = "require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';";
// [label, file in the scratch copy, text to find, what to put in its place, the page that shows it, the words the reader must use]
$plants = [
    ['E01 a bare script written into a page', $apps . 'settings' . $sep . 'index.php', $foot, "?><script>var planted = 1;</script><?php\n" . $foot, '/admin/settings', 'a script with no nonce'],
    ['E02 a handler written on an element of a page', $apps . 'settings' . $sep . 'index.php', $foot, "?><button type=\"button\" onclick=\"planted()\">x</button><?php\n" . $foot, '/admin/settings', 'a handler on an element: <button onclick'],
    ['E03 a script with a nonce that is not this answer\'s', $apps . 'settings' . $sep . 'index.php', $foot, "?><script nonce=\"0123456789abcdef0123456789abcdef\">var planted = 1;</script><?php\n" . $foot, '/admin/settings', 'not the one in this answer'],
    // Planted just before the partial's own script, in the page's own text. (The first draft put it in front of the
    // file's first line, which pushed `declare(strict_types=1)` out of first place and broke the partial instead.)
    ['E04 a bare script printed by `echo` inside a partial that another page includes', $apps . 'calendar' . $sep . 'manage' . $sep . '_event_form.php', '<script nonce="',
        "<?php echo '<scr' . 'ipt>var planted = 1;</scr' . 'ipt>'; ?>\n<script nonce=\"", '/calendar/manage', 'a script with no nonce'],
    ['E05 the nonce taken off the script that _core/Asset.php writes into every page', $core . 'Asset.php', "'<script nonce=\"' . self::esc(App::cspNonce()) . '\">'", "'<script>'", '/', 'a script with no nonce'],
    ['E06 a "send on change" attribute turned back into a handler', $apps . 'venues' . $sep . 'generate.php', 'data-portal-submit-on-change', 'onchange="this.form.submit()"', '/venues/generate', 'a handler on an element: <select onchange'],
    ['E07 a javascript: link written into a page', $apps . 'settings' . $sep . 'index.php', $foot, "?><a href=\"javascript:void(0)\">x</a><?php\n" . $foot, '/admin/settings', 'an address that starts "javascript:"'],
];
$jar = signIn('admin');
foreach ($plants as [$label, $path, $find, $put, $address, $words]) {
    $original = (string) file_get_contents($path);
    $position = strpos($original, $find);
    if ($position === false) {
        ok($label . ': caught', false, 'the text to change was not found in ' . basename($path) . ', so nothing was planted');
        continue;
    }
    file_put_contents($path, substr_replace($original, $put, $position, strlen($find)));
    $answer  = req($jar, 'GET', $address);
    $planted = judge($answer['head'], $answer['body']);
    file_put_contents($path, $original);
    $back    = req($jar, 'GET', $address);
    $clean   = judge($back['head'], $back['body']);
    $caught  = array_filter($planted['wrong'], static fn (string $w): bool => str_contains($w, $words) === true) !== [];
    ok($label . ': caught on ' . $address . ', and the page is clean again without it',
        $answer['status'] === 200 && $planted['rule'] === 'standard' && $caught === true && $back['status'] === 200 && $clean['wrong'] === [] && $clean['marked'] >= 2,
        'planted: HTTP ' . $answer['status'] . ', ' . faults($planted) . ' | taken out: HTTP ' . $back['status'] . ', ' . faults($clean));
}

// =============================================================================
// 🧹 Errors, and the clean-up
// =============================================================================
head('Clean-up');
$errorLog = $child . $sep . 'php-errors.log';
$errors   = is_file($errorLog) === true ? array_values(array_filter(file($errorLog, FILE_IGNORE_NEW_LINES) ?: [], static fn (string $l): bool => preg_match('/PHP (Fatal|Parse)/', $l) === 1)) : [];
// Part E plants a fault by rewriting a file while the server runs; a request that lands mid-write is not expected,
// and a page that cannot be read at all would show here.
ok('Z01 no page failed to run during the whole test (no fatal or parse error in the server\'s own log)', $errors === [], count($errors) . ' line(s), the first: ' . substr((string) ($errors[0] ?? ''), 0, 300));
cleanup();
$leftRows = (int) $state['left']; // counted by removeWorld() itself, across every table that can point at a person
$leftSettings = 0;
foreach ($state['settings'] as $before) {
    $leftSettings += (string) (one('SELECT settingValue FROM tblSettings WHERE settingID = ?', 'i', [(int) $before['settingID']])['settingValue'] ?? '') === (string) $before['settingValue'] ? 0 : 1;
}
ok('Z02 every made-up row is gone, with every row the portal wrote about the two people, and every setting is back as it was', $leftRows === 0 && $leftSettings === 0,
    ($leftRows === -1 ? 'the removal did not finish' : $leftRows . ' row(s) left') . ', ' . $leftSettings . ' setting(s) not put back');
ok('Z03 the scratch folder is gone and the server has stopped', file_exists($child) === false && portAnswers($port) === false);

$total = $pass + $fail;
echo "\n{$pass} of {$total} checks passed.\n";
if ($total !== EXPECTED) {
    echo 'FAIL — this run made ' . $total . ' checks and a full run makes ' . EXPECTED . ": a block was skipped or ran twice.\n";
    exit(1);
}
echo "The test reached its end.\n";
exit($fail === 0 ? 0 : 1);
