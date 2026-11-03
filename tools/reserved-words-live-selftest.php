<?php
// Path: tools/reserved-words-live-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The hard-coded reserved-word list is checked against a REAL server 🗝️ (#719)
 * -----------------------------------------------------------------------------
 * `tools/audit-checks/check_reserved_words_quoted.py` carries a hard-coded set of
 * reserved words — the UNION of what MySQL 8.0, MySQL 8.4 and MariaDB 11.4
 * reserve, because the check runs with no database and cannot know which engine
 * a customer has. It has to run with no database — that is what lets the pre-push hook run every one of them in about nine
 * seconds. The cost is that **a hard-coded list goes stale silently**, and the
 * reserved set is not static: MySQL 8.4 added `QUALIFY` and `TABLESAMPLE` to
 * 8.0's. The next version will add more and nobody will remember to edit a
 * Python constant.
 *
 * So the list is verified where a database already exists. A test that fails the
 * day the list falls behind is the difference between a guard and a
 * guard-shaped object.
 *
 * -----------------------------------------------------------------------------
 * THE TWO DIRECTIONS ARE NOT THE SAME, AND ONLY ONE OF THEM FAILS
 * -----------------------------------------------------------------------------
 * A word THE SERVER RESERVES AND OUR LIST DOES NOT is a word the check cannot
 * catch — a bare reference to it would pass review and then fail to parse on a
 * customer's database. That FAILS.
 *
 * A word OUR LIST CARRIES AND THE SERVER DOES NOT reserve is the other
 * direction: our list is then conservative, which is safe, and it is EXPECTED
 * whenever this runs against a server older than the list describes. Running on
 * MySQL 8.0 must not fail because the list knows about 8.4's additions. That is
 * REPORTED and counted, never failed.
 *
 * -----------------------------------------------------------------------------
 * WHY THIS IS NOT DONE AT RUN TIME (#719's other half, answered no)
 * -----------------------------------------------------------------------------
 * Reading the reserved set at run time and quoting identifiers dynamically means
 * parsing SQL in PHP, where a parser bug corrupts queries silently — worse than
 * the fault being fixed. The standing rule needs no lookup at all: backtick
 * every identifier in SQL you write or change. It works on every version and
 * both engines, with no query and no cache.
 *
 * -----------------------------------------------------------------------------
 * MARIADB CANNOT BE ASKED, SO IT IS MEASURED INSTEAD
 * -----------------------------------------------------------------------------
 * MariaDB 11.4.13 HAS `INFORMATION_SCHEMA.KEYWORDS` — 699 rows — but with a
 * `WORD` column ONLY and no `RESERVED` flag, and MEMBERSHIP DOES NOT MEAN
 * RESERVED: `FULLTEXT`, `STATUS` and `WINDOW` are all listed, yet a bare
 * `FULLTEXT` fails while `STATUS` and `WINDOW` work. Its only other candidate
 * table is `SQL_FUNCTIONS`, which is functions.
 *
 * So on MariaDB this asks the only thing that answers the question: it TRIES the
 * word as an identifier. Every keyword the server lists that our set does not
 * carry is used as a column name in a temporary table; one that will not parse
 * is a word MariaDB reserves and we did not know about, and that fails.
 *
 * WHAT IT NEEDS: php and a throwaway database whose name starts with
 * `selftest_`. No schema, no web server, no network. It creates and drops its own
 * temporary tables and writes nothing else.
 *
 * WHAT IT CANNOT PROVE:
 *   - Anything about a version it is not pointed at. It is the WIRING that gives
 *     this value: `portal-selftests.yml` runs the set on MySQL 8.0.36 AND
 *     8.4.11, and `calendar-selftests.yml` runs it on those two AND on
 *     MariaDB 11.4, which is the only matrix in this repository that does — so
 *     the parse-test half really runs. (An earlier version of this line said
 *     "nothing in CI points it at MariaDB today", written before the wiring it
 *     describes landed in the same change.)
 *   - A MariaDB reserved word whose name contains a DIGIT. The parse test tries
 *     only candidates matching `^[a-z_]+$`, so seven words are never probed
 *     there: `float4`, `float8`, `int1`, `int2`, `int3`, `int4`, `int8`.
 *     Measured on MySQL 8.0.36 —
 *       SELECT WORD FROM INFORMATION_SCHEMA.KEYWORDS
 *        WHERE RESERVED = 1 AND WORD REGEXP '[0-9]'
 *     — and all seven are ALREADY in the hard-coded list, with no column in
 *     this schema named any of them, so the skip costs nothing today. It would
 *     cost something the day MariaDB reserved a NEW digit-bearing word that the
 *     list lacked: the only direction this test fails on is "the server
 *     reserves a word we do not carry", and that one would slip through.
 *     (A first version of this paragraph said no reserved word anywhere
 *     contains a digit. That was written without measuring and is false.)
 *   - That every identifier in the schema is quoted. That is the audit script's
 *     job, and this verifies the LIST the audit script compares against.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_rw SELFTEST_DB_PORT=… php tools/reserved-words-live-selftest.php
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/719
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    echo "REFUSED — set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}').\n";
    exit(1);
}

$mysqli = new mysqli(
    getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    $dbName,
    getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
);

$passes   = 0;
$failures = 0;

function rw_check(string $what, bool $isOk, string $detail = ''): void
{
    global $passes, $failures;
    if ($isOk === true) {
        $passes++;
        echo '  ✓ ' . $what . "\n";

        return;
    }
    $failures++;
    echo '  ✗ ' . $what . ($detail === '' ? '' : "\n        " . $detail) . "\n";
}

// 🗝️ OUR LIST, READ OUT OF THE AUDIT SCRIPT ITSELF. One place owns it, so this
//    test cannot drift from the thing it is verifying — which is the whole
//    point: a second copy here would make the two agree with each other and
//    with nothing else.
$checkPath = __DIR__ . DIRECTORY_SEPARATOR . 'audit-checks' . DIRECTORY_SEPARATOR
           . 'check_reserved_words_quoted.py';
$checkText = (string) file_get_contents($checkPath);
if (preg_match('/^RESERVED = set\("""(.*?)"""/ms', $checkText, $found) !== 1) {
    echo "REFUSED — could not read the RESERVED set out of " . basename($checkPath) . ".\n";
    echo "That block is the one place the list lives. If its shape has changed, change this test\n";
    echo "with it rather than keeping a second copy of the words here.\n";
    exit(1);
}
$ours = [];
foreach (preg_split('/\s+/', trim($found[1])) as $word) {
    if ($word !== '') {
        $ours[strtolower($word)] = true;
    }
}

$version = (string) ($mysqli->query('SELECT VERSION() AS v')->fetch_assoc()['v'] ?? '');
$isMaria = stripos($version, 'mariadb') !== false;

echo PHP_EOL . 'Server: ' . $version . ' — ' . ($isMaria === true ? 'MariaDB' : 'MySQL') . PHP_EOL;
echo 'Our list carries ' . count($ours) . ' reserved words.' . PHP_EOL;

rw_check('our list was read out of the audit script and is not empty', count($ours) > 100,
    'read ' . count($ours) . ' words');

// -----------------------------------------------------------------------------
echo PHP_EOL . 'A. Does the server reserve anything our list does not know about?' . PHP_EOL;
// -----------------------------------------------------------------------------
$missing = [];
$extra   = [];

if ($isMaria === false) {
    // 💡 MySQL answers directly. This is the query #718 recorded as the one to
    //    trust when a hosting company's documentation disagrees with the
    //    database — DreamHost's notice named four new reserved words in 8.4 and
    //    only two of them are.
    $rows = $mysqli->query('SELECT WORD FROM INFORMATION_SCHEMA.KEYWORDS WHERE RESERVED = 1');
    if ($rows === false) {
        rw_check('INFORMATION_SCHEMA.KEYWORDS could be read', false, (string) $mysqli->error);
    } else {
        $serverSays = [];
        while ($row = $rows->fetch_assoc()) {
            $serverSays[strtolower((string) $row['WORD'])] = true;
        }
        rw_check('the server named its reserved words', count($serverSays) > 100,
            'got ' . count($serverSays));
        $missing = array_keys(array_diff_key($serverSays, $ours));
        $extra   = array_keys(array_diff_key($ours, $serverSays));
        sort($missing);
        sort($extra);
    }
} else {
    // 💡 MariaDB cannot be asked, so each candidate is TRIED. Only the words it
    //    lists that we do not carry are worth trying: a word we already carry
    //    is already caught by the audit script, whether or not this server
    //    reserves it.
    $rows = $mysqli->query('SELECT WORD FROM INFORMATION_SCHEMA.KEYWORDS');
    if ($rows === false) {
        rw_check('INFORMATION_SCHEMA.KEYWORDS could be read', false, (string) $mysqli->error);
    } else {
        $candidates = [];
        while ($row = $rows->fetch_assoc()) {
            $word = strtolower((string) $row['WORD']);
            if (isset($ours[$word]) === false && preg_match('/^[a-z_]+$/', $word) === 1) {
                $candidates[] = $word;
            }
        }
        rw_check('the server listed keywords to try', $candidates !== [],
            'got ' . count($candidates) . ' candidates');
        echo '   Trying ' . count($candidates) . ' keyword(s) as a column name…' . PHP_EOL;
        foreach ($candidates as $word) {
            $mysqli->query('DROP TEMPORARY TABLE IF EXISTS `_rw_probe`');
            // A bare identifier. If the word is reserved this does not parse.
            $ok = false;
            try {
                $ok = $mysqli->query('CREATE TEMPORARY TABLE _rw_probe (' . $word . ' INT)') !== false;
            } catch (\Throwable $refused) {
                $ok = false;
            }
            if ($ok === false) {
                $missing[] = $word;
            }
            $mysqli->query('DROP TEMPORARY TABLE IF EXISTS `_rw_probe`');
        }
        sort($missing);
    }
}

// ⛔ THE DIRECTION THAT FAILS.
rw_check('the server reserves NOTHING our list is missing', $missing === [],
    'THE LIST IS STALE. These are reserved here and not in '
    . 'tools/audit-checks/check_reserved_words_quoted.py, so a bare reference to one would pass '
    . 'review and then fail to parse: ' . implode(', ', array_slice($missing, 0, 40))
    . (count($missing) > 40 ? ' … and ' . (count($missing) - 40) . ' more' : '')
    . '. Add them to that script\'s RESERVED set.');

// -----------------------------------------------------------------------------
echo PHP_EOL . 'B. Words our list carries that this server does not reserve' . PHP_EOL;
// -----------------------------------------------------------------------------
// 🚫 NOT A FAILURE, ON PURPOSE. Our list is the UNION of what MySQL 8.0,
//    MySQL 8.4 and MariaDB 11.4 reserve, which is not the same as any one of
//    their lists, so
//    there are words to report in BOTH directions: `qualify` and `tablesample`
//    are reserved only from 8.4, and `master_bind` and
//    `master_ssl_verify_server_cert` only up to 8.0. Carrying all four is the
//    safe direction — the audit script then asks for a backtick on a word that
//    may be legal on the server in front of it, and a backtick is never wrong.
//    Failing would mean this test could not run on both the versions this
//    portal supports, which is exactly the coverage that gives it value.
if ($isMaria === false) {
    echo '   ' . count($extra) . ' word(s): ' . ($extra === []
        ? 'none — the list matches this server exactly.'
        : implode(', ', array_slice($extra, 0, 25))
            . (count($extra) > 25 ? ' … and ' . (count($extra) - 25) . ' more' : '')) . PHP_EOL;
    echo '   Reported, not failed: carrying a word this server does not reserve is the SAFE' . PHP_EOL;
    echo '   direction, and happens in both directions as versions move — `qualify` and' . PHP_EOL;
    echo '   `tablesample` are 8.4 and later, `master_bind` and' . PHP_EOL;
    echo '   `master_ssl_verify_server_cert` are 8.0 and earlier.' . PHP_EOL;
} else {
    echo '   Not asked on MariaDB: its KEYWORDS table has no RESERVED flag, so' . PHP_EOL;
    echo '   "this server does not reserve it" is not a question it can answer.' . PHP_EOL;
}
rw_check('being ahead of the server is reported and never failed', true);

// -----------------------------------------------------------------------------
echo PHP_EOL . 'C. The one identifier this schema needs quoted really is reserved here' . PHP_EOL;
// -----------------------------------------------------------------------------
// 🧷 `tblTranscript.fullText` is the single exemption in the audit script, and
//    the fault that shipped (#276) was a bare reference to it. If a server ever
//    stopped reserving FULLTEXT the exemption would be pointless — and if this
//    probe stopped working, the whole of section A would be measuring nothing.
//    So it is checked the hard way, by trying it.
$mysqli->query('DROP TEMPORARY TABLE IF EXISTS `_rw_probe`');
$bareWorks = false;
try {
    $bareWorks = $mysqli->query('CREATE TEMPORARY TABLE _rw_probe (fullText INT)') !== false;
} catch (\Throwable $refused) {
    $bareWorks = false;
}
$mysqli->query('DROP TEMPORARY TABLE IF EXISTS `_rw_probe`');
rw_check('a bare `fullText` really is refused by this server, so the exemption earns its place',
    $bareWorks === false,
    'this server accepted `fullText` as a bare identifier, which means either the probe is broken '
    . '(and section A proves nothing) or this server does not reserve FULLTEXT');

$mysqli->query('DROP TEMPORARY TABLE IF EXISTS `_rw_probe`');
try {
    $quotedWorks = $mysqli->query('CREATE TEMPORARY TABLE _rw_probe (`fullText` INT)') !== false;
} catch (\Throwable $refused) {
    $quotedWorks = false;
}
$mysqli->query('DROP TEMPORARY TABLE IF EXISTS `_rw_probe`');
rw_check('and backticked it is accepted, which is what the standing rule relies on',
    $quotedWorks === true);

echo PHP_EOL . str_repeat('=', 78) . PHP_EOL;
echo $passes . ' passed, ' . $failures . " failed.\n";
if ($failures === 0) {
    echo "PASS — the hard-coded reserved-word list is not behind this server. REACHED THE END.\n";
    exit(0);
}
echo "FAIL — read the lines above: a stale reserved-word list means the audit script cannot\n";
echo "catch a bare reference that will not parse on a customer's database.\n";
exit(1);
