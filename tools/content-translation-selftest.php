<?php
// Path: tools/content-translation-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Content translation, on the REAL pages and the REAL endpoint — self-test 🌐
 * -----------------------------------------------------------------------------
 * `tools/audit-checks/check_translation_text_from_db.py` reads this feature as
 * text: it pins the allow-list, insists one file calls the engine, and insists
 * that file takes its words from `ContentTranslation::readSourceText()`. It
 * cannot run the thing. This file runs the portal and asks it.
 *
 * WHAT IT IS FOR
 * --------------
 * #485 is the owner's decision (D-049) to finish content translation, which had
 * been built and connected to nothing. #761 is the three faults that would have
 * gone live the moment it was connected, and two of them are invisible to any
 * check that does not make a real request:
 *
 *   • **Reading back another organisation's words.** `lookupCached()` is keyed
 *     on the source row and asks nothing about who is reading, so posting a row
 *     number was enough. Section D is what fails if that comes back, and D02 is
 *     the decisive one: organisation B's announcement is REALLY TRANSLATED
 *     first, so the cache holds its words, and then a member of A asks for it
 *     by number. A fixture where nothing had ever been translated would pass on
 *     the broken code.
 *   • **Translating whatever was posted.** The old handler took the text from
 *     `$_POST['text']`, so the allow-list constrained the LABEL and not the
 *     words. C02 posts a `text` field carrying something else entirely and
 *     requires the answer to be a translation of the ROW, not of the posted
 *     text. **It is the one check in this file that fails on the old design
 *     while everything else about the feature still works** — the button
 *     translates, something comes back, the cache fills.
 *
 * NO OUTSIDE COMPANY IS CALLED. The run starts a SECOND tiny server of its
 * own, points `translation.libre.baseUrl` at it, and that stub answers in
 * LibreTranslate's shape. It also RECORDS what it was asked to translate and
 * how many times, which is what lets C02 prove the provider saw the row's
 * words and E01 prove the cache saved a call. Nothing in this run reaches the
 * internet, and no API key is needed.
 *
 * WHAT THIS CANNOT SHOW, said plainly because an overstated guarantee is worse
 * than none
 * ---------------------------------------------------------------------------
 *   • **It cannot prove the cap is obeyed by a PAID provider.** The stub is
 *     reached through the `libre` route, and `libreTranslate()` reports
 *     `costPence` 0 by design (a self-hosted LibreTranslate is free). So
 *     spending is made to happen by writing a row's `costPence` directly, and
 *     section F proves the LIMIT is obeyed, not that any provider's own figure
 *     is right. **On the `libre` route the limit can never bite**, because
 *     nothing ever adds to the spend — that is a real gap and it is #762.
 *   • **It cannot drive a real browser**, so the control's behaviour after the
 *     press — the toggle, the deadline, the abort path, and the sharing of the
 *     replacement form token across the page — is not exercised here at all.
 *     H03 proves the ROTATION really happens, by making two presses in
 *     sequence, and H04-H06 prove the SHAPE of the clash the sharing exists to
 *     prevent; the sharing itself is pinned by check F of
 *     `tools/audit-checks/check_translation_text_from_db.py`, which reads the
 *     script as text. **Said plainly rather than implied: no test in this
 *     repository runs that script.**
 *   • **It cannot tell the ENGINE's order from the HANDLER's gate.** Both ask
 *     the same `translation.enabled` setting, and the handler asks first, so
 *     no request can show whether `Translation::translate()` reads its cache
 *     before or after that test. E02 proves what a member meets; the engine's
 *     own order is defence in depth and is exercised by nothing here.
 *   • **It runs ONE worker** (`PHP_CLI_SERVER_WORKERS` is unset), so two
 *     presses never overlap. A test of two members translating the same piece
 *     at the same moment would pass on broken code and is not written.
 *   • It does not judge whether a translation is any good. The stub's answer is
 *     a marker, not a translation.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PASS=… \
 *             SELFTEST_WEB_DIR=/a/quiet/folder php tools/content-translation-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default
 *         root), SELFTEST_DB_PORT (default 3306), SELFTEST_WEB_PORT (default
 *         9671 — a port of its own, so it can run beside the other tests; the
 *         stub takes the next port up). The database must already hold
 *         `web/_sql/full_schema.sql`. Needs PHP's curl and `proc_open()`.
 *
 * @package   WebMS-Intra / tools
 * @author    Claude Opus 5 (for MWBM Partners Ltd, t/a MWservices)
 * @copyright All Rights Reserved -- MWBM Partners Ltd
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/485
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/761
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** Every row this test inserts is numbered in this range. */
const CT_LOW  = 927000;
const CT_HIGH = 927999;
const MEMBER_A = 927001;   // an ordinary member of organisation A
const ADMIN_A  = 927003;   // a global administrator, for the admin page

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
$sep  = DIRECTORY_SEPARATOR;
$repo = (string) realpath(dirname(__DIR__));
$webSrc = $repo . $sep . 'web';
foreach (['_core' . $sep . 'ContentTranslation.php', '_apps' . $sep . 'translation' . $sep . 'api' . $sep . 'content.php',
          '_core' . $sep . 'templates' . $sep . 'translate-control.php'] as $needed) {
    if (is_file($webSrc . $sep . $needed) === false) {
        refuse("web/{$needed} is not there");
    }
}
$parentRaw = getenv('SELFTEST_WEB_DIR');
$parent    = realpath(($parentRaw === false || $parentRaw === '') ? sys_get_temp_dir() : (string) $parentRaw);
if ($parent === false || is_dir($parent) === false) {
    refuse('the scratch parent folder (SELFTEST_WEB_DIR) does not exist');
}
if (str_starts_with($repo . $sep, $parent . $sep) === true || str_starts_with($parent . $sep, $repo . $sep) === true) {
    refuse("the scratch parent folder '{$parent}' and the repository must not contain each other; choose another with SELFTEST_WEB_DIR");
}
$portRaw = getenv('SELFTEST_WEB_PORT');
$port    = ($portRaw === false || $portRaw === '') ? 9671 : (int) $portRaw;
if ($port < 1024 || $port > 65534) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65534 (the stub takes the next port up)');
}
$stubPort = $port + 1;

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

/**
 * Remove every row this test's numbers reach, and every row the PORTAL wrote
 * about its people. Returns how many are LEFT, which must be none.
 *
 * The second half matters: signing in and opening pages makes the portal write
 * rows that point at a person (the activity log, for one), and the database
 * then refuses to remove the person.
 */
function removeWorld(): int
{
    $columns = q(
        "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE "
        . "WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'tblUsers' "
        . "UNION SELECT c.TABLE_NAME, c.COLUMN_NAME FROM information_schema.COLUMNS c "
        . "JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE' "
        . "WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME IN ('userID', 'siteID', 'announcementID', 'projectID', 'updateID', 'requestID', 'translationID')"
    );
    foreach ([['tblLocalAccounts', 'userID'], ['tblUserSites', 'userID'], ['tblUsers', 'userID'],
              ['tblUserTranslationPref', 'userID'], ['tblSites', 'siteID']] as [$table, $column]) {
        $columns[] = ['t' => $table, 'c' => $column];
    }
    $left = 0;
    q('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ([1, 2] as $round) {
            foreach ($columns as $column) {
                $where = '`' . $column['c'] . '` BETWEEN ' . CT_LOW . ' AND ' . CT_HIGH;
                if ($round === 1) {
                    q('DELETE FROM `' . $column['t'] . '` WHERE ' . $where);
                } else {
                    $left += (int) (one('SELECT COUNT(*) AS n FROM `' . $column['t'] . '` WHERE ' . $where)['n'] ?? 0);
                }
            }
        }
        // The cache is keyed on the SOURCE row, not on a number of its own in
        // this range, so it is cleared by the rows it points at.
        q('DELETE FROM `tblContentTranslation` WHERE `sourceID` BETWEEN ' . CT_LOW . ' AND ' . CT_HIGH);
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

$state = ['started' => false, 'cleaned' => false, 'proc' => null, 'stub' => null, 'child' => '',
          'token' => bin2hex(random_bytes(16)), 'left' => -1, 'settings' => []];

/**
 * Put back everything this run changed, ONCE. Also a shutdown function.
 *
 * The translation settings are portal-wide, so they are written down before
 * being changed and put back exactly — including rows that did NOT exist,
 * which are deleted rather than left behind.
 *
 * **"Everything this run changed" means every key `$state['settings']` holds,
 * and nothing else.** That is not the same as every key the run CAUSED to
 * change: a page this test presses can write settings the test never named,
 * and two were being left at the handler's defaults until 5 November 2026.
 * `rememberSetting()` is how a key the PORTAL changes gets into that list, and
 * anything pressing a settings page must call it first.
 *
 * **ONE RESIDUE IT DOES NOT PUT BACK, named rather than left to be found:
 * `tblSettings.updatedAt`.** The save handler sets `updatedAt = NOW()`; this
 * puts `settingValue` back and the column has no `ON UPDATE`, so those rows
 * keep the run's timestamp. The VALUES are right and this only ever runs on a
 * throwaway database, so it is cosmetic — but it is #694's shape exactly
 * ("the right key, the right value and the wrong last-changed date"), and that
 * is why it is written down instead of being covered by "put back everything".
 */
function cleanup(): void
{
    global $state, $db;
    if ($state['started'] === false || $state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
    foreach (['proc', 'stub'] as $which) {
        if (is_resource($state[$which]) === true) {
            proc_terminate($state[$which]);
            $until = microtime(true) + 5;
            while (proc_get_status($state[$which])['running'] === true && microtime(true) < $until) {
                usleep(50000);
            }
            if (proc_get_status($state[$which])['running'] === true) {
                proc_terminate($state[$which], 9);
            }
            proc_close($state[$which]);
            $state[$which] = null;
        }
    }
    try {
        foreach ($state['settings'] as $key => $was) {
            if ($was === null) {
                q('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [$key]);
            } else {
                q('UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingKey` = ? AND `siteID` IS NULL', 'ss', [$was, $key]);
            }
        }
        $state['left'] = removeWorld();
    } catch (Throwable $e) {
        echo 'FAIL — the clean-up could not put the database back: ' . $e->getMessage() . "\n";
    }
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.content-translation-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}

/**
 * Write down what a portal-wide setting holds now, WITHOUT changing it, so the
 * clean-up can put it back.
 *
 * 🚨 THIS EXISTS BECAUSE THE PORTAL CHANGES SETTINGS THIS TEST NEVER
 *    TOUCHES. `admin/translation/save` upserts SIX installation-wide keys from
 *    its own `??` defaults whenever a field is absent from the form — so
 *    section J, which posts five fields and presses Save eleven times, silently
 *    reset `translation.anthropic.model` and `translation.openai.model` to the
 *    handler's defaults. Measured with marker values: both came back as
 *    defaults after a run that reported "88 passed, 0 failed" with Z01 green.
 *
 *    **The lesson, because this is the second time: it is not enough to track
 *    the settings the TEST writes. Track every setting anything the test
 *    PRESSES can write.** Round 3 found the same thing one key over
 *    (`translation.libre.baseUrl`) and the fix then was to post that one field
 *    — which closed the instance, not the class. Z01 cannot see any of this,
 *    because it counts rows in this test's own number range and a settings row
 *    is not in it.
 */
function rememberSetting(string $key): void
{
    global $state;
    if (array_key_exists($key, $state['settings']) === true) {
        return;
    }
    $row = one('SELECT `settingValue` AS v FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL LIMIT 1', 's', [$key]);
    $state['settings'][$key] = $row === null ? null : (string) $row['v'];
}

/**
 * Set a portal-wide setting for this run, remembering what it was so the
 * clean-up can put it back. A key that did not exist is remembered as null and
 * deleted at the end.
 */
function setSetting(string $key, string $value): void
{
    global $state;
    rememberSetting($key);
    if ($state['settings'][$key] === null) {
        q('INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, ?, ?, ?, 0)',
            'sss', [$key, $value, $value]);
        // Remembered as null above, so the clean-up deletes it.
        return;
    }
    q('UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingKey` = ? AND `siteID` IS NULL', 'ss', [$value, $key]);
}

/**
 * One real HTTP request. Redirects are never followed. An answer without this
 * run's header came from somewhere else and is returned as status -1, which no
 * check expects.
 *
 * `$json` is sent as a JSON body, because `ApiAuth::body()` reads ONLY
 * `php://input` as JSON and never looks at `$_POST` — so a form-encoded post
 * to this endpoint arrives with no fields at all, the form token included.
 * Established by reading the method, after a first draft sent a form post and
 * every request was refused for a missing token.
 *
 * @param array<string,mixed>|null $form
 * @param array<string,mixed>|null $json
 * @param list<string>             $headers
 *
 * @return array{status:int, location:string, body:string, head:string, json:array<string,mixed>|null}
 */
function req(string $jar, string $method, string $path, ?array $form = null, ?array $json = null, array $headers = []): array
{
    global $port, $state;
    $handle = curl_init('http://127.0.0.1:' . $port . $path);
    $send   = array_merge(['Expect:', 'Accept-Language: en'], $headers);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 150,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
    ]);
    if ($jar !== '') {
        curl_setopt($handle, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $jar);
    }
    if ($json !== null) {
        $send[] = 'Content-Type: application/json';
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, (string) json_encode($json));
    } elseif ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($form ?? []));
    }
    curl_setopt($handle, CURLOPT_HTTPHEADER, $send);
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle), 'head' => '', 'json' => null];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr((string) $raw, 0, $headSize);
    if (preg_match('/^X-Content-Translation-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start', 'head' => '', 'json' => null];
    }
    $body    = substr((string) $raw, $headSize);
    $decoded = json_decode($body, true);
    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S*)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'body'     => $body,
        'head'     => $head,
        'json'     => is_array($decoded) === true ? $decoded : null,
    ];
}

/** The form token on a page. */
function token(string $html): string
{
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

/** The form token the control carries, which is what the real press uses. */
function controlToken(string $html): string
{
    return preg_match('/data-csrf="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

/** What the stub was asked, and how often. Read from its own log. */
function stubCalls(): array
{
    global $state;
    $path = (string) $state['child'] . DIRECTORY_SEPARATOR . 'stub-calls.log';
    $raw  = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $out = [];
    foreach (explode("\n", trim($raw)) as $line) {
        $row = json_decode($line, true);
        if (is_array($row) === true) {
            $out[] = $row;
        }
    }
    return $out;
}

/** How much PHP has written to its own error log. A page that throws lands here. */
function errorLogSize(): int
{
    global $state;
    clearstatcache(true);
    $path = (string) $state['child'] . DIRECTORY_SEPARATOR . 'php-errors.log';
    return is_file($path) === true ? (int) filesize($path) : 0;
}

register_shutdown_function('cleanup');

// =============================================================================
// 🏗️ The world: two organisations, three people, four pieces of content
// =============================================================================
$state['started'] = true;
if (removeWorld() > 0) {
    refuse('rows with this test\'s numbers (' . CT_LOW . ' to ' . CT_HIGH . ') are in the database and could not be removed');
}

// 🏢 ORGANISATION A IS THE ONE THE PORTAL REALLY SERVES, not a new row.
//    This matters and a first draft got it wrong: it made two fresh
//    organisations, and every lookup then answered 404 — correctly, because
//    the harness has no host detection switched on, so `Site::id()` resolves
//    to the installation's own first organisation and the content belonged to
//    neither of the new ones. The fix is to put A's content where the portal
//    is actually looking.
//
//    Organisation B is a second row, and nobody is ever SERVED as B. That is
//    a real limit of this harness and section D says so at the point it
//    matters: B's cached translation is written with SQL rather than earned by
//    B's own member pressing the button. The state is one production reaches
//    easily (B's member translates B's announcement), which is what makes the
//    fixture legitimate — a fixture in a state production can never reach
//    tests nothing.
$siteA = (int) (one('SELECT MIN(siteID) AS s FROM `tblSites` WHERE isActive = 1')['s'] ?? 0);
if ($siteA <= 0) {
    refuse('the database has no organisation: load web/_sql/full_schema.sql first');
}
$siteB = CT_LOW + 11;
// ⚠️ THE COLUMN IS `siteKey`, NOT `siteSlug`. A first draft guessed
//    `siteSlug` and the run died on its first line with "Unknown column" —
//    CLAUDE.md's "name things by the right identifier" trap, which says to
//    check a name against the thing that uses it (`web/_sql/full_schema.sql`)
//    rather than against what looks similar. Caught by running it.
q('INSERT INTO `tblSites` (`siteID`, `siteName`, `siteKey`, `isActive`, `timezone`) VALUES (?, ?, ?, 1, ?)',
    'isss', [$siteB, 'CT Selftest B', 'ct-selftest-b', 'Europe/London']);

$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
// `locale` is the language each person reads in. Member A reads Welsh, so a
// translation really has somewhere to go; the English source is what makes
// `Translation::detect()` answer 'en' and the two differ.
foreach ([[MEMBER_A, 'member-a', $siteA, 0, 'cy'], [ADMIN_A, 'admin-a', $siteA, 1, 'en']]
         as [$id, $who, $site, $admin, $locale]) {
    q('INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `locale`) VALUES (?, ?, ?, 1, ?, ?, ?)',
        'issiis', [$id, 'CT Selftest ' . $who, "selftest-ct-{$who}@content-translation.selftest.invalid", $admin, $admin, $locale]);
    q('INSERT INTO `tblUserSites` (`userID`, `siteID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES (?, ?, 1, ?, ?)',
        'iiii', [$id, $site, $admin, $admin]);
    q('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)',
        'iss', [$id, 'selftest-ct-' . $who, $hash]);
}

// The content. Each body is distinctive, so an answer can be traced to the row
// it really came from — which is the whole of section C.
$annA      = CT_LOW + 20;   // organisation A, published
$annB      = CT_LOW + 21;   // organisation B, published
$annDraft  = CT_LOW + 22;   // organisation A, NOT published
$annGone   = CT_LOW + 23;   // organisation A, expired
$bodyA     = 'The hall roof is being mended on Tuesday and the side door will be shut.';
$bodyB     = 'Organisation B is holding its own meeting about the car park.';
foreach ([[$annA, $siteA, 'ct-ann-a', $bodyA, 1, null, null],
          [$annB, $siteB, 'ct-ann-b', $bodyB, 1, null, null],
          [$annDraft, $siteA, 'ct-ann-draft', 'A draft nobody has published yet.', 0, null, null],
          [$annGone, $siteA, 'ct-ann-gone', 'An announcement that stopped being shown last year.', 1, null, '2025-01-01 00:00:00']]
         as [$id, $site, $slug, $body, $published, $publishAt, $expiresAt]) {
    q('INSERT INTO `tblAnnouncements` (`announcementID`, `siteID`, `title`, `slug`, `body`, `isPublished`, `isDeleted`, `publishAt`, `expiresAt`) '
      . 'VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)',
        'iissssss', [$id, $site, 'CT ' . $slug, $slug, $body, (string) $published, $publishAt, $expiresAt]);
}

$projA   = CT_LOW + 30;
$updA    = CT_LOW + 31;
// ⚠️ NO STANDALONE " a " IN THIS SENTENCE, ON PURPOSE. The first version
//    read "...money for a new boiler..." and `Translation::detect()` called it
//    WELSH: " a " is one of its Welsh markers, the English markers in that
//    sentence scored the same 1, and the tie goes to whichever language the
//    list reaches first — which is Welsh. The press then answered "already in
//    that language" and translated nothing. The detector is a cheap guess and
//    api-spec.json says so; this is a fixture working round it, not a fault.
$descA   = 'We are raising money to replace the boiler in our church hall this winter.';
$updBody = 'Thank you: the first thousand pounds came in this week.';
q('INSERT INTO `tblProject` (`projectID`, `siteID`, `slug`, `title`, `description`, `targetAmountPence`, `status`, `isPublic`) '
  . 'VALUES (?, ?, ?, ?, ?, 500000, ?, 1)',
    'iissss', [$projA, $siteA, 'ct-proj-a', 'CT Boiler appeal', $descA, 'active']);
q('INSERT INTO `tblProjectUpdate` (`updateID`, `projectID`, `content`) VALUES (?, ?, ?)', 'iis', [$updA, $projA, $updBody]);

// 🚨 THESE THREE EXIST BECAUSE WITHOUT THEM TWO CONDITIONS WERE PINNED BY
//    NOTHING, and an independent check measured it: the organisation test on
//    the project and project-update statements could be replaced with
//    `(p.siteID = ? OR 1 = 1)`, and `AND p.isPublic = 1` could be DELETED from
//    the PROJECT statement, and this test stayed at "66 passed, 0 failed" with
//    the hard gate green. The world had organisation B's ANNOUNCEMENT but no
//    project and no update of B, and no non-public project at all — so there
//    was nothing for either condition to refuse.
//
//    ⚠️ AND THESE THREE ROWS DID NOT CLOSE THE CLASS, which a first version
//    of this note claimed: a SECOND round found the project-UPDATE's own
//    `isPublic`, and the announcement's `isDeleted` and `publishAt` clauses,
//    equally untrippable. Three more rows below, with three more checks.
//    **Five conditions, not two — do not describe this as two.**
//
//    **A condition no fixture can trip is not a condition.** With the first
//    plant a member of A reads another organisation's project description and
//    its updates; with the second, a member reads a project
//    `projects/view.php` itself refuses to show.
$projB     = CT_LOW + 32;   // organisation B's project
$updB      = CT_LOW + 33;   // ...and an update on it
$projHidden = CT_LOW + 34;  // organisation A's own project, NOT public
$descB      = 'Organisation B is raising money to re-lay its own car park surface.';
$updBodyB   = 'B reports that the car park tender has been awarded this month.';
$descHidden = 'A quiet appeal nobody has published yet, for the vestry window.';
q('INSERT INTO `tblProject` (`projectID`, `siteID`, `slug`, `title`, `description`, `targetAmountPence`, `status`, `isPublic`) '
  . 'VALUES (?, ?, ?, ?, ?, 400000, ?, 1)',
    'iissss', [$projB, $siteB, 'ct-proj-b', 'CT B car park', $descB, 'active']);
q('INSERT INTO `tblProjectUpdate` (`updateID`, `projectID`, `content`) VALUES (?, ?, ?)', 'iis', [$updB, $projB, $updBodyB]);
q('INSERT INTO `tblProject` (`projectID`, `siteID`, `slug`, `title`, `description`, `targetAmountPence`, `status`, `isPublic`) '
  . 'VALUES (?, ?, ?, ?, ?, 300000, ?, 0)',
    'iissss', [$projHidden, $siteA, 'ct-proj-hidden', 'CT hidden appeal', $descHidden, 'planning']);

// 🚨 AND THREE MORE, because a SECOND round of the independent check found
//    that three further conditions were still pinned by nothing — the first
//    round's fix had closed two of five. Each of these was measured: delete
//    the matching clause and every check stayed green.
//
//      • an update on the NON-PUBLIC project, for `tblProjectUpdate`'s own
//        `isPublic` test (the only update in the world had a public parent);
//      • a SOFT-DELETED announcement, for `isDeleted = 0` (every fixture had
//        `isDeleted = 0`);
//      • an announcement whose `publishAt` is in the FUTURE, for the embargo
//        clause (every fixture had `publishAt` NULL).
//
//    All three are content the displaying page itself refuses to show, and
//    translating one would send it to an outside company and cache it where
//    the next reader of that row gets it.
$updHidden = CT_LOW + 35;   // an update on the not-public project
$annGonea  = CT_LOW + 36;   // soft-deleted
$annLater  = CT_LOW + 37;   // published, but not yet
$updHiddenBody = 'A quiet note about the vestry window, on an appeal nobody has published.';
q('INSERT INTO `tblProjectUpdate` (`updateID`, `projectID`, `content`) VALUES (?, ?, ?)',
    'iis', [$updHidden, $projHidden, $updHiddenBody]);
q('INSERT INTO `tblAnnouncements` (`announcementID`, `siteID`, `title`, `slug`, `body`, `isPublished`, `isDeleted`) '
  . 'VALUES (?, ?, ?, ?, ?, 1, 1)',
    'iisss', [$annGonea, $siteA, 'CT withdrawn', 'ct-ann-withdrawn',
              'A withdrawn notice about the burst pipe, which nobody should read now.']);
q('INSERT INTO `tblAnnouncements` (`announcementID`, `siteID`, `title`, `slug`, `body`, `isPublished`, `isDeleted`, `publishAt`) '
  . 'VALUES (?, ?, ?, ?, ?, 1, 0, DATE_ADD(NOW(), INTERVAL 1 DAY))',
    'iisss', [$annLater, $siteA, 'CT embargoed', 'ct-ann-embargoed',
              'An embargoed notice about the new minister, not to be seen until tomorrow.']);

// A prayer request, so section B refuses a row that really exists rather than
// one that happens to be missing. A refusal on a missing row would pass on
// code that had no allow-list at all.
$prayerA = CT_LOW + 40;
q('INSERT INTO `tblPrayerRequests` (`requestID`, `siteID`, `submitterID`, `subject`, `body`, `visibility`, `status`) '
  . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
    'iiissss', [$prayerA, $siteA, MEMBER_A, 'CT prayer subject', 'Please pray for my mother, who goes into hospital on Friday.', 'congregation', 'active']);

// ── the scratch copy of web/ ─────────────────────────────────────────────────
$child = $parent . $sep . 'content-translation-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.content-translation-selftest-owner', $state['token']);   // the marker FIRST
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
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
    "<?php\n// Written by tools/content-translation-selftest.php for one run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// ── the stub translation service ─────────────────────────────────────────────
// Answers in LibreTranslate's shape and writes down what it was asked. It is a
// SEPARATE process on its own port, because the portal's own server runs one
// worker: a stub served by the same server could never answer a request the
// portal made while handling a request.
$stub = <<<'STUB'
<?php
// Written by tools/content-translation-selftest.php for ONE run. Test only.
declare(strict_types=1);
$raw = (string) file_get_contents('php://input');
$in  = json_decode($raw, true);
$ask = is_array($in) === true ? (string) ($in['q'] ?? '') : '';
file_put_contents(
    __DIR__ . '/stub-calls.log',
    json_encode(['q' => $ask, 'source' => is_array($in) ? ($in['source'] ?? '') : '', 'target' => is_array($in) ? ($in['target'] ?? '') : '']) . "\n",
    FILE_APPEND
);
header('Content-Type: application/json');
// A marker rather than a translation: the test only needs to know WHICH words
// reached the provider, and a marker makes that unmistakable.
echo json_encode(['translatedText' => '[[CY]] ' . $ask]);
STUB;
file_put_contents($child . $sep . 'stub.php', $stub);
touch($child . $sep . 'stub-calls.log');
$state['stub'] = proc_open(
    [PHP_BINARY, '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
        '-S', '127.0.0.1:' . $stubPort, $child . $sep . 'stub.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'stub-output.log', 'a'], 2 => ['file', $child . $sep . 'stub-output.log', 'a']],
    $stubPipes,
    $child
);
if (is_resource($state['stub']) === false) {
    refuse('the stub translation service could not be started');
}

// ── the settings this run needs ──────────────────────────────────────────────
// Written down and put back by cleanup(). `libre` is the route, pointed at the
// stub; `monthCapPence` is set high so section F can lower it deliberately.
setSetting('translation.enabled', '1');
setSetting('translation.provider', 'libre');
setSetting('translation.libre.baseUrl', 'http://127.0.0.1:' . $stubPort);
setSetting('translation.libre.apiKey', '');
setSetting('translation.monthCapPence', '5000');
setSetting('api.translation.content.enabled', 'true');

// ── the portal's own server ──────────────────────────────────────────────────
$router = <<<'ROUTER'
<?php
// Written by tools/content-translation-selftest.php for ONE run. Test only.
declare(strict_types=1);
header('X-Content-Translation-Selftest: __TOKEN__');
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
    [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log',
        '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'max_execution_time=120',
        '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
        '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server-output.log', 'a'], 2 => ['file', $child . $sep . 'server-output.log', 'a']],
    $pipes,
    $child,
    $env
);
if (is_resource($state['proc']) === false) {
    refuse('the web server could not be started');
}
foreach ([$port, $stubPort] as $which) {
    $deadline = microtime(true) + 10;
    while (portAnswers($which) === false && microtime(true) < $deadline) {
        usleep(100000);
    }
    if (portAnswers($which) === false) {
        refuse('nothing answered on port ' . $which);
    }
}

echo "\nContent translation, on the real pages and endpoint — self-test (#485, #761)\n";
echo 'portal on port ' . $port . ', stub provider on port ' . $stubPort . ', PHP ' . PHP_VERSION
    . ', database ' . (string) one('SELECT VERSION() AS v')['v'] . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";
echo "organisations {$siteA} and {$siteB}; NOTHING in this run reaches the internet.\n";

/** Sign one made-up person in through the real sign-in page; returns their cookie jar. */
function signIn(string $who): string
{
    global $child, $sep, $password;
    $file   = $child . $sep . 'jar-' . $who . '-' . bin2hex(random_bytes(3)) . '.txt';
    $tok    = token(req($file, 'GET', '/login')['body']);
    $answer = req($file, 'POST', '/login', ['csrf_token' => $tok, 'username' => 'selftest-ct-' . $who, 'password' => $password]);
    if ($answer['status'] !== 302 || str_contains($answer['location'], '/login') === true) {
        refuse("signing in as selftest-ct-{$who} did not work (HTTP {$answer['status']} {$answer['location']})");
    }
    return $file;
}

/** Say that one person has permitted translation for their own account. */
function optIn(int $userId, bool $on): void
{
    q('INSERT INTO `tblUserTranslationPref` (`userID`, `autoTranslate`) VALUES (?, ?) '
      . 'ON DUPLICATE KEY UPDATE `autoTranslate` = VALUES(`autoTranslate`)',
        'ii', [$userId, $on === true ? 1 : 0]);
}

/**
 * One press of the control, as the script makes it: a JSON body with the form
 * token in both the body and the header.
 *
 * @param array<string,mixed> $extra Anything else to put in the body — used by
 *                                   C02 to send a `text` field the handler must
 *                                   ignore.
 */
function press(string $jar, string $table, int $id, string $language, string $tok, array $extra = []): array
{
    $body = array_merge([
        'sourceTable'    => $table,
        'sourceID'       => $id,
        'targetLanguage' => $language,
        'csrf_token'     => $tok,
    ], $extra);
    return req($jar, 'POST', '/api/translation/content', null, $body, ['X-CSRF-Token: ' . $tok]);
}

/** A fresh form token for a person, taken from a real page. */
function freshToken(string $jar): string
{
    return token(req($jar, 'GET', '/account/translation')['body']);
}

$jarA = signIn('member-a');
$jarAdmin = signIn('admin-a');

// =============================================================================
// A. THE GATE: signed in, switched on, opted in, and a form token
// =============================================================================
head('A. The gate: nothing is sent without a sign-in, the switch, the opt-in and a token');

optIn(MEMBER_A, false);

$answer = req('', 'POST', '/api/translation/content', null,
    ['sourceTable' => 'tblAnnouncements', 'sourceID' => $annA, 'targetLanguage' => 'cy', 'csrf_token' => 'x']);
ok('A01 a request with no sign-in is refused with 401', $answer['status'] === 401,
    'got HTTP ' . $answer['status'] . ': ' . substr($answer['body'], 0, 200));

$tokA = freshToken($jarA);
ok('A02 a real form token was found on the account page', strlen($tokA) === 64, 'got ' . strlen($tokA) . ' characters');

$answer = press($jarA, 'tblAnnouncements', $annA, 'cy', $tokA);
ok('A03 a member who has not opted in is refused with 403', $answer['status'] === 403,
    'got HTTP ' . $answer['status'] . ': ' . substr($answer['body'], 0, 300));
ok('A04 and the refusal tells them where to switch it on',
    str_contains(strtolower((string) ($answer['json']['message'] ?? '')), 'account') === true,
    'said: ' . (string) ($answer['json']['message'] ?? '(nothing)'));
ok('A05 nothing was sent to the provider while they had not opted in', count(stubCalls()) === 0,
    count(stubCalls()) . ' call(s) reached the stub');

optIn(MEMBER_A, true);
$answer = press($jarA, 'tblAnnouncements', $annA, 'cy', 'not-a-real-token');
ok('A06 a press with a token that is not this session\'s is refused', $answer['status'] === 403,
    'got HTTP ' . $answer['status']);
ok('A07 and still nothing reached the provider', count(stubCalls()) === 0, count(stubCalls()) . ' call(s)');

$answer = req($jarA, 'GET', '/api/translation/content');
ok('A08 only POST is accepted', $answer['status'] === 405, 'got HTTP ' . $answer['status']);

$answer2 = req($jarA, 'POST', '/api/translation/content', null,
    ['sourceTable' => 'tblAnnouncements', 'sourceID' => $annA, 'targetLanguage' => 'cy', 'csrf_token' => freshToken($jarA)],
    ['Authorization: Bearer wbms_0123456789abcdef0123456789abcdef']);
ok('A09 a request carrying an API key is refused with 403', $answer2['status'] === 403,
    'got HTTP ' . $answer2['status'] . ': ' . substr($answer2['body'], 0, 200));
ok('A10 and the refusal says a key cannot hold a member\'s own permission',
    str_contains(strtolower((string) ($answer2['json']['message'] ?? '')), 'permission') === true,
    'said: ' . (string) ($answer2['json']['message'] ?? '(nothing)'));

// =============================================================================
// B. THE ALLOW-LIST: a prayer request that really exists is still refused
// =============================================================================
head('B. The allow-list: a prayer request is refused although the row is really there');

$row = one('SELECT `body` AS b FROM `tblPrayerRequests` WHERE `requestID` = ?', 'i', [$prayerA]);
ok('B01 the prayer request really is in the database, so B02 refuses a row that EXISTS',
    $row !== null && str_contains((string) $row['b'], 'my mother') === true,
    'the fixture row is missing, so the next check would pass on code with no allow-list at all');

$tokA = freshToken($jarA);
$answer = press($jarA, 'tblPrayerRequests', $prayerA, 'cy', $tokA);
ok('B02 a prayer request is refused with 403, not translated', $answer['status'] === 403,
    'got HTTP ' . $answer['status'] . ': ' . substr($answer['body'], 0, 300));
ok('B03 and nothing about it reached the provider', count(stubCalls()) === 0,
    'the stub was asked: ' . json_encode(stubCalls()));

$tokA = freshToken($jarA);
$answer = press($jarA, 'tblUsers', ADMIN_A, 'cy', $tokA);
ok('B04 a table that is not on the list is refused with 403', $answer['status'] === 403, 'got HTTP ' . $answer['status']);
ok('B05 and nothing reached the provider', count(stubCalls()) === 0, json_encode(stubCalls()));

$tokA = freshToken($jarA);
$answer = press($jarA, 'tblAnnouncements', $annA, '', $tokA);
ok('B06 a press with no language is refused with 403', $answer['status'] === 403, 'got HTTP ' . $answer['status']);

// ⚠️ THIS PAIR FOUND A CLAUSE THAT COULD NOT FAIL. The gate's
//    "no language" test used to normalise the value first, and
//    `Translation::normaliseLocale('')` answers `'en'` — so the branch never
//    fired: a press with no language was read as English and came back
//    "already in that language" with HTTP 200. B06 is what caught it.
$before = count(stubCalls());
$tokA   = freshToken($jarA);
$answer = press($jarA, 'tblAnnouncements', $annA, 'zz', $tokA);
ok('B07 a language the portal does not offer is refused, before any money is spent',
    $answer['status'] === 403 && count(stubCalls()) === $before,
    'HTTP ' . $answer['status'] . '; calls before ' . $before . ', after ' . count(stubCalls())
    . ' — a made-up code would otherwise be paid for and the rubbish cached against a real row');

// =============================================================================
// C. THE WORDS COME FROM THE DATABASE (#761 fault 2)
// =============================================================================
head('C. The words come from the database, never from the request');

$tokA   = freshToken($jarA);
$answer = press($jarA, 'tblAnnouncements', $annA, 'cy', $tokA);
ok('C01 a good press answers 200', $answer['status'] === 200,
    'got HTTP ' . $answer['status'] . ': ' . substr($answer['body'], 0, 400));
ok('C01b and the answer is a translation of the announcement',
    (string) ($answer['json']['data']['translation'] ?? '') === '[[CY]] ' . $bodyA,
    'got: ' . (string) ($answer['json']['data']['translation'] ?? '(nothing)'));
$calls = stubCalls();
ok('C01c the provider was asked exactly once, for the ROW\'s own words',
    count($calls) === 1 && (string) ($calls[0]['q'] ?? '') === $bodyA,
    'the stub was asked: ' . json_encode($calls));

// The decisive one. A `text` field is posted carrying something else entirely.
// On the old design this was THE source of the words, so the answer would be a
// translation of the posted text and the cache would hold it against this real
// row's number.
$planted = 'PLANTED TEXT THAT IS IN NO ROW OF ANY TABLE ANYWHERE.';
$tokA    = freshToken($jarA);
$answer  = press($jarA, 'tblAnnouncements', $annA, 'de', $tokA, ['text' => $planted, 'sourceField' => 'title']);
ok('C02 a posted `text` field is ignored: the answer translates the ROW, not the request',
    $answer['status'] === 200 && (string) ($answer['json']['data']['translation'] ?? '') === '[[CY]] ' . $bodyA,
    'HTTP ' . $answer['status'] . ', translation: ' . (string) ($answer['json']['data']['translation'] ?? '(nothing)'));
$calls = stubCalls();
$everAsked = '';
foreach ($calls as $call) {
    $everAsked .= (string) ($call['q'] ?? '');
}
ok('C02b the planted text never reached the provider at all',
    str_contains($everAsked, 'PLANTED') === false,
    'the stub was asked: ' . json_encode($calls));
ok('C02c a posted `sourceField` is ignored too — the field is fixed per table',
    one('SELECT COUNT(*) AS n FROM `tblContentTranslation` WHERE `sourceID` = ? AND `sourceField` <> ?', 'is', [$annA, 'body'])['n'] === '0'
    || (int) one('SELECT COUNT(*) AS n FROM `tblContentTranslation` WHERE `sourceID` = ? AND `sourceField` <> ?', 'is', [$annA, 'body'])['n'] === 0,
    'a cache row was written against a field the request chose');

$cached = one('SELECT `translatedContent` AS t, `sourceField` AS f, `targetLanguage` AS l FROM `tblContentTranslation` '
    . 'WHERE `sourceTable` = ? AND `sourceID` = ? AND `targetLanguage` = ?', 'sis', ['tblAnnouncements', $annA, 'cy']);
ok('C03 the cache holds the translation of the row\'s own words, against `body`',
    $cached !== null && (string) $cached['t'] === '[[CY]] ' . $bodyA && (string) $cached['f'] === 'body',
    'cached: ' . json_encode($cached));

// The project and its update, so all three allow-listed kinds are exercised.
$tokA   = freshToken($jarA);
$answer = press($jarA, 'tblProject', $projA, 'cy', $tokA);
ok('C04 a project\'s description translates', $answer['status'] === 200
    && (string) ($answer['json']['data']['translation'] ?? '') === '[[CY]] ' . $descA,
    'HTTP ' . $answer['status'] . ': ' . (string) ($answer['json']['data']['translation'] ?? ''));
$tokA   = freshToken($jarA);
$answer = press($jarA, 'tblProjectUpdate', $updA, 'cy', $tokA);
ok('C05 an update posted against a project translates, found through its parent\'s organisation',
    $answer['status'] === 200 && (string) ($answer['json']['data']['translation'] ?? '') === '[[CY]] ' . $updBody,
    'HTTP ' . $answer['status'] . ': ' . (string) ($answer['json']['data']['translation'] ?? ''));

// =============================================================================
// D. ANOTHER ORGANISATION, AND ROWS NOBODY MAY SEE (#761 faults 1 and 3)
// =============================================================================
head('D. Another organisation\'s words, and rows nobody may see');

// FIRST put organisation B's announcement in the cache, ALREADY TRANSLATED.
// Without this, D02 would pass on the broken code: there would be nothing for
// the cache to hand over, so a leak could not show. It is inserted with SQL
// because nobody in this run is SERVED as organisation B (see the note beside
// the world above) — but it is a state production reaches the moment B's own
// member presses the button, which is what makes it a fair fixture.
q('INSERT INTO `tblContentTranslation` (`sourceTable`, `sourceID`, `sourceField`, `sourceLanguage`, '
  . '`targetLanguage`, `translatedContent`, `provider`, `costPence`) VALUES (?, ?, ?, ?, ?, ?, ?, 0)',
    'sisssss', ['tblAnnouncements', $annB, 'body', 'en', 'cy', '[[CY]] ' . $bodyB, 'libre']);
$cachedB = one('SELECT `translatedContent` AS t FROM `tblContentTranslation` WHERE `sourceID` = ? LIMIT 1', 'i', [$annB]);
ok('D01 organisation B\'s translated words really are in the cache, which is what makes D02 decisive',
    $cachedB !== null && str_contains((string) $cachedB['t'], 'car park') === true,
    'nothing cached for B: D02 would then pass on code that leaks the cache');

$tokA   = freshToken($jarA);
$answer = press($jarA, 'tblAnnouncements', $annB, 'cy', $tokA);
ok('D02 a member of A asking for B\'s announcement gets 404', $answer['status'] === 404,
    'got HTTP ' . $answer['status'] . ': ' . substr($answer['body'], 0, 300));
ok('D02b and B\'s words are nowhere in the answer',
    str_contains($answer['body'], 'car park') === false && str_contains($answer['body'], 'Organisation B') === false,
    'the answer carried B\'s content: ' . substr($answer['body'], 0, 400));

$tokA   = freshToken($jarA);
$answer = press($jarA, 'tblAnnouncements', $annDraft, 'cy', $tokA);
ok('D03 an unpublished announcement of this organisation gets 404', $answer['status'] === 404, 'got HTTP ' . $answer['status']);

$tokA   = freshToken($jarA);
$answer = press($jarA, 'tblAnnouncements', $annGone, 'cy', $tokA);
ok('D04 an expired announcement gets 404', $answer['status'] === 404, 'got HTTP ' . $answer['status']);

// 🚨 D04b-D04h: THE FIVE VISIBILITY CONDITIONS THAT WERE PINNED BY NOTHING.
//    Four checks closed two of them on 5 November 2026; the three below closed
//    the rest after a second round found them. **Each of these seven checks
//    was measured to go RED with its own condition removed** — counted that
//    way rather than by how many checks there are, because an earlier version
//    of this comment said "each of these four" about three conditions.
$tokA   = freshToken($jarA);
$answer = press($jarA, 'tblProject', $projB, 'cy', $tokA);
ok('D04b another organisation\'s project description gets 404',
    $answer['status'] === 404, 'got HTTP ' . $answer['status']
    . ' — without this, deleting the organisation test from the project statement is invisible');
ok('D04c and none of B\'s words are in the answer',
    str_contains($answer['body'], 'car park surface') === false,
    'the answer carried B\'s description: ' . substr($answer['body'], 0, 300));

$tokA   = freshToken($jarA);
$answer = press($jarA, 'tblProjectUpdate', $updB, 'cy', $tokA);
ok('D04d another organisation\'s project UPDATE gets 404, found through its parent',
    $answer['status'] === 404 && str_contains($answer['body'], 'tender') === false,
    'HTTP ' . $answer['status'] . ': ' . substr($answer['body'], 0, 300));

$tokA   = freshToken($jarA);
$answer = press($jarA, 'tblProject', $projHidden, 'cy', $tokA);
ok('D04e this organisation\'s own NOT-PUBLIC project gets 404',
    $answer['status'] === 404 && str_contains($answer['body'], 'vestry') === false,
    'HTTP ' . $answer['status'] . ' — `projects/view.php` refuses to show it, so translating it '
    . 'would read out something the page itself hides');

$tokA   = freshToken($jarA);
$answer = press($jarA, 'tblProjectUpdate', $updHidden, 'cy', $tokA);
ok('D04f an update on a NOT-PUBLIC project gets 404, through its parent',
    $answer['status'] === 404 && str_contains($answer['body'], 'vestry window') === false,
    'HTTP ' . $answer['status'] . ': ' . substr($answer['body'], 0, 300)
    . ' — the project statement\'s isPublic test was closed first and the UPDATE\'s was not');

$tokA   = freshToken($jarA);
$answer = press($jarA, 'tblAnnouncements', $annGonea, 'cy', $tokA);
ok('D04g a soft-deleted announcement gets 404',
    $answer['status'] === 404 && str_contains($answer['body'], 'burst pipe') === false,
    'HTTP ' . $answer['status'] . ': ' . substr($answer['body'], 0, 300));

$tokA   = freshToken($jarA);
$answer = press($jarA, 'tblAnnouncements', $annLater, 'cy', $tokA);
ok('D04h an announcement still under embargo gets 404',
    $answer['status'] === 404 && str_contains($answer['body'], 'new minister') === false,
    'HTTP ' . $answer['status'] . ': ' . substr($answer['body'], 0, 300)
    . ' — nothing should be sent to an outside company before members may read it');

$tokA   = freshToken($jarA);
$answer = press($jarA, 'tblAnnouncements', 0, 'cy', $tokA);
ok('D05 a row number of nought gets 404', $answer['status'] === 404, 'got HTTP ' . $answer['status']);
ok('D06 a missing row and a row this reader may not see give the SAME answer, so neither says which',
    $answer['status'] === 404,
    'the two must not be distinguishable: that is how a refusal would reveal a row exists');

// =============================================================================
// E. THE CACHE, AND THE SWITCH
// =============================================================================
head('E. The cache saves a call, and switching translation off really stops it');

$before = count(stubCalls());
$tokA   = freshToken($jarA);
$answer = press($jarA, 'tblAnnouncements', $annA, 'cy', $tokA);
ok('E01 a second press of the same piece answers from the cache, with no new provider call',
    $answer['status'] === 200 && count(stubCalls()) === $before,
    'HTTP ' . $answer['status'] . '; calls before ' . $before . ', after ' . count(stubCalls()));

setSetting('translation.enabled', '0');
$tokA   = freshToken($jarA);
$answer = press($jarA, 'tblAnnouncements', $annA, 'cy', $tokA);
// ⚠️ WHAT THIS PROVES, EXACTLY, AND WHAT IT DOES NOT. It proves the
//    USER-FACING guarantee: with translation switched off, a member pressing
//    the control on something already translated is refused. It does NOT
//    prove the ENGINE asks the switch before reading its cache, although
//    `Translation::translate()` was reordered to do so in the same change.
//    Measured, not assumed: putting the old order back leaves this check
//    GREEN, because the handler's own gate (`whyNotOffered()`) refuses with
//    403 before the engine is ever called, so no HTTP path can tell the two
//    orders apart. An earlier label here claimed it caught the reorder, which
//    was a reassurance the check could not support.
//
//    The reorder stays as defence in depth for any future caller, and the
//    hard gate `check_translation_text_from_db.py` is what forbids a second
//    caller appearing without its own checks.
ok('E02 with translation switched off, even an ALREADY CACHED piece is refused',
    $answer['status'] === 403,
    'got HTTP ' . $answer['status'] . ': ' . substr($answer['body'], 0, 300));
setSetting('translation.enabled', '1');

// =============================================================================
// F. THE MONTHLY LIMIT
// =============================================================================
head('F. The monthly limit is obeyed where the money is spent');

// Spending is made to happen by writing a cache row's own cost, because the
// stub is reached through the `libre` route and that route reports 0 pence by
// design. See "WHAT THIS CANNOT SHOW" at the top, and #762.
q('UPDATE `tblContentTranslation` SET `costPence` = 9999 WHERE `sourceID` = ?', 'i', [$annA]);
setSetting('translation.monthCapPence', '100');

$before = count(stubCalls());
$tokA   = freshToken($jarA);
// A piece never translated before, so it would need a paid call.
$answer = press($jarA, 'tblProjectUpdate', $updA, 'fr', $tokA);
ok('F01 past the month\'s limit, a piece never translated before is refused with 503',
    $answer['status'] === 503, 'got HTTP ' . $answer['status'] . ': ' . substr($answer['body'], 0, 300));
ok('F01b and nothing was sent to the provider', count(stubCalls()) === $before,
    'calls before ' . $before . ', after ' . count(stubCalls()));
ok('F01c the refusal says the limit is reached, not that the service failed',
    str_contains(strtolower((string) ($answer['json']['message'] ?? '')), 'limit') === true,
    'said: ' . (string) ($answer['json']['message'] ?? '(nothing)'));

$tokA   = freshToken($jarA);
$answer = press($jarA, 'tblAnnouncements', $annA, 'cy', $tokA);
ok('F02 past the limit, a piece ALREADY translated is still served',
    $answer['status'] === 200 && (string) ($answer['json']['data']['translation'] ?? '') === '[[CY]] ' . $bodyA,
    'HTTP ' . $answer['status'] . ': ' . (string) ($answer['json']['data']['translation'] ?? ''));

// An organisation may hold itself to LESS than the installation allows and
// never to more (D-013). This row is written with raw SQL on purpose: the
// settings page refuses to add a per-organisation row for a key the
// installation already holds, so it is a state no administrator can produce —
// and the reader must still cap it (#668: a save-time cap is not a cap).
setSetting('translation.monthCapPence', '100');
q('INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, ?, ?, ?, 0)',
    'isss', [$siteA, 'translation.monthCapPence', '999999', '5000']);
$before = count(stubCalls());
$tokA   = freshToken($jarA);
$answer = press($jarA, 'tblProjectUpdate', $updA, 'fr', $tokA);
ok('F03 an organisation row ABOVE the installation\'s limit does not raise it',
    $answer['status'] === 503 && count(stubCalls()) === $before,
    'HTTP ' . $answer['status'] . '; calls before ' . $before . ', after ' . count(stubCalls())
    . ' — the installation\'s figure must win, whatever the organisation\'s row says');
q('DELETE FROM `tblSettings` WHERE `siteID` = ? AND `settingKey` = ?', 'is', [$siteA, 'translation.monthCapPence']);

// Put the money back so the page sections are not refused.
q('UPDATE `tblContentTranslation` SET `costPence` = 0 WHERE `sourceID` = ?', 'i', [$annA]);
setSetting('translation.monthCapPence', '5000');

// =============================================================================
// G. THE PAGES
// =============================================================================
head('G. The control appears exactly where the rule allows it');

$logBefore = errorLogSize();
$answer    = req($jarA, 'GET', '/announcements/view?slug=ct-ann-a');
ok('G01 the announcement page opens for a member', $answer['status'] === 200, 'got HTTP ' . $answer['status']);
ok('G01b and it FINISHED — the closing tag is there and PHP logged nothing',
    str_contains($answer['body'], '</html>') === true && errorLogSize() === $logBefore,
    'log grew by ' . (errorLogSize() - $logBefore) . ' bytes; a page that throws part-way still answers 200');
ok('G02 the control is on the page, naming this announcement',
    str_contains($answer['body'], 'data-portal-translate') === true
    && str_contains($answer['body'], 'data-table="tblAnnouncements"') === true
    && str_contains($answer['body'], 'data-id="' . $annA . '"') === true,
    'the control is missing or names the wrong row');
preg_match('/data-language="([a-z-]*)"/', $answer['body'], $langSeen);
ok('G02b it carries the reader\'s OWN STORED language (cy), although the interface is English',
    str_contains($answer['body'], 'data-language="cy"') === true,
    'member A\'s tblUsers.locale is cy, so the control must ask for cy; it asked for "'
    . (string) ($langSeen[1] ?? '(no data-language at all)') . '"');
ok('G02c and a real form token', strlen(controlToken($answer['body'])) === 64,
    'got ' . strlen(controlToken($answer['body'])) . ' characters');
ok('G02d the control\'s address does not end in .php',
    preg_match('/data-url="[^"]*\.php/', $answer['body']) !== 1,
    'an address ending in .php is answered 404 by .htaccess');

optIn(MEMBER_A, false);
$answer = req($jarA, 'GET', '/announcements/view?slug=ct-ann-a');
ok('G03 with the opt-in off the control is GONE from the page',
    $answer['status'] === 200 && str_contains($answer['body'], 'data-portal-translate') === false,
    'the control was still drawn for somebody who has not permitted it');
optIn(MEMBER_A, true);

setSetting('translation.enabled', '0');
$answer = req($jarA, 'GET', '/announcements/view?slug=ct-ann-a');
ok('G04 with translation switched off the control is gone too',
    $answer['status'] === 200 && str_contains($answer['body'], 'data-portal-translate') === false,
    'the control was drawn although translation is off for the organisation');
setSetting('translation.enabled', '1');

$logBefore = errorLogSize();
$answer    = req($jarA, 'GET', '/projects/view?slug=ct-proj-a');
ok('G05 the project page opens and finishes',
    $answer['status'] === 200 && str_contains($answer['body'], '</html>') === true && errorLogSize() === $logBefore,
    'HTTP ' . $answer['status'] . ', log grew ' . (errorLogSize() - $logBefore));
ok('G05b it carries a control for the project and one for its update',
    str_contains($answer['body'], 'data-table="tblProject"') === true
    && str_contains($answer['body'], 'data-table="tblProjectUpdate"') === true
    && str_contains($answer['body'], 'data-id="' . $updA . '"') === true,
    'one of the two controls is missing or names the wrong row');

$answer = req('', 'GET', '/projects/view?slug=ct-proj-a');
ok('G06 a visitor who is NOT signed in sees the public project page',
    $answer['status'] === 200, 'got HTTP ' . $answer['status']);
ok('G06b and NO control, so a passer-by cannot spend the organisation\'s money',
    str_contains($answer['body'], 'data-portal-translate') === false,
    'the control was drawn for somebody not signed in, on a page anybody can open');

$answer = req($jarA, 'GET', '/prayer-requests');
ok('G07 the prayer requests page carries no translate control at all',
    str_contains($answer['body'], 'data-portal-translate') === false,
    'a prayer request must never offer translation (D-049)');

// =============================================================================
// H. THE REPLACEMENT FORM TOKEN
// =============================================================================
head('H. Every answer hands back the replacement form token');

$tokA   = freshToken($jarA);
$answer = press($jarA, 'tblAnnouncements', $annA, 'cy', $tokA);
$handedBack = (string) ($answer['json']['meta']['csrfToken'] ?? '');
ok('H01 a success carries meta.csrfToken', strlen($handedBack) === 64, 'got ' . strlen($handedBack) . ' characters');
ok('H01b and it is a DIFFERENT token from the one just spent', $handedBack !== $tokA && $handedBack !== '',
    'the token was not replaced, so a second press would be refused');

$answer = press($jarA, 'tblAnnouncements', $annB, 'cy', $handedBack);
ok('H02 a refusal reached after the rotation carries the replacement too',
    $answer['status'] === 404 && strlen((string) ($answer['json']['meta']['csrfToken'] ?? '')) === 64,
    'HTTP ' . $answer['status'] . ', token length '
    . strlen((string) ($answer['json']['meta']['csrfToken'] ?? '')));

$next = (string) ($answer['json']['meta']['csrfToken'] ?? '');
$answer = press($jarA, 'tblAnnouncements', $annA, 'cy', $next);
ok('H03 the handed-back token really works for the next press, with no page reload',
    $answer['status'] === 200, 'got HTTP ' . $answer['status'] . ' — two presses in a row must both be accepted');

// 🚨 H04-H06: WHY THE SCRIPT HAS TO SHARE THE TOKEN WITH THE WHOLE PAGE.
//    An independent check measured the harm on 5 November 2026: press Translate
//    on `/projects/view`, then pledge, and the pledge was refused with a bare
//    "Bad request" and what the member typed was gone. A second Translate
//    control on the same page was refused for ever, and a refused form token is
//    the ONE refusal that carries no replacement.
//
//    **WHAT THESE THREE CHECKS CAN AND CANNOT DO, said plainly.** Nothing here
//    drives a browser, so they cannot prove the script really redistributes the
//    replacement — that is pinned by check F of
//    `tools/audit-checks/check_translation_text_from_db.py`, which fails when
//    the sharing call goes. What they DO prove is the SHAPE of the harm: that
//    the page's own form and the control carry one token, and that a press
//    spends it. So if somebody ever concludes the sharing is unnecessary,
//    these say why it is not.
head('H2. One form token for the whole page, which is why the script shares it');

$projectPage = req($jarA, 'GET', '/projects/view?slug=ct-proj-a');
$pageToken   = token($projectPage['body']);
$ctrlToken   = controlToken($projectPage['body']);
ok('H04 the pledge form and the Translate control carry the SAME token',
    $pageToken !== '' && $pageToken === $ctrlToken,
    'form token "' . substr($pageToken, 0, 12) . '…", control token "' . substr($ctrlToken, 0, 12) . '…"'
    . ' — if these ever differ, read the note above before deleting anything');

$countControls = preg_match_all('/data-portal-translate(?![a-z-])/', $projectPage['body']);
ok('H05 the page really carries more than one control, so the clash is reachable',
    $countControls >= 2,
    'found ' . $countControls . ' control(s); with only one, H06 proves nothing');

$answer = press($jarA, 'tblProject', $projA, 'cy', $pageToken);
$replacement = (string) ($answer['json']['meta']['csrfToken'] ?? '');
ok('H06 a press SPENDS that one token and hands back a replacement',
    $answer['status'] === 200 && strlen($replacement) === 64 && $replacement !== $pageToken,
    'HTTP ' . $answer['status'] . ', replacement length ' . strlen($replacement)
    . ' — every other control AND the pledge form on that page are now holding a spent '
    . 'token, which is exactly why `shareToken()` writes the replacement into all of them');

// =============================================================================
// I. THE MEMBER'S OWN PAGE AND THE ADMINISTRATOR'S
// =============================================================================
head('I. What the two pages tell a person');

$answer = req($jarA, 'GET', '/account/translation');
$words  = strtolower($answer['body']);
ok('I01 the member\'s page opens and finishes',
    $answer['status'] === 200 && str_contains($answer['body'], '</html>') === true, 'HTTP ' . $answer['status']);
ok('I02 it says the text goes to an outside company', str_contains($words, 'outside') === true,
    'this is the consent box: it cannot ask for permission without saying what for');
ok('I03 it says prayer requests are never translated', str_contains($words, 'prayer request') === true,
    'a member must not be left thinking their prayer request may be sent away');
ok('I04 it no longer claims to translate automatically',
    str_contains($words, 'always translate') === false,
    'the old label promised something the portal does not do');

$answer = req($jarAdmin, 'GET', '/admin/translation');
$words  = strtolower($answer['body']);
ok('I05 the administrator\'s page opens and finishes',
    $answer['status'] === 200 && str_contains($answer['body'], '</html>') === true, 'HTTP ' . $answer['status']);
ok('I06 it no longer says nothing is being translated',
    str_contains($words, 'nothing is being translated') === false,
    'that warning was true until this feature was finished, and is now false');
ok('I07 it names what members can translate, and says prayer requests are not',
    str_contains($words, 'announcement') === true && str_contains($words, 'prayer request') === true,
    'an administrator cannot see the allow-list from the settings alone');
ok('I08 it says each member must switch it on for themselves',
    str_contains($words, 'account') === true, 'otherwise an administrator expects members to see translations at once');

// =============================================================================
// J. THE MONEY LIMIT'S OWN SAVE HANDLER
// =============================================================================
// 🚨 NOTHING POSTED TO THIS PAGE UNTIL 5 November 2026. An independent
//    check found that the whole refusal block could be deleted — leaving an
//    empty, negative or non-numeric figure to become 0, which means NO LIMIT
//    and is the most expensive outcome there is — with every check in this
//    file and all sixty-one audit scripts green. `grep -rn "monthCap" tools/`
//    found nothing.
//
//    This is the ONE page where the money limit is set, and D-049 makes the
//    limit's trustworthiness a condition of shipping the feature. A good value
//    is posted BEFORE each bad one, so "the figure did not change" cannot be
//    mistaken for "the bad figure was stored".
head('J. The monthly limit\'s save handler refuses what it cannot read');

// 📝 EVERY KEY `admin/translation/save` CAN WRITE is written down before this
//    section presses it eleven times. See `rememberSetting()` for what went
//    wrong when only the keys this test writes were tracked. The five
//    `*.apiKey` rows are not listed because that handler writes one only when
//    its field arrives non-empty, and this section sends none — stated rather
//    than assumed, having read the handler.
foreach (['translation.enabled', 'translation.provider', 'translation.monthCapPence',
          'translation.anthropic.model', 'translation.openai.model',
          'translation.libre.baseUrl'] as $pressable) {
    rememberSetting($pressable);
}

/** What the installation's stored limit is, in pence. */
$capNow = static function (): int {
    $row = one('SELECT `settingValue` AS v FROM `tblSettings` '
        . 'WHERE `settingKey` = ? AND `siteID` IS NULL LIMIT 1', 's', ['translation.monthCapPence']);
    return $row === null ? -1 : (int) $row['v'];
};

/**
 * Post the admin form with one monthly figure; answers the stored pence after.
 *
 * ⚠️ `libre_base` IS SENT, POINTING AT THIS RUN'S OWN STUB. The first
 *    version left it out, and the handler's `?? 'https://libretranslate.com'`
 *    then rewrote the setting to a REAL OUTSIDE ADDRESS on each of the eleven
 *    saves. Nothing was sent there, because this is the last section and no
 *    press follows it — but that is an accident of ORDER, and a test whose
 *    safety depends on where it sits in the file is one reordering away from
 *    posting a member's words to a company nobody chose.
 */
$saveCap = static function (string $typed) use ($jarAdmin, $stubPort): array {
    $page = req($jarAdmin, 'GET', '/admin/translation');
    $tok  = token($page['body']);
    $answer = req($jarAdmin, 'POST', '/admin/translation/save', [
        'csrf_token' => $tok,
        'provider'   => 'libre',
        'enabled'    => '1',
        'libre_base' => 'http://127.0.0.1:' . $stubPort,
        'monthCap'   => $typed,
    ]);
    return $answer;
};

$refusalsBefore = (int) (one('SELECT COUNT(*) AS n FROM `tblActivityLogs` WHERE `activityType` = ?',
    's', ['TranslationCapRefused'])['n'] ?? 0);

$capCases = [
    ['J01', '7',       700,    true,  'an ordinary figure is stored'],
    ['J02', '',        700,    false, 'an EMPTY box is refused — no crafted request needed: the box is prefilled and min="0" does not stop it being submitted'],
    ['J03', '8',       800,    true,  'and the page still works afterwards'],
    ['J04', '-5',      800,    false, 'a negative figure is refused, not clamped to 0'],
    ['J05', '9',       900,    true,  'and the page still works afterwards'],
    ['J06', 'abc',     900,    false, 'text that is not a figure is refused'],
    ['J07', '0',       0,      true,  'a deliberate 0 IS accepted — it is how "no limit" is said'],
    ['J08', ' 50 ',    5000,   true,  'spaces round a figure are trimmed, not refused'],
    ['J09', '50.00',   5000,   true,  'pence are accepted'],
    ['J10', '1000',    100000, true,  'the hard maximum exactly is accepted'],
    ['J11', '2000',    100000, false, 'above the hard maximum is refused'],
];
foreach ($capCases as [$label, $typed, $expected, $shouldStore, $what]) {
    $before = $capNow();
    $answer = $saveCap($typed);
    $after  = $capNow();
    $stored = $after === $expected;
    $unchanged = $after === $before;
    ok(
        $label . ' typed "' . $typed . '": ' . $what,
        $answer['status'] === 302 && ($shouldStore === true ? $stored : $unchanged),
        'HTTP ' . $answer['status'] . ', stored ' . $before . ' → ' . $after
        . ', expected ' . ($shouldStore === true ? 'to become ' . $expected : 'to stay ' . $before)
    );
}

$refusalsAfter = (int) (one('SELECT COUNT(*) AS n FROM `tblActivityLogs` WHERE `activityType` = ?',
    's', ['TranslationCapRefused'])['n'] ?? 0);
// ⚠️ THE EXPECTED NUMBER IS DERIVED FROM THE CASES, not typed. A hand-written
//    4 was safe — it goes red if a case is added — but it would go red for the
//    wrong reason, and a number in a label is the shape that goes stale.
$shouldRefuse = count(array_filter($capCases, static fn (array $row): bool => $row[3] === false));
ok('J12 every refusal is recorded in the activity log',
    $refusalsAfter - $refusalsBefore === $shouldRefuse,
    'recorded ' . ($refusalsAfter - $refusalsBefore) . ' refusal(s); ' . $shouldRefuse
    . ' figure(s) were refused. A refusal nobody can see is not a refusal that helps anybody');

setSetting('translation.monthCapPence', '5000');

// =============================================================================
// The verdict
// =============================================================================
head('Result');
echo "This run called NO outside company: every translation came from the stub on port {$stubPort}.\n";
echo "It did NOT prove a paid provider's own cost figure is right, did not drive a browser,\n";
echo "and ran one worker, so two presses never overlapped. See the file's header.\n\n";

cleanup();
if ($state['left'] > 0) {
    ok('Z01 the clean-up removed every row this run made', false, $state['left'] . ' row(s) are left behind');
} else {
    ok('Z01 the clean-up removed every row this run made', true);
}

echo "\n" . $pass . ' passed, ' . $fail . " failed.\n";
if ($fail === 0) {
    echo "REACHED THE END — content translation is reachable, gated, and takes its words from the database.\n";
}
exit($fail === 0 ? 0 : 1);
