<?php
// Path: tools/position-refusal-selftest.php
/**
 * -----------------------------------------------------------------------------
 * A map position that cannot be used is REFUSED, with the reason, on every form 📍 (#1030)
 * -----------------------------------------------------------------------------
 * WHAT WAS WRONG. `GeoLocation::validateCoords()` answers null for a position
 * it cannot use — a word in a box, a decimal comma, one box left empty, a
 * number out of range — and null is also its answer for two empty boxes. Seven
 * forms took null as "no position" and saved:
 *
 *   asset locations, venues, resources, events, a date's own details (the
 *   per-occurrence overrides), a member's directory entry, and small groups
 *   (the group's meeting place, and a host's home — two pages, one form kind).
 *
 * So a person who mistyped a latitude was told the save had worked while the
 * position was dropped, and where the form EDITS something that already had a
 * position, the stored one was WIPED. Two of the pages (the overrides and the
 * directory entry) did not even print their save handler's messages, so their
 * existing what3words refusal had been just as invisible.
 *
 * WHAT IT IS NOW. `GeoLocation::typedPositionProblem()` is the ONE judge of why
 * a typed position cannot be used, and `GeoLocation::positionRefusal()` the one
 * sentence; every one of the eight pages asks it and refuses before anything is
 * written, the way seven of them already refused a mistyped what3words address
 * (asset locations still drops one silently, which is not this change). (The
 * organisation page, #945, asks the same judge and keeps the stored position
 * instead; `time-zones-selftest.php` part P drives that one.)
 *
 * WHAT THIS DOES. It copies `web/` into a scratch folder, starts PHP's own web
 * server on the copy, signs a made-up global administrator in through the real
 * sign-in form, and for each page:
 *
 *   - reads the form as the page DRAWS it, so the request is the one a
 *     browser would send (only the fields named in each section are changed);
 *   - posts it with a WORD in the latitude box, and requires the page the
 *     person lands on to SAY the position could not be used and why, and the
 *     database to hold nothing new — or, on an edit, everything exactly as it
 *     was, the stored position included;
 *   - posts it again with a usable position, and requires that to be saved —
 *     so a refusal is never just a request that could not have worked.
 *
 *   U  the judge itself, without HTTP: the three reasons, both boxes empty,
 *      and the shapes `coordinateNumber()` accepts.
 *   A–H  the eight pages, as listed in the output. Each refused post is also
 *      required to leave EVERY table in the database with the rows it had
 *      (X2b): "Nothing was saved." is a claim about the whole database.
 *   B4 the venue form can create a new LANDLORD as well as a venue, and used
 *      to do so ABOVE its refusals, so a refused venue left a landlord row
 *      (and each retry another). Every refusal the page makes is posted with a
 *      landlord name filled in, and must write nothing at all (round 2).
 *   M  Venues::saveVenue() and AssetRegister::saveLocation() ask the judge a
 *      second time; no page reaches that ask, so it is called directly, in a
 *      separate process loading the COPY's classes.
 *   Z  plants, in the COPY: each page's refusal switched off in turn (eight),
 *      and then the judge itself made to find nothing; the landlord's creation
 *      moved back above the refusals; the new "no venue name" refusal switched
 *      off; and each model-layer ask switched off. Each must turn its check
 *      red, and each file is put back byte for byte.
 *
 * WHAT IT CANNOT DO, said plainly
 *   - A browser stops most of this before the request is sent: the boxes are
 *     `type="number"` with a range. The server is the boundary, because a
 *     request can come from anything; this test sends what a browser would
 *     not, on purpose.
 *   - It runs ONE worker (`PHP_CLI_SERVER_WORKERS` unset), with no what3words
 *     key and the automatic address look-up OFF (a fresh installation), so no
 *     request leaves this machine. Whether a look-up would have filled a
 *     position in after a refusal is moot: nothing is saved.
 *   - It does not judge how the refusal LOOKS, only that its words are on the
 *     page the person lands on.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_position SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         php tools/position-refusal-selftest.php
 *
 *         The database must already hold `web/_sql/full_schema.sql`. Needs
 *         PHP's curl and dom extensions and `proc_open()`. It REFUSES rather
 *         than skipping when anything is missing, because a skipped test reads
 *         exactly like a passing one.
 *
 * Exit:   0 only when every check ran and passed and the number of checks is
 *         the number expected; 1 otherwise; 2 when it refused to run.
 *
 * WHAT IT CHANGES, AND PUTS BACK. One made-up person, numbered 1030801, with a
 * sign-in of their own; the small-groups switch, turned on for the run and put
 * back; and the rows the control saves make, each named "Selftest 1030 …" and
 * removed by its own number at the end, with the person's activity records.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1030
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** The made-up global administrator. Nothing else in tools/ uses 10308xx. */
const PR_ADMIN = 1030801;
/** Every row this test makes carries this in its name, so it can be found. */
const PR_MARK = 'Selftest 1030';

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);

$passed = 0;
$failed = 0;
$state  = ['proc' => null, 'child' => null, 'db' => null, 'sgBefore' => false];

function refuse(string $why): never
{
    echo "REFUSED: {$why}.\n";
    echo "Nothing more was checked, and this run fails. A skipped test reads exactly\n";
    echo "like a passing one, which is why this refuses rather than skipping.\n";
    exit(2);
}

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok === true) {
        $passed += 1;
        echo "  ✓ {$name}\n";
        return;
    }
    $failed += 1;
    echo "  ✗ {$name}\n";
    if ($detail !== '') {
        echo "      {$detail}\n";
    }
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
if (class_exists('DOMDocument') === false) {
    refuse('this test needs PHP\'s dom extension, to read each form as the page draws it');
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
$db->set_charset('utf8mb4');
$state['db'] = $db;
$tables = (int) ($db->query(
    'SELECT COUNT(*) AS `n` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE()'
)->fetch_assoc()['n'] ?? 0);
if ($tables < 100) {
    refuse("the database holds only {$tables} tables — load web/_sql/full_schema.sql first");
}

/** @param list<mixed> $params @return array<string,mixed>|null */
function one(string $sql, string $types = '', array $params = []): ?array
{
    global $db;
    $st = $db->prepare($sql);
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    return $row === null ? null : $row;
}

/** @param list<mixed> $params */
function q(string $sql, string $types = '', array $params = []): void
{
    global $db;
    $st = $db->prepare($sql);
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $st->close();
}

// ── The judge's own class, for section U ────────────────────────────────────
require $repo . $sep . 'web' . $sep . '_core' . $sep . 'GeoLocation.php';
use Portal\Core\GeoLocation;

// ── A copy of web/, so a planted fault never touches the repository ─────────
$child = sys_get_temp_dir() . $sep . 'position-refusal-' . bin2hex(random_bytes(6));
$state['child'] = $child;

/**
 * Remove what this run made, children first, and put the small-groups switch
 * back. Runs before the run (anything an earlier run left), at the end, AND
 * when the run stops early.
 *
 * Rows are found by PR_MARK in their own name, never by a list of numbers this
 * run kept: a page with its refusal switched off (section Z, or the code before
 * #1030) saves rows nobody numbered, and a list would leave them behind. The
 * first version kept a list and did leave them -- found when this test was run
 * against the old code. Every table name here is written in this file.
 */
$tidy = static function () use (&$state): void {
    $db = $state['db'];
    if (($db instanceof mysqli) === false) {
        return;
    }
    if ($state['sgBefore'] !== false) {
        try {
            $st = $db->prepare("UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingKey` = 'small-groups.enabled' AND `siteID` IS NULL");
            $st->bind_param('s', $state['sgBefore']);
            $st->execute();
            $st->close();
            $state['sgBefore'] = false;
        } catch (Throwable $e) {
            echo '  ! could not put the small-groups switch back: ' . $e->getMessage() . "\n";
        }
    }
    $like = PR_MARK . '%';
    $statements = [
        'DELETE FROM `tblSmallGroupPlaces` WHERE `groupID` IN (SELECT `groupID` FROM `tblSmallGroups` WHERE `groupName` LIKE ?)',
        'DELETE FROM `tblSmallGroupMembers` WHERE `groupID` IN (SELECT `groupID` FROM `tblSmallGroups` WHERE `groupName` LIKE ?)',
        'DELETE FROM `tblSmallGroups` WHERE `groupName` LIKE ?',
        'DELETE FROM `tblEventOccurrenceOverrides` WHERE `eventID` IN (SELECT `eventID` FROM `tblEvents` WHERE `eventName` LIKE ?)',
        'DELETE FROM `tblEvents` WHERE `eventName` LIKE ?',
        'DELETE FROM `tblVenueUsageTypes` WHERE `venueID` IN (SELECT `venueID` FROM `tblVenues` WHERE `venueName` LIKE ?)',
        'DELETE FROM `tblVenues` WHERE `venueName` LIKE ?',
        // The venue form's "new landlord" (#1030 round 2), after the venues that name one.
        'DELETE FROM `tblAssetOrgs` WHERE `orgName` LIKE ?',
        'DELETE FROM `tblAssetLocations` WHERE `locationName` LIKE ?',
        'DELETE FROM `tblResource` WHERE `name` LIKE ?',
    ];
    foreach ($statements as $sql) {
        try {
            $st = $db->prepare($sql);
            $st->bind_param('s', $like);
            $st->execute();
            $st->close();
        } catch (Throwable $e) {
            echo '  ! the tidy-up could not run "' . $sql . '": ' . $e->getMessage() . "\n";
        }
    }
    try {
        $rs = $db->query(
            "SELECT `TABLE_NAME` FROM `information_schema`.`KEY_COLUMN_USAGE` "
            . "WHERE `TABLE_SCHEMA` = DATABASE() AND `REFERENCED_TABLE_NAME` = 'tblUsers' AND `COLUMN_NAME` = 'userID'"
        );
        while (($row = $rs->fetch_assoc()) !== null) {
            try {
                // A table name read from the server, never from a request.
                $db->query('DELETE FROM `' . str_replace('`', '``', (string) $row['TABLE_NAME']) . '` WHERE `userID` = ' . PR_ADMIN);
            } catch (Throwable $e) {
                // A table of a different shape; the person's own delete below says whether it mattered.
            }
        }
        $rs->free();
        $db->query('DELETE FROM `tblUsers` WHERE `userID` = ' . PR_ADMIN);
    } catch (Throwable $e) {
        echo '  ! could not remove the test person ' . PR_ADMIN . ': ' . $e->getMessage() . "\n";
    }
};

register_shutdown_function(static function () use (&$state, $tidy): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
    }
    $tidy();
    if ($state['child'] !== null && is_dir($state['child']) === true) {
        // A plain recursive delete of a folder THIS run made, under the system
        // temp folder, whose name holds random bytes.
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
file_put_contents(
    $child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
    "<?php\n// Written by tools/position-refusal-selftest.php for ONE run.\nreturn "
    . var_export(['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort], true)
    . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// The router imitates web/public_html/.htaccess, as the other page tests do.
$router = <<<'ROUTER'
<?php
// Written by tools/position-refusal-selftest.php for ONE run. Test only.
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
ROUTER;
file_put_contents($child . $sep . 'router.php', $router);

$port = getenv('SELFTEST_WEB_PORT') !== false ? (int) getenv('SELFTEST_WEB_PORT') : 9030 + random_int(0, 200);
$jar  = $child . $sep . 'cookies.txt';

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
        // Off, so a planted file is read again on the very next request.
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

/**
 * One request, with this run's cookies. Redirects are NOT followed: where a
 * handler sends the person is a fact this test reads.
 *
 * @param array<string,string>|null $post
 * @return array{status: int, location: string, body: string}
 */
$http = static function (string $method, string $path, ?array $post = null) use ($port, $jar): array {
    $handle = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => ['Expect:', 'Accept-Language: en'],
    ]);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post ?? []));
    }
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle)];
    }
    $size = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head = substr((string) $raw, 0, $size);

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $m) === 1 ? $m[1] : '',
        'body'     => substr((string) $raw, $size),
    ];
};

$up = false;
for ($i = 0; $i < 80; $i++) {
    if ($http('GET', '/login')['status'] > 0) {
        $up = true;
        break;
    }
    usleep(250000);
}
if ($up === false) {
    refuse('the test server never answered');
}

// ── The administrator, and the switches ─────────────────────────────────────
$tidy();   // anything a previous run left behind
$siteId = (int) (one('SELECT MIN(`siteID`) AS `s` FROM `tblSites`')['s'] ?? 0);
if ($siteId <= 0) {
    refuse('the database has no organisation');
}
$password = 'Sel' . bin2hex(random_bytes(8)) . '!9x';
q('INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `createdAt`) '
    . "VALUES (?, 'Selftest 1030 Admin', 'position.refusal@selftest.invalid', 1, 1, 1, NOW())", 'i', [PR_ADMIN]);
q('INSERT INTO `tblUserSites` (`userID`, `siteID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES (?, ?, 1, 1, 1)', 'ii', [PR_ADMIN, $siteId]);
$hash = password_hash($password, PASSWORD_DEFAULT);
q("INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, 'selftest-position-1030', ?, 1)", 'is', [PR_ADMIN, $hash]);

// Small groups ship switched OFF; every other app these pages belong to ships on.
$sg = one("SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'small-groups.enabled' AND `siteID` IS NULL");
if ($sg === null) {
    refuse('this installation has no small-groups.enabled row to switch on for the run');
}
$state['sgBefore'] = (string) $sg['settingValue'];
q("UPDATE `tblSettings` SET `settingValue` = '1' WHERE `settingKey` = 'small-groups.enabled' AND `siteID` IS NULL");

/** The form token on a page, or ''. */
$tokenOn = static function (string $html): string {
    if (preg_match('/name="csrf_token"\s+value="([0-9a-f]{64})"/', $html, $m) === 1) {
        return $m[1];
    }

    return preg_match('/<meta\s+name="csrf-token"\s+content="([0-9a-f]{64})"/i', $html, $m) === 1 ? $m[1] : '';
};
$page = $http('GET', '/login');
$in   = $http('POST', '/login', ['csrf_token' => $tokenOn($page['body']), 'username' => 'selftest-position-1030', 'password' => $password]);
if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
    refuse("signing the administrator in did not work (HTTP {$in['status']} {$in['location']})");
}

/**
 * The form on $url that holds a box named $latBox, as a browser would send it
 * untouched: inputs with their drawn values, the selected option of each
 * select (the first when none is), a textarea's text, a ticked box's value.
 * `$change` overrides fields (null removes one).
 *
 * @param array<string,string|null> $change
 * @return array{status: int, fields: array<string,string>, found: bool}
 */
$formAt = static function (string $url, string $latBox, array $change = []) use ($http): array {
    $page = $http('GET', $url);
    $dom  = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $page['body']);
    libxml_clear_errors();
    $xp    = new DOMXPath($dom);
    $forms = $xp->query('//form[.//input[@name="' . $latBox . '"]]');
    $fields = [];
    if ($forms !== false && $forms->length > 0) {
        foreach ($xp->query('.//*[self::input or self::select or self::textarea]', $forms->item(0)) as $el) {
            $name = $el->getAttribute('name');
            if ($name === '' || $el->hasAttribute('disabled') === true || $el->getAttribute('type') === 'file') {
                continue;
            }
            if ($el->nodeName === 'select') {
                $value = '';
                foreach ($el->getElementsByTagName('option') as $i => $opt) {
                    if ($i === 0 || $opt->hasAttribute('selected') === true) {
                        $value = $opt->getAttribute('value');
                    }
                }
                $fields[$name] = $value;
            } elseif ($el->nodeName === 'textarea') {
                $fields[$name] = $el->textContent;
            } elseif (in_array($el->getAttribute('type'), ['checkbox', 'radio'], true) === true) {
                if ($el->hasAttribute('checked') === true) {
                    $fields[$name] = $el->getAttribute('value') !== '' ? $el->getAttribute('value') : 'on';
                }
            } elseif ($el->getAttribute('type') !== 'submit' && $el->getAttribute('type') !== 'button') {
                $fields[$name] = $el->getAttribute('value');
            }
        }
    }
    foreach ($change as $k => $v) {
        if ($v === null) {
            unset($fields[$k]);
        } else {
            $fields[$k] = $v;
        }
    }

    return ['status' => $page['status'], 'fields' => $fields, 'found' => $forms !== false && $forms->length > 0];
};

/**
 * Post a form and return the words the person then READS: the page a redirect
 * lands on, or the page the post answered with (resources draws its own). The
 * HTML is decoded and its tags removed, so a sentence is matched as written.
 *
 * @param array<string,string> $fields
 * @return array{status: int, text: string}
 */
$postAndRead = static function (string $to, array $fields) use ($http): array {
    $answer = $http('POST', $to, $fields);
    $body   = $answer['body'];
    if (in_array($answer['status'], [301, 302, 303], true) === true && $answer['location'] !== '') {
        $path = (string) parse_url($answer['location'], PHP_URL_PATH);
        $qs   = (string) parse_url($answer['location'], PHP_URL_QUERY);
        $body = $http('GET', $path . ($qs !== '' ? '?' . $qs : ''))['body'];
    }
    $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return ['status' => $answer['status'], 'text' => (string) preg_replace('/\s+/', ' ', $text)];
};

/**
 * How many rows EVERY table in the database holds (#1030, round 2). A refusal
 * that says "Nothing was saved." must leave every one of them as it was: the
 * venue page used to create a new LANDLORD (tblAssetOrgs) above its refusals,
 * so a refused venue still wrote a row, and a check that counted only the
 * venue table could not see it. Asking every table, rather than a list of the
 * tables each page "can" write, is what makes the check reach a write nobody
 * thought of. It cannot see a row CHANGED in place; the edit sections compare
 * the row being edited for that.
 *
 * @return array<string,int>
 */
$tableCounts = static function () use ($db): array {
    $counts = [];
    $names  = $db->query(
        "SELECT `TABLE_NAME` AS `t` FROM `information_schema`.`TABLES` "
        . "WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_TYPE` = 'BASE TABLE' ORDER BY `TABLE_NAME`"
    )->fetch_all(MYSQLI_ASSOC);
    foreach ($names as $row) {
        // A table name read from the server, never from a request.
        $table          = (string) $row['t'];
        $counts[$table] = (int) $db->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetch_row()[0];
    }

    return $counts;
};
/** The tables whose row count differs between two snapshots, as "table a -> b". @return list<string> */
$countsMoved = static function (array $before, array $after): array {
    $moved = [];
    foreach ($after + $before as $table => $ignored) {
        if (($before[$table] ?? -1) !== ($after[$table] ?? -1)) {
            $moved[] = $table . ' ' . ($before[$table] ?? 'none') . ' -> ' . ($after[$table] ?? 'none');
        }
    }

    return $moved;
};

/** The refusal's words for a word in the latitude box, as the one judge builds them. */
$wordRefusal = (string) GeoLocation::positionRefusal('north', '-0.1278');
$says = static fn (array $read): bool => str_contains($read['text'], $wordRefusal) === true;

/**
 * One page, both posts. $name is the field holding the thing's name (so a
 * refused create can be counted), $count counts rows carrying the name, $pos
 * reads the stored position as "lat,lng" (or '' when there is none).
 *
 * Returns the bad post's verdict so the plants in section Z can ask it again.
 *
 * @param array<string,string|null> $extra
 */
$section = static function (
    string $code,
    string $title,
    string $formUrl,
    string $postTo,
    string $latBox,
    string $lngBox,
    array $extra,
    callable $state1,
    bool $isEdit
) use ($formAt, $postAndRead, $says, $wordRefusal, $tableCounts, $countsMoved): void {
    echo "\n{$code}. {$title}\n";
    $form = $formAt($formUrl, $latBox, $extra);
    check("{$code}0 premise: the page draws the form, with both position boxes ({$latBox}, {$lngBox})",
        $form['status'] === 200 && $form['found'] === true && array_key_exists($lngBox, $form['fields']) === true,
        'status ' . $form['status'] . ', fields: ' . implode(', ', array_keys($form['fields'])));
    $before = $state1();
    $counts = $tableCounts();
    $bad    = $postAndRead($postTo, array_merge($form['fields'], [$latBox => 'north', $lngBox => '-0.1278']));
    $moved  = $countsMoved($counts, $tableCounts());
    check("{$code}1 a WORD in the latitude box is REFUSED, and the page the person lands on says why: \"{$wordRefusal}\"",
        $says($bad), 'status ' . $bad['status'] . '; page text: ' . mb_substr($bad['text'], 0, 300));
    check("{$code}2 ...and nothing was saved" . ($isEdit === true ? ' — the stored position included, which the old code WIPED' : ''),
        $state1() === $before, 'before ' . json_encode($before) . ', after ' . json_encode($state1()));
    check("{$code}2b ...and no table in the whole database gained or lost a row",
        $moved === [], 'moved: ' . implode('; ', $moved));
};

// =============================================================================
// U. The judge itself
// =============================================================================
echo "\nU. The one judge, GeoLocation::typedPositionProblem()\n";
$cases = [
    ['', '', ''],
    ['   ', ' ', ''],
    [null, null, ''],
    ['51.5074', '-0.1278', ''],
    ['0', '0', ''],
    [' 4.0E-5 ', '-15', ''],
    ['north', '-0.1278', 'numbers'],
    ['51,5', '-0.1278', 'numbers'],
    [['1'], '0', 'numbers'],
    ['51.5074', '', 'one'],
    ['51.5074', '   ', 'one'],
    ['', 'west', 'one'],
    ['95', '0', 'range'],
    ['51.5074', '200', 'range'],
];
$want = ['' => '', 'numbers' => 'the latitude and longitude must be numbers', 'one' => 'only one of the two boxes was filled in', 'range' => 'the latitude must be from -90 to 90'];
$right = 0;
$wrongCases = [];
foreach ($cases as [$lat, $lng, $kind]) {
    $got = GeoLocation::typedPositionProblem($lat, $lng);
    $ok  = $kind === '' ? $got === '' : str_starts_with($got, $want[$kind]);
    $right += $ok === true ? 1 : 0;
    if ($ok === false) {
        $wrongCases[] = json_encode([$lat, $lng]) . ' gave "' . $got . '"';
    }
}
check('U1 all ' . count($cases) . ' pairs get the right answer: nothing to say for a usable pair or two empty boxes (spaces count as empty), and the one reason that fits otherwise',
    $right === count($cases), implode('; ', $wrongCases));
check('U2 positionRefusal() is null exactly when there is nothing to say, and otherwise ends "Nothing was saved."',
    GeoLocation::positionRefusal('', '') === null && GeoLocation::positionRefusal('51.5', '0.1') === null
    && str_ends_with((string) GeoLocation::positionRefusal('north', '0'), 'Nothing was saved.') === true);

// =============================================================================
// A–H. The eight pages
// =============================================================================
$admin = PR_ADMIN;

// ---- A. Asset locations -----------------------------------------------------
$locCount = static fn (string $name): int => (int) (one('SELECT COUNT(*) AS `n` FROM `tblAssetLocations` WHERE `locationName` = ?', 's', [$name])['n'] ?? -1);
$section('A', 'Asset locations: adding one', '/assets/locations', '/assets/locations', 'latitude', 'longitude',
    ['locationName' => PR_MARK . ' location'], static fn (): int => $locCount(PR_MARK . ' location'), false);
$form = $formAt('/assets/locations', 'latitude', ['locationName' => PR_MARK . ' location', 'latitude' => '51.5074', 'longitude' => '-0.1278']);
$postAndRead('/assets/locations', $form['fields']);
$loc = one('SELECT `locationID`, `latitude`, `longitude` FROM `tblAssetLocations` WHERE `locationName` = ?', 's', [PR_MARK . ' location']);
check('A3 control: the same form with a usable position IS saved, with that position',
    $loc !== null && (float) $loc['latitude'] === 51.5074 && (float) $loc['longitude'] === -0.1278, json_encode($loc));
$locId = (int) ($loc['locationID'] ?? 0);
$locRow = static fn (): ?array => one('SELECT `locationName`, `latitude`, `longitude` FROM `tblAssetLocations` WHERE `locationID` = ?', 'i', [$locId]);
$section('A', 'Asset locations: editing one that has a position', '/assets/locations?edit=' . $locId, '/assets/locations', 'latitude', 'longitude',
    ['locationName' => PR_MARK . ' location renamed'], $locRow, true);

// ---- B. Venues -------------------------------------------------------------
$venueCount = static fn (string $name): int => (int) (one('SELECT COUNT(*) AS `n` FROM `tblVenues` WHERE `venueName` = ?', 's', [$name])['n'] ?? -1);
$section('B', 'Venues: adding one', '/venues/manage', '/venues/venue-save', 'latitude', 'longitude',
    ['venueName' => PR_MARK . ' venue', 'countryCode' => 'GB'], static fn (): int => $venueCount(PR_MARK . ' venue'), false);
$form = $formAt('/venues/manage', 'latitude', ['venueName' => PR_MARK . ' venue', 'countryCode' => 'GB', 'latitude' => '51.5074', 'longitude' => '-0.1278']);
$postAndRead('/venues/venue-save', $form['fields']);
$venue = one('SELECT `venueID`, `latitude`, `longitude` FROM `tblVenues` WHERE `venueName` = ?', 's', [PR_MARK . ' venue']);
check('B3 control: the same form with a usable position IS saved, with that position',
    $venue !== null && (float) $venue['latitude'] === 51.5074 && (float) $venue['longitude'] === -0.1278, json_encode($venue));
$venueId = (int) ($venue['venueID'] ?? 0);
$venueRow = static fn (): ?array => one('SELECT `venueName`, `latitude`, `longitude` FROM `tblVenues` WHERE `venueID` = ?', 'i', [$venueId]);
$section('B', 'Venues: editing one that has a position', '/venues/manage?edit=' . $venueId, '/venues/venue-save', 'latitude', 'longitude',
    ['venueName' => PR_MARK . ' venue renamed'], $venueRow, true);

// ---- B4. Venues: a refused venue must not leave a NEW LANDLORD behind -------
// The form can create a landlord as well as a venue. That used to happen ABOVE
// every refusal, so each refused post added a landlord row (measured 0 -> 1).
// Every refusal the page makes is posted here with a landlord name filled in.
echo "\nB4. Venues: every refusal, with a NEW landlord's name filled in, writes nothing at all\n";
$landlordName = PR_MARK . ' landlord';
$orgCount = static fn (): int => (int) (one('SELECT COUNT(*) AS `n` FROM `tblAssetOrgs` WHERE `orgName` = ?', 's', [$landlordName])['n'] ?? -1);
/**
 * Post the venue form with a landlord name and $change; say whether $words are
 * on the page the person lands on, and which tables gained or lost a row.
 *
 * @param array<string,string> $change
 * @return array{said: bool, moved: list<string>, text: string}
 */
$landlordCase = static function (array $change, string $words) use ($formAt, $postAndRead, $tableCounts, $countsMoved, $landlordName): array {
    $form   = $formAt('/venues/manage', 'latitude', ['venueName' => PR_MARK . ' venue L', 'countryCode' => 'GB', 'landlord_orgName' => $landlordName]);
    $counts = $tableCounts();
    $read   = $postAndRead('/venues/venue-save', array_merge($form['fields'], $change));

    return ['said' => str_contains($read['text'], $words), 'moved' => $countsMoved($counts, $tableCounts()), 'text' => $read['text']];
};
$landlordCases = [
    'B4a' => ['a word in the latitude box', ['latitude' => 'north', 'longitude' => '-0.1278'], $wordRefusal],
    'B4b' => ['a what3words address that is not one', ['what3words' => 'not a three word address'], 'That does not look like a what3words address'],
    'B4c' => ['a country that is not a two-letter code', ['countryCode' => 'Ireland'], 'give its country as a two-letter code'],
    'B4d' => ['a time zone the portal does not know', ['timezone' => 'Mars/Olympus_Mons'], 'is not one this portal knows'],
    'B4e' => ['no venue name', ['venueName' => ''], 'a venue name is required'],
    'B4f' => ['an edit of a venue this organisation does not have', ['venueID' => '987654321'], 'That venue could not be found'],
];
foreach ($landlordCases as $label => [$what, $change, $words]) {
    $r = $landlordCase($change, $words);
    check("{$label} {$what}: refused with its own words, and NO table gained a row — the landlord included",
        $r['said'] === true && $r['moved'] === [] && $orgCount() === 0,
        'said: ' . var_export($r['said'], true) . '; moved: ' . implode('; ', $r['moved']) . '; page: ' . mb_substr($r['text'], 0, 200));
}
$form = $formAt('/venues/manage', 'latitude', ['venueName' => PR_MARK . ' venue L', 'countryCode' => 'GB', 'landlord_orgName' => $landlordName]);
$postAndRead('/venues/venue-save', $form['fields']);
$withLandlord = one('SELECT v.`venueID`, o.`orgName` FROM `tblVenues` v LEFT JOIN `tblAssetOrgs` o ON o.`orgID` = v.`landlordOrgID` WHERE v.`venueName` = ?', 's', [PR_MARK . ' venue L']);
check('B5 control: the same form with nothing wrong IS saved, the venue naming the new landlord, and exactly one landlord made',
    $withLandlord !== null && $withLandlord['orgName'] === $landlordName && $orgCount() === 1, json_encode($withLandlord) . ', landlords: ' . $orgCount());
q('DELETE FROM `tblVenueUsageTypes` WHERE `venueID` IN (SELECT `venueID` FROM `tblVenues` WHERE `venueName` = ?)', 's', [PR_MARK . ' venue L']);
q('DELETE FROM `tblVenues` WHERE `venueName` = ?', 's', [PR_MARK . ' venue L']);
q('DELETE FROM `tblAssetOrgs` WHERE `orgName` = ?', 's', [$landlordName]);

// ---- C. Resources (adding only: the page has no edit) ----------------------
$resCount = static fn (string $name): int => (int) (one('SELECT COUNT(*) AS `n` FROM `tblResource` WHERE `name` = ?', 's', [$name])['n'] ?? -1);
$section('C', 'Resources: adding one (the page draws its own answer)', '/resources/manage', '/resources/manage', 'latitude', 'longitude',
    ['name' => PR_MARK . ' resource'], static fn (): int => $resCount(PR_MARK . ' resource'), false);
$form = $formAt('/resources/manage', 'latitude', ['name' => PR_MARK . ' resource', 'latitude' => '51.5074', 'longitude' => '-0.1278']);
$postAndRead('/resources/manage', $form['fields']);
$res = one('SELECT `resourceID`, `latitude`, `longitude` FROM `tblResource` WHERE `name` = ?', 's', [PR_MARK . ' resource']);
check('C3 control: the same form with a usable position IS saved, with that position',
    $res !== null && (float) $res['latitude'] === 51.5074 && (float) $res['longitude'] === -0.1278, json_encode($res));

// ---- D. Events -------------------------------------------------------------
$eventFields = ['eventName' => PR_MARK . ' event', 'startDateTime' => '2031-06-01T10:00', 'endDateTime' => '2031-06-01T11:00', 'status' => 'published'];
$eventCount  = static fn (string $name): int => (int) (one('SELECT COUNT(*) AS `n` FROM `tblEvents` WHERE `eventName` = ?', 's', [$name])['n'] ?? -1);
$section('D', 'Events: adding one', '/calendar/manage', '/calendar/manage/save', 'locationGeoLat', 'locationGeoLng',
    $eventFields, static fn (): int => $eventCount(PR_MARK . ' event'), false);
$form = $formAt('/calendar/manage', 'locationGeoLat', $eventFields + ['locationGeoLat' => '51.5074', 'locationGeoLng' => '-0.1278']);
$postAndRead('/calendar/manage/save', $form['fields']);
$event = one('SELECT `eventID`, `locationGeoLat`, `locationGeoLng` FROM `tblEvents` WHERE `eventName` = ?', 's', [PR_MARK . ' event']);
check('D3 control: the same form with a usable position IS saved, with that position',
    $event !== null && (float) $event['locationGeoLat'] === 51.5074 && (float) $event['locationGeoLng'] === -0.1278, json_encode($event));
$eventId = (int) ($event['eventID'] ?? 0);
$eventRow = static fn (): ?array => one('SELECT `eventName`, `locationGeoLat`, `locationGeoLng` FROM `tblEvents` WHERE `eventID` = ?', 'i', [$eventId]);
$section('D', 'Events: editing one that has a position', '/calendar/manage?edit=' . $eventId, '/calendar/manage/save', 'locationGeoLat', 'locationGeoLng',
    ['eventName' => PR_MARK . ' event renamed'], $eventRow, true);

// ---- E. A date's own details (the per-occurrence overrides) ----------------
$ovCount = static fn (): int => (int) (one('SELECT COUNT(*) AS `n` FROM `tblEventOccurrenceOverrides` WHERE `eventID` = ?', 'i', [$eventId])['n'] ?? -1);
$ovUrl   = '/calendar/event/overrides?eventID=' . $eventId;
$section('E', 'A date\'s own details: adding one (this page printed no message at all before)', $ovUrl, '/calendar/event/overrides/save', 'overrideGeoLat', 'overrideGeoLng',
    ['occurrenceDate' => '2031-06-08', 'overrideName' => PR_MARK . ' date'], $ovCount, false);
$form = $formAt($ovUrl, 'overrideGeoLat', ['occurrenceDate' => '2031-06-08', 'overrideName' => PR_MARK . ' date', 'overrideGeoLat' => '51.5074', 'overrideGeoLng' => '-0.1278']);
$postAndRead('/calendar/event/overrides/save', $form['fields']);
$ov = one('SELECT `overrideID`, `overrideGeoLat`, `overrideGeoLng` FROM `tblEventOccurrenceOverrides` WHERE `eventID` = ?', 'i', [$eventId]);
check('E3 control: the same form with a usable position IS saved, with that position',
    $ov !== null && (float) $ov['overrideGeoLat'] === 51.5074 && (float) $ov['overrideGeoLng'] === -0.1278, json_encode($ov));
$ovRow = static fn (): ?array => one('SELECT `overrideName`, `overrideGeoLat`, `overrideGeoLng` FROM `tblEventOccurrenceOverrides` WHERE `eventID` = ?', 'i', [$eventId]);
$section('E', 'A date\'s own details: saving the same date again (which used to WIPE its position)', $ovUrl, '/calendar/event/overrides/save', 'overrideGeoLat', 'overrideGeoLng',
    ['occurrenceDate' => '2031-06-08', 'overrideName' => PR_MARK . ' date renamed'], $ovRow, true);

// ---- F. A member's own directory entry -------------------------------------
$form = $formAt('/directory/my-settings', 'latitude', ['displayBio' => PR_MARK . ' bio', 'latitude' => '51.5074', 'longitude' => '-0.1278']);
$postAndRead('/directory/save', $form['fields']);
$me = one('SELECT `displayBio`, `latitude`, `longitude` FROM `tblUsers` WHERE `userID` = ?', 'i', [$admin]);
echo "\nF. A member's directory entry\n";
check('F3 control (first here, so there is a position to keep): a usable position IS saved',
    $me !== null && (float) $me['latitude'] === 51.5074 && (float) $me['longitude'] === -0.1278 && $me['displayBio'] === PR_MARK . ' bio', json_encode($me));
$meRow = static fn (): ?array => one('SELECT `displayBio`, `latitude`, `longitude` FROM `tblUsers` WHERE `userID` = ?', 'i', [$admin]);
$section('F', 'A member\'s directory entry: editing it (this page printed no message at all before)', '/directory/my-settings', '/directory/save', 'latitude', 'longitude',
    ['displayBio' => PR_MARK . ' bio changed'], $meRow, true);

// ---- G. A small group's meeting place --------------------------------------
$sgCount = static fn (string $name): int => (int) (one('SELECT COUNT(*) AS `n` FROM `tblSmallGroups` WHERE `groupName` = ?', 's', [$name])['n'] ?? -1);
$section('G', 'Small groups: adding one', '/small-groups/manage', '/small-groups/save', 'latitude', 'longitude',
    ['groupName' => PR_MARK . ' group'], static fn (): int => $sgCount(PR_MARK . ' group'), false);
$form = $formAt('/small-groups/manage', 'latitude', ['groupName' => PR_MARK . ' group', 'latitude' => '51.5074', 'longitude' => '-0.1278']);
$postAndRead('/small-groups/save', $form['fields']);
$group = one('SELECT `groupID`, `latitude`, `longitude` FROM `tblSmallGroups` WHERE `groupName` = ?', 's', [PR_MARK . ' group']);
check('G3 control: the same form with a usable position IS saved, with that position',
    $group !== null && (float) $group['latitude'] === 51.5074 && (float) $group['longitude'] === -0.1278, json_encode($group));
$groupId = (int) ($group['groupID'] ?? 0);
$groupRow = static fn (): ?array => one('SELECT `groupName`, `latitude`, `longitude` FROM `tblSmallGroups` WHERE `groupID` = ?', 'i', [$groupId]);
$section('G', 'Small groups: editing one that has a position', '/small-groups/manage?id=' . $groupId, '/small-groups/save', 'latitude', 'longitude',
    ['groupName' => PR_MARK . ' group renamed'], $groupRow, true);

// ---- H. A host's home, for a small group -----------------------------------
$homeCount = static fn (): int => (int) (one('SELECT COUNT(*) AS `n` FROM `tblSmallGroupPlaces` WHERE `groupID` = ?', 'i', [$groupId])['n'] ?? -1);
$homeUrl   = '/small-groups/place?id=' . $groupId;
// Only a position: so the old code, having dropped it, refused the home as
// having "no address" -- a refusal, but an untrue one.
$section('H', 'Small groups: adding a home with only a map position', $homeUrl, '/small-groups/place-save', 'latitude', 'longitude',
    ['placeLabel' => PR_MARK . ' home'], $homeCount, false);
$form = $formAt($homeUrl, 'latitude', ['placeLabel' => PR_MARK . ' home', 'latitude' => '51.5074', 'longitude' => '-0.1278']);
$postAndRead('/small-groups/place-save', $form['fields']);
$home = one('SELECT `placeID`, `latitude`, `longitude` FROM `tblSmallGroupPlaces` WHERE `groupID` = ?', 'i', [$groupId]);
check('H3 control: the same form with a usable position IS saved, with that position',
    $home !== null && (float) $home['latitude'] === 51.5074 && (float) $home['longitude'] === -0.1278, json_encode($home));
$homeId  = (int) ($home['placeID'] ?? 0);
$homeRow = static fn (): ?array => one('SELECT `placeLabel`, `latitude`, `longitude` FROM `tblSmallGroupPlaces` WHERE `placeID` = ?', 'i', [$homeId]);
$section('H', 'Small groups: editing a home that has a position', $homeUrl . '&placeID=' . $homeId, '/small-groups/place-save', 'latitude', 'longitude',
    ['placeLabel' => PR_MARK . ' home renamed'], $homeRow, true);

// =============================================================================
// M. The model layer's own second refusal, asked directly
// =============================================================================
// Venues::saveVenue() and AssetRegister::saveLocation() ask the judge AGAIN, so
// no other caller can drop a typed position or wipe a stored one. The pages
// above never reach that second ask (each refuses first), so it is called here
// directly -- in a separate PHP process that loads the COPY's classes, so a
// plant in section Z changes what runs.
echo "\nM. Venues::saveVenue() and AssetRegister::saveLocation() refuse a typed position they cannot use\n";
file_put_contents($child . $sep . 'model-call.php', <<<'PROBE'
<?php
// Written by tools/position-refusal-selftest.php for ONE run. Test only.
declare(strict_types=1);
$web = __DIR__ . DIRECTORY_SEPARATOR . 'web';
define('PORTAL_ROOT', $web);
define('PORTAL_CORE', $web . DIRECTORY_SEPARATOR . '_core');
define('PORTAL_APPS', $web . DIRECTORY_SEPARATOR . '_apps');
define('PORTAL_WEBROOT', $web . DIRECTORY_SEPARATOR . 'public_html');
spl_autoload_register(static function (string $class) use ($web): void {
    if (str_starts_with($class, 'Portal\\Core\\') === true) {
        $file = $web . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . substr($class, 12) . '.php';
        if (is_file($file) === true) {
            require $file;
        }
    }
});
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli((string) getenv('PR_DB_HOST'), (string) getenv('PR_DB_USER'), (string) getenv('PR_DB_PASS'), (string) getenv('PR_DB_NAME'), (int) getenv('PR_DB_PORT'));
$mysqli->set_charset('utf8mb4');
$GLOBALS['mysqli'] = $mysqli;
$a = json_decode((string) ($argv[1] ?? ''), true);
$r = $a['what'] === 'venue'
    ? \Portal\Core\Venues::saveVenue((int) $a['site'], (int) $a['id'], $a['data'], (int) $a['user'])
    : \Portal\Core\AssetRegister::saveLocation((int) $a['site'], (int) $a['id'], $a['data'], (int) $a['user']);
echo json_encode(['returned' => $r]);
PROBE);
/**
 * Call one of the two methods in its own process; answer what it returned (or
 * null and the output, when it did not run) and which tables moved.
 *
 * @param array<string,string> $data
 * @return array{returned: ?int, moved: list<string>, out: string}
 */
$modelCall = static function (string $what, int $id, array $data) use ($child, $sep, $siteId, $tableCounts, $countsMoved, $dbHost, $dbUser, $dbPass, $dbName, $dbPort): array {
    $env = getenv();
    $env['PR_DB_HOST'] = $dbHost;
    $env['PR_DB_USER'] = $dbUser;
    $env['PR_DB_PASS'] = $dbPass;
    $env['PR_DB_NAME'] = $dbName;
    $env['PR_DB_PORT'] = (string) $dbPort;
    $counts = $tableCounts();
    $proc   = proc_open(
        [PHP_BINARY, '-d', 'display_errors=stderr', $child . $sep . 'model-call.php',
            json_encode(['what' => $what, 'site' => $siteId, 'id' => $id, 'data' => $data, 'user' => PR_ADMIN])],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $child,
        $env
    );
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $answer = json_decode(trim((string) preg_replace('/^.*?(\{"returned".*\})\s*$/s', '$1', $out)), true);

    return ['returned' => is_array($answer) === true ? (int) $answer['returned'] : null, 'moved' => $countsMoved($counts, $tableCounts()), 'out' => $out];
};
$venueBad = ['venueName' => PR_MARK . ' model venue', 'countryCode' => 'GB', 'latitude' => 'north', 'longitude' => '-0.1278', 'what3words' => ''];
$locBad   = ['locationName' => PR_MARK . ' model location', 'latitude' => 'north', 'longitude' => '-0.1278'];
$modelCases = [
    'M1' => ['saveVenue(), adding a venue', 'venue', 0, $venueBad, null],
    'M2' => ['saveVenue(), editing the venue that has a position', 'venue', $venueId, ['venueName' => PR_MARK . ' venue renamed'] + $venueBad, $venueRow],
    'M3' => ['saveLocation(), adding a location', 'location', 0, $locBad, null],
    'M4' => ['saveLocation(), editing the location that has a position', 'location', $locId, ['locationName' => PR_MARK . ' location renamed'] + $locBad, $locRow],
];
foreach ($modelCases as $label => [$what, $kind, $id, $data, $rowOf]) {
    $rowBefore = $rowOf !== null ? $rowOf() : null;
    $r = $modelCall($kind, $id, $data);
    check("{$label} {$what} with a WORD in the latitude: answers 0, and nothing was written"
        . ($rowOf !== null ? ' — the stored position kept' : ''),
        $r['returned'] === 0 && $r['moved'] === [] && ($rowOf === null || $rowOf() === $rowBefore),
        'returned ' . var_export($r['returned'], true) . '; moved: ' . implode('; ', $r['moved']) . '; output: ' . mb_substr($r['out'], 0, 300));
}
$control = $modelCall('location', 0, ['latitude' => '51.5074', 'longitude' => '-0.1278'] + $locBad);
check('M5 control: the same saveLocation() call with a usable position DOES save (so a 0 above is a refusal, and the call really ran)',
    ($control['returned'] ?? 0) > 0 && one('SELECT `locationID` FROM `tblAssetLocations` WHERE `locationName` = ?', 's', [PR_MARK . ' model location']) !== null,
    'returned ' . var_export($control['returned'], true) . '; output: ' . mb_substr($control['out'], 0, 300));
q('DELETE FROM `tblAssetLocations` WHERE `locationName` = ?', 's', [PR_MARK . ' model location']);
$controlV = $modelCall('venue', 0, ['latitude' => '51.5074', 'longitude' => '-0.1278'] + $venueBad);
check('M6 control: the same saveVenue() call with a usable position DOES save',
    ($controlV['returned'] ?? 0) > 0, 'returned ' . var_export($controlV['returned'], true) . '; output: ' . mb_substr($controlV['out'], 0, 300));
q('DELETE FROM `tblVenueUsageTypes` WHERE `venueID` IN (SELECT `venueID` FROM `tblVenues` WHERE `venueName` = ?)', 's', [PR_MARK . ' model venue']);
q('DELETE FROM `tblVenues` WHERE `venueName` = ?', 's', [PR_MARK . ' model venue']);

// =============================================================================
// Z. Plants, in the copy
// =============================================================================
echo "\nZ. Plants: each refusal switched off in the COPY, and the check must see it\n";
/**
 * Switch one refusal off by changing its condition, post a word again, and say
 * whether the refusal's words were still on the page. Then put the file back
 * and confirm it is byte for byte what it was.
 *
 * @param array<string,string|null> $change
 * @return array{applied: bool, said: bool, restored: bool}
 */
$plantOne = static function (string $file, string $from, string $to, string $formUrl, string $postTo, string $latBox, string $lngBox, array $change)
    use ($child, $sep, $formAt, $postAndRead, $says): array {
    $path   = $child . $sep . 'web' . $sep . str_replace('/', $sep, $file);
    $before = (string) file_get_contents($path);
    if (substr_count($before, $from) !== 1) {
        return ['applied' => false, 'said' => true, 'restored' => true];
    }
    file_put_contents($path, str_replace($from, $to, $before));
    clearstatcache(true, $path);
    $form = $formAt($formUrl, $latBox, $change);
    $read = $postAndRead($postTo, array_merge($form['fields'], [$latBox => 'north', $lngBox => '-0.1278']));
    file_put_contents($path, $before);
    clearstatcache(true, $path);

    return ['applied' => true, 'said' => $says($read), 'restored' => (string) file_get_contents($path) === $before];
};
$off = ['if ($positionRefusal !== null) {', 'if (false && $positionRefusal !== null) {'];
$plants = [
    ['Z1', 'asset locations', '_apps/assets/locations.php', $off, '/assets/locations', '/assets/locations', 'latitude', 'longitude', ['locationName' => PR_MARK . ' plant']],
    ['Z2', 'venues', '_apps/venues/venue-save.php', $off, '/venues/manage', '/venues/venue-save', 'latitude', 'longitude', ['venueName' => PR_MARK . ' plant', 'countryCode' => 'GB']],
    ['Z3', 'resources', '_apps/resources/manage.php', $off, '/resources/manage', '/resources/manage', 'latitude', 'longitude', ['name' => PR_MARK . ' plant']],
    ['Z4', 'events', '_apps/calendar/manage/save.php', $off, '/calendar/manage', '/calendar/manage/save', 'locationGeoLat', 'locationGeoLng', ['eventName' => PR_MARK . ' plant'] + $eventFields],
    ['Z5', 'a date\'s own details', '_apps/calendar/event-overrides-save.php', $off, $ovUrl, '/calendar/event/overrides/save', 'overrideGeoLat', 'overrideGeoLng', ['occurrenceDate' => '2031-06-15']],
    ['Z6', 'the directory entry', '_apps/directory/save.php', $off, '/directory/my-settings', '/directory/save', 'latitude', 'longitude', []],
    ['Z7', 'small groups', '_apps/small-groups/save.php', $off, '/small-groups/manage', '/small-groups/save', 'latitude', 'longitude', ['groupName' => PR_MARK . ' plant']],
    ['Z8', 'a host\'s home', '_apps/small-groups/place-save.php', $off, $homeUrl, '/small-groups/place-save', 'latitude', 'longitude', ['placeLabel' => PR_MARK . ' plant']],
    // Z9: the judge itself made to find nothing. Every page must then lose its refusal.
    ['Z9', 'the one judge (asked through the venue page)', '_core/GeoLocation.php',
        ['        $latTyped = $typed($lat);', '        return \'\'; $latTyped = $typed($lat);'],
        '/venues/manage', '/venues/venue-save', 'latitude', 'longitude', ['venueName' => PR_MARK . ' plant', 'countryCode' => 'GB']],
    // Z10: the directory page's new message block taken away. The handler still
    // refuses, but the person is told nothing -- which is how it was before.
    ['Z10', 'the directory page\'s message', '_apps/directory/me.php',
        ['<?php if ($flashMsg !== \'\'): ?>', '<?php if (false): ?>'],
        '/directory/my-settings', '/directory/save', 'latitude', 'longitude', []],
    ['Z11', 'the overrides page\'s message', '_apps/calendar/event-overrides.php',
        ['<?php if ($flashMsg !== \'\'): ?>', '<?php if (false): ?>'],
        $ovUrl, '/calendar/event/overrides/save', 'overrideGeoLat', 'overrideGeoLng', ['occurrenceDate' => '2031-06-22']],
];
foreach ($plants as [$code, $what, $file, [$from, $to], $formUrl, $postTo, $latBox, $lngBox, $change]) {
    $r = $plantOne($file, $from, $to, $formUrl, $postTo, $latBox, $lngBox, $change);
    check("{$code} plant, {$what}: switched off, the refusal check goes RED, and the file is put back byte for byte",
        $r['applied'] === true && $r['said'] === false && $r['restored'] === true,
        $r['applied'] === false ? 'NOT APPLIED: the text to change was not found exactly once in ' . $file
            : ($r['said'] === true ? 'the refusal was still on the page, so this plant changed nothing the check can see' : 'the file was not put back'));
}

/**
 * A plant of any shape: $transform turns the file's text into the planted text
 * (null = it could not be applied); $scenario runs with it in place; the file
 * is then put back and confirmed byte for byte.
 *
 * @return array{applied: bool, result: mixed, restored: bool}
 */
$plantRun = static function (string $file, callable $transform, callable $scenario) use ($child, $sep): array {
    $path    = $child . $sep . 'web' . $sep . str_replace('/', $sep, $file);
    $before  = (string) file_get_contents($path);
    $planted = $transform($before);
    if (is_string($planted) === false || $planted === $before) {
        return ['applied' => false, 'result' => null, 'restored' => true];
    }
    file_put_contents($path, $planted);
    clearstatcache(true, $path);
    try {
        $result = $scenario();
    } finally {
        file_put_contents($path, $before);
        clearstatcache(true, $path);
    }

    return ['applied' => true, 'result' => $result, 'restored' => (string) file_get_contents($path) === $before];
};
$swapOnce = static fn (string $from, string $to): callable => static fn (string $text): ?string
    => substr_count($text, $from) === 1 ? str_replace($from, $to, $text) : null;
$landlordRed = static function (array $r) use ($orgCount): bool {
    // Red means: the landlord check of B4 would fail -- a row moved.
    $red = ($r['moved'] ?? []) !== [] || $orgCount() !== 0;
    q('DELETE FROM `tblAssetOrgs` WHERE `orgName` = ?', 's', [PR_MARK . ' landlord']);
    q('DELETE FROM `tblVenueUsageTypes` WHERE `venueID` IN (SELECT `venueID` FROM `tblVenues` WHERE `venueName` = ?)', 's', [PR_MARK . ' venue L']);
    q('DELETE FROM `tblVenues` WHERE `venueName` = ?', 's', [PR_MARK . ' venue L']);

    return $red;
};
// Z12: the landlord's creation moved back UP, above every refusal, where it was.
$moveUp = static function (string $text): ?string {
    $start = strpos($text, '    // ✍️ THE FIRST WRITE.');
    $end   = strpos($text, "    \$savedId = Venues::saveVenue(");
    $to    = strpos($text, '    // 📍 #456 Chunk A — pre-validate');
    if ($start === false || $end === false || $to === false || $to > $start) {
        return null;
    }
    $block = substr($text, $start, $end - $start);
    $text  = substr($text, 0, $start) . substr($text, $end);

    return substr($text, 0, $to) . $block . substr($text, $to);
};
$plants2 = [
    ['Z12', 'the landlord created ABOVE the refusals again (B4a must see a landlord row)', '_apps/venues/venue-save.php', $moveUp,
        static fn (): array => $landlordCase(['latitude' => 'north', 'longitude' => '-0.1278'], $wordRefusal)],
    ['Z13', 'the new "no venue name" refusal switched off (B4e must see a landlord row)', '_apps/venues/venue-save.php',
        $swapOnce("    if (trim(\$data['venueName']) === '') {", "    if (false && trim(\$data['venueName']) === '') {"),
        static fn (): array => $landlordCase(['venueName' => ''], 'a venue name is required')],
];
foreach ($plants2 as [$code, $what, $file, $transform, $scenario]) {
    $r = $plantRun($file, $transform, $scenario);
    check("{$code} plant, {$what}: the check goes RED, and the file is put back byte for byte",
        $r['applied'] === true && $landlordRed($r['result'] ?? []) === true && $r['restored'] === true,
        $r['applied'] === false ? 'NOT APPLIED in ' . $file : ($r['restored'] === false ? 'the file was not put back' : 'nothing moved, so this plant changed nothing the check can see'));
}
// Z14, Z15: the model layer's second ask switched off; M1 and M3 must go red.
$plants3 = [
    ['Z14', 'Venues::saveVenue()\'s own refusal', '_core/Venues.php', 'venue', $venueBad, PR_MARK . ' model venue'],
    ['Z15', 'AssetRegister::saveLocation()\'s own refusal', '_core/AssetRegister.php', 'location', $locBad, PR_MARK . ' model location'],
];
$guard = "        if (GeoLocation::typedPositionProblem(\$data['latitude'] ?? null, \$data['longitude'] ?? null) !== '') {";
foreach ($plants3 as [$code, $what, $file, $kind, $data, $name]) {
    $r = $plantRun($file, $swapOnce($guard, str_replace('if (', 'if (false && ', $guard)), static fn (): array => $modelCall($kind, 0, $data));
    $red = $r['applied'] === true && (($r['result']['returned'] ?? 0) !== 0 || ($r['result']['moved'] ?? []) !== []);
    q('DELETE FROM `tblVenueUsageTypes` WHERE `venueID` IN (SELECT `venueID` FROM `tblVenues` WHERE `venueName` = ?)', 's', [$name]);
    q('DELETE FROM `tblVenues` WHERE `venueName` = ?', 's', [$name]);
    q('DELETE FROM `tblAssetLocations` WHERE `locationName` = ?', 's', [$name]);
    check("{$code} plant, {$what} switched off: the direct call's check goes RED (it saved), and the file is put back byte for byte",
        $red === true && $r['restored'] === true,
        $r['applied'] === false ? 'NOT APPLIED in ' . $file : 'returned ' . var_export($r['result']['returned'] ?? null, true));
}

// ── Summary ─────────────────────────────────────────────────────────────────
$expected = 2 + 14 * 4 + 8 + count($landlordCases) + 1 + count($modelCases) + 2 + count($plants) + count($plants2) + count($plants3);
printf("\n%d passed, %d failed, %d ran of %d expected\n", $passed, $failed, $passed + $failed, $expected);
$log = (string) @file_get_contents($child . $sep . 'php-errors.log');
if (trim($log) !== '') {
    echo "PHP wrote to its error log during the run (first lines):\n" . implode("\n", array_slice(explode("\n", $log), 0, 8)) . "\n";
}
if ($passed + $failed !== $expected) {
    echo "The number of checks is not the number expected, so a block was skipped or ran twice.\n";
    exit(1);
}
echo "It drives the real pages over HTTP with one worker, no what3words key and no\n";
echo "address look-up; a browser would stop most of these values before sending them.\n";
exit($failed === 0 ? 0 : 1);
