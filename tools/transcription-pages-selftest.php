<?php
// Path: tools/transcription-pages-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Transcription, on the real pages — self-test 🗒 (#276, migration 229)
 * -----------------------------------------------------------------------------
 * WHY THIS FILE EXISTS
 * -----------------------------------------------------------------------------
 * Transcription looked finished and did nothing at all. `Transcription::queue()`
 * worked, `recordings/transcribe` worked, the address was registered, the
 * transcript page worked, the admin page had a provider chooser and somewhere
 * to type an API key. NOTHING ANYWHERE POSTED TO THE HANDLER, so no recording
 * was ever queued, no transcript was ever made, and `/recordings/search`
 * searched an empty table for ever.
 *
 * Not one of the checks this project already runs could see that. Every file
 * existed, every address was registered, `php -l` was clean, and
 * `check_registered_addresses.py` is satisfied by an address that something
 * ANSWERS — it has nothing to say about an address nothing CALLS. The gap was
 * in what the pages pointed at, which is exactly the shape the ".php in a web
 * address" rule was written for.
 *
 * So this test drives the real pages over real HTTP: it signs in, asks for a
 * recording's page, presses the button that is drawn there, and then reads
 * `tblTranscript` OUT OF THE DATABASE rather than believing the page's own
 * cheerful message.
 *
 * THE FIELD NAMES ARE CROSS-CHECKED, NOT LISTED TWICE
 * -----------------------------------------------------------------------------
 * Section A reads the `$_POST[…]` names out of the handler itself and requires
 * each one to appear in the form the portal really served. A list typed into
 * this file would be a third place to keep in step, and a test holding its own
 * copy of what it is testing proves nothing — this project has been caught by
 * exactly that before (`ExpenseRules::paymentReferences()`).
 *
 * THE THREE FAULTS IT WAS WRITTEN AGAINST, all found by running it
 * -----------------------------------------------------------------------------
 *   • No button existed at all (the reason for the whole package).
 *   • `recordings/view.php` had NO FLASH BLOCK, and nothing in this portal
 *     prints one centrally: 141 pages print their own. So every message the
 *     handler set was swallowed and the button would have looked dead whether it
 *     worked or was refused. Section C.
 *   • The admin page's "Process next" button sat INSIDE the Save form. HTML
 *     has no nested forms, so the button's owner became the Save form and
 *     pressing it saved the settings and drained nothing. Section K checks the
 *     button's form owner with PHP's own spec-compliant HTML parser rather than
 *     by searching the text, because the text looks perfectly correct.
 *
 * WHAT IT DOES NOT COVER, said plainly
 * -----------------------------------------------------------------------------
 *   • It never calls a transcription company. There is no API key in this
 *     world, so `openaiTranscribe()` returns 'openai-not-configured' before it
 *     opens a connection. That is USED rather than worked around: it is how
 *     section L proves the sweep claimed one organisation's row and left
 *     another's alone, with no network and no money spent.
 *   • Two administrators pressing the button at the same instant. The built-in
 *     server runs with one worker here (PHP_CLI_SERVER_WORKERS is unset), so
 *     two requests never overlap and such a test would PASS ON THE BROKEN CODE.
 *     The row lock in `Transcription::queue()` is what stops that; what IS
 *     tested here is the state the lock exists to protect — section I sets a
 *     row to `processing` and proves a second press is refused and changes
 *     nothing.
 *   • Whether a transcript is any good. That is the provider's business.
 *
 * HOW TO RUN IT
 * -----------------------------------------------------------------------------
 *   docker run --rm -d --name p5tr-mysql -e MYSQL_ALLOW_EMPTY_PASSWORD=1 \
 *     -e MYSQL_DATABASE=selftest_tr -p 3402:3306 mysql:8.0.36
 *   # load web/_sql/full_schema.sql and every numbered migration, then:
 *   SELFTEST_DB_NAME=selftest_tr SELFTEST_DB_PORT=3402 \
 *     SELFTEST_WEB_DIR=/tmp SELFTEST_WEB_PORT=9081 \
 *     php tools/transcription-pages-selftest.php
 *
 * `--rm` matters: a plain `docker rm` leaves the container's data behind in a
 * separate volume, and this machine once held 415 of them taking 101 GB.
 *
 * @package   WebMS Intra
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/276
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
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes and deletes rows");
}
// `exec` is needed by section O, which is the only check that proves a finished
// transcript can be WRITTEN. Required rather than skipped: a check that quietly
// does not run is worse than no check, because it is believed.
foreach (['curl_init', 'proc_open', 'exec'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this PHP has no {$needed}()");
    }
}
// 🧪 Section K parses real HTML with the engine a browser uses. PHP 8.4 brought
//    `Dom\HTMLDocument` (lexbor); without it the nested-form check cannot be
//    made at all, and this test refuses rather than quietly skipping the one
//    check that catches the fault that made the feature inert.
if (class_exists('Dom\HTMLDocument') === false) {
    refuse('this PHP has no Dom\HTMLDocument (PHP 8.4+), which section K needs to parse HTML the way a browser does');
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
$port    = ($portRaw === false || $portRaw === '') ? 9081 : (int) $portRaw;
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
const ORG   = 921001;
const OTHER = 921002;   // another organisation on the same installation
const GLOBALADMIN = 921011; // a GLOBAL administrator: may change the settings
const ORGADMIN    = 921012; // an administrator of ORG only: may not
const MEMBER      = 921013; // an ordinary member: may read a finished transcript
const ZONE  = 'Europe/London';

// The recordings. Numbers are fixed so every section can name one.
const REC_FILE  = 921101;  // has a media file on this server
const REC_LINK  = 921102;  // links out to another website: nothing to transcribe
const REC_OTHER = 921103;  // belongs to OTHER
const REC_SPEND = 921104;  // carries a finished, charged-for transcript

/**
 * 🌐 The first part of every address this run asks for.
 *
 * With no organisation in the address `Site::preDetect()` falls back to
 * organisation 1, so the pages would work perfectly against SOMEBODY ELSE'S
 * organisation while every query here — all filtered on ORG — found nothing,
 * and the run would report a fault in the code. So this run switches the
 * installation to telling organisations apart by the first part of the address
 * (recorded and put back by the clean-up) and asks for everything under its own.
 */
const ADDR = '/selftest-tr';

$state = [
    'proc'     => null,
    'child'    => '',
    'token'    => bin2hex(random_bytes(16)),
    'started'  => false,
    'cleaned'  => false,
    'settings' => [],
    // 🗄️ Every installation-wide transcription row as this run found it, so the
    //    clean-up can put back ones this file never set itself — section M09
    //    makes the portal write four of those. Filled in once the world is
    //    built, and used by cleanup().
    'trRows'   => [],
];

function portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);

        return true;
    }

    return false;
}

/** Everything this run put in the database, taken out again. */
function removeWorld(): void
{
    global $db;
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    q('DELETE FROM tblTranscriptSearch WHERE siteID IN (?, ?)', 'ii', [ORG, OTHER]);
    q('DELETE FROM tblTranscript WHERE recordingID BETWEEN 921100 AND 921199');
    q('DELETE FROM tblRecordingPlay WHERE recordingID BETWEEN 921100 AND 921199');
    q('DELETE FROM tblRecordingNote WHERE recordingID BETWEEN 921100 AND 921199');
    q('DELETE FROM tblRecording WHERE siteID IN (?, ?)', 'ii', [ORG, OTHER]);
    q('DELETE FROM tblLocalAccounts WHERE userID BETWEEN 921000 AND 921099');
    q('DELETE FROM tblActivityLogs WHERE userID BETWEEN 921000 AND 921099 OR siteID IN (?, ?)', 'ii', [ORG, OTHER]);
    q('DELETE FROM tblErrors WHERE userID BETWEEN 921000 AND 921099 OR siteID IN (?, ?)', 'ii', [ORG, OTHER]);
    q('DELETE FROM tblUserSites WHERE siteID IN (?, ?) OR userID BETWEEN 921000 AND 921099', 'ii', [ORG, OTHER]);
    q('DELETE FROM tblUsers WHERE userID BETWEEN 921000 AND 921099');
    q('DELETE FROM tblSettings WHERE siteID IN (?, ?)', 'ii', [ORG, OTHER]);
    q('DELETE FROM tblSites WHERE siteID IN (?, ?)', 'ii', [ORG, OTHER]);
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
}

/** Put back everything this run changed, ONCE. Also a shutdown function. */
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
        // The installation-wide settings this run borrowed, put back exactly as
        // they were — including "there was no row at all". These rows belong to
        // the whole installation and a throwaway database is still somebody's.
        //
        // TWO LISTS, because one was not enough. `$state['settings']` holds the
        // keys this file sets itself, and can restore "there was no row";
        // `$state['trRows']` holds every installation-wide `transcription.%`
        // row as it was found, which is what covers the four the PORTAL writes
        // when section M09 posts its settings form. With only the first list a
        // clean pass left transcription switched on installation-wide.
        foreach ($state['settings'] as $key => $before) {
            if ($before === null) {
                q('DELETE FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [(string) $key]);
            } else {
                q('UPDATE tblSettings SET settingValue = ? WHERE settingID = ?', 'si', [$before['settingValue'], (int) $before['settingID']]);
            }
        }
        // 🗄️ And every installation-wide transcription row back to the value
        //    this run found, by its own row number — including the four that
        //    the portal's own settings form writes and that nothing here ever
        //    set. Done AFTER the loop above so these win: they are what was
        //    really on disk when the run started.
        foreach ($state['trRows'] as $before) {
            q(
                'UPDATE tblSettings SET settingValue = ? WHERE settingID = ?',
                'si',
                [(string) $before['settingValue'], (int) $before['settingID']]
            );
        }
        removeWorld();
    } catch (Throwable $e) {
        echo 'FAIL — the clean-up could not put the database back: ' . $e->getMessage() . "\n";
    }
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.transcription-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}

/**
 * One real HTTP request to the server this run started. Redirects are never
 * followed. An answer without this run's header came from somewhere else and is
 * returned as status -1, which no check expects.
 *
 * @param array<string,mixed>|null $post
 *
 * @return array{status:int, location:string, body:string}
 */
function req(string $jar, string $method, string $path, ?array $post = null): array
{
    global $port, $state;
    $handle = curl_init('http://127.0.0.1:' . $port . ADDR . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 180, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_HTTPHEADER => ['Expect:'], CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
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
    if (preg_match('/^X-Transcription-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
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
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

/**
 * 🔐 A VALID form token for one session, from a page that always has a form.
 *
 * WHY THIS IS NEEDED, and why taking the token off the page under test does not
 * work: several sections hide the transcribe button on purpose and then post
 * anyway, to prove the refusal is in the HANDLER and not merely in the page. A
 * hidden button means no token on that page, so `token()` gives '' and the
 * handler refuses at the token check — a 400 that looks like the gate working
 * while saying nothing about the gate at all. Three checks passed for that
 * wrong reason on the first run.
 *
 * `recordings/manage` is the source: an administrator always gets a form there,
 * and it is gated by `recordings.enabled`, which no section switches off.
 * `Auth::verifyCsrf()` replaces the token every time it accepts one, so this is
 * asked again for every post rather than cached.
 */
function freshToken(string $jar): string
{
    return token(req($jar, 'GET', '/recordings/manage')['body']);
}

/** A page's text, with the tags taken out and the spaces tidied. */
function text(string $html): string
{
    return (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
}

/**
 * 🧹 PHP source with its comments taken out, using PHP'S OWN TOKENIZER.
 *
 * WHY THIS EXISTS. Check A02 asks whether anything in the portal still posts to
 * `recordings/transcribe` — the gap this whole package closed. Written as a
 * plain text search it PASSED WITH THE BUTTON REMOVED, because the comments
 * explaining the fault mention the address, and a comment is not a caller. That
 * is the shape this project already has a rule about: a check satisfied by a
 * comment mentioning the thing it is looking for.
 *
 * `token_get_all()` is used rather than a regular expression on purpose. This
 * repository has been caught three times by hand-rolled comment and string
 * stripping — an apostrophe in `// don't` put every later quote out of step —
 * and the tokenizer is the same parser PHP itself uses, so it cannot disagree.
 */
function phpWithoutComments(string $code): string
{
    $kept = '';
    foreach (token_get_all($code) as $token) {
        if (is_array($token) === true) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $kept .= $token[1];

            continue;
        }
        $kept .= $token;
    }

    return $kept;
}

/**
 * 🧵 A method body with its SQL readable as one continuous string.
 *
 * SQL in this code is built from several PHP string literals joined with `.`,
 * and the quotes inside them are escaped. So the source text of one statement
 * reads `status = \'processing\', startedAt = NOW() \' . \'WHERE …` — which no
 * pattern written against the SQL will match. Check Q06 failed for exactly that
 * reason and it was the CHECK that was wrong, not the code.
 *
 * This takes the escapes out and closes up the joins, so a statement reads the
 * way it reads in the database. It is only ever used to ASK WHETHER a statement
 * is still there, never to run anything.
 */
function sqlish(string $code): string
{
    $flat = str_replace('\\\'', '\'', $code);
    // 'first half ' . 'second half'  ->  'first half second half'
    $flat = (string) preg_replace('/\'\s*\.\s*\'/', '', $flat);

    return (string) preg_replace('/\s+/', ' ', $flat);
}

/**
 * 🔬 One method's body, with comments gone, found with PHP'S OWN TOKENIZER.
 *
 * A brace-counting walk over raw text is the obvious way to do this and is
 * wrong: this repository has already been caught by one, where a single `{`
 * inside a log message swallowed the rest of a function and the check passed on
 * a removed gate. Every string literal is ONE token here, so a brace inside one
 * can never be counted.
 */
function methodBody(string $code, string $name): string
{
    $tokens = token_get_all($code);
    $count  = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_array($token) === false || $token[0] !== T_FUNCTION) {
            continue;
        }
        // The name follows, possibly after whitespace.
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) === true && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $count || is_array($tokens[$j]) === false || $tokens[$j][1] !== $name) {
            continue;
        }
        // Walk to the opening brace of the body, then to its match.
        $depth = 0;
        $body  = '';
        for ($k = $j; $k < $count; $k++) {
            $piece = is_array($tokens[$k]) === true ? $tokens[$k][1] : $tokens[$k];
            if (is_array($tokens[$k]) === true
                && ($tokens[$k][0] === T_COMMENT || $tokens[$k][0] === T_DOC_COMMENT)) {
                continue;
            }
            if ($piece === '{') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            }
            if ($piece === '}') {
                $depth--;
                if ($depth === 0) {
                    return $body;
                }
            }
            if ($depth >= 1) {
                $body .= $piece;
            }
        }
    }

    return '';
}

/** The transcript row for one recording, straight out of the database. */
function transcriptRow(int $recordingId): ?array
{
    return one(
        // 🧨 `fullText` is BACKQUOTED because `FULLTEXT` is a reserved word in
        //    MySQL and keywords ignore case, so a bare one does not parse. That
        //    is not a quirk of this test: writing it bare is exactly how the
        //    statement in `processQueue()` that SAVES a finished transcript came
        //    to be unable to run at all, which this run found.
        'SELECT transcriptID, provider, status, startedAt, queuedAt, generatedAt, errorMsg, costPence, `fullText` '
        . 'FROM tblTranscript WHERE recordingID = ? LIMIT 1',
        'i',
        [$recordingId]
    );
}

/**
 * 🧪 The form that owns a named submit button, as a BROWSER sees it.
 *
 * Searching the markup for the right `action` cannot answer this: the markup
 * that put "Process next" inside the Save form LOOKED exactly right, with its
 * own `<form method="post" action=".../run">` around it. A parser following the
 * HTML standard throws that inner tag away, and the button ends up owned by the
 * form that was already open. So the question is asked of the parsed document.
 *
 * Returns the owning form's `action`, '' when the button is in no form at all,
 * or null when there is no such button.
 */
function formActionOwning(string $html, string $buttonText): ?string
{
    $doc = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    foreach ($doc->getElementsByTagName('button') as $button) {
        if (str_contains((string) $button->textContent, $buttonText) === false) {
            continue;
        }
        // A `form=` attribute would name the owner outright. Nothing here uses
        // one; checked anyway so this helper cannot quietly give a wrong answer
        // if somebody adds one later.
        // `Dom\HTMLDocument` answers NULL for an attribute that is not there,
        // where the older DOM answered ''. Both are handled, because getting it
        // wrong here is a fatal error rather than a failed check.
        $named = (string) ($button->getAttribute('form') ?? '');
        if ($named !== '') {
            $owner = $doc->getElementById($named);

            return $owner === null ? '' : (string) ($owner->getAttribute('action') ?? '');
        }
        $parent = $button->parentNode;
        while ($parent !== null) {
            if (strcasecmp($parent->nodeName, 'form') === 0) {
                return (string) ($parent->getAttribute('action') ?? '');
            }
            $parent = $parent->parentNode;
        }

        return '';
    }

    return null;
}

/**
 * 🗄️ Every installation-wide `transcription.*` row as it stands, remembered so
 * the clean-up can put them all back.
 *
 * WHY `installSetting()` IS NOT ENOUGH, found by the independent check and
 * proved by experiment rather than read: it remembers a before-value only for
 * keys THIS FILE sets. Section M09 posts the real settings form as a global
 * administrator, and `admin/transcription/save.php` then writes SEVEN
 * installation-wide rows — including `transcription.enabled`,
 * `transcription.language`, `transcription.openai.model` and
 * `transcription.assemblyai.apiKey`, which nothing here had recorded. So a
 * clean 126/126 pass left the installation with transcription switched ON (the
 * seeded value is `0`) and its language changed, altering the starting world
 * for anything else run against the same database.
 *
 * CI never saw it, because every test there gets its own fresh database. That
 * is exactly why it had to be measured locally.
 *
 * @return list<array{settingID:int, settingKey:string, settingValue:string}>
 */
function transcriptionRowsNow(): array
{
    return q(
        'SELECT settingID, settingKey, settingValue FROM tblSettings '
        . "WHERE siteID IS NULL AND settingKey LIKE 'transcription.%' ORDER BY settingID"
    );
}

/** Set an INSTALLATION-WIDE setting for this run, remembering what it was. */
function installSetting(string $key, string $value): void
{
    global $state;
    if (array_key_exists($key, $state['settings']) === false) {
        $state['settings'][$key] = one(
            'SELECT settingID, settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL',
            's',
            [$key]
        );
    }
    $row = one('SELECT settingID FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]);
    if ($row === null) {
        q(
            'INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, ?, ?, ?, 0)',
            'sss',
            [$key, $value, $value]
        );

        return;
    }
    q('UPDATE tblSettings SET settingValue = ? WHERE settingID = ?', 'si', [$value, (int) $row['settingID']]);
}

/** One organisation's own value for a setting (replacing any it had). */
function siteSetting(int $site, string $key, string $value): void
{
    q('DELETE FROM tblSettings WHERE siteID = ? AND settingKey = ?', 'is', [$site, $key]);
    q(
        'INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, ?, ?, ?, 0)',
        'isss',
        [$site, $key, $value, $value]
    );
}

/** Take an organisation's own row for a setting away again. */
function clearSiteSetting(int $site, string $key): void
{
    q('DELETE FROM tblSettings WHERE siteID = ? AND settingKey = ?', 'is', [$site, $key]);
}

// =============================================================================
// 🌍 Build the world
// =============================================================================
removeWorld();

// 🗄️ Every installation-wide transcription row AS FOUND, before this run
//    changes anything. Read first, so the clean-up can put back the ones the
//    portal itself writes when section M09 posts the settings form.
$state['trRows'] = transcriptionRowsNow();

q(
    "INSERT INTO tblSites (siteID, siteKey, siteName, isActive, timezone) VALUES "
    . "(?, 'selftest-tr', 'Selftest transcription', 1, ?), "
    . "(?, 'selftest-tr-other', 'Selftest transcription other', 1, ?)",
    'isis',
    [ORG, ZONE, OTHER, ZONE]
);

// 👤 Three people, deliberately different in ONE way each.
//
//    GLOBALADMIN carries tblUsers.isRootAdmin — the only flag App::isRootAdmin()
//    reads, and the one the settings form is for. ORGADMIN carries
//    tblUserSites.isSiteAdmin instead, which makes App::isAdmin() true and
//    isRootAdmin() false: that is the person the old page offered a Save button
//    to and the save handler then refused.
// One statement per person rather than one long one: a hand-counted string of
// bind types ('issississ' and the like) is a bug waiting to happen, and getting
// it wrong here would look like a fault in the portal.
$people = [
    GLOBALADMIN => ['Gail Global', 1, 0],  // tblUsers.isRootAdmin — may change the settings
    ORGADMIN    => ['Olive Org',   0, 1],  // tblUserSites.isSiteAdmin only — may not
    MEMBER      => ['Mo Member',   0, 0],  // an ordinary member
];
foreach ($people as $id => [$name, $isRoot, $isSiteAdmin]) {
    q(
        'INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, ?)',
        'issi',
        [$id, $name, 'u' . $id . '.tr@selftest.invalid', $isRoot]
    );
    q(
        'INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, ?, 0)',
        'iii',
        [$id, ORG, $isSiteAdmin]
    );
}

// 🎙️ The recordings.
//
//    REC_FILE names a file; the file itself is written into the scratch copy
//    below, because `processQueue()` checks `is_file()` before it calls
//    anybody. REC_LINK has only an external address, which is the shape that
//    can never be transcribed here. REC_OTHER belongs to the other
//    organisation and its title is distinctive so section L can prove it never
//    appears.
q(
    'INSERT INTO tblRecording (recordingID, siteID, title, kind, filePath, mimeType, externalUrl, isPublished, recordedAt) VALUES '
    . "(?, ?, 'Morning service talk', 'sermon', 'selftest-talk.mp3', 'audio/mpeg', NULL, 1, '2026-10-04'), "
    . "(?, ?, 'Linked elsewhere talk', 'sermon', NULL, NULL, 'https://example.invalid/elsewhere', 1, '2026-10-11'), "
    . "(?, ?, 'OTHERORGSECRETTITLE', 'sermon', 'selftest-other.mp3', 'audio/mpeg', NULL, 1, '2026-10-18'), "
    . "(?, ?, 'Already transcribed talk', 'sermon', 'selftest-spend.mp3', 'audio/mpeg', NULL, 1, '2026-10-25')",
    'iiiiiiii',
    [REC_FILE, ORG, REC_LINK, ORG, REC_OTHER, OTHER, REC_SPEND, ORG]
);

// 🔛 Both apps on for this organisation. `recordings.enabled` gates everything
//    under /recordings and `transcription.enabled` gates /admin/transcription —
//    two different switches, which is itself worth pinning: the handler has to
//    test the transcription one itself, because the router only applies it to
//    addresses under that app's own prefix.
siteSetting(ORG, 'recordings.enabled', '1');
siteSetting(ORG, 'transcription.enabled', '1');
siteSetting(OTHER, 'recordings.enabled', '1');
siteSetting(OTHER, 'transcription.enabled', '1');

// 🔑 A provider key, so `providerIsReady()` is true. It is NOT a real key and
//    nothing here ever reaches a network: `openaiTranscribe()` opens no
//    connection when the key is empty, and section L empties it on purpose.
installSetting('transcription.provider', 'openai');
installSetting('transcription.openai.apiKey', 'sk-selftest-not-a-real-key');
installSetting('transcription.batchSize', '5');
installSetting('transcription.monthCapPence', '5000');

// 🌐 Organisations told apart by the first part of the address.
foreach (['multisite.enabled' => 'true', 'multisite.detectionMode' => 'path'] as $key => $value) {
    installSetting($key, $value);
}

$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
foreach (['global' => GLOBALADMIN, 'orgadmin' => ORGADMIN, 'member' => MEMBER] as $who => $id) {
    q(
        'INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)',
        'iss',
        [$id, 'selftest-tr-' . $who, $hash]
    );
}

// The scratch copy of web/, with a throwaway connection file and key.
$sep   = DIRECTORY_SEPARATOR;
$child = $parent . $sep . 'transcription-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.transcription-selftest-owner', $state['token']); // the marker FIRST
register_shutdown_function('cleanup');
$state['started'] = true;

mkdir($child . $sep . 'web', 0700);
$copied = 0;
$tree   = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($webSrc, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
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
mkdir($child . $sep . 'web' . $sep . '_uploads' . $sep . 'recordings', 0700, true);
mkdir($child . $sep . 'sessions', 0700);

// 🎧 The media files themselves. `processQueue()` refuses a job whose file is
//    missing BEFORE it calls anybody, so without these section L would prove
//    nothing about scoping — both rows would fail for the same boring reason.
foreach (['selftest-talk.mp3', 'selftest-other.mp3', 'selftest-spend.mp3'] as $name) {
    file_put_contents($child . $sep . 'web' . $sep . '_uploads' . $sep . 'recordings' . $sep . $name, 'not really audio');
}

$creds = [
    'db_host' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    'db_user' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    'db_pass' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    'db_name' => $dbName,
    'db_port' => getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306,
];
file_put_contents(
    $child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
    "<?php\n// Written by tools/transcription-pages-selftest.php for one run; deleted with its folder.\nreturn "
    . var_export($creds, true) . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/transcription-pages-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Transcription-Selftest: __TOKEN__');
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
    [
        PHP_BINARY,
        '-d', 'session.save_path=' . $child . $sep . 'sessions',
        '-d', 'error_log=' . $child . $sep . 'php-errors.log',
        '-d', 'log_errors=1',
        '-d', 'display_errors=0',
        '-S', '127.0.0.1:' . $port,
        '-t', $child . $sep . 'web' . $sep . 'public_html',
        $child . $sep . 'router.php',
    ],
    [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $child . $sep . 'server-output.log', 'a'],
        2 => ['file', $child . $sep . 'server-output.log', 'a'],
    ],
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

echo "Transcription, on the real pages — self-test (#276, migration 229)\n";
echo 'server process ' . (int) proc_get_status($state['proc'])['pid'] . ', port ' . $port
    . ', PHP ' . PHP_VERSION . ', database ' . (string) one('SELECT VERSION() AS v')['v']
    . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";

$jar = [];
/** Sign one account in, in a browser of its own. */
$signIn = static function (string $who) use (&$jar, $child, $sep, $password): array {
    $jar[$who] = $child . $sep . 'jar-' . $who . '.txt';
    $tok       = token(req($jar[$who], 'GET', '/login')['body']);

    return req($jar[$who], 'POST', '/login', [
        'csrf_token' => $tok,
        'username'   => 'selftest-tr-' . $who,
        'password'   => $password,
    ]);
};
foreach (['global', 'orgadmin', 'member'] as $who) {
    $answer = $signIn($who);
    if ($answer['status'] !== 302 && $answer['status'] !== 303) {
        refuse("could not sign '{$who}' in (status {$answer['status']}); nothing below would mean anything");
    }
}

// 🎛️ THE STAND-IN FOR A TRANSCRIPTION COMPANY, built once for the whole run.
//
//    The `local` provider runs a program on the server, so a few lines of shell
//    stand in for it. Every section that actually DRAINS the queue uses this,
//    for three reasons: nothing leaves this machine, the answer is the same
//    every time, and no account is charged. The first version of this run left
//    the provider as `openai` with a made-up key, which really did try to reach
//    api.openai.com — so the run was slower, depended on the network, and the
//    docblock's claim that it calls nobody was simply untrue.
//
//    `localTranscribe()` runs `<bin> <audio> --output-json <tmp>` and then
//    reads `<tmp>.json`, so the output path is the THIRD argument. It was
//    written as the fourth at first and every end-to-end check failed with the
//    row marked "failed" — which reads exactly like a fault in the portal.
//    Measured with a one-line probe rather than counted off the source.
$stub = $child . $sep . 'stand-in-whisper.sh';
file_put_contents(
    $stub,
    "#!/bin/sh\n"
    . "# Written by tools/transcription-pages-selftest.php for ONE run.\n"
    . "printf '%s' '{\"text\":\"the stand in heard every word of this talk\"}' > \"\$3.json\"\n"
    . "exit 0\n"
);
chmod($stub, 0700);
if (is_executable($stub) === false) {
    refuse('the stand-in transcription program could not be made runnable, so nothing below could transcribe anything');
}
installSetting('transcription.local.binPath', $stub);

$recPage   = '/recordings/view?id=' . REC_FILE;
$linkPage  = '/recordings/view?id=' . REC_LINK;
$adminPage = '/admin/transcription';

// =============================================================================
// 🅰️ A. The button exists, and its fields are the ones the handler reads
// =============================================================================
head('A. The button exists and matches the handler');

$handlerSrc = (string) file_get_contents($repo . $sep . 'web' . $sep . '_apps' . $sep . 'recordings' . $sep . 'transcribe.php');
$page       = req($jar['global'], 'GET', $recPage);

ok('A01 the recording page is served to an administrator', $page['status'] === 200, 'status ' . $page['status']);

// 🔗 The whole point of this package: SOMETHING posts to the handler. Checked
//    across the repository rather than on this page alone, because the fault
//    was that no file anywhere did.
$postsToHandler = [];
foreach (['_apps', '_core', 'public_html'] as $where) {
    $dir = $repo . $sep . 'web' . $sep . $where;
    if (is_dir($dir) === false) {
        continue;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        $path = (string) $f;
        if (str_ends_with($path, 'transcribe.php') === true || is_file($path) === false) {
            continue;
        }
        // 🎯 A CALL, not a mention: the address has to appear inside a
        //    `Site::url(...)` in code with the comments stripped out. Asking
        //    only whether the text appeared anywhere passed with the button
        //    deleted, because the comments above it name the address.
        $code = phpWithoutComments((string) file_get_contents($path));
        if (preg_match('/Site::url\(\s*[\'"]recordings\/transcribe[\'"]\s*\)/', $code) === 1) {
            $postsToHandler[] = substr($path, strlen($repo) + 1);
        }
    }
}
ok(
    'A02 at least one page in the portal BUILDS the address recordings/transcribe (the gap this package closed)',
    $postsToHandler !== [],
    'no file under web/ builds that address in code — the handler is unreachable again, as it was for the whole life of the feature'
);
ok(
    'A02b and that check is not satisfied by a comment mentioning the address',
    preg_match('/Site::url\(\s*[\'"]recordings\/transcribe[\'"]\s*\)/', phpWithoutComments('<?php // Site::url(\'recordings/transcribe\')' . "\n")) !== 1,
    'the comment stripper is not working, so A02 can pass on a commented-out call'
);

ok(
    'A03 the recording page carries a form posting to recordings/transcribe',
    preg_match('#<form[^>]+action="[^"]*recordings/transcribe"#', $page['body']) === 1,
    'no such form on the page'
);

// 📋 The field names come OUT OF THE HANDLER, never from a list typed here.
preg_match_all('/\$_POST\[\'([a-zA-Z_]+)\'\]/', $handlerSrc, $m);
$wanted = array_values(array_unique($m[1]));
ok('A04 the handler reads at least two posted fields (the list is not empty)', count($wanted) >= 2, 'found: ' . implode(', ', $wanted));
foreach ($wanted as $field) {
    ok(
        'A05 the form offers the field the handler reads: ' . $field,
        preg_match('/name="' . preg_quote($field, '/') . '"/', $page['body']) === 1,
        'the handler reads $_POST[\'' . $field . '\'] and no input of that name is on the page'
    );
}

// 💬 The audio leaves the server, and whoever presses the button is told so
//    BEFORE they press it rather than afterwards.
$pageText = text($page['body']);
ok(
    'A06 the page says the audio is sent to an outside company before it is sent',
    str_contains($pageText, 'sent to the transcription company') === true,
    'nothing on the page warns that the recording leaves the server'
);

// =============================================================================
// 🅱️ B. Pressing it really queues the recording
// =============================================================================
head('B. Pressing it queues the recording, read back from the database');

ok('B01 there is no transcript before the button is pressed', transcriptRow(REC_FILE) === null);

$csrf    = token($page['body']);
$pressed = req($jar['global'], 'POST', '/recordings/transcribe', [
    'csrf_token'  => $csrf,
    'recordingID' => (string) REC_FILE,
]);
ok('B02 the handler answers with a redirect', in_array($pressed['status'], [302, 303], true) === true, 'status ' . $pressed['status'] . ' body ' . mb_substr($pressed['body'], 0, 200));
ok('B03 it sends the person back to the recording', str_contains($pressed['location'], 'recordings/view') === true, 'went to ' . $pressed['location']);

$row = transcriptRow(REC_FILE);
ok('B04 a transcript row now exists', $row !== null);
ok('B05 it is waiting, not running', $row !== null && (string) $row['status'] === 'queued', 'status ' . (string) ($row['status'] ?? 'none'));
ok('B06 nobody has been handed it yet (startedAt is empty)', $row !== null && $row['startedAt'] === null, 'startedAt ' . (string) ($row['startedAt'] ?? 'null'));
ok('B07 the chosen company is recorded against it', $row !== null && (string) $row['provider'] === 'openai', 'provider ' . (string) ($row['provider'] ?? 'none'));

// 📋 Queueing spends somebody's money, so it leaves a line in the log.
$logged = one(
    'SELECT logID, activityType FROM tblActivityLogs WHERE activityType = ? AND userID = ? ORDER BY logID DESC LIMIT 1',
    'si',
    ['TranscriptionQueued', GLOBALADMIN]
);
ok('B08 the request is recorded in the activity log', $logged !== null, 'no TranscriptionQueued row for the administrator');

// =============================================================================
// 🅲 C. The message is actually SHOWN — the page had no flash block
// =============================================================================
head('C. The answer is shown to the person (this page had no flash block)');

$after = req($jar['global'], 'GET', $recPage);
$afterText = text($after['body']);
ok('C01 the page is served after the redirect', $after['status'] === 200, 'status ' . $after['status']);
ok(
    'C02 the message set by the handler appears on the page',
    str_contains($afterText, 'waiting to be transcribed') === true,
    'the handler set a flash and the page never printed it — the button looks dead whether it worked or not'
);
ok(
    'C03 the message is not vacuous: the page really is the recording\'s page',
    str_contains($afterText, 'Morning service talk') === true,
    'C02 would pass on any page carrying that phrase; this pins it to the right one'
);
ok(
    'C04 the flash is cleared once shown (it does not stick on the next view)',
    str_contains(text(req($jar['global'], 'GET', $recPage)['body']), 'waiting to be transcribed') === false,
    'the message is still there on a second view, so it was never taken out of the session'
);
ok(
    'C05 the card reports the state in plain words, not the database word',
    str_contains($afterText, 'Waiting to be transcribed') === true,
    'the card does not show the queued state in words'
);

// =============================================================================
// 🅳 D. Switched off — the button goes, and a direct post is still refused
// =============================================================================
head('D. Transcription switched off for the organisation');

q('DELETE FROM tblTranscript WHERE recordingID = ?', 'i', [REC_FILE]);
siteSetting(ORG, 'transcription.enabled', '0');

$offPage = req($jar['global'], 'GET', $recPage);
ok(
    'D01 no button is offered while transcription is switched off',
    preg_match('#<form[^>]+action="[^"]*recordings/transcribe"#', $offPage['body']) !== 1,
    'the button is still drawn'
);

// 🚧 The boundary, not the courtesy: a hidden button is no protection at all.
$offPost = req($jar['global'], 'POST', '/recordings/transcribe', [
    'csrf_token'  => freshToken($jar['global']),
    'recordingID' => (string) REC_FILE,
]);
ok('D02 a direct post is answered with a redirect, not an error page', in_array($offPost['status'], [302, 303], true) === true, 'status ' . $offPost['status']);
ok('D03 and NOTHING was queued', transcriptRow(REC_FILE) === null, 'a transcript row was written while the feature was off');

$offAfter = text(req($jar['global'], 'GET', $recPage)['body']);
ok(
    'D04 the person is told why, in words naming the cause',
    str_contains($offAfter, 'switched off for this organisation') === true,
    'the refusal was silent or unexplained; page text: ' . mb_substr($offAfter, 0, 400)
);

siteSetting(ORG, 'transcription.enabled', '1');

// =============================================================================
// 🅴 E. A recording that links out has nothing to transcribe
// =============================================================================
head('E. A recording with no file on this server');

$linkView = req($jar['global'], 'GET', $linkPage);
ok('E01 the link-only recording\'s page is served', $linkView['status'] === 200, 'status ' . $linkView['status']);
ok(
    'E02 no transcribe button on a recording with no file here',
    preg_match('#<form[^>]+action="[^"]*recordings/transcribe"#', $linkView['body']) !== 1,
    'the button is offered for a recording there is nothing to send'
);
ok(
    'E03 and no explanation is forced under every such recording either',
    str_contains(text($linkView['body']), 'nothing to transcribe') === false,
    'an administrator is shown a notice they can do nothing about on every linked recording'
);

$linkPost = req($jar['global'], 'POST', '/recordings/transcribe', [
    'csrf_token'  => freshToken($jar['global']),
    'recordingID' => (string) REC_LINK,
]);
ok('E04 a direct post for it is refused', in_array($linkPost['status'], [302, 303], true) === true, 'status ' . $linkPost['status']);
ok('E05 and nothing was queued', transcriptRow(REC_LINK) === null, 'a row was written for a recording with no audio');
ok(
    'E06 the reason given is about the missing file, not about a provider or a key',
    str_contains(text(req($jar['global'], 'GET', $linkPage)['body']), 'no audio or video file on this server') === true,
    'the person is sent off to fix the wrong thing'
);

// =============================================================================
// 🅵 F. No company set up
// =============================================================================
head('F. No transcription company set up');

installSetting('transcription.openai.apiKey', '');
$noKeyPage = req($jar['global'], 'GET', $recPage);
ok(
    'F01 no button while no company has a key',
    preg_match('#<form[^>]+action="[^"]*recordings/transcribe"#', $noKeyPage['body']) !== 1,
    'the button is offered when pressing it could only fail'
);
$noKeyPost = req($jar['global'], 'POST', '/recordings/transcribe', [
    'csrf_token'  => freshToken($jar['global']),
    'recordingID' => (string) REC_FILE,
]);
ok('F02 a direct post is refused', in_array($noKeyPost['status'], [302, 303], true) === true, 'status ' . $noKeyPost['status']);
ok('F03 and nothing was queued', transcriptRow(REC_FILE) === null);
ok(
    'F04 the admin page says so at the top rather than leaving a queue that never moves',
    str_contains(text(req($jar['global'], 'GET', $adminPage)['body']), 'No transcription company is set up yet') === true,
    'the admin page is silent about having no provider'
);
installSetting('transcription.openai.apiKey', 'sk-selftest-not-a-real-key');

// =============================================================================
// 🅶 G. Another organisation's recording, and somebody who is not an administrator
// =============================================================================
head('G. Somebody else\'s recording, and somebody who may not');

$ownPage  = req($jar['global'], 'GET', $recPage);
$crossing = req($jar['global'], 'POST', '/recordings/transcribe', [
    'csrf_token'  => token($ownPage['body']),
    'recordingID' => (string) REC_OTHER,
]);
ok('G01 posting another organisation\'s recording is refused', $crossing['status'] === 404, 'status ' . $crossing['status']);
ok('G02 and nothing was queued for it', transcriptRow(REC_OTHER) === null, 'a row was written against another organisation\'s recording');

$memberPage = req($jar['member'], 'GET', $recPage);
ok('G03 an ordinary member may still read the recording\'s page', $memberPage['status'] === 200, 'status ' . $memberPage['status']);
ok(
    'G04 but is offered no transcribe button',
    preg_match('#<form[^>]+action="[^"]*recordings/transcribe"#', $memberPage['body']) !== 1,
    'a member is offered a button that spends money'
);
$memberPost = req($jar['member'], 'POST', '/recordings/transcribe', [
    'csrf_token'  => token($memberPage['body']),
    'recordingID' => (string) REC_FILE,
]);
ok('G05 and a direct post from a member is refused outright', $memberPost['status'] === 403, 'status ' . $memberPost['status']);
ok('G06 nothing was queued by the member', transcriptRow(REC_FILE) === null);

// 🔐 The form token is what stops another website posting this on somebody's
//    behalf. Checked with a VALID session and a WRONG token, so the refusal can
//    only be the token's doing.
$noToken = req($jar['global'], 'POST', '/recordings/transcribe', [
    'csrf_token'  => str_repeat('0', 64),
    'recordingID' => (string) REC_FILE,
]);
ok('G07 a wrong form token is refused', $noToken['status'] === 400, 'status ' . $noToken['status']);
ok('G08 and nothing was queued', transcriptRow(REC_FILE) === null);

// =============================================================================
// 🅷 H. A member may read a finished transcript, but not the provider's words
// =============================================================================
head('H. What a member may read');

q(
    'INSERT INTO tblTranscript (recordingID, provider, status, `fullText`, generatedAt, errorMsg) '
    . "VALUES (?, 'openai', 'failed', NULL, NULL, ?)",
    'is',
    [REC_FILE, 'openai-http-401 key sk-leaky-example rejected']
);
$memberFail = text(req($jar['member'], 'GET', '/recordings/transcript?id=' . REC_FILE)['body']);
ok(
    'H01 a member is told the state in plain words',
    str_contains($memberFail, 'Could not be transcribed') === true,
    'the transcript page does not report the state in words'
);
ok(
    'H02 and is NOT shown the company\'s own error text',
    str_contains($memberFail, 'sk-leaky-example') === false,
    'the provider\'s message — which can quote what was sent — is shown to every member'
);
$adminFail = text(req($jar['global'], 'GET', '/recordings/transcript?id=' . REC_FILE)['body']);
ok(
    'H03 an administrator IS shown it, so a fault can be diagnosed',
    str_contains($adminFail, 'sk-leaky-example') === true,
    'the detail is hidden from administrators too, leaving nobody able to fix it'
);
q('DELETE FROM tblTranscript WHERE recordingID = ?', 'i', [REC_FILE]);

// =============================================================================
// 🅸 I. The double bill, and the wedged row that a flat refusal would create
// =============================================================================
head('I. A recording already with the company is not sent again');

$page = req($jar['global'], 'GET', $recPage);
req($jar['global'], 'POST', '/recordings/transcribe', ['csrf_token' => token($page['body']), 'recordingID' => (string) REC_FILE]);
ok('I01 the recording is queued to begin with', (string) (transcriptRow(REC_FILE)['status'] ?? '') === 'queued');

// 🏭 Imitate the state the sweep leaves while it waits for the company: the row
//    is claimed and the moment is recorded. This is what a real run looks like
//    from the moment it hands the audio over to the moment it comes back.
q("UPDATE tblTranscript SET status = 'processing', startedAt = NOW() WHERE recordingID = ?", 'i', [REC_FILE]);
$whileRunning = transcriptRow(REC_FILE);
$startedWas   = (string) $whileRunning['startedAt'];

$busyPage = req($jar['global'], 'GET', $recPage);
ok(
    'I02 no button is offered while the audio is with the company',
    preg_match('#<form[^>]+action="[^"]*recordings/transcribe"#', $busyPage['body']) !== 1,
    'the button is offered during a run, which is how the same audio gets sent twice'
);
ok(
    'I03 the page says what is happening rather than looking stuck',
    str_contains(text($busyPage['body']), 'with the transcription company now') === true,
    'the page does not say the job is running'
);

$busyPost = req($jar['global'], 'POST', '/recordings/transcribe', [
    'csrf_token'  => freshToken($jar['global']),
    'recordingID' => (string) REC_FILE,
]);
ok('I04 a direct post during a run is refused', in_array($busyPost['status'], [302, 303], true) === true, 'status ' . $busyPost['status']);

$afterBusy = transcriptRow(REC_FILE);
ok(
    'I05 the row is UNCHANGED — still running, not put back in the queue',
    (string) $afterBusy['status'] === 'processing',
    'status became ' . (string) $afterBusy['status'] . ': a second run would now send the same audio and be charged again'
);
ok(
    'I06 and the moment it was handed over was not reset',
    (string) $afterBusy['startedAt'] === $startedWas,
    'startedAt moved from ' . $startedWas . ' to ' . (string) $afterBusy['startedAt'] . ', which would keep the row looking busy for ever'
);
ok(
    'I07 the person is told waiting costs nothing and asking again would be charged',
    str_contains(text(req($jar['global'], 'GET', $recPage)['body']), 'charged twice') === true,
    'the refusal does not explain itself'
);

// ⏱️ NOW THE OTHER DIRECTION, which is the trap in the fix above. A request
//    killed by the host leaves the row saying `processing` for ever. A flat
//    refusal would wedge that recording permanently, so the refusal has to be
//    "running RECENTLY" — and this is the check that proves it is.
q(
    "UPDATE tblTranscript SET startedAt = DATE_SUB(NOW(), INTERVAL ? MINUTE) WHERE recordingID = ?",
    'ii',
    [60 * 5, REC_FILE]
);
$wedgedPage = req($jar['global'], 'GET', $recPage);
ok(
    'I08 a job handed over five hours ago reads as abandoned, and the button comes back',
    preg_match('#<form[^>]+action="[^"]*recordings/transcribe"#', $wedgedPage['body']) === 1,
    'a recording whose request the host killed can never be transcribed again'
);
ok(
    'I09 and the page explains the run was cut short rather than claiming it is still going',
    str_contains(text($wedgedPage['body']), 'never came back') === true,
    'the page still claims the job is running'
);
$freed = req($jar['global'], 'POST', '/recordings/transcribe', [
    'csrf_token'  => token($wedgedPage['body']),
    'recordingID' => (string) REC_FILE,
]);
ok('I10 pressing it is accepted', in_array($freed['status'], [302, 303], true) === true, 'status ' . $freed['status']);
$freedRow = transcriptRow(REC_FILE);
ok('I11 the abandoned row goes back in the queue', (string) $freedRow['status'] === 'queued', 'status ' . (string) $freedRow['status']);
ok('I12 with the stale hand-over moment cleared', $freedRow['startedAt'] === null, 'startedAt is still ' . (string) $freedRow['startedAt']);

// 🧾 Re-queueing must not throw away the transcript somebody can already read.
q(
    "UPDATE tblTranscript SET status = 'completed', `fullText` = 'the words from the first run', "
    . 'generatedAt = NOW(), startedAt = NULL WHERE recordingID = ?',
    'i',
    [REC_FILE]
);
$donePage = req($jar['global'], 'GET', $recPage);
ok(
    'I13 a finished transcript offers a link to read it',
    str_contains($donePage['body'], 'recordings/transcript') === true,
    'a finished transcript cannot be reached from the recording'
);
ok(
    'I14 and the button now says it would be done again',
    str_contains(text($donePage['body']), 'Transcribe again') === true,
    'nothing tells an administrator the recording has already been transcribed'
);
req($jar['global'], 'POST', '/recordings/transcribe', ['csrf_token' => token($donePage['body']), 'recordingID' => (string) REC_FILE]);
$requeued = transcriptRow(REC_FILE);
ok('I15 asking again queues it', (string) $requeued['status'] === 'queued', 'status ' . (string) $requeued['status']);
ok(
    'I16 and the words from the first run are still there to read meanwhile',
    (string) $requeued['fullText'] === 'the words from the first run',
    'the existing transcript was thrown away the moment a new one was asked for'
);

q('DELETE FROM tblTranscript WHERE recordingID = ?', 'i', [REC_FILE]);

// =============================================================================
// 🅹 J. The budget — and the installation caps the organisation
// =============================================================================
head('J. The monthly budget, and which of the two levels wins');

/** What the pages say is in force, read off the admin page. */
$budgetInForce = static function () use (&$jar, $adminPage): string {
    $body = text(req($jar['global'], 'GET', $adminPage)['body']);

    return preg_match('/of £([\d,]+\.\d\d)/', $body, $m) === 1 ? $m[1] : '(not shown)';
};

clearSiteSetting(ORG, 'transcription.monthCapPence');
installSetting('transcription.monthCapPence', '5000');
ok('J01 with only the installation\'s figure, that is what is in force', $budgetInForce() === '50.00', 'showed ' . $budgetInForce());

// 🏢 An organisation may hold itself to LESS.
siteSetting(ORG, 'transcription.monthCapPence', '1000');
ok('J02 an organisation may hold itself to less than the installation allows', $budgetInForce() === '10.00', 'showed ' . $budgetInForce());

// 🚧 And may NEVER give itself more. This is the boundary between two parties:
//    the installation's administrator may be a different person entirely.
siteSetting(ORG, 'transcription.monthCapPence', '2000000');
ok('J03 an organisation asking for more is held to the installation\'s figure', $budgetInForce() === '50.00', 'showed ' . $budgetInForce());
ok(
    'J04 and the number is attributed honestly, so nobody sets it again',
    str_contains(text(req($jar['global'], 'GET', $adminPage)['body']), 'the installation allows') === true,
    'the page shows a figure the organisation did not choose and does not say why'
);

// 🏢 THE CASE THAT WAS GOT WRONG IN EventRecurrence AND CAUGHT BY A CHECK:
//    an installation RAISING its limit, with the organisation having chosen
//    nothing. Resolving "not chosen" to the built-in default would make the
//    default cap the installation, so an installation could lower a limit but
//    never raise one.
clearSiteSetting(ORG, 'transcription.monthCapPence');
installSetting('transcription.monthCapPence', '20000');
ok('J05 an installation may RAISE its figure above the built-in default', $budgetInForce() === '200.00', 'showed ' . $budgetInForce());

// 🧱 The hard maximum in code, which no setting may pass. Three tiers.
installSetting('transcription.monthCapPence', '999999999');
// 🧱 PINNED BY HAND, not read from the constant. This run drives the portal
//    over HTTP and never loads its classes, so the constant is not even in
//    scope here — but it would be the wrong thing to use in any case: asking
//    the code for the number and then checking the code against it proves only
//    that the constant agrees with itself. £5,000 is written out, so changing
//    the ceiling has to be a deliberate edit in two places.
$hardMax = '5,000.00';
ok('J06 a figure beyond the hard maximum in code is cut back to it', $budgetInForce() === $hardMax, 'showed ' . $budgetInForce() . ', the hard maximum should be ' . $hardMax);

installSetting('transcription.monthCapPence', '5000');
clearSiteSetting(ORG, 'transcription.monthCapPence');

// 💳 Spending up to the budget stops the button, with the reason.
q(
    'INSERT INTO tblTranscript (recordingID, provider, status, `fullText`, costPence, generatedAt) '
    . "VALUES (?, 'openai', 'completed', 'spent words', 6000, NOW())",
    'i',
    [REC_SPEND]
);
$spentPage = req($jar['global'], 'GET', $recPage);
ok(
    'J07 with the budget used up, no button is offered',
    preg_match('#<form[^>]+action="[^"]*recordings/transcribe"#', $spentPage['body']) !== 1,
    'the button is offered past the budget'
);
$spentPost = req($jar['global'], 'POST', '/recordings/transcribe', [
    'csrf_token'  => freshToken($jar['global']),
    'recordingID' => (string) REC_FILE,
]);
ok('J08 a direct post past the budget is refused', in_array($spentPost['status'], [302, 303], true) === true, 'status ' . $spentPost['status']);
ok('J09 and nothing was queued', transcriptRow(REC_FILE) === null, 'a row was written past the budget');
ok(
    'J10 the person is told the budget is the reason, and when it resets',
    str_contains(text(req($jar['global'], 'GET', $recPage)['body']), 'budget has been used up') === true,
    'the refusal does not name the cause'
);

// 📋 Asking to exceed what the installation allows crosses a line between two
//    parties, so it is recorded.
$refusedLog = one(
    'SELECT logID FROM tblActivityLogs WHERE activityType = ? ORDER BY logID DESC LIMIT 1',
    's',
    ['TranscriptionRefused']
);
ok('J11 a refusal is recorded in the activity log', $refusedLog !== null, 'no TranscriptionRefused row');

// 🙈 And the reason recorded must not carry the recording's name, which can
//    name a person.
$refusedRow = one(
    'SELECT activityDescription FROM tblActivityLogs WHERE activityType = ? ORDER BY logID DESC LIMIT 1',
    's',
    ['TranscriptionRefused']
);
ok(
    'J12 the recorded line names the reason and not the recording\'s title',
    $refusedRow !== null
        && str_contains((string) $refusedRow['activityDescription'], 'over-cap') === true
        && str_contains((string) $refusedRow['activityDescription'], 'Morning service talk') === false,
    'recorded: ' . (string) ($refusedRow['activityDescription'] ?? 'nothing')
);

q('DELETE FROM tblTranscript WHERE recordingID = ?', 'i', [REC_SPEND]);

// =============================================================================
// 🅺 K. The "Process next" button is in a form of its OWN
// =============================================================================
head('K. The queue button posts to the queue, not to Save');

// 🧪 This section exists because the markup LOOKED right. The button had its
//    own `<form method="post" action=".../run">` around it — and that form was
//    inside the configuration form, which HTML does not allow: a parser meeting
//    a second `<form>` while one is open DROPS the tag and keeps its contents.
//    So the button's owner became the Save form, pressing "Process next" posted
//    the settings, and the only control that could drain the queue drained
//    nothing while saying "Transcription settings saved".
//
//    Searching the text for the right action cannot see that. The question is
//    asked of the PARSED document, with the engine a browser uses.
// 🎛️ From here on the queue is really drained, so the stand-in takes over
//    from the made-up OpenAI key: nothing leaves this machine.
installSetting('transcription.provider', 'local');
q(
    'INSERT INTO tblTranscript (recordingID, provider, status) VALUES '
    . "(?, 'local', 'queued')",
    'i',
    [REC_FILE]
);
$adminBody = req($jar['global'], 'GET', $adminPage)['body'];
$owner     = formActionOwning($adminBody, 'Process next');

ok('K01 the admin page offers a "Process next" button when something is waiting', $owner !== null, 'no such button on the page');
ok(
    'K02 and the form that owns it posts to admin/transcription/run',
    $owner !== null && str_contains($owner, 'admin/transcription/run') === true,
    'the button belongs to a form posting to "' . (string) $owner . '" — if that is the Save address, pressing it saves the settings and transcribes nothing'
);
ok(
    'K03 it is NOT owned by the Save form',
    $owner !== null && str_contains($owner, 'admin/transcription/save') === false,
    'the queue button is owned by the configuration form'
);

// 🧪 And the helper itself is not vacuous: it finds the Save button and
//    attributes it to the Save form. Without this, K02 and K03 would pass on a
//    page with no forms at all.
$saveOwner = formActionOwning($adminBody, 'Save');
ok(
    'K04 the same test attributes the Save button to the Save form (so the test works)',
    $saveOwner !== null && str_contains($saveOwner, 'admin/transcription/save') === true,
    'the helper found "' . (string) $saveOwner . '" for Save, so its answers above cannot be trusted'
);

// 🧪 No nested form anywhere on the page — the general statement, cheap to make
//    now that the document is parsed. A browser would report fewer forms than
//    the markup contains.
$parsed    = \Dom\HTMLDocument::createFromString($adminBody, LIBXML_NOERROR);
$formsSeen = $parsed->getElementsByTagName('form')->length;
$formsWritten = preg_match_all('/<form\b/i', $adminBody);
ok(
    'K05 every form written on the page survives being parsed (none is nested)',
    $formsSeen === $formsWritten,
    $formsWritten . ' form tags written, ' . $formsSeen . ' in the parsed page: the difference is forms a browser threw away'
);

// ▶️ Now press it for real.
$runPage = req($jar['global'], 'GET', $adminPage);
$ran     = req($jar['global'], 'POST', '/admin/transcription/run', ['csrf_token' => token($runPage['body'])]);
ok('K06 pressing it is accepted', in_array($ran['status'], [302, 303], true) === true, 'status ' . $ran['status']);

// The row was CLAIMED — which proves the sweep really ran. There is no real
// provider key behind this, so it then fails; that is the point of section L.
$afterRun = transcriptRow(REC_FILE);
ok(
    'K07 the queued row was picked up (it is no longer waiting)',
    $afterRun !== null && (string) $afterRun['status'] !== 'queued',
    'the row is still queued, so the run did nothing at all'
);
ok(
    'K08 and the moment it was handed over was recorded',
    $afterRun !== null && $afterRun['startedAt'] !== null,
    'startedAt was never set, so a request killed part-way could never be told from one still running'
);

// =============================================================================
// 🅻 L. One organisation's queue, and one organisation's figures
// =============================================================================
head('L. The admin page and the sweep stay inside the organisation');

// Give the other organisation a waiting row and a finished, charged-for one.
q(
    'INSERT INTO tblTranscript (recordingID, provider, status, `fullText`, costPence, generatedAt) '
    . "VALUES (?, 'local', 'completed', 'other org words', 4200, NOW())",
    'i',
    [REC_OTHER]
);
q('DELETE FROM tblTranscript WHERE recordingID = ?', 'i', [REC_FILE]);
q("INSERT INTO tblTranscript (recordingID, provider, status) VALUES (?, 'local', 'queued')", 'i', [REC_FILE]);
// The other organisation's waiting row, so the sweep has something it must not take.
q("UPDATE tblTranscript SET status = 'queued', costPence = NULL, generatedAt = NULL WHERE recordingID = ?", 'i', [REC_OTHER]);

$ourAdmin = req($jar['global'], 'GET', $adminPage);
$ourText  = text($ourAdmin['body']);

ok(
    'L01 another organisation\'s recording title does not appear',
    str_contains($ourText, 'OTHERORGSECRETTITLE') === false,
    'the recent list carries another organisation\'s recording titles'
);
ok(
    'L02 the list is not vacuous — our own organisation\'s work IS shown',
    str_contains($ourText, 'Morning service talk') === true,
    'L01 would pass on an empty page; this pins it to a page that really lists transcripts'
);
ok(
    'L03 the waiting count is ours alone',
    preg_match('/Waiting\s*1\b/', $ourText) === 1,
    'the waiting count is not 1; both organisations have one waiting row each. Page said: '
        . mb_substr((string) (preg_match('/Waiting.{0,40}/', $ourText, $m) === 1 ? $m[0] : '?'), 0, 60)
);

// 💳 And the month's spend. The other organisation has spent £42; we have spent
//    nothing. A figure of £42.00 here would mean one organisation reads
//    another's spending — and, because the budget is checked against it, one
//    busy organisation would stop every other one transcribing anything.
q(
    "UPDATE tblTranscript SET status = 'completed', `fullText` = 'other org words', costPence = 4200, generatedAt = NOW() "
    . 'WHERE recordingID = ?',
    'i',
    [REC_OTHER]
);
$spendText = text(req($jar['global'], 'GET', $adminPage)['body']);
ok(
    'L04 the month\'s spend excludes the other organisation',
    preg_match('/This month £0\.00/', $spendText) === 1,
    'the spend shown includes another organisation: ' . mb_substr((string) (preg_match('/This month.{0,40}/', $spendText, $m) === 1 ? $m[0] : '?'), 0, 60)
);

// 🏭 The sweep itself. Put the other organisation's row back to waiting, press
//    our button, and the other organisation's row must be untouched.
q("UPDATE tblTranscript SET status = 'queued', costPence = NULL, generatedAt = NULL, startedAt = NULL WHERE recordingID = ?", 'i', [REC_OTHER]);
q("UPDATE tblTranscript SET status = 'queued', startedAt = NULL WHERE recordingID = ?", 'i', [REC_FILE]);

$runPage2 = req($jar['global'], 'GET', $adminPage);
$ran2     = req($jar['global'], 'POST', '/admin/transcription/run', ['csrf_token' => token($runPage2['body'])]);
ok('L05 the run is accepted', in_array($ran2['status'], [302, 303], true) === true, 'status ' . $ran2['status']);

$oursAfter  = transcriptRow(REC_FILE);
$theirsAfter = transcriptRow(REC_OTHER);
ok(
    'L06 OUR waiting row was taken (so the run genuinely did something)',
    $oursAfter !== null && (string) $oursAfter['status'] !== 'queued',
    'our row is still waiting, so L07 proves nothing'
);
ok(
    'L07 and the other organisation\'s row was NOT touched',
    $theirsAfter !== null && (string) $theirsAfter['status'] === 'queued' && $theirsAfter['startedAt'] === null,
    'the other organisation\'s row is now "' . (string) ($theirsAfter['status'] ?? 'gone')
        . '": one organisation\'s administrator sent another\'s audio to the provider on the installation\'s account'
);

// =============================================================================
// 🅼 M. Who may change settings that belong to the whole installation
// =============================================================================
head('M. An administrator of one organisation is not offered somebody else\'s settings');

// 🧪 Something has to be WAITING, or the queue card shows a sentence instead
//    of a button and M04 would fail for a reason that has nothing to do with
//    who the person is. Section L drained the queue, so one is put back.
q('DELETE FROM tblTranscript WHERE recordingID = ?', 'i', [REC_FILE]);
q("INSERT INTO tblTranscript (recordingID, provider, status) VALUES (?, 'local', 'queued')", 'i', [REC_FILE]);

$orgAdminPage = req($jar['orgadmin'], 'GET', $adminPage);
$orgAdminText = text($orgAdminPage['body']);
ok('M01 the page is served to an administrator of the organisation', $orgAdminPage['status'] === 200, 'status ' . $orgAdminPage['status']);
ok(
    'M02 no form posting to the settings handler is offered to them',
    preg_match('#<form[^>]+action="[^"]*admin/transcription/save"#', $orgAdminPage['body']) !== 1,
    'they are offered a Save button the handler will refuse — which reads as a broken portal'
);
ok(
    'M03 they are told the settings belong to the whole installation, and who to ask',
    str_contains($orgAdminText, 'every organisation on this installation') === true
        && str_contains($orgAdminText, 'global administrator') === true,
    'the page is silent about why there is nothing to edit'
);
ok(
    'M04 but they may still work their OWN organisation\'s queue',
    formActionOwning($orgAdminPage['body'], 'Process next') !== null,
    'an administrator cannot transcribe their own organisation\'s recordings'
);
ok(
    'M05 and no account key is printed on the page for them',
    str_contains($orgAdminPage['body'], 'sk-selftest-not-a-real-key') === false,
    'the installation\'s API key is on the page'
);
// 🚧 The boundary itself, not the page: a direct post is refused.
//
//    What it was BEFORE the post is read here rather than written into the
//    check. Pinning the expected value by hand looked tidier and was wrong: an
//    earlier section switches the provider to the stand-in, so the check failed
//    against a value this run had changed itself — reporting a breach of the
//    role gate that had not happened. The question is "did their post change
//    it", so both sides come from the database.
$providerBefore = (string) (one("SELECT settingValue FROM tblSettings WHERE settingKey = 'transcription.provider' AND siteID IS NULL")['settingValue'] ?? '');
$orgSave = req($jar['orgadmin'], 'POST', '/admin/transcription/save', [
    // 🔐 Not from the page above: it deliberately has no settings form, so it
    //    carries no token, and a 400 at the token check would look like the
    //    role gate working while proving nothing about it.
    'csrf_token'     => freshToken($jar['orgadmin']),
    'provider'       => 'assemblyai',
    'language'       => 'fr',
    'batchSize'      => '9',
    'monthCapPence'  => '999999',
]);
ok('M06 a direct post of the settings by them is answered with a redirect', in_array($orgSave['status'], [302, 303], true) === true, 'status ' . $orgSave['status']);
$providerAfter = (string) (one("SELECT settingValue FROM tblSettings WHERE settingKey = 'transcription.provider' AND siteID IS NULL")['settingValue'] ?? '');
ok(
    'M07 and nothing was changed',
    $providerAfter === $providerBefore && $providerAfter !== 'assemblyai',
    'the provider went from "' . $providerBefore . '" to "' . $providerAfter
        . '": an administrator of one organisation changed every organisation\'s settings'
);
$capStill = one("SELECT settingValue FROM tblSettings WHERE settingKey = 'transcription.monthCapPence' AND siteID IS NULL");
ok(
    'M08 including the budget, which they asked to raise to £9,999',
    $capStill !== null && (string) $capStill['settingValue'] === '5000',
    'the installation\'s budget is now ' . (string) ($capStill['settingValue'] ?? '?')
);

// ✅ And a GLOBAL administrator really can save, so M06-M08 are about the role
//    and not about a form that never worked.
$globalAdminPage = req($jar['global'], 'GET', $adminPage);
$globalSave = req($jar['global'], 'POST', '/admin/transcription/save', [
    'csrf_token'    => token($globalAdminPage['body']),
    'provider'      => 'openai',
    'language'      => 'en',
    'batchSize'     => '5',
    'enabled'       => '1',
    'monthCapPence' => '7500',
]);
ok('M09 a global administrator may save', in_array($globalSave['status'], [302, 303], true) === true, 'status ' . $globalSave['status']);
$capSaved = one("SELECT settingValue FROM tblSettings WHERE settingKey = 'transcription.monthCapPence' AND siteID IS NULL");
ok(
    'M10 and the budget they set is stored',
    $capSaved !== null && (string) $capSaved['settingValue'] === '7500',
    'the budget is ' . (string) ($capSaved['settingValue'] ?? '?') . ', so M08 may have passed because the field is simply ignored'
);
installSetting('transcription.monthCapPence', '5000');

// =============================================================================
// 🅽 N. Searching inside transcripts is reachable at all
// =============================================================================
head('N. The transcript search can be found');

$library = req($jar['member'], 'GET', '/recordings');
ok('N01 the recordings library is served', $library['status'] === 200, 'status ' . $library['status']);
ok(
    'N02 it links to the search across transcripts',
    str_contains($library['body'], 'recordings/search') === true,
    '/recordings/search is reachable from nowhere: it can only be found by typing the address'
);
ok('N03 and that address really answers', req($jar['member'], 'GET', '/recordings/search')['status'] === 200);

siteSetting(ORG, 'transcription.enabled', '0');
ok(
    'N04 the link is not offered while transcription is switched off',
    str_contains(req($jar['member'], 'GET', '/recordings')['body'], 'recordings/search') === false,
    'members are offered a search of something the organisation does not use'
);
siteSetting(ORG, 'transcription.enabled', '1');

// =============================================================================
// 🅾️ O. A transcript really gets WRITTEN — the whole way through
// =============================================================================
head('O. A recording goes in and a readable transcript comes out');

// 🧪 WHY THIS SECTION EXISTS, and why it is not a formality.
//
//    Everything above proves a recording can be QUEUED. None of it proves a
//    transcript can be SAVED, and those turned out to be different questions:
//    the statement in `processQueue()` that writes a finished transcript had a
//    bare `fullText = ?` in its SET clause, and `FULLTEXT` is a reserved word
//    in MySQL. It did not parse. The connection is strict, so it would have
//    thrown — AFTER the transcription company had been called and charged —
//    and the row would have sat at `processing` for ever. Nothing had ever
//    noticed, because no page could queue a recording in the first place.
//
//    So the whole path is walked here, with a STAND-IN for the transcription
//    company rather than a real one: the `local` provider runs a program on the
//    server, so a few lines of shell in this run's own scratch folder stand in
//    for it. No network, no account, no money, and the real
//    `Transcription::processQueue()` code path either side of it.
ok('O01 the stand-in program is in place and runnable', is_executable($stub) === true, 'could not make ' . $stub . ' executable');

installSetting('transcription.provider', 'local');
installSetting('transcription.local.binPath', $stub);

q('DELETE FROM tblTranscript WHERE recordingID = ?', 'i', [REC_FILE]);
$e2ePage = req($jar['global'], 'GET', $recPage);
ok(
    'O02 the button is offered with the local program set up',
    preg_match('#<form[^>]+action="[^"]*recordings/transcribe"#', $e2ePage['body']) === 1,
    'providerIsReady() does not accept an executable local program'
);
req($jar['global'], 'POST', '/recordings/transcribe', ['csrf_token' => token($e2ePage['body']), 'recordingID' => (string) REC_FILE]);
ok('O03 it is queued', (string) (transcriptRow(REC_FILE)['status'] ?? '') === 'queued');

$runNow = req($jar['global'], 'GET', $adminPage);
$drained = req($jar['global'], 'POST', '/admin/transcription/run', ['csrf_token' => token($runNow['body'])]);
ok('O04 the queue run is accepted', in_array($drained['status'], [302, 303], true) === true, 'status ' . $drained['status']);

$finished = transcriptRow(REC_FILE);
ok(
    'O05 THE TRANSCRIPT IS SAVED — the row says it is finished',
    $finished !== null && (string) $finished['status'] === 'completed',
    'status is "' . (string) ($finished['status'] ?? 'gone') . '". A row left at "processing" is the shape of a '
        . 'statement that could not run: the company was called and the answer could not be written down'
);
ok(
    'O06 and the words themselves are in the row',
    $finished !== null && str_contains((string) $finished['fullText'], 'heard every word') === true,
    'fullText holds: ' . mb_substr((string) ($finished['fullText'] ?? 'nothing'), 0, 120)
);
ok(
    'O07 with the moment it finished recorded',
    $finished !== null && $finished['generatedAt'] !== null,
    'generatedAt is empty on a finished transcript'
);

// 🔎 The search mirror. Without this row `/recordings/search` finds nothing,
//    which is what it did for the whole life of the feature.
$mirror = one('SELECT siteID, body FROM tblTranscriptSearch WHERE recordingID = ? LIMIT 1', 'i', [REC_FILE]);
ok('O08 the words are mirrored into the search table', $mirror !== null, 'nothing was written to tblTranscriptSearch');
ok(
    'O09 against the right organisation',
    $mirror !== null && (int) $mirror['siteID'] === ORG,
    'the mirror row says organisation ' . (string) ($mirror['siteID'] ?? '?') . ' rather than ' . ORG
);

// 📖 And a member can read it, which is the point of all of it.
$readable = text(req($jar['member'], 'GET', '/recordings/transcript?id=' . REC_FILE)['body']);
ok(
    'O10 a member can read the transcript on the page',
    str_contains($readable, 'heard every word') === true,
    'the transcript page does not show the words: ' . mb_substr($readable, 0, 300)
);

// 🔎 The search really finds it. FULLTEXT in natural-language mode ignores very
//    common words, so the term asked for is a distinctive one from the text.
$found = text(req($jar['member'], 'GET', '/recordings/search?q=talk')['body']);
ok(
    'O11 searching inside transcripts finds the recording',
    str_contains($found, 'Morning service talk') === true,
    'the search found nothing: ' . mb_substr($found, 0, 300)
);

// 💳 The cost is recorded and the month's figure moves. The stand-in reports no
//    duration, so the cost is 0 — which is honest: there is nothing to charge
//    for. What matters is that the figure is read from THIS organisation's rows.
$spendNow = text(req($jar['global'], 'GET', $adminPage)['body']);
ok(
    'O12 the admin page counts the finished transcript as ours',
    preg_match('/Ready to read\s*1\b/', $spendNow) === 1,
    'the finished count is not 1: ' . mb_substr((string) (preg_match('/Ready to read.{0,40}/', $spendNow, $m) === 1 ? $m[0] : '?'), 0, 60)
);

installSetting('transcription.provider', 'openai');
installSetting('transcription.local.binPath', '/usr/local/bin/whisper');

// =============================================================================
// 🆁 Q. The lock that stops the same audio being sent twice
// =============================================================================
head('Q. The lock itself — what this harness can and cannot prove');

// 🚧 SAID FIRST, BECAUSE IT MATTERS MORE THAN THE CHECKS BELOW.
//
//    Section I proves the HANDLER refuses to re-send a recording that is with
//    the transcription company. It does NOT prove the lock inside
//    `Transcription::queue()` works, and cannot: this run's server has ONE
//    worker (PHP_CLI_SERVER_WORKERS is unset), so two requests never overlap,
//    and the handler's own refusal fires first every time. Removing the
//    lock-based refusal entirely leaves every check in sections A to P green —
//    that was measured by planting it, not assumed.
//
//    This project has the same limit written down for the expense payment race,
//    and for the same reason. So what is done here is what CAN be done: prove
//    the two writers still TAKE the lock, and prove the lock really blocks on
//    this database engine. Neither is a substitute for a real race.
$coreSrc   = phpWithoutComments((string) file_get_contents($repo . $sep . 'web' . $sep . '_core' . $sep . 'Transcription.php'));
$queueBody = sqlish(methodBody($coreSrc, 'queue'));
$sweepBody = sqlish(methodBody($coreSrc, 'processQueue'));

ok('Q01 the queue() body was found (so Q02-Q04 are about real code)', $queueBody !== '' && strlen($queueBody) > 400, 'body length ' . strlen($queueBody));
ok('Q02 the sweep\'s body was found too', $sweepBody !== '' && strlen($sweepBody) > 400, 'body length ' . strlen($sweepBody));

ok(
    'Q03 queue() takes the recording\'s row lock',
    preg_match('/FROM tblRecording WHERE recordingID = \? FOR UPDATE/', $queueBody) === 1,
    'the lock is gone from queue(), so it no longer takes turns with the sweep'
);
// 🎯 THE WHOLE CONDITION, THE ROLLBACK AND THE RETURN AS ONE PATTERN, not
//    three facts that happen to be in the same file.
//
//    The first version of this check looked for `stillRunning` and
//    `return 'busy'` anywhere in the body, and planting proved it worthless:
//    adding `&& false` to the condition left both strings exactly where they
//    were and the check stayed green on a guard that no longer guarded
//    anything. That is the same weakness the QR-service check had twice.
//
//    WHAT THIS STILL CANNOT SEE, said plainly: it reads the text of the code,
//    so it knows the refusal is WRITTEN, not that it RUNS. A rewrite that keeps
//    this exact shape and makes it unreachable some other way — an early return
//    above it, for instance — would pass. Nothing here can close that; the
//    reason this is a text check at all is that a single-worker harness cannot
//    create the race the refusal exists for.
ok(
    'Q04 and refuses with "busy" while the row is still running, with the condition intact',
    preg_match(
        "/\\\$before !== null && \\(int\\) \\\$before\\['stillRunning'\\] === 1\\)\\s*\\{\\s*\\\$db->rollback\\(\\);\\s*return 'busy';/",
        $queueBody
    ) === 1,
    'the refusal inside the lock is gone or its condition has been changed: a press during a run would put the row back in the queue and the same audio would be sent, and charged for, twice'
);
ok(
    'Q05 the sweep takes the SAME lock before it claims a row',
    preg_match('/FROM tblRecording WHERE recordingID = \? FOR UPDATE/', $sweepBody) === 1,
    'only one of the two writers locks, which makes the lock useless — see queue()\'s own comment'
);
ok(
    'Q06 and its claim is still conditional on the row being queued',
    preg_match("/UPDATE tblTranscript SET status = 'processing'.*?WHERE transcriptID = \? AND status = 'queued'/s", $sweepBody) === 1,
    'the claim no longer checks the row is waiting, so two runs could both claim it'
);
// 🎯 Asked of the TRANSACTION, not of the whole method.
//
//    The first version was `/commit\(\).*?providerTranscribe/s`, which only
//    proved that SOME commit came before SOME call — true of almost any
//    arrangement, including the one it exists to forbid, as long as an
//    unrelated commit happened earlier. The independent check pointed that out.
//
//    What is asked now: between the transaction being opened and the next
//    commit, the provider is not called. That is the claim this guard is
//    about.
//
//    WHAT IT STILL CANNOT SEE: a SECOND transaction opened later with the call
//    inside it. There is only one here, and the text below would have to be
//    rewritten to add another, so this is a tripwire rather than a proof.
$txStart = strpos($sweepBody, 'begin_transaction()');
$txEnd   = $txStart === false ? false : strpos($sweepBody, 'commit()', $txStart);
$inside  = ($txStart !== false && $txEnd !== false)
    ? substr($sweepBody, $txStart, $txEnd - $txStart)
    : '';
ok('Q07 the sweep opens a transaction and commits it (so Q08 below is about real code)', $inside !== '', 'no begin_transaction()/commit() pair found in processQueue');
ok(
    'Q07b and the provider is NOT called between the two',
    $inside !== '' && str_contains($inside, 'providerTranscribe') === false,
    'the long call to the outside company is inside the transaction: a lock held across a twenty-five minute request blocks every edit of that recording, and on a shared host the request is killed with the lock still held'
);
ok(
    'Q07c while the provider IS called somewhere in the method (not simply absent)',
    str_contains($sweepBody, 'providerTranscribe') === true,
    'nothing calls the provider at all, so Q07b passes for the wrong reason'
);

// 🔬 And the lock really blocks, on this engine, measured with TWO REAL
//    CONNECTIONS. Without this, Q03 and Q05 only prove the words are present.
$second = new mysqli(
    getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    $dbName,
    getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
);
$second->set_charset('utf8mb4');
$second->query('SET innodb_lock_wait_timeout = 1');

$db->begin_transaction();
$db->query('SELECT recordingID FROM tblRecording WHERE recordingID = ' . REC_FILE . ' FOR UPDATE');

$blocked = false;
try {
    $second->begin_transaction();
    $second->query('SELECT recordingID FROM tblRecording WHERE recordingID = ' . REC_FILE . ' FOR UPDATE');
    $second->commit();
} catch (Throwable $e) {
    $blocked = true;
    $second->rollback();
}
ok(
    'Q08 a second connection really is held off by that lock',
    $blocked === true,
    'the FOR UPDATE did not block a second connection, so the two writers would not take turns at all'
);

// 🧪 Not vacuous: the same read WITHOUT the lock goes straight through, so Q08
//    is about the lock and not about something else refusing.
$wentThrough = false;
try {
    $second->begin_transaction();
    $second->query('SELECT recordingID FROM tblRecording WHERE recordingID = ' . REC_FILE);
    $second->commit();
    $wentThrough = true;
} catch (Throwable $e) {
    $second->rollback();
}
ok(
    'Q09 while an ordinary read of the same row is not held off (so Q08 tests the lock)',
    $wentThrough === true,
    'even an unlocked read failed, so Q08 may have passed for some other reason entirely'
);
$db->rollback();
$second->close();

// =============================================================================
// 🆂 R. The five things the independent check found
// =============================================================================
head('R. What the independent check found, each now guarded');

// 🚧 R3 — the queue run must ask the same question the page asks.
//
//    The page draws "Process next" with `disabled` when no company is set up,
//    and that WAS the only thing in the way. A posted request reached the
//    handler, claimed every waiting row, got `…-not-configured` back and marked
//    the lot FAILED — up to `batchSize` recordings moved out of the queue for a
//    fault that had nothing to do with any of them.
q('DELETE FROM tblTranscript WHERE recordingID BETWEEN 921100 AND 921199');
q("INSERT INTO tblTranscript (recordingID, provider, status) VALUES (?, 'openai', 'queued')", 'i', [REC_FILE]);
installSetting('transcription.provider', 'assemblyai');
installSetting('transcription.assemblyai.apiKey', '');

$noProviderPage = req($jar['global'], 'GET', $adminPage);
$noProviderRun  = req($jar['global'], 'POST', '/admin/transcription/run', ['csrf_token' => token($noProviderPage['body'])]);
ok('R01 a queue run with no company set up is answered, not an error page', in_array($noProviderRun['status'], [302, 303], true) === true, 'status ' . $noProviderRun['status']);

$untouched = transcriptRow(REC_FILE);
ok(
    'R02 and the waiting recording is STILL WAITING, not marked as failed',
    $untouched !== null && (string) $untouched['status'] === 'queued' && $untouched['startedAt'] === null,
    'the row became "' . (string) ($untouched['status'] ?? 'gone') . '": a posted request moved every waiting '
        . 'recording out of the queue because no company was configured'
);
ok(
    'R03 and the person is told nothing was sent and nothing was failed',
    str_contains(text(req($jar['global'], 'GET', $adminPage)['body']), 'still waiting') === true,
    'the refusal does not say what happened to the queue'
);
// 🎛️ The stand-in put back BY PATH, not just by name. Section O set
//    `local.binPath` back to `/usr/local/bin/whisper`, which does not exist
//    here — so `providerIsReady()` was false and the NEW gate above refused
//    every run below. R05 then passed for entirely the wrong reason (the
//    provider gate, not the budget) and R06 is what caught it. A check that
//    passes for the wrong reason is the thing this file exists to avoid.
installSetting('transcription.provider', 'local');
installSetting('transcription.local.binPath', $stub);
installSetting('transcription.assemblyai.apiKey', 'unused-by-this-run');

// 💳 obs6 — the budget stopping a run PART-WAY, which nothing exercised.
//
//    The row is queued with SQL rather than through the button, because the
//    button would refuse once the budget is spent — and what is under test here
//    is the check inside the sweep, not the one on the page.
q('DELETE FROM tblTranscript WHERE recordingID BETWEEN 921100 AND 921199');
q(
    'INSERT INTO tblTranscript (recordingID, provider, status, `fullText`, costPence, generatedAt) '
    . "VALUES (?, 'local', 'completed', 'already spent', 9000, NOW())",
    'i',
    [REC_SPEND]
);
q("INSERT INTO tblTranscript (recordingID, provider, status) VALUES (?, 'local', 'queued')", 'i', [REC_FILE]);
installSetting('transcription.monthCapPence', '5000');

$capRunPage = req($jar['global'], 'GET', $adminPage);
$capRun     = req($jar['global'], 'POST', '/admin/transcription/run', ['csrf_token' => token($capRunPage['body'])]);
ok('R04 a run past the budget is accepted', in_array($capRun['status'], [302, 303], true) === true, 'status ' . $capRun['status']);
$notSent = transcriptRow(REC_FILE);
ok(
    'R05 and the waiting recording was NOT sent',
    $notSent !== null && (string) $notSent['status'] === 'queued',
    'the row became "' . (string) ($notSent['status'] ?? 'gone') . '" — the budget did not stop the sweep'
);
ok(
    'R06 and the run SAYS the budget stopped it, rather than looking like an empty run',
    str_contains(text(req($jar['global'], 'GET', $adminPage)['body']), 'budget is used up') === true,
    'a run stopped by the budget reads exactly like one that found nothing to do, so the button gets pressed again and again'
);

// 🚧 `skipped` is NOT exercised here and cannot be. A row is only skipped when
//    another run claims it between this one's SELECT and its UPDATE, and the
//    harness has one worker, so the two can never interleave. The wording is
//    checked where it lives instead, in section Q's style, rather than faked.
$runSrc = phpWithoutComments((string) file_get_contents($repo . $sep . 'web' . $sep . '_apps' . $sep . 'admin' . $sep . 'transcription' . $sep . 'run.php'));
ok(
    'R07 the run page still has words for a row another run had claimed (unreachable here, so read instead)',
    str_contains($runSrc, 'already been claimed') === true,
    'nothing would tell an administrator why a run did less than they expected'
);

// 💳 obs5 — a budget of nothing is not a budget that ran out.
q('DELETE FROM tblTranscript WHERE recordingID BETWEEN 921100 AND 921199');
installSetting('transcription.monthCapPence', '0');
$zeroPage = text(req($jar['global'], 'GET', $recPage)['body']);
ok(
    'R08 with no budget set, the page says so',
    str_contains($zeroPage, 'No transcription budget has been set') === true,
    'page says: ' . mb_substr($zeroPage, 0, 300)
);
ok(
    'R09 and does NOT claim a budget has been used up',
    str_contains($zeroPage, 'has been used up') === false,
    'an administrator is sent looking for spending that never happened'
);
installSetting('transcription.monthCapPence', '5000');

// 🏷️ R5 — "your organisation asked for more" must be about the ORGANISATION.
//
//    This is the state J06 creates: the installation's own figure is above the
//    hard maximum in code, and the organisation has chosen nothing. The page
//    must show £5,000 and must NOT blame the organisation for it.
clearSiteSetting(ORG, 'transcription.monthCapPence');
installSetting('transcription.monthCapPence', '999999999');
$hardCapText = text(req($jar['global'], 'GET', $adminPage)['body']);
ok('R10 the figure in force is the hard maximum in code', str_contains($hardCapText, 'of £5,000.00') === true, 'showed: ' . mb_substr((string) (preg_match('/of £[\d,\.]+/', $hardCapText, $m) === 1 ? $m[0] : '?'), 0, 40));
ok(
    'R11 and the organisation is NOT blamed for a figure it never chose',
    str_contains($hardCapText, 'asked for more') === false,
    'the page tells a global administrator their organisation asked for more than it may have, about a number the organisation never touched'
);

// 🧪 And the same sentence still APPEARS when it is true, so R11 is not passing
//    because the line was simply deleted.
siteSetting(ORG, 'transcription.monthCapPence', '2000000');
installSetting('transcription.monthCapPence', '5000');
ok(
    'R12 while an organisation that really did ask for more IS told so (R11 is not vacuous)',
    str_contains(text(req($jar['global'], 'GET', $adminPage)['body']), 'asked for more') === true,
    'the attribution line never appears at all, so R11 proves nothing'
);
clearSiteSetting(ORG, 'transcription.monthCapPence');

// 🛟 obs1 — one bad row must not end the batch. The fault that would prove this
//    is a statement that cannot run, which cannot be arranged from out here, so
//    the containment is read in the code as section Q does.
$coreAgain  = phpWithoutComments((string) file_get_contents($repo . $sep . 'web' . $sep . '_core' . $sep . 'Transcription.php'));
$sweepAgain = methodBody($coreAgain, 'processQueue');
// 🎯 THE CONTAINMENT, NOT JUST THE WORDS FOR IT.
//
//    The first version asked only whether `catch (\Throwable` and
//    `markFailed(…, 'internal-error')` were present, and planting proved that
//    worthless: adding `throw $e;` INSIDE the catch left both strings exactly
//    where they were and the check stayed green on a sweep that once again
//    ended at the first bad row. The same weakness Q04 had.
//
//    So the question asked is the one that matters: this method must never
//    re-throw. There is no legitimate `throw` in it — every fault becomes a
//    failed row — so the absence of the word IS the guarantee, and a deliberate
//    future `throw` will have to come with a deliberate edit here.
ok(
    'R13 the sweep contains a fault in one job rather than letting it end the run',
    preg_match('/catch\s*\(\\\\?Throwable/', $sweepAgain) === 1
        && preg_match("/markFailed\(\\\$tid, 'internal-error'\)/", $sweepAgain) === 1,
    'a statement that cannot run would escape as a bare 500: the rows already done go unreported and the row in hand sits claimed'
);
ok(
    'R13b and never passes a fault back out, which would end the run at the first bad row',
    str_contains($sweepAgain, 'throw ') === false,
    'the sweep re-throws: one recording the database cannot write gives a bare 500 and abandons every row after it'
);
ok(
    'R14 and never records the fault\'s own words',
    preg_match('/markFailed\(\$tid, \$e/', $sweepAgain) !== 1
        && preg_match('/getMessage\(\)/', $sweepAgain) !== 1,
    'the fault\'s message is recorded, and it can quote what was sent to the outside company'
);

q('DELETE FROM tblTranscript WHERE recordingID BETWEEN 921100 AND 921199');

// =============================================================================
// 🅿️ P. Nothing fell over on the way
// =============================================================================
// =============================================================================
// 🏛️ S. The form shows the INSTALLATION's settings, not this organisation's (#674)
// =============================================================================
//
// WHY THIS SECTION EXISTS. `admin/transcription/save.php` writes every field on
// that form installation-wide — the row with `siteID IS NULL`. The page used to
// fill its boxes from `$SETTINGS`, which has ALREADY merged the installation's
// rows with the open organisation's. So a global administrator who opened the
// page while in an organisation holding its own value saw that value, and
// pressing Save without touching anything made it the INSTALLATION's, for every
// organisation on it. It needed no mistake by anybody.
//
// Nothing in the portal writes an organisation-level row for these keys, so this
// section has to create one to test the behaviour at all — which is also why the
// page now REPORTS such a row rather than hiding it.
head('S. The form shows the installation settings, not this organisation (#674)');

$setOrgRow = static function (string $key, string $value): void {
    q('DELETE FROM tblSettings WHERE settingKey = ? AND siteID = ?', 'si', [$key, ORG]);
    q(
        'INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) '
        . 'VALUES (?, ?, ?, ?, 0)',
        'isss',
        [ORG, $key, $value, $value]
    );
};

// The installation says one thing; this organisation says another.
installSetting('transcription.provider', 'openai');
installSetting('transcription.language', 'en');
$setOrgRow('transcription.provider', 'assemblyai');

$s1 = req($jar['global'], 'GET', '/admin/transcription');
ok(
    'S01 the page is still answered with an organisation-level row present',
    $s1['status'] === 200,
    'status ' . $s1['status']
);

// 🧪 EVERY box the form submits must show the INSTALLATION's value — not just
//    the provider. The first version of this section checked `provider` alone,
//    and the independent check proved what that was worth: putting the merged
//    read back on `language` and `batchSize` left all 149 checks PASSING and the
//    audit gate green. One pinned field out of nine is one guard and eight
//    unguarded fields.
//
//    Each control is given a DIFFERENT installation value and organisation
//    value, so a box showing either one identifies which layer it read.
$boxes = [
    // [setting key, control name, installation value, this organisation's value]
    ['transcription.provider',     'provider',  'openai',          'assemblyai'],
    ['transcription.language',     'language',  'en',              'cy'],
    ['transcription.batchSize',    'batchSize', '7',               '23'],
    ['transcription.openai.model', 'oa_model',  'whisper-1',       'whisper-SELFTEST'],
    ['transcription.local.binPath', 'local_bin', '/usr/bin/instal', '/usr/bin/orgonly'],
];
foreach ($boxes as [$key, $control, $installValue, $orgValue]) {
    installSetting($key, $installValue);
    $setOrgRow($key, $orgValue);
}
$sBoxes = req($jar['global'], 'GET', '/admin/transcription');

// Read the PARSED document: which option carries `selected`, and what an input
// holds, are exactly the things a regular expression gets wrong on attribute
// order. `getAttribute()` returns NULL for a missing attribute, not ''.
$valueOf = static function (string $html, string $control): ?string {
    if (class_exists('Dom\HTMLDocument') === false) {
        return null;
    }
    $doc = Dom\HTMLDocument::createFromString(
        '<!doctype html><html><body>' . $html . '</body></html>',
        LIBXML_NOERROR
    );
    foreach ($doc->getElementsByTagName('input') as $input) {
        if ((string) $input->getAttribute('name') === $control) {
            return (string) $input->getAttribute('value');
        }
    }
    foreach ($doc->getElementsByTagName('select') as $select) {
        if ((string) $select->getAttribute('name') !== $control) {
            continue;
        }
        foreach ($select->getElementsByTagName('option') as $option) {
            if ($option->getAttribute('selected') !== null) {
                return (string) $option->getAttribute('value');
            }
        }
        return '';   // a select with nothing selected
    }
    return null;
};

foreach ($boxes as $i => [$key, $control, $installValue, $orgValue]) {
    $shown = $valueOf($sBoxes['body'], $control);
    ok(
        sprintf('S02%s the %s box shows the INSTALLATION value, not this organisation override',
            chr(97 + $i), $control),
        $shown === $installValue,
        sprintf('shows %s; the installation holds %s and this organisation holds %s',
            var_export($shown, true), var_export($installValue, true), var_export($orgValue, true))
    );
}

$selected = $valueOf($sBoxes['body'], 'provider');

ok(
    'S03 and the page SAYS this organisation has its own value, naming the setting',
    str_contains($s1['body'], 'transcription.provider')
        && str_contains(text($s1['body']), 'own value'),
    'the notice must name the key, because hiding the difference is how the old fault stayed invisible'
);

// Saving without touching anything must not promote the organisation value.
$beforeSave = one(
    'SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL',
    's',
    ['transcription.provider']
);
$s4 = req($jar['global'], 'POST', '/admin/transcription/save', [
    'csrf_token' => freshToken($jar['global']),
    'enabled'    => '1',
    'provider'   => $selected ?? 'openai',
    'language'   => 'en',
    'batchSize'  => '5',
]);
$afterSave = one(
    'SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL',
    's',
    ['transcription.provider']
);
ok(
    'S04 saving the form as shown leaves the installation value alone',
    $afterSave !== null && (string) $afterSave['settingValue'] === 'openai',
    'before ' . var_export($beforeSave['settingValue'] ?? null, true)
        . ', after ' . var_export($afterSave['settingValue'] ?? null, true)
        . ' (status ' . $s4['status'] . ')'
);
ok(
    'S05 and this organisation own row is untouched by that save',
    (static function (): bool {
        $row = one('SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID = ?', 'si',
            ['transcription.provider', ORG]);
        return $row !== null && (string) $row['settingValue'] === 'assemblyai';
    })(),
    'the save writes installation-wide only; it must not reach into an organisation row'
);

// 🚫 S03 would pass on a page that always shows that notice, so prove it does
//    not: with the override gone, the notice must go too.
foreach ($boxes as [$key, $control, $installValue, $orgValue]) {
    q('DELETE FROM tblSettings WHERE settingKey = ? AND siteID = ?', 'si', [$key, ORG]);
}
$s6 = req($jar['global'], 'GET', '/admin/transcription');
ok(
    'S06 with no organisation row, the notice is GONE (S03 is not vacuous)',
    str_contains(text($s6['body']), 'own value') === false,
    'a notice that is always shown says nothing'
);

head('P. The server logged no fault');

$errorLog = $child . $sep . 'php-errors.log';
$logged   = is_file($errorLog) === true ? (string) file_get_contents($errorLog) : '';
// A notice about a session cookie sent after output is the built-in server's
// own business and says nothing about this code; everything else matters.
$realFaults = array_values(array_filter(
    preg_split('/\R/', $logged) ?: [],
    static fn (string $line): bool => $line !== ''
        && str_contains($line, 'Cannot modify header information') === false
));
ok(
    'P01 no PHP error, warning or notice was logged by any page in this run',
    $realFaults === [],
    implode("\n        ", array_slice($realFaults, 0, 6))
);
$serverLog = $child . $sep . 'server-output.log';
$serverOut = is_file($serverLog) === true ? (string) file_get_contents($serverLog) : '';
ok(
    'P02 and no page answered 500',
    preg_match('/ 500 /', $serverOut) !== 1,
    (string) (preg_match('/^.* 500 .*$/m', $serverOut, $m) === 1 ? $m[0] : '')
);

// =============================================================================
// 🧮 The verdict
// =============================================================================
echo "\n" . str_repeat('-', 72) . "\n";
echo 'checks: ' . ($pass + $fail) . ', passed ' . $pass . ', failed ' . $fail . "\n";
if ($fail > 0) {
    echo "VERDICT: FAILED\n";
    exit(1);
}
echo "VERDICT: PASSED\n";
exit(0);
