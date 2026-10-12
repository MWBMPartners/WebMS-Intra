<?php
// Path: tools/channel-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The release channel, and a web folder of any name — self-test 🧭 (#499, #500)
 * -----------------------------------------------------------------------------
 * Which copy of the portal an installation is (live, or a pre-release copy)
 * decides two things a stranger could otherwise see: PHP's error text, and
 * every page that is not behind the staff sign-in gate. `Portal\Core\Channel`
 * decides it in this order (the owner's final rule, 12 October 2026): the
 * `PORTAL_ENV` setting; else the first line of the file `.channel` beside
 * `_core`; else the web folder's own name, when it is exactly `public_html`,
 * `public_html_beta` or `public_html_dev`; else the portal ASSUMES IT IS
 * LIVE. This file checks that decision, and then runs the portal to see
 * that the pages obey it.
 *
 * It was first written for the rule that came before ("fail closed": an
 * installation that said nothing was shut to all but staff). That rule was
 * replaced before it was released, and the checks that asserted it were
 * reworked to assert the final rule, not deleted: the checks that an answer
 * nobody stated is "never live" now check that it is ALWAYS live, with
 * nothing shown that a pre-release copy would show.
 *
 * WHAT IT CHECKS
 * --------------
 *   T. The decision, as a table: every known word from the setting and from
 *      the file; each step beating the ones after it; a word nobody knows, in
 *      the setting or the file, falling through to the next step; a
 *      byte-order mark, Windows and old Mac line ends; a word on the second
 *      line (never believed); a first line cut off at the edge of what is
 *      read (never believed); each of the three folder names; names that are
 *      nearly one of them; and nothing at all. For every row: the channel,
 *      how it was decided, and whether errors are shown.
 *   F. The same through the real `Channel::decide()`, with real files in a
 *      scratch folder, the real setting and a real folder path: the file is
 *      read from the folder that holds `_core` and from nowhere else; only
 *      the LAST part of the web folder's path is its name; and nothing a
 *      visitor sends (a header, the address, a cookie, a form field) or the
 *      web server reports (`DOCUMENT_ROOT`) is believed.
 *   D. What is on disk: the five "refuse everything" files are one file,
 *      whose only rule refuses; the ignore list names the channel file; no
 *      code under `web/` names the web folder `public_html` except the one
 *      documented stand-in; start-up does not read `DOCUMENT_ROOT`, and hands
 *      the web folder to the decision only when a front controller stated
 *      it; the deciding class reads nothing of the request; and three
 *      defaults lean to "live" when the channel has not been decided yet.
 *   P. The pages, on a scratch copy of `web/` behind PHP's built-in server:
 *      A  a folder named `public_html` and nothing else: live, by the folder.
 *      B  the file says `beta`: the staff gate. Who passes it and who does
 *         not; who reaches the database upgrade page (a Root Admin only);
 *         scheduled jobs, every `/api/` address and the API documentation
 *         page are behind it; a visitor who says "live" is not believed; a
 *         missing switch row is not "off"; the switch itself; and the portal
 *         closed for an upgrade.
 *      C  the file says `live`: open, errors hidden.
 *      E  the folder renamed `public_html_beta`; G `public_html_dev`: gated
 *         by the folder's name alone.
 *      J  the folder renamed `admin_html`, nothing else: ASSUMED LIVE. Nobody
 *         is locked out; nothing is printed; `?debug=true` gives nothing to a
 *         Root Admin, an Admin, an administrator of one organisation, a
 *         member or a visitor; the error log and the admin dashboard say so,
 *         and only administrators see the dashboard's note.
 *      K  the same renamed folder with a file: sign-in, a page, the offline
 *         page, the manifest (the same bytes as before the rename),
 *         robots.txt, the sitemap, the API description, the web server's
 *         error page and the reserved-key list all still right.
 *      L  real folders named nearly like one of the three: assumed live.
 *      M  the setting: it wins; the CONTROLS (a stated dev copy does print
 *         the warning, the debug panel and the exception text, so "nothing"
 *         in part J means something); an unknown word in it falls through.
 *      N  a command-line program that loads start-up with no front
 *         controller: step 3 is skipped.
 *
 * SAFETY
 * ------
 * - It writes rows, so it REFUSES unless the database's name starts with
 *   `selftest_`. Its rows are accounts 918001 to 918099, one switched-off
 *   organisation numbered 918101, and log rows carrying its marker; they are
 *   removed at the START (in case an earlier run was interrupted) and at the
 *   END.
 * - It changes three portal-wide settings (`multisite.enabled`,
 *   `portal.gatekeeper.enabled`, `portal.maintenance.active`), for one check
 *   renames the second so that no row of that name exists, and puts all
 *   three back by their row numbers, name and value. So do NOT run it at the
 *   same moment as another self-test on the same database.
 * - The repository's files are only read. Every change (the channel file,
 *   the renamed web folder, the planted lines in the offline page and the
 *   API documentation page) is made in a NEW scratch folder under
 *   `SELFTEST_WEB_DIR` (or the system's temporary folder). It refuses if
 *   that folder contains the repository or is inside it, and deletes its
 *   child at the end only if it still holds this run's marker.
 *   `web/_auth_keys` and a developer's own `web/.channel` are never read or
 *   copied.
 * - The server is started with its command as a list (no shell), one at a
 *   time, and stopped by the process number it was given, never by a name.
 *   It refuses to start if anything already answers on its port. Every
 *   answer must carry this run's header.
 * - The clean-up also runs from a shutdown function, so a crash part way
 *   still puts things back. WHAT IT CANNOT SURVIVE: being killed outright.
 *
 * WHAT THIS CANNOT PROVE
 * ----------------------
 * - Apache. The router only imitates `.htaccess` (including that Apache
 *   answers `/api-docs/` with that folder's own index.php), so this does not
 *   show that the five "refuse everything" files are obeyed, or that Apache
 *   passes the setting on to PHP.
 * - That whatever uploads the code writes the right `.channel` file. A file
 *   that wrongly says `live` opens the copy, and nothing here can know.
 * - That a pre-release copy whose channel is only assumed gets noticed. It
 *   is open; this checks only that `/health`, the error log and the admin
 *   dashboard say so.
 * - A channel file the web server's user may not read. It is treated as "no
 *   file", but the check would fail for the wrong reason when the test is
 *   run by the system's administrator account, so it is not made.
 * - A web folder that is a link to another folder (the name compared is the
 *   real folder's).
 * - The `detail` field of an API error and the one form that shows a real
 *   error message in debug mode. They ask the same question as the
 *   exception text checked here (`App::isDebug()`), and were not each tried.
 * - Sign-in through Microsoft, Google, a passkey or a second step behind
 *   the gate: they need outside servers. Their addresses are on the gate's
 *   open list, which this does not exercise.
 * - A browser, or how any page looks. Several organisations on one
 *   installation. MariaDB.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             SELFTEST_WEB_DIR=/a/quiet/folder php tools/channel-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default
 *         root), SELFTEST_WEB_PORT (default 9590), SELFTEST_PAGE_SECONDS
 *         (how long one page may take; default 30, PHP's own; raise it on a
 *         busy machine). The database must already hold
 *         `web/_sql/full_schema.sql`. Needs PHP's curl extension and
 *         `proc_open()`.
 * Exit:   0 only when every check ran and passed; 1 otherwise, including
 *         every refusal to run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/499
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/500
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 220;

/** The made-up world: four accounts and one switched-off organisation. */
const STAFF      = 918001; // a Root Admin (a global administrator)
const MEMBER     = 918002; // an ordinary member of the default organisation
const ADMIN      = 918003; // the Admin flag alone: passes the staff gate, but is not a global administrator
const ORG_ADMIN  = 918004; // an administrator of the default organisation only: not staff for the installation
const ORG_PARKED = 918101; // switched off; its key is the name of a folder this test puts in the web folder
const PARKED_KEY = 'chan-selftest-dir';
/** The name of the variable planted in the scratch copy's offline page; PHP's warning carries it. */
const MARKER     = 'channelSelftestMarker918';
/** The message of the exception planted beside it. It holds MARKER, so the clean-up that looks for MARKER finds it too. */
const THROWN     = MARKER . 'Thrown';
/** Three bytes some Windows editors put at the start of a UTF-8 file. */
const BOM        = "\xEF\xBB\xBF";

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
    refuse("the scratch parent folder '{$parent}' and the repository must not contain each other; choose another with SELFTEST_WEB_DIR");
}
$portRaw = getenv('SELFTEST_WEB_PORT');
$port    = ($portRaw === false || $portRaw === '') ? 9590 : (int) $portRaw;
if ($port < 1024 || $port > 65535) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65535');
}
$secondsRaw  = getenv('SELFTEST_PAGE_SECONDS');
$pageSeconds = ($secondsRaw === false || $secondsRaw === '') ? 30 : max(1, (int) $secondsRaw);

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
$creds = [
    'db_host' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    'db_user' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    'db_pass' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    'db_name' => $dbName,
    'db_port' => getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306,
];
$db = new mysqli($creds['db_host'], $creds['db_user'], $creds['db_pass'], $creds['db_name'], $creds['db_port']);
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

/** A PHP file's code with its comments taken out, so a word in a comment is never mistaken for code. */
function codeOnly(string $file): string
{
    $code = '';
    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (is_array($token) === true && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) === true) {
            continue;
        }
        $code .= is_array($token) === true ? $token[1] : $token;
    }

    return $code;
}

/** One decision, written the way the tables below write what they expect. */
function shown(array $decision): string
{
    return ($decision['channel'] ?? '?') . ' / ' . ($decision['source'] ?? '?') . ' / errors ' . (($decision['showErrors'] ?? null) === true ? 'shown' : 'hidden');
}

require $webSrc . $sep . '_core' . $sep . 'Channel.php';

use Portal\Core\Channel;

echo "The release channel, and a web folder of any name — self-test (#499, #500)\n";

// =============================================================================
// T. THE DECISION, AS A TABLE
// =============================================================================
head('T. The decision: the setting, else the file\'s first line, else one of three folder names, else assumed live');

const LIVE    = 'prod / %s / errors hidden';
/** Step 4. Always the live channel, always with errors hidden, and its own word for how it was decided. */
const ASSUMED = 'prod / assumed / errors hidden';
/** What a stated pre-release channel looks like: gated (by Gatekeeper) and errors shown. */
function pre(string $channel, string $source): string
{
    return $channel . ' / ' . $source . ' / errors shown';
}
$spaces = static fn (int $many): string => str_repeat(' ', $many);
/**
 * [what it shows, the setting (null = not set), the file's text (null = no file), the web folder's name
 * (null = no front controller said), what is expected]
 *
 * Wherever a row says something is NOT believed, the thing not believed is a pre-release word or a different
 * source, so the row cannot pass by the answer happening to be the same as the one it fell through to.
 */
$table = [
    // ---- step 1: the setting ----------------------------------------------------
    ['the setting says prod', 'prod', null, null, sprintf(LIVE, 'environment')],
    ['the setting says live: the same channel as prod', 'live', null, null, sprintf(LIVE, 'environment')],
    ['the setting says beta', 'beta', null, null, pre('beta', 'environment')],
    ['the setting says alpha', 'alpha', null, null, pre('alpha', 'environment')],
    ['the setting says dev', 'dev', null, null, pre('dev', 'environment')],
    ['capital letters and spaces round the word are forgiven in the setting', ' Beta ', null, null, pre('beta', 'environment')],
    ['the setting in capitals', 'LIVE', null, null, sprintf(LIVE, 'environment')],
    ['the setting beats the file: prod over beta', 'prod', 'beta', null, sprintf(LIVE, 'environment')],
    ['the setting beats the file the other way too: beta over live', 'beta', "live\n", null, pre('beta', 'environment')],
    ['the setting beats the folder\'s name: prod in a folder named public_html_beta', 'prod', null, 'public_html_beta', sprintf(LIVE, 'environment')],
    ['the setting beats the folder\'s name the other way: beta in a folder named public_html', 'beta', null, 'public_html', pre('beta', 'environment')],
    ['the setting beats the file AND the folder', 'alpha', "live\n", 'public_html_dev', pre('alpha', 'environment')],
    // ---- an unknown word in the setting counts as "not there" ----------------------
    ['an unknown word in the setting falls through to the FILE', 'staging', "beta\n", null, pre('beta', 'file')],
    ['the setting "0" is a word nobody knows: the file is asked', '0', "beta\n", null, pre('beta', 'file')],
    ['a nearly right word in the setting is not guessed at: the file is asked', 'live!', "beta\n", null, pre('beta', 'file')],
    ['"production" in the setting is not "prod": the file is asked', 'production', "dev\n", null, pre('dev', 'file')],
    ['an unknown word in the setting and no file: the FOLDER is asked', 'staging', null, 'public_html_dev', pre('dev', 'folder')],
    ['an unknown word in the setting AND in the file: the folder is asked', 'staging', "bta\n", 'public_html_beta', pre('beta', 'folder')],
    ['an unknown word in the setting, in the file, and a folder of another name: assumed live', 'staging', "bta\n", 'httpdocs', ASSUMED],
    ['an unknown word in the setting and nothing else: assumed live', 'production', null, null, ASSUMED],
    ['an EMPTY setting does not hide the file', '', "beta\n", null, pre('beta', 'file')],
    ['a setting of spaces only does not hide the file', '   ', "beta\n", null, pre('beta', 'file')],
    // ---- step 2: the file -----------------------------------------------------------
    ['the file says live', null, 'live', null, sprintf(LIVE, 'file')],
    ['the file says prod', null, "prod\n", null, sprintf(LIVE, 'file')],
    ['the file says beta', null, "beta\n", null, pre('beta', 'file')],
    ['the file says alpha', null, "alpha\n", null, pre('alpha', 'file')],
    ['the file says dev', null, "dev\n", null, pre('dev', 'file')],
    ['the file beats the folder\'s name: beta in a folder named public_html', null, "beta\n", 'public_html', pre('beta', 'file')],
    ['the file beats the folder\'s name the other way: live in a folder named public_html_dev', null, "live\n", 'public_html_dev', sprintf(LIVE, 'file')],
    ['a Unix line end', null, "beta\n", null, pre('beta', 'file')],
    ['a Windows line end', null, "beta\r\n", null, pre('beta', 'file')],
    ['an old Mac line end ends the first line too', null, "beta\rlive", null, pre('beta', 'file')],
    ['a byte-order mark before the word', null, BOM . 'beta', null, pre('beta', 'file')],
    ['a byte-order mark AND a Windows line end, as Notepad saves it', null, BOM . "live\r\n", null, sprintf(LIVE, 'file')],
    ['capital letters, spaces and a tab round the word are forgiven in the file', null, "  Beta \t\n", null, pre('beta', 'file')],
    ['only the FIRST line counts: lines after a known word are ignored', null, "beta\nlive\n", null, pre('beta', 'file')],
    // ---- an unknown first line counts as "not there" --------------------------------
    ['an empty file: not there, so assumed live', null, '', null, ASSUMED],
    ['an empty file in a folder named public_html_beta: the folder is asked', null, '', 'public_html_beta', pre('beta', 'folder')],
    ['a file holding only a line end: not there', null, "\n", null, ASSUMED],
    ['a blank first line with beta on the SECOND: the second line is never believed', null, "\nbeta\n", null, ASSUMED],
    ['the same with Windows line ends', null, "\r\nbeta\r\n", null, ASSUMED],
    ['an unknown first line with beta on the second: not believed', null, "staging\nbeta\n", null, ASSUMED],
    ['a comment line first, then beta: not believed (the file has no comments)', null, "# the channel\nbeta\n", null, ASSUMED],
    ['an unknown word in the file falls through to the FOLDER', null, "bta\n", 'public_html_beta', pre('beta', 'folder')],
    ['an unknown word in the file, and a folder named public_html: live, decided by the folder', null, "production\n", 'public_html', sprintf(LIVE, 'folder')],
    ['two words on the first line: not a known word', null, "beta dev\n", null, ASSUMED],
    ['a word with something stuck to it: not a known word', null, "beta;\n", null, ASSUMED],
    ['a byte-order mark and nothing else: not there', null, BOM, null, ASSUMED],
    ['TWO byte-order marks: only one is forgiven', null, BOM . BOM . 'beta', null, ASSUMED],
    ['a file saved as UTF-16: not read as a word', null, "\xFF\xFEb\x00e\x00t\x00a\x00", null, ASSUMED],
    ['the file "0": not a known word', null, '0', null, ASSUMED],
    // ---- a first line cut off at the edge of what is read (the limit is 256 bytes) ------
    ['a word CUT OFF at the limit is not believed: 252 spaces then "production" (its first 4 letters are "prod")', null, $spaces(252) . 'production', null, ASSUMED],
    ['"live", 300 spaces, then more text: the line did not end inside the limit, so it is not believed', null, 'live' . $spaces(300) . 'x', null, ASSUMED],
    ['252 spaces then "betamax": cut off after "beta", not believed, so the folder is asked', null, $spaces(252) . 'betamax', 'public_html_dev', pre('dev', 'folder')],
    ['252 spaces, "beta" and a line end: the line DOES end, on the byte after the limit, so it is read', null, $spaces(252) . "beta\n", null, pre('beta', 'file')],
    ['253 spaces, "beta" and a line end: one byte too long, not read', null, $spaces(253) . "beta\n", null, ASSUMED],
    ['5,000 spaces then beta: far past the limit, not read', null, $spaces(5000) . "beta\n", null, ASSUMED],
    ['a long first line that is not a word, with a short known second line: not believed', null, str_repeat('x', 300) . "\nbeta\n", null, ASSUMED],
    // ---- step 3: the web folder's own name, whole and exactly as written -----------------
    ['a folder named public_html: live', null, null, 'public_html', sprintf(LIVE, 'folder')],
    ['a folder named public_html_beta: beta', null, null, 'public_html_beta', pre('beta', 'folder')],
    ['a folder named public_html_dev: dev (as the last release had it, not alpha)', null, null, 'public_html_dev', pre('dev', 'folder')],
    ['public_html_old is not a known name ("starts with" is not enough)', null, null, 'public_html_old', ASSUMED],
    ['my_public_html is not a known name ("ends with" is not enough)', null, null, 'my_public_html', ASSUMED],
    ['public_html_beta.bak is not a known name', null, null, 'public_html_beta.bak', ASSUMED],
    ['public_html_dev2 is not a known name', null, null, 'public_html_dev2', ASSUMED],
    ['old_public_html_dev_copy is not a known name ("contains" is not enough)', null, null, 'old_public_html_dev_copy', ASSUMED],
    ['Public_HTML is not a known name (capitals are not folded)', null, null, 'Public_HTML', ASSUMED],
    ['PUBLIC_HTML_BETA is not a known name', null, null, 'PUBLIC_HTML_BETA', ASSUMED],
    ['Public_Html_Dev is not a known name', null, null, 'Public_Html_Dev', ASSUMED],
    ['a space after the name is not forgiven in a folder\'s name', null, null, 'public_html_beta ', ASSUMED],
    ['a space before the name is not forgiven either', null, null, ' public_html_dev', ASSUMED],
    ['a path is not a name: "public_html_beta/site" is not known', null, null, 'public_html_beta/site', ASSUMED],
    ['public_html_alpha is not one of the three', null, null, 'public_html_alpha', ASSUMED],
    ['beta_html, which the last release also knew, is not one of the three', null, null, 'beta_html', ASSUMED],
    ['alpha_html, the same', null, null, 'alpha_html', ASSUMED],
    ['the word "beta" alone is a channel word, not a folder name', null, null, 'beta', ASSUMED],
    ['httpdocs: assumed live', null, null, 'httpdocs', ASSUMED],
    ['www: assumed live', null, null, 'www', ASSUMED],
    ['an empty folder name: assumed live', null, null, '', ASSUMED],
    // ---- step 4: nothing says ---------------------------------------------------------------
    ['no setting, no file, no front controller: assumed live', null, null, null, ASSUMED],
    ['an empty setting, no file, no front controller: assumed live', '', null, null, ASSUMED],
];
$decisions = [];
foreach ($table as $number => [$label, $variable, $fileText, $folderName, $expected]) {
    $decision    = Channel::decideFrom($variable, $fileText, $folderName);
    $decisions[] = $decision;
    ok(sprintf('T%02d %s', $number + 1, $label), shown($decision) === $expected && array_keys($decision) === ['channel', 'source', 'showErrors'], 'got ' . shown($decision) . '; expected ' . $expected);
}

// What must hold for EVERY answer, whatever the table says. These are the promises the final rule makes.
$stating      = [Channel::SOURCE_ENVIRONMENT, Channel::SOURCE_FILE, Channel::SOURCE_FOLDER];
$assumedWrong = [];
$errorsWrong  = [];
$sourceWrong  = [];
foreach ($decisions as $number => $decision) {
    $stated     = in_array($decision['source'], $stating, true);
    $preRelease = in_array($decision['channel'], Channel::PRE_RELEASE, true);
    if ($stated === false && $decision['source'] !== Channel::SOURCE_ASSUMED) {
        $sourceWrong[] = 'row ' . ($number + 1) . ': ' . shown($decision);
    }
    if ($stated === false && ($decision['channel'] !== 'prod' || $decision['showErrors'] !== false)) {
        $assumedWrong[] = 'row ' . ($number + 1) . ': ' . shown($decision);
    }
    if ($decision['showErrors'] !== ($stated === true && $preRelease === true) || ($preRelease === false && $decision['channel'] !== 'prod')) {
        $errorsWrong[] = 'row ' . ($number + 1) . ': ' . shown($decision);
    }
}
$n = count($table);
ok(sprintf('T%02d every answer says how it was decided in one of four words: environment, file, folder or assumed', $n + 1), $sourceWrong === [], implode('; ', $sourceWrong));
ok(sprintf('T%02d every answer that nothing stated is the LIVE channel with errors hidden: never a pre-release channel, and nothing a pre-release copy would show', $n + 2), $assumedWrong === [], implode('; ', $assumedWrong));
ok(sprintf('T%02d errors are shown on EVERY pre-release channel that was stated and on nothing else: never on live, however it was decided', $n + 3), $errorsWrong === [], implode('; ', $errorsWrong));
ok(sprintf('T%02d the pre-release channels are exactly alpha, beta and dev, and live is not one', $n + 4), Channel::PRE_RELEASE === ['alpha', 'beta', 'dev']);
ok(sprintf('T%02d the folder names that state a channel are exactly three, each with the meaning the last release gave it', $n + 5),
    Channel::FOLDER_NAMES === ['public_html' => 'prod', 'public_html_beta' => 'beta', 'public_html_dev' => 'dev'], json_encode(Channel::FOLDER_NAMES));
$gatekeeper = codeOnly($webSrc . $sep . '_core' . $sep . 'Gatekeeper.php');
ok(sprintf('T%02d the staff gate reads its list of channels from Channel, so the two cannot drift apart', $n + 6), preg_match('/const\s+VALID_CHANNELS\s*=\s*Channel::PRE_RELEASE\s*;/', $gatekeeper) === 1);

// =============================================================================
// F. THE REAL FILE, THE REAL SETTING AND A REAL FOLDER PATH
// =============================================================================
head('F. Channel::decide(): real files, the real setting, the web folder as a path');

$state = ['proc' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'settings' => [], 'started' => false, 'cleaned' => false, 'logRead' => 0, 'webFolder' => 'public_html'];
$child = $parent . $sep . 'channel-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.channel-selftest-owner', $state['token']); // the marker FIRST
register_shutdown_function('cleanup');

// A made-up installation: an outer folder, the folder that holds _core inside it, and a web folder inside that.
// The web folder is named `httpdocs`, a name the portal does not know, unless a check says otherwise: so
// whatever a check expects of the setting or the file cannot be supplied by the folder's name instead.
$outer = $child . $sep . 'f';
$root  = $outer . $sep . 'installation';
mkdir($root . $sep . '_core', 0700, true);
mkdir($root . $sep . 'httpdocs', 0700);
/** Put the made-up installation's files as given (path under the outer folder => text), removing any from before. */
function files(array $wanted): void
{
    global $outer, $sep;
    foreach (['.channel', 'installation' . $sep . '.channel', 'installation' . $sep . '_core' . $sep . '.channel', 'installation' . $sep . 'httpdocs' . $sep . '.channel'] as $path) {
        $full = $outer . $sep . $path;
        if (is_dir($full) === true) {
            rmdir($full);
        } elseif (is_file($full) === true) {
            unlink($full);
        }
    }
    foreach ($wanted as $path => $text) {
        file_put_contents($outer . $sep . str_replace('/', $sep, $path), $text);
    }
}
/**
 * Channel::decide() for the made-up installation, with the setting at `$variable` (null = not set at all)
 * and the web folder given as `$webFolder`: a name under the installation, a whole path when it starts with a
 * slash, or null for "no front controller said".
 */
function decideReal(?string $variable, ?string $webFolder = 'httpdocs'): string
{
    global $root, $sep;
    putenv($variable === null ? 'PORTAL_ENV' : 'PORTAL_ENV=' . $variable);
    $path     = ($webFolder === null || str_starts_with($webFolder, '/') === true) ? $webFolder : $root . $sep . $webFolder;
    $decision = Channel::decide($root, $path);
    putenv('PORTAL_ENV');

    return shown($decision);
}

files([]);
ok('F01 no setting, no file anywhere, a web folder named httpdocs: assumed live', decideReal(null) === ASSUMED, decideReal(null));
files(['installation/.channel' => "beta\n"]);
ok('F02 the file beside _core says beta: beta, decided by the file', decideReal(null) === pre('beta', 'file'), decideReal(null));
ok('F03 its path is "<the folder that holds _core>/.channel", with or without a slash on the end', Channel::filePath($root) === $root . $sep . '.channel' && Channel::filePath($root . $sep) === $root . $sep . '.channel', Channel::filePath($root));
files(['installation/_core/.channel' => "beta\n"]);
ok('F04 a file INSIDE _core is not read', decideReal(null) === ASSUMED, decideReal(null));
files(['installation/httpdocs/.channel' => "beta\n"]);
ok('F05 a file inside the WEB folder is not read', decideReal(null) === ASSUMED, decideReal(null));
files(['.channel' => "beta\n"]);
ok('F06 a file one folder ABOVE the installation is not read', decideReal(null) === ASSUMED, decideReal(null));
files(['installation/.channel' => BOM . "beta\r\n"]);
ok('F07 a real file with a byte-order mark and a Windows line end: beta', decideReal(null) === pre('beta', 'file'), decideReal(null));
files(['installation/.channel' => "\nbeta\n"]);
ok('F08 a real file whose first line is blank and whose second says beta: not believed', decideReal(null) === ASSUMED, decideReal(null));
files(['installation/.channel' => "live\n"]);
ok('F09 the real setting beats the real file', decideReal('beta') === pre('beta', 'environment'), decideReal('beta'));
files(['installation/.channel' => "beta\n"]);
ok('F10 the real setting set to nothing does not hide the file', decideReal('') === pre('beta', 'file'), decideReal(''));
ok('F11 the real setting holding an unknown word falls through to the real file', decideReal('staging') === pre('beta', 'file'), decideReal('staging'));
files(['installation/.channel' => "bta\n"]);
ok('F12 an unknown word in the real setting AND in the real file falls through to the folder\'s name', decideReal('staging', 'public_html_dev') === pre('dev', 'folder'), decideReal('staging', 'public_html_dev'));
files([]);
mkdir($root . $sep . '.channel', 0700);
$warnings = [];
set_error_handler(static function (int $number, string $text) use (&$warnings): bool {
    $warnings[] = $text;

    return true;
});
$asFolder = decideReal(null, 'public_html_beta');
restore_error_handler();
ok('F13 a FOLDER named .channel is "no file": the web folder\'s name is asked, and PHP raises no warning', $asFolder === pre('beta', 'folder') && $warnings === [], $asFolder . '; warnings: ' . implode(' | ', $warnings));
files([]);
// A first line cut off at the edge of what is read. The first two are the independent check's own cases: both
// were believed (as live, decided by the file) when exactly 256 bytes were read and taken as the line.
file_put_contents($root . $sep . '.channel', str_repeat(' ', 252) . 'production');
ok('F14 a real file of 252 spaces then "production" is NOT read as "prod"', decideReal(null) === ASSUMED, decideReal(null));
file_put_contents($root . $sep . '.channel', 'live' . str_repeat(' ', 300) . 'x');
ok('F15 a real file of "live", 300 spaces and more text is NOT read as "live"', decideReal(null) === ASSUMED, decideReal(null));
file_put_contents($root . $sep . '.channel', str_repeat(' ', 252) . 'betamax');
ok('F16 a real file of 252 spaces then "betamax" is NOT read as "beta": the folder\'s name is asked', decideReal(null, 'public_html_dev') === pre('dev', 'folder'), decideReal(null, 'public_html_dev'));
file_put_contents($root . $sep . '.channel', str_repeat(' ', 5000) . "beta\n");
ok('F17 a real first line that goes on far past what is read (5,000 spaces, then beta) is not read', decideReal(null) === ASSUMED, decideReal(null));
file_put_contents($root . $sep . '.channel', str_repeat(' ', 252) . "beta\n");
ok('F18 a real first line that ends on the byte after the limit IS read: nothing shorter than the limit is lost', decideReal(null) === pre('beta', 'file'), decideReal(null));
files([]);

// The web folder, as a path. Only its LAST part is the name, and only when a front controller gave it.
$byName = [];
foreach (['public_html' => sprintf(LIVE, 'folder'), 'public_html_beta' => pre('beta', 'folder'), 'public_html_dev' => pre('dev', 'folder')] as $name => $expected) {
    if (decideReal(null, $name) !== $expected) {
        $byName[] = $name . ' gave ' . decideReal(null, $name);
    }
}
ok('F19 a web folder whose path ends in each of the three names is that channel, decided by the folder', $byName === [], implode('; ', $byName));
$parents = [];
foreach (['/srv/public_html_beta/site/httpdocs', '/home/public_html_dev/portal/www', $outer . $sep . 'public_html_beta' . $sep . 'installation' . $sep . 'web', '/var/www/public_html_dev.example/htdocs'] as $path) {
    if (decideReal(null, $path) !== ASSUMED) {
        $parents[] = $path . ' gave ' . decideReal(null, $path);
    }
}
ok('F20 a PARENT folder named like a channel is not believed: only the web folder\'s own name counts, never "somewhere in the path"', $parents === [], implode('; ', $parents));
ok('F21 no front controller said which folder (null): step 3 is skipped, so assumed live', decideReal(null, null) === ASSUMED, decideReal(null, null));
// Nothing a visitor sends is read. These are set the way PHP sets them for a request: a header named
// PORTAL_ENV or Portal-Env arrives as HTTP_PORTAL_ENV; the last release read DOCUMENT_ROOT.
$visitor = ['HTTP_PORTAL_ENV' => 'beta', 'PORTAL_ENV' => 'beta', 'REDIRECT_PORTAL_ENV' => 'beta', 'HTTP_X_PORTAL_ENV' => 'beta', 'DOCUMENT_ROOT' => '/srv/public_html_beta',
    'SCRIPT_FILENAME' => '/srv/public_html_beta/index.php', 'PHP_SELF' => '/public_html_beta/index.php', 'SCRIPT_NAME' => '/public_html_beta/index.php', 'HTTP_HOST' => 'public_html_beta'];
$kept    = [$_SERVER, $_GET, $_POST, $_COOKIE, $_REQUEST, $_ENV];
$_SERVER = array_merge($_SERVER, $visitor);
$_GET['PORTAL_ENV'] = $_POST['PORTAL_ENV'] = $_COOKIE['PORTAL_ENV'] = $_REQUEST['PORTAL_ENV'] = $_ENV['PORTAL_ENV'] = 'beta';
$sent = decideReal(null);
[$_SERVER, $_GET, $_POST, $_COOKIE, $_REQUEST, $_ENV] = $kept;
ok('F22 a request header, an address, a cookie or a form field named like the setting is NOT believed, and nor is the path the web server reports', $sent === ASSUMED, $sent);

// =============================================================================
// D. WHAT IS ON DISK
// =============================================================================
head('D. The deny files, the ignore list, and the web folder\'s name');

$denyFolders = ['_apps', '_core', '_lang', '_sql', '_vendor'];
$denyTexts   = [];
foreach ($denyFolders as $folder) {
    $file               = $webSrc . $sep . $folder . $sep . '.htaccess';
    $denyTexts[$folder] = is_file($file) === true ? (string) file_get_contents($file) : '(missing from ' . $folder . ')';
}
ok('D01 each of _apps, _core, _lang, _sql and _vendor holds a "refuse everything" file, and the five are one file byte for byte', count(array_unique($denyTexts)) === 1 && str_contains((string) reset($denyTexts), '(missing') === false, json_encode(array_map('md5', $denyTexts)));
$rules = array_values(array_filter(array_map('trim', explode("\n", (string) reset($denyTexts))), static fn (string $line): bool => $line !== '' && str_starts_with($line, '#') === false));
ok('D02 its ONLY rule is "Require all denied": nothing in it switches anything on', $rules === ['Require all denied'], json_encode($rules));
$ignore = array_map('trim', explode("\n", (string) file_get_contents($repo . $sep . '.gitignore')));
ok('D03 the ignore list names /web/.channel, so one installation\'s channel is never committed', in_array('/web/.channel', $ignore, true) === true);

// Every PHP file under web/, code only: who still spells the web folder's name?
$named = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($webSrc, FilesystemIterator::SKIP_DOTS));
$read  = 0;
foreach ($files as $path => $info) {
    $rel = substr((string) $path, strlen($webSrc) + 1);
    if ($info->isFile() === false || str_ends_with($rel, '.php') === false || in_array(explode($sep, $rel)[0], ['_auth_keys', '_uploads', '_backups', '_libraries'], true) === true) {
        continue;
    }
    $read++;
    foreach (token_get_all((string) file_get_contents((string) $path)) as $token) {
        if (is_array($token) === true && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) === true && str_contains($token[1], 'public_html') === true) {
            $named[] = str_replace($sep, '/', $rel) . ':' . $token[2];
        }
    }
}
// Two places may: start-up's stand-in for tools that load it without a front controller, and the list of the
// three folder names in Channel.php. The admin dashboard's note also SHOWS the three names to an administrator,
// in the page's text, which is not code and is not read here.
sort($named);
$mayName = static fn (string $where): bool => str_starts_with($where, '_core/bootstrap.php:') === true || str_starts_with($where, '_core/Channel.php:') === true;
ok('D04 no code under web/ builds a path with the name "public_html": only start-up\'s one stand-in and the three names in Channel.php spell it',
    $read > 500 && count($named) === 4 && count(array_filter($named, $mayName)) === 4 && count(array_filter($named, static fn (string $where): bool => str_starts_with($where, '_core/Channel.php:') === true)) === 3,
    'read ' . $read . ' PHP files; found in: ' . implode(', ', $named));
$bootstrap = codeOnly($webSrc . $sep . '_core' . $sep . 'bootstrap.php');
ok('D05 start-up does not read the path the web server reports (DOCUMENT_ROOT) to decide anything: on some hosting a visitor\'s Host header shapes it', str_contains($bootstrap, 'DOCUMENT_ROOT') === false);
// The folder's name may reach the decision ONLY when a front controller stated it. Start-up notes whether
// one did BEFORE it defines its own stand-in, and hands over the folder only on a yes.
$noted   = strpos($bootstrap, "\$portalWebrootStated = defined('PORTAL_WEBROOT');");
$standIn = strpos($bootstrap, "define('PORTAL_WEBROOT'");
ok('D06 start-up asks Channel::decide() once, handing it the web folder only when a front controller stated it (never its own stand-in name), and sets PORTAL_ENV and PORTAL_ENV_SOURCE from the answer',
    substr_count($bootstrap, 'Channel::decide(') === 1 && substr_count($bootstrap, 'Channel::decide(PORTAL_ROOT, $portalWebrootStated === true ? PORTAL_WEBROOT : null)') === 1
    && $noted !== false && $standIn !== false && $noted < $standIn && substr_count($bootstrap, '$portalWebrootStated = ') === 1
    && preg_match('/define\(\'PORTAL_ENV\',\s*\$portalChannel\[\'channel\'\]\)/', $bootstrap) === 1
    && preg_match('/define\(\'PORTAL_ENV_SOURCE\',\s*\$portalChannel\[\'source\'\]\)/', $bootstrap) === 1 && substr_count($bootstrap, "define('PORTAL_ENV'") === 1);
$wrong = [];
foreach (['index.php', 'error.php'] as $front) {
    $code    = codeOnly($webSrc . $sep . 'public_html' . $sep . $front);
    $defined = strpos($code, "define('PORTAL_WEBROOT', __DIR__);");
    // The statement that LOADS start-up: `require`, or `require_once`, then anything up to its semicolon that names $bootstrap or bootstrap.php.
    $loads   = preg_match('/\brequire(?:_once)?\b[^;]*bootstrap[^;]*;/', $code, $m, PREG_OFFSET_CAPTURE) === 1 ? $m[0][1] : false;
    if ($defined === false || $loads === false || $defined > $loads) {
        $wrong[] = $front . ' (defined at ' . var_export($defined, true) . ', start-up loaded at ' . var_export($loads, true) . ')';
    }
}
ok('D07 both front controllers say the web folder is their OWN folder, before they load start-up', $wrong === [], implode('; ', $wrong));
// Channel.php itself: nothing a visitor can send, and nothing the web server reports about the request.
$channelCode = codeOnly($webSrc . $sep . '_core' . $sep . 'Channel.php');
$reads       = array_values(array_filter(['$_SERVER', '$_GET', '$_POST', '$_COOKIE', '$_REQUEST', '$_ENV', '$_FILES', '$GLOBALS', 'getallheaders', 'apache_request_headers', 'filter_input', 'DOCUMENT_ROOT', 'SCRIPT_FILENAME', 'PHP_SELF', 'str_contains', 'stripos', 'strcasecmp'],
    static fn (string $word): bool => str_contains($channelCode, $word) === true));
ok('D08 the class that decides the channel reads nothing of the request (no header, address, cookie or form field), and compares the folder\'s name by looking it up whole, not by searching in it', $reads === [] && substr_count($channelCode, 'getenv(') === 1, 'found: ' . implode(', ', $reads));

// Three places answer for themselves when start-up has not decided the channel yet (the constant is missing).
// They used to lean towards "a pre-release copy"; the portal's own last resort is now "live", and so are they.
$probe = <<<'PROBE'
spl_autoload_register(static function (string $class): void {
    $file = $_SERVER['argv'][1] . DIRECTORY_SEPARATOR . substr($class, strlen('Portal\\Core\\')) . '.php';
    if (str_starts_with($class, 'Portal\\Core\\') === true && is_file($file) === true) {
        require $file;
    }
});
$_GET['debug'] = 'true';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
// A signed-in Root Admin, put in by hand because start-up has not run: without one, debug mode would refuse
// for want of an administrator and the check could not tell which way the missing constant leans.
$app = new ReflectionClass(Portal\Core\App::class);
$app->getProperty('currentUser')->setValue(null, ['userID' => 918001, 'isAdmin' => 1, 'isRootAdmin' => 1, 'isSiteAdmin' => 1, 'isSiteRootAdmin' => 1]);
$app->getProperty('userLoaded')->setValue(null, true);
echo 'PROBE=' . json_encode(['constant' => defined('PORTAL_ENV'), 'rootAdmin' => Portal\Core\App::isRootAdmin(), 'env' => Portal\Core\App::env(), 'debugMode' => Portal\Core\App::isDebug(), 'panel' => Portal\Core\Debug::isEnabled(), 'panelHtml' => Portal\Core\Debug::renderPanel()]) . "\n";
PROBE;
$probeEnv = getenv();
unset($probeEnv['PORTAL_ENV']);
$probeRun = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', $probe, '--', $webSrc . $sep . '_core'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $probePipes, null, $probeEnv);
$probeOut = is_resource($probeRun) === true ? (string) stream_get_contents($probePipes[1]) . (string) stream_get_contents($probePipes[2]) : '';
$probeExit = is_resource($probeRun) === true ? proc_close($probeRun) : -1;
$probed    = preg_match('/^PROBE=(\{.*\})$/m', $probeOut, $m) === 1 ? json_decode($m[1], true) : null;
ok('D09 with the channel constant missing, App::env() says prod, and debug mode and the debug panel both refuse a Root Admin who asks with ?debug=true: a missing answer leans to live',
    $probeExit === 0 && $probed === ['constant' => false, 'rootAdmin' => true, 'env' => 'prod', 'debugMode' => false, 'panel' => false, 'panelHtml' => ''], 'exit ' . $probeExit . '; said: ' . substr(trim($probeOut), 0, 300));

// =============================================================================
// 🧰 The server, and one request
// =============================================================================
function portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);

        return true;
    }

    return false;
}

/** Stop the server this run started, by the process proc_open() gave — never by a name. */
function stopServer(): void
{
    global $state, $port;
    if (is_resource($state['proc']) === false) {
        return;
    }
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
    $until         = microtime(true) + 5;
    while (portAnswers($port) === true && microtime(true) < $until) {
        usleep(50000);
    }
}

/** Remove this test's rows. Only its own account numbers, its own organisation and rows carrying its marker. */
function removeWorld(): void
{
    // The second half is for rows with no account: a visitor's refused attempt to switch debug mode on is
    // logged with the address asked for, and every such address this test asks carries its marker.
    q("DELETE FROM tblActivityLogs WHERE userID BETWEEN 918001 AND 918099 OR activityDescription LIKE '%" . MARKER . "%'");
    q("DELETE FROM tblErrors WHERE userID BETWEEN 918001 AND 918099 OR errorTitle LIKE '%" . MARKER . "%' OR errorDetail LIKE '%" . MARKER . "%'");
    q('DELETE FROM tblUserSites WHERE userID BETWEEN 918001 AND 918099');
    q('DELETE FROM tblLocalAccounts WHERE userID BETWEEN 918001 AND 918099');
    q('DELETE FROM tblUsers WHERE userID BETWEEN 918001 AND 918099');
    q('DELETE FROM tblSites WHERE siteID = ? AND siteKey = ?', 'is', [ORG_PARKED, PARKED_KEY]);
}

/** Put back everything this run changed, ONCE. Also registered as a shutdown function. */
function cleanup(): void
{
    global $state;
    if ($state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
    stopServer();
    if ($state['started'] === true) {
        try {
            // The NAME is put back as well as the value: one check renames the gate's switch so that no row
            // of that name exists, and a crash in the middle of it must not leave the row lost under another name.
            foreach ($state['settings'] as $key => $before) {
                q('UPDATE tblSettings SET settingKey = ?, settingValue = ? WHERE settingID = ?', 'ssi', [(string) $key, $before['settingValue'], (int) $before['settingID']]);
            }
            removeWorld();
        } catch (Throwable $e) {
            echo 'FAIL — the clean-up could not put the database back: ' . $e->getMessage() . "\n";
        }
    }
    // The scratch child: only the one this run made, and only while it still holds this run's marker.
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false && @file_get_contents($child . DIRECTORY_SEPARATOR . '.channel-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}

/**
 * Start the server on the scratch copy, stopping the one before it.
 *
 * `$variable` is what PORTAL_ENV holds for the whole server (null = the
 * variable does not exist, which is how most hosting runs). `$webFolder` is
 * the name the web folder has at that moment.
 *
 * `display_errors=1` is given ON PURPOSE: PHP would print every warning
 * unless the portal's own start-up switches that off. So "the warning is not
 * printed" below is the portal's doing, not this command's.
 */
function startServer(?string $variable, string $webFolder): void
{
    global $state, $child, $sep, $port, $pageSeconds;
    stopServer();
    if (portAnswers($port) === true) {
        refuse("something still answers on port {$port}; this test never drives a server it did not start");
    }
    $env = getenv();
    unset($env['PHP_CLI_SERVER_WORKERS'], $env['PORTAL_ENV']);
    if ($variable !== null) {
        $env['PORTAL_ENV'] = $variable;
    }
    $state['proc'] = proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1',
            '-d', 'display_errors=1', '-d', 'html_errors=0', '-d', 'opcache.enable=0', '-d', 'max_execution_time=' . $pageSeconds,
            '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . $webFolder, $child . $sep . 'router.php'],
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
}

/** Write the scratch installation's channel file, or remove it (null). */
function channelFile(?string $text): void
{
    global $child, $sep;
    $file = $child . $sep . 'web' . $sep . '.channel';
    if ($text === null) {
        if (is_file($file) === true) {
            unlink($file);
        }

        return;
    }
    file_put_contents($file, $text);
}

/**
 * One real HTTP request to the server this run started. Redirects are never
 * followed. An answer without this run's header came from somewhere else
 * and is returned as status -1, which no check expects.
 *
 * @param array<string,mixed>|null $post
 * @param list<string>             $headers Extra request headers, each as "Name: value": what a visitor might send.
 *
 * @return array{status:int, location:string, body:string, head:string}
 */
function req(string $jar, string $method, string $path, ?array $post = null, array $headers = []): array
{
    global $port, $state, $pageSeconds;
    $handle = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => $pageSeconds + 30,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => array_merge(['Expect:', 'Accept-Language: en'], $headers),
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
    if (preg_match('/^X-Channel-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start', 'head' => ''];
    }

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'body'     => substr((string) $raw, $headSize),
        'head'     => $head,
    ];
}

/** An answer in a few words, for a failure message. */
function said(array $answer): string
{
    return 'HTTP ' . $answer['status'] . ($answer['location'] === '' ? '' : ' to ' . $answer['location']);
}

/** Was the visitor sent to the sign-in page? That is what the staff gate does to somebody who is not signed in. */
function sentToSignIn(array $answer): bool
{
    return $answer['status'] === 302 && str_contains($answer['location'], 'login') === true;
}

/**
 * What `/health` says about the channel, as "status channel / how it was decided".
 *
 * @param list<string> $headers Extra request headers, as for req().
 */
function healthSays(array $headers = [], string $query = ''): string
{
    $answer = req('', 'GET', '/health' . $query, null, $headers);
    $json   = json_decode($answer['body'], true);

    return $answer['status'] . ' ' . (is_array($json) === true ? ($json['env'] ?? '?') . ' / ' . ($json['channelSource'] ?? '(no channelSource)') : 'not JSON: ' . substr($answer['body'], 0, 80));
}

/** Sign one account in through the real sign-in page. Returns its cookie jar and the answer to the form. */
function signIn(string $who): array
{
    global $child, $sep, $password;
    $jar  = $child . $sep . 'jar-' . $who . '-' . bin2hex(random_bytes(3)) . '.txt';
    $form = req($jar, 'GET', '/login');
    $tok  = preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $form['body'], $m) === 1 ? $m[1] : '';
    $in   = req($jar, 'POST', '/login', ['csrf_token' => $tok, 'username' => 'selftest-channel-' . $who, 'password' => $password]);

    return ['jar' => $jar, 'form' => $form, 'answer' => $in, 'in' => $in['status'] === 302 && str_contains($in['location'], 'login') === false];
}

/** What PHP's error log has gained since this was last asked. */
function logSince(): string
{
    global $state, $child, $sep;
    $file = $child . $sep . 'php-errors.log';
    clearstatcache(true, $file);
    $text             = is_file($file) === true ? (string) file_get_contents($file, false, null, $state['logRead']) : '';
    $state['logRead'] += strlen($text);

    return $text;
}

/**
 * Ask for the offline page, in which this test planted a line that raises a
 * PHP warning. Says whether the page itself came, whether the warning's text
 * was printed into it, and whether PHP reported the warning at all (to its
 * log): without that last one, "not printed" would prove nothing.
 *
 * "Printed" looks for the warning's own words and for a path on this machine,
 * not for the bare marker word: an address asked with `$query` may carry
 * that word, and a page may quote its own address.
 *
 * @param list<string> $headers Extra request headers, as for req().
 *
 * @return array{page:bool, printed:bool, reported:bool, said:string}
 */
function warningOnAPage(string $jar = '', string $query = '', array $headers = []): array
{
    global $child;
    logSince();
    $answer = req($jar, 'GET', '/offline' . $query, null, $headers);
    $log    = logSince();

    return [
        'page'     => $answer['status'] === 200 && str_contains($answer['body'], '<title>Offline') === true,
        'printed'  => str_contains($answer['body'], '$' . MARKER) === true || str_contains($answer['body'], 'Undefined variable') === true || str_contains($answer['body'], $child) === true,
        'reported' => str_contains($log, MARKER) === true,
        'said'     => said($answer) . '; first bytes: ' . json_encode(substr(trim($answer['body']), 0, 90)),
    ];
}

/**
 * The three addresses whose pages live in the web folder and are reached
 * through the router: for each, the status and whether the answer is that
 * page's own. On a new database the sitemap's own answer is a 404 in plain
 * words ("this site … has no sitemap"), which is NOT the portal's "page not
 * found": that is what a page the router could not find would give.
 *
 * @return array<string,string>
 */
function routedFromWebFolder(): array
{
    $own = ['/robots.txt' => 'User-agent', '/sitemap.xml' => 'so it has no sitemap', '/openapi.json' => '"openapi"'];
    $got = [];
    foreach ($own as $address => $words) {
        $answer        = req('', 'GET', $address);
        $got[$address] = $answer['status'] . (str_contains($answer['body'], $words) === true ? ' own page' : ' NOT its own page');
    }

    return $got;
}

/** Stop the server, give the scratch web folder another name, and start the server on it. */
function webFolderBecomes(string $name, ?string $variable = null): void
{
    global $state, $child, $sep;
    stopServer();
    if (rename($child . $sep . 'web' . $sep . $state['webFolder'], $child . $sep . 'web' . $sep . $name) === false) {
        refuse("could not rename the scratch web folder to {$name}");
    }
    $state['webFolder'] = $name;
    startServer($variable, $name);
}

/** Does the answer carry the debug panel? */
function panelIn(array $answer): bool
{
    return str_contains($answer['body'], 'portal-debug-bar') === true;
}

/**
 * Does the answer carry PHP's own error text, or a path on this machine? Not
 * judged by this run's marker word: several addresses asked here carry that
 * word themselves, and a page may quote its own address.
 */
function leaks(array $answer): bool
{
    global $child;
    foreach (['$' . MARKER, THROWN, 'Undefined variable', 'Fatal error', 'Stack trace', 'Uncaught', 'RuntimeException', $child] as $sign) {
        if (str_contains($answer['body'], $sign) === true) {
            return true;
        }
    }

    return false;
}

/**
 * The admin dashboard's note about how the channel was decided: the source
 * it names, '' when the note is not there, '?' when it is there but not in
 * the shape expected.
 */
function noteIn(array $answer): string
{
    if (preg_match('/id="portal-channel-note" data-channel-source="([a-z]*)"/', $answer['body'], $m) === 1) {
        return $m[1];
    }

    return str_contains($answer['body'], 'portal-channel-note') === true ? '?' : '';
}

/** Is that note amber (Bootstrap's "warning") and not red ("danger")? */
function noteIsAmber(array $answer): bool
{
    return preg_match('/<div class="alert alert-warning" role="alert" id="portal-channel-note"/', $answer['body']) === 1;
}

/** How many times the portal has recorded this test's planted exception. */
function thrownRows(): int
{
    return (int) q("SELECT COUNT(*) AS n FROM tblErrors WHERE errorTitle LIKE '%" . THROWN . "%'")[0]['n'];
}

/**
 * Ask for the offline page with ?debug=true and the planted line that
 * throws. Says the status, whether the exception's text (or a path) was
 * printed, and whether the portal recorded the exception: without that,
 * "not printed" would prove nothing.
 *
 * @return array{status:int, printed:bool, recorded:bool, said:string}
 */
function exceptionOnAPage(string $jar): array
{
    $before = thrownRows();
    $answer = req($jar, 'GET', '/offline?debug=true&selftestThrow=1&m=' . MARKER);

    return [
        'status'   => $answer['status'],
        'printed'  => leaks($answer),
        'recorded' => thrownRows() === $before + 1,
        'said'     => said($answer) . '; recorded: ' . var_export(thrownRows() === $before + 1, true) . '; first bytes: ' . json_encode(substr(trim($answer['body']), 0, 90)),
    ];
}

/**
 * Ask for the API documentation page, in which this test planted a line that
 * raises a PHP warning. Says whether the page itself came, whether anything
 * leaked into it, and whether PHP reported the warning (to its log).
 *
 * @return array{page:bool, leak:bool, reported:bool, said:string}
 */
function docsPage(string $jar, string $address): array
{
    logSince();
    $answer = req($jar, 'GET', $address);
    $log    = logSince();

    return [
        'page'     => $answer['status'] === 200 && str_contains($answer['body'], 'id="swagger-ui"') === true && str_contains($answer['body'], 'SwaggerUIBundle') === true,
        'leak'     => leaks($answer),
        'reported' => str_contains($log, MARKER) === true && str_contains($log, 'api-docs') === true,
        'said'     => said($answer) . '; leak: ' . var_export(leaks($answer), true) . '; warning reported: ' . var_export(str_contains($log, MARKER), true) . '; first bytes: ' . json_encode(substr(trim($answer['body']), 0, 90)),
    ];
}

/**
 * Load the scratch copy's start-up from a command-line program, with no
 * front controller, and say what it decided ("channel / how"). `$webFolder`
 * is defined as PORTAL_WEBROOT first when it is not null, which is how a
 * tool states its own web folder.
 */
function startUpAlone(?string $webFolder): string
{
    global $child, $sep;
    $code = ($webFolder === null ? '' : 'define("PORTAL_WEBROOT", $_SERVER["argv"][2]); ')
        . 'require $_SERVER["argv"][1]; echo "\nCHANNEL=" . PORTAL_ENV . " / " . PORTAL_ENV_SOURCE . "\n";';
    $env  = getenv();
    unset($env['PORTAL_ENV']);
    $run = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'session.save_path=' . $child . $sep . 'sessions', '-r', $code, '--',
            $child . $sep . 'web' . $sep . '_core' . $sep . 'bootstrap.php', (string) $webFolder],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $child,
        $env
    );
    if (is_resource($run) === false) {
        return 'the program could not be started';
    }
    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    proc_close($run);

    return preg_match('/^CHANNEL=(.+)$/m', $out, $m) === 1 ? $m[1] : 'no answer: ' . substr(trim($out), 0, 200);
}

/** Set one portal-wide setting this run recorded at the start. */
function setting(string $key, string $value): void
{
    global $state;
    q('UPDATE tblSettings SET settingValue = ? WHERE settingID = ?', 'si', [$value, (int) $state['settings'][$key]['settingID']]);
}

// =============================================================================
// 🏗️ Set-up for the pages
// =============================================================================
if (portAnswers($port) === true) {
    refuse("something already answers on port {$port}; this test never drives a server it did not start");
}
foreach (['multisite.enabled' => 'false', 'portal.gatekeeper.enabled' => 'true', 'portal.maintenance.active' => '0'] as $key => $value) {
    $rows = q('SELECT settingID, settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]);
    if (count($rows) !== 1) {
        refuse("the database must hold exactly one portal-wide row for the setting {$key}, as a new install does (found " . count($rows) . ')');
    }
    $state['settings'][$key] = $rows[0];
}
$before           = $state['settings'];
$state['started'] = true;
removeWorld();
foreach ($state['settings'] as $key => $row) {
    setting((string) $key, ['multisite.enabled' => 'false', 'portal.gatekeeper.enabled' => 'true', 'portal.maintenance.active' => '0'][$key]);
}
q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 1)', 'iss', [STAFF, 'Selftest Channel Staff', 'staff.channel@selftest.invalid']);
q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [MEMBER, 'Selftest Channel Member', 'member.channel@selftest.invalid']);
q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 1, 0)', 'iss', [ADMIN, 'Selftest Channel Admin', 'admin.channel@selftest.invalid']);
q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [ORG_ADMIN, 'Selftest Channel Organisation Admin', 'orgadmin.channel@selftest.invalid']);
q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, 1, 1, 0, 0)', 'i', [MEMBER]);
q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, 1, 1, 0, 0)', 'i', [ADMIN]);
q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, 1, 1, 1, 0)', 'i', [ORG_ADMIN]);
$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
foreach (['staff' => STAFF, 'member' => MEMBER, 'admin' => ADMIN, 'orgadmin' => ORG_ADMIN] as $who => $id) {
    q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-channel-' . $who, $hash]);
}
// A switched-off organisation whose key is the name of a folder put in the scratch web folder below. It is
// there for ONE check (the reserved-key list) and, being switched off, changes nothing else.
q('INSERT INTO tblSites (siteID, siteKey, siteName, hostPattern, isActive) VALUES (?, ?, ?, NULL, 0)', 'iss', [ORG_PARKED, PARKED_KEY, 'Selftest Channel Parked']);

// The scratch copy of web/, with a throwaway connection file and key.
mkdir($child . $sep . 'web', 0700);
$copied = 0;
$tree   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($webSrc, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($tree as $path => $info) {
    $rel = substr((string) $path, strlen($webSrc) + 1);
    // Never the secrets or the uploads, and never a developer's own channel file: the copy must start with none.
    if (in_array(explode($sep, $rel)[0], ['_auth_keys', '_uploads', '_backups', '_libraries', '.channel'], true) === true) {
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
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\n// Written by tools/channel-selftest.php for one run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));
mkdir($child . $sep . 'web' . $sep . 'public_html' . $sep . PARKED_KEY, 0700);

// Two planted lines, in the SCRATCH copy of the offline page only. The first reads a variable that does not
// exist, which makes PHP raise a warning inside a real page that everybody can reach on every channel. The
// second throws an exception nothing catches, but only when the address asks for it.
$offline = $child . $sep . 'web' . $sep . 'public_html' . $sep . 'offline' . $sep . 'index.php';
$planted = str_replace("declare(strict_types=1);\n?>", "declare(strict_types=1);\necho $" . MARKER . "; // planted by tools/channel-selftest.php\n"
    . "if (isset(\$_GET['selftestThrow']) === true) { throw new \\RuntimeException('" . THROWN . "'); } // planted by tools/channel-selftest.php\n?>", (string) file_get_contents($offline), $plantedTimes);
if ($plantedTimes !== 1 || file_put_contents($offline, $planted) === false) {
    refuse('could not plant the warning in the scratch copy of the offline page (its first lines have changed)');
}
// The same warning, planted in the SCRATCH copy of the API documentation page, just before its first own line
// of work: the one page the web server runs by itself, which must never print PHP's error text.
$docsFile = $child . $sep . 'web' . $sep . 'public_html' . $sep . 'api-docs' . $sep . 'index.php';
$planted  = str_replace('$localOnly = Asset::swaggerUiLocalOnly();', 'echo $' . MARKER . "; // planted by tools/channel-selftest.php\n" . '$localOnly = Asset::swaggerUiLocalOnly();', (string) file_get_contents($docsFile), $plantedTimes);
if ($plantedTimes !== 1 || file_put_contents($docsFile, $planted) === false) {
    refuse('could not plant the warning in the scratch copy of the API documentation page (its first lines have changed)');
}

// The router: imitates the web folder's .htaccess and marks every answer. The web folder is whatever folder
// the server was started on, so the same router serves the renamed folder.
$router = <<<'ROUTER'
<?php
// Written by tools/channel-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Channel-Selftest: __TOKEN__');
$root = (string) realpath((string) $_SERVER['DOCUMENT_ROOT']);
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#(^|/)\.(?!well-known)#', $uri) === 1) { http_response_code(403); exit('403'); }
if (preg_match('#\.(php\d?|phtml|phar)$#i', $uri) === 1 && in_array($uri, ['/index.php', '/api-docs/index.php', '/error.php'], true) === false) { http_response_code(404); exit('404'); }
// Apache answers /api-docs/ with that folder's OWN index.php and never hands it to the portal's index.php (the
// folder is real: see the web folder's .htaccess). Any other spelling of the address, /api-docs// for one, is
// left to fall through to index.php, which is how the test reaches the same page through the portal's router.
if ($uri === '/api-docs' || $uri === '/api-docs/') { $uri = '/api-docs/index.php'; }
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

// =============================================================================
// A. THE WEB FOLDER IS NAMED public_html, AND NOTHING ELSE SAYS
// =============================================================================
head('A. A web folder named public_html, no setting, no file: live, decided by the folder\'s name');
channelFile(null);
startServer(null, 'public_html');
echo 'server process ' . (int) proc_get_status($state['proc'])['pid'] . ', port ' . $port . ', PHP ' . PHP_VERSION . ', database ' . (string) q('SELECT VERSION() AS v')[0]['v'] . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";

ok('A01 /health says the live channel, decided by "folder"', healthSays() === '200 prod / folder', healthSays());
$public = req('', 'GET', '/manifest.json');
ok('A02 the manifest answers without a sign-in: there is no staff gate on the live channel', $public['status'] === 200 && str_contains($public['body'], '"icons"') === true, said($public));
$staff    = signIn('staff');
$admin    = signIn('admin');
$orgAdmin = signIn('orgadmin');
$member   = signIn('member');
$notIn    = array_keys(array_filter(['the Root Admin' => $staff, 'the Admin' => $admin, 'the administrator of one organisation' => $orgAdmin, 'the member' => $member], static fn (array $who): bool => $who['in'] === false));
ok('A03 the four accounts this test uses all sign in (a Root Admin, an Admin, an administrator of one organisation, an ordinary member)', $notIn === [], 'could not sign in: ' . implode(', ', $notIn));
$page = req($member['jar'], 'GET', '/dashboard');
ok('A04 the ordinary member gets the dashboard', $page['status'] === 200 && str_contains($page['body'], '<h1') === true, said($page));
$seen = warningOnAPage();
ok('A05 a PHP warning raised inside a real page is NOT printed into it (and was raised: PHP reported it to its log)', $seen['page'] === true && $seen['printed'] === false && $seen['reported'] === true, $seen['said'] . '; reported: ' . var_export($seen['reported'], true));
logSince();
req('', 'GET', '/manifest.json');
ok('A06 the error log gains no line about the channel: the folder\'s name stated it', str_contains(logSince(), 'release channel') === false);
$dash = req($staff['jar'], 'GET', '/admin');
ok('A07 the admin dashboard tells an administrator, in amber, that the channel was worked out from the folder\'s name (public_html, so live) and that a .channel file settles it',
    $dash['status'] === 200 && noteIn($dash) === 'folder' && noteIsAmber($dash) === true && str_contains($dash['body'], '<code>public_html</code>') === true && str_contains($dash['body'], '<strong>live</strong>') === true
    && str_contains($dash['body'], '<code>.channel</code>') === true && str_contains($dash['body'], $child) === false, said($dash) . '; the note says: ' . var_export(noteIn($dash), true));
$docs = docsPage('', '/api-docs/');
ok('A08 the API documentation page (/api-docs/, which the web server runs by itself) answers: no fatal error, no file path, and a PHP warning raised inside it is not printed',
    $docs['page'] === true && $docs['leak'] === false && $docs['reported'] === true, $docs['said']);
$docsFile = docsPage('', '/api-docs/index.php');
$docsVia  = docsPage('', '/api-docs//');
ok('A09 the same page by its file name, and when the portal\'s own router includes it (a server that hands every address to index.php): both answer, neither prints anything',
    $docsFile['page'] === true && $docsFile['leak'] === false && $docsVia['page'] === true && $docsVia['leak'] === false, 'by file name: ' . $docsFile['said'] . ' | through the router: ' . $docsVia['said']);

// =============================================================================
// B. THE FILE SAYS BETA: THE STAFF GATE
// =============================================================================
head('B. The file says beta (the folder is still public_html): the file beats the folder, and the staff gate is on');
channelFile("beta\n");
ok('B01 /health says beta, decided by the file: the file is asked before the folder\'s name', healthSays() === '200 beta / file', healthSays());
$public = req('', 'GET', '/manifest.json');
ok('B02 an address that needs no sign-in on a live portal (the manifest) is behind the staff sign-in', sentToSignIn($public) === true && str_contains($public['body'], '"icons"') === false, said($public));
$front = req('', 'GET', '/');
ok('B03 the front page sends a visitor to the sign-in page', sentToSignIn($front) === true, said($front));
$again = signIn('member');
ok('B04 the sign-in page still answers behind the gate, and an ordinary member\'s password is still accepted', $again['form']['status'] === 200 && $again['in'] === true, 'the form: ' . said($again['form']) . '; signing in: ' . said($again['answer']));
$page = req($member['jar'], 'GET', '/dashboard');
ok('B05 that member is then REFUSED the dashboard (403): they are not staff', $page['status'] === 403, said($page));
$page = req($member['jar'], 'GET', '/manifest.json');
ok('B06 and refused the manifest too: the gate is in front of every address, not only the protected ones', $page['status'] === 403, said($page));
$page = req($orgAdmin['jar'], 'GET', '/dashboard');
ok('B07 an administrator of ONE organisation is refused as well: being in charge of one organisation is not being staff for the installation', $page['status'] === 403, said($page));
$page = req($staff['jar'], 'GET', '/dashboard');
ok('B08 a Root Admin passes the gate and gets the dashboard', $page['status'] === 200 && str_contains($page['body'], '<h1') === true, said($page));
$page = req($admin['jar'], 'GET', '/dashboard');
ok('B09 an account with the Admin flag alone passes the gate too', $page['status'] === 200 && str_contains($page['body'], '<h1') === true, said($page));
$page = req($staff['jar'], 'GET', '/admin/upgrade');
ok('B10 the Root Admin reaches the database upgrade page', $page['status'] === 200 && stripos($page['body'], 'upgrade') !== false && str_contains($page['body'], '<h1') === true, said($page));
$page = req($admin['jar'], 'GET', '/admin/upgrade');
ok('B11 the Admin flag alone does NOT: passing the gate is not being allowed every page behind it (403)', $page['status'] === 403, said($page));
$seen = warningOnAPage();
ok('B12 on a pre-release copy that was stated, a PHP warning IS printed, and to a VISITOR, on an address the gate leaves open. (Also the control: the checks that say "not printed" can see it when it is.)',
    $seen['page'] === true && $seen['printed'] === true && $seen['reported'] === true, $seen['said']);
logSince();
req('', 'GET', '/manifest.json');
ok('B13 the error log gains no line about the channel: this one was stated', str_contains(logSince(), 'release channel') === false);
// The addresses machines use are behind the gate as well, each with its own secret or not.
$job = req('', 'GET', '/cron/health?token=not-the-token');
ok('B14 a scheduled job\'s address is behind the gate: it is sent to the sign-in page and never reaches its own token check', sentToSignIn($job) === true, said($job));
$apiAddresses = ['/api/selftest/nothing', '/api/v1/selftest', '/api', '/api/'];
$apiPast      = [];
foreach ($apiAddresses as $address) {
    $answer = req('', 'GET', $address);
    if (sentToSignIn($answer) === false) {
        $apiPast[] = $address . ' gave ' . said($answer);
    }
}
ok('B15 every address under /api/ is behind the gate too: a visitor is sent to the sign-in page, not answered by the API', $apiPast === [], implode('; ', $apiPast));
$docs = req('', 'GET', '/api-docs/');
$mine = docsPage($staff['jar'], '/api-docs/');
ok('B16 the API documentation page is behind the gate as well, though the web server runs it by itself: a visitor is sent to sign in, the Root Admin gets the page, and nothing is printed into it',
    sentToSignIn($docs) === true && str_contains($docs['body'], 'swagger-ui') === false && $mine['page'] === true && $mine['leak'] === false, 'visitor: ' . said($docs) . ' | Root Admin: ' . $mine['said']);
// What a visitor sends does not change the channel: the names the setting has, and the name PHP gives a header.
$claims = ['PORTAL_ENV: prod', 'Portal-Env: prod', 'X-Portal-Env: live', 'REDIRECT_PORTAL_ENV: prod', 'Cookie: PORTAL_ENV=prod'];
$health = healthSays($claims, '?PORTAL_ENV=prod');
$public = req('', 'GET', '/manifest.json?PORTAL_ENV=prod', null, $claims);
$posted = req('', 'POST', '/manifest.json', ['PORTAL_ENV' => 'prod'], $claims);
ok('B17 a visitor who SAYS the copy is live (a request header, the address, a cookie, a form field, each named like the setting) is not believed: still beta, still gated',
    $health === '200 beta / file' && sentToSignIn($public) === true && sentToSignIn($posted) === true, $health . '; the manifest: ' . said($public) . '; posted: ' . said($posted));

// The switch. A row that is MISSING is not "off": a database from the last release has no such row.
$switchId = (int) $state['settings']['portal.gatekeeper.enabled']['settingID'];
q('UPDATE tblSettings SET settingKey = ? WHERE settingID = ?', 'si', ['portal.gatekeeper.enabled.selftest-away', $switchId]);
$rowsLeft = (int) q("SELECT COUNT(*) AS n FROM tblSettings WHERE settingKey = 'portal.gatekeeper.enabled'")[0]['n'];
$public   = req('', 'GET', '/manifest.json');
q('UPDATE tblSettings SET settingKey = ? WHERE settingID = ?', 'si', ['portal.gatekeeper.enabled', $switchId]);
ok('B18 with NO portal.gatekeeper.enabled row at all (as on a database from the last release) the gate is still ON: a missing switch is not "switched off"', $rowsLeft === 0 && sentToSignIn($public) === true, 'rows of that name while it was away: ' . $rowsLeft . '; the manifest: ' . said($public));
setting('portal.gatekeeper.enabled', 'false');
$public = req('', 'GET', '/manifest.json');
ok('B19 with the switch set to false the gate is off: a global administrator\'s own decision about the whole installation', $public['status'] === 200 && str_contains($public['body'], '"icons"') === true, said($public));
setting('portal.gatekeeper.enabled', 'true');
$public = req('', 'GET', '/manifest.json');
ok('B20 and with the switch back on, the gate is back', sentToSignIn($public) === true, said($public));

// The portal closed for an upgrade, on a gated copy: what a pre-release copy is the moment new code is uploaded.
setting('portal.maintenance.active', '1');
ok('B21 while the portal is closed for an upgrade, /health still answers, and still says how the channel was decided', healthSays() === '200 beta / file', healthSays());
$near = [];
foreach (['/healthx', '/health/extra', '/manifest.json', '/api-docs/'] as $address) {
    $answer = req('', 'GET', $address);
    if ($answer['status'] !== 503) {
        $near[] = $address . ' gave ' . said($answer);
    }
}
ok('B22 only that exact address: /healthx, /health/extra, the manifest and the API documentation page get the holding page (503)', $near === [], implode('; ', $near));
$page = req($member['jar'], 'GET', '/dashboard');
ok('B23 the signed-in member gets the holding page', $page['status'] === 503, said($page));
$fresh = signIn('staff');
$page  = req($fresh['jar'], 'GET', '/admin/upgrade');
ok('B24 a Root Admin can still SIGN IN while it is closed and gated, and reach the upgrade page',
    $fresh['form']['status'] === 200 && $fresh['in'] === true && $page['status'] === 200 && stripos($page['body'], 'upgrade') !== false, 'the form: ' . said($fresh['form']) . '; signing in: ' . said($fresh['answer']) . '; the upgrade page: ' . said($page));
setting('portal.maintenance.active', '0');

// =============================================================================
// C. THE FILE SAYS LIVE
// =============================================================================
head('C. The file says live: open, errors hidden');
channelFile("live\n");
ok('C01 /health says the live channel, decided by the file', healthSays() === '200 prod / file', healthSays());
$manifestB = req('', 'GET', '/manifest.json');
ok('C02 the manifest answers without a sign-in', $manifestB['status'] === 200 && is_array(json_decode($manifestB['body'], true)) === true, said($manifestB));
$page = req($member['jar'], 'GET', '/dashboard');
ok('C03 the ordinary member gets the dashboard', $page['status'] === 200 && str_contains($page['body'], '<h1') === true, said($page));
$seen = warningOnAPage();
ok('C04 the PHP warning is not printed (and was raised)', $seen['page'] === true && $seen['printed'] === false && $seen['reported'] === true, $seen['said'] . '; reported: ' . var_export($seen['reported'], true));
logSince();
req('', 'GET', '/manifest.json');
ok('C05 the error log gains no line about the channel', str_contains(logSince(), 'release channel') === false);
$dash = req($staff['jar'], 'GET', '/admin');
ok('C06 the admin dashboard shows NO note: the channel is written down', $dash['status'] === 200 && noteIn($dash) === '', said($dash) . '; the note says: ' . var_export(noteIn($dash), true));
$job = req('', 'GET', '/cron/health?token=not-the-token');
ok('C07 on the live channel the scheduled job\'s address reaches its own page, which refuses the wrong token itself (403)', $job['status'] === 403 && sentToSignIn($job) === false, said($job));
$apiPast = [];
foreach ($apiAddresses as $address) {
    $answer = req('', 'GET', $address);
    if (sentToSignIn($answer) === true) {
        $apiPast[] = $address;
    }
}
ok('C08 CONTROL for B15: on the live channel the same /api/ addresses are answered by the API or the router, not sent to the staff sign-in', $apiPast === [], 'sent to sign in: ' . implode(', ', $apiPast));
// Kept for part K: the same answers must come from a web folder with another name.
$routedB  = routedFromWebFolder();
$offlineB = req($member['jar'], 'GET', '/offline');

channelFile(BOM . "live\r\n");
ok('C09 the same file as Notepad saves it (a byte-order mark, a Windows line end): still live, decided by the file', healthSays() === '200 prod / file', healthSays());
channelFile("\nbeta\n");
$public = req('', 'GET', '/manifest.json');
ok('C10 a file whose FIRST line is blank, with beta on the second: the second line is not believed, so the folder\'s name decides (live)', healthSays() === '200 prod / folder' && $public['status'] === 200, healthSays() . '; the manifest: ' . said($public));
channelFile("bta\n");
ok('C11 a file holding a word nobody knows counts as not there: the folder\'s name decides', healthSays() === '200 prod / folder', healthSays());
channelFile(str_repeat(' ', 252) . 'betamax');
ok('C12 a file whose first line is cut off at the edge of what is read (252 spaces, then "betamax") is not read as beta: the folder\'s name decides', healthSays() === '200 prod / folder', healthSays());

// =============================================================================
// E. THE WEB FOLDER IS NAMED public_html_beta
// =============================================================================
head('E. The web folder renamed public_html_beta, no setting, no file: beta, decided by the folder\'s name');
channelFile(null);
webFolderBecomes('public_html_beta');
ok('E01 /health says beta, decided by "folder"', is_file($child . $sep . 'web' . $sep . 'public_html_beta' . $sep . 'index.php') === true && healthSays() === '200 beta / folder', healthSays());
$public = req('', 'GET', '/manifest.json');
$page   = req($member['jar'], 'GET', '/dashboard');
$mine   = req($staff['jar'], 'GET', '/dashboard');
ok('E02 the staff gate is ON for a beta copy however it was decided: a visitor is sent to sign in, the member is refused (403), the Root Admin gets in',
    sentToSignIn($public) === true && $page['status'] === 403 && $mine['status'] === 200, 'visitor: ' . said($public) . '; member: ' . said($page) . '; Root Admin: ' . said($mine));
$seen = warningOnAPage();
ok('E03 PHP warnings are shown here, as the last release showed them for this folder name: a folder name is a statement, like the setting and the file', $seen['page'] === true && $seen['printed'] === true && $seen['reported'] === true, $seen['said']);
logSince();
req('', 'GET', '/manifest.json');
ok('E04 the error log gains no line saying nothing states the channel: the folder\'s name does', str_contains(logSince(), 'release channel') === false);
$dash = req($staff['jar'], 'GET', '/admin');
ok('E05 the admin dashboard\'s amber note names the folder (public_html_beta) and the copy (beta)',
    $dash['status'] === 200 && noteIn($dash) === 'folder' && noteIsAmber($dash) === true && str_contains($dash['body'], '<code>public_html_beta</code>') === true && str_contains($dash['body'], '<strong>beta</strong>') === true, said($dash) . '; the note says: ' . var_export(noteIn($dash), true));
$health = healthSays($claims, '?PORTAL_ENV=prod');
$public = req('', 'GET', '/manifest.json?PORTAL_ENV=prod', null, $claims);
ok('E06 a visitor who says the copy is live is not believed here either: still beta, still gated', $health === '200 beta / folder' && sentToSignIn($public) === true, $health . '; the manifest: ' . said($public));
channelFile("live\n");
$public = req('', 'GET', '/manifest.json');
ok('E07 a file saying live beats the folder\'s name: live, decided by the file, open', healthSays() === '200 prod / file' && $public['status'] === 200, healthSays() . '; the manifest: ' . said($public));
channelFile("bta\n");
$public = req('', 'GET', '/manifest.json');
ok('E08 a file holding a word nobody knows falls through to the folder\'s name: beta again, gated again', healthSays() === '200 beta / folder' && sentToSignIn($public) === true, healthSays() . '; the manifest: ' . said($public));
channelFile(null);

// =============================================================================
// G. THE WEB FOLDER IS NAMED public_html_dev
// =============================================================================
head('G. The web folder renamed public_html_dev: dev, decided by the folder\'s name');
webFolderBecomes('public_html_dev');
$public = req('', 'GET', '/manifest.json');
$mine   = req($staff['jar'], 'GET', '/dashboard');
ok('G01 /health says dev (not alpha), decided by "folder", and the staff gate is on', healthSays() === '200 dev / folder' && sentToSignIn($public) === true && $mine['status'] === 200, healthSays() . '; visitor: ' . said($public) . '; Root Admin: ' . said($mine));

// =============================================================================
// J. NOTHING SAYS THE CHANNEL: ASSUMED LIVE
// =============================================================================
head('J. The web folder renamed admin_html, no setting, no file: nothing says, so the portal assumes it is live');
webFolderBecomes('admin_html');
ok('J01 /health says the live channel, and says it was only "assumed"', file_exists($child . $sep . 'web' . $sep . 'public_html') === false && healthSays() === '200 prod / assumed', healthSays());
$public = req('', 'GET', '/manifest.json');
$job    = req('', 'GET', '/cron/health?token=not-the-token');
ok('J02 nobody is locked out for want of a file: the manifest answers without a sign-in, and a scheduled job\'s address reaches its own token check (403), not the staff gate',
    $public['status'] === 200 && str_contains($public['body'], '"icons"') === true && $job['status'] === 403 && sentToSignIn($job) === false, 'the manifest: ' . said($public) . '; the job: ' . said($job));
$page = req($member['jar'], 'GET', '/dashboard');
ok('J03 the ordinary member gets the dashboard: the staff gate is OFF when the channel was only assumed', $page['status'] === 200 && str_contains($page['body'], '<h1') === true, said($page));
$seen = warningOnAPage();
ok('J04 a PHP warning raised inside a real page is NOT printed into it', $seen['page'] === true && $seen['printed'] === false, $seen['said']);
ok('J05 … and the warning really was raised: PHP reported it to its log', $seen['reported'] === true, 'the log gained nothing naming ' . MARKER);
logSince();
req('', 'GET', '/manifest.json');
$said = array_values(array_filter(explode("\n", logSince()), static fn (string $line): bool => str_contains($line, 'Nothing says which release channel') === true));
ok('J06 PHP\'s error log gains ONE line for that one request, saying the channel was assumed, what that leaves open, and what settles it',
    count($said) === 1 && str_contains($said[0], 'LIVE') === true && str_contains($said[0], Channel::FILE_NAME) === true && str_contains($said[0], 'open to the public') === true && str_contains($said[0], $child) === false, count($said) . ' line(s): ' . substr(implode(' | ', $said), 0, 300));

// ?debug=true must give NOBODY anything on a copy whose channel was only assumed: no debug panel, no PHP
// warning text and no exception text. Part M shows each of the three appearing on a stated dev copy, so
// "nothing" here is not a page that never had anything to show.
$people = ['a visitor' => '', 'an ordinary member' => $member['jar'], 'an administrator of one organisation' => $orgAdmin['jar'], 'an Admin' => $admin['jar'], 'a Root Admin' => $staff['jar']];
$number = 7;
$recordedBefore = thrownRows();
foreach ($people as $who => $jar) {
    $got = [];
    foreach ($jar === '' ? ['/help', '/login'] : ['/help', '/dashboard', '/admin', '/admin/upgrade'] as $address) {
        $answer = req($jar, 'GET', $address . '?debug=true&m=' . MARKER);
        if (panelIn($answer) === true || leaks($answer) === true || in_array($answer['status'], [200, 403], true) === false) {
            $got[] = $address . ' (' . said($answer) . (panelIn($answer) === true ? ', the debug panel' : '') . (leaks($answer) === true ? ', error text or a path' : '') . ')';
        }
    }
    $seen   = warningOnAPage($jar, '?debug=true&m=' . MARKER);
    $thrown = exceptionOnAPage($jar);
    ok(sprintf('J%02d ?debug=true gives %s NOTHING: no debug panel on any page tried, no PHP warning text, and the error page (500), not the text, for an uncaught exception', $number, $who),
        $got === [] && $seen['page'] === true && $seen['printed'] === false && $seen['reported'] === true && $thrown['status'] === 500 && $thrown['printed'] === false,
        'pages: ' . implode('; ', $got) . ' | the warning: ' . $seen['said'] . ' | the exception: ' . $thrown['said']);
    $number++;
}
ok('J12 … and the exception really was raised each of the five times: the portal recorded it for an administrator to read', thrownRows() === $recordedBefore + 5, 'rows gained: ' . (thrownRows() - $recordedBefore));
$claimsDev = ['PORTAL_ENV: dev', 'Portal-Env: dev', 'X-Portal-Env: dev', 'REDIRECT_PORTAL_ENV: dev', 'Cookie: PORTAL_ENV=dev'];
$health    = healthSays($claimsDev, '?PORTAL_ENV=dev');
$seen      = warningOnAPage('', '?PORTAL_ENV=dev&debug=true&m=' . MARKER, $claimsDev);
ok('J13 a visitor who SAYS the copy is a dev copy (a header, the address, a cookie) is not believed: still assumed live, still nothing printed', $health === '200 prod / assumed' && $seen['page'] === true && $seen['printed'] === false && $seen['reported'] === true, $health . '; the warning: ' . $seen['said']);

// The amber note, and who sees it.
$dash = req($staff['jar'], 'GET', '/admin');
ok('J14 the admin dashboard tells the Root Admin, in amber and not in red, that the channel was assumed, that a .channel file or the setting settles it, and NOT to create the file where copies share one _core',
    $dash['status'] === 200 && noteIn($dash) === 'assumed' && noteIsAmber($dash) === true && str_contains($dash['body'], 'has not been told which copy it is') === true && str_contains($dash['body'], '<code>.channel</code>') === true
    && str_contains($dash['body'], '<code>PORTAL_ENV</code>') === true && str_contains($dash['body'], 'do not create the file') === true && str_contains($dash['body'], $child) === false, said($dash) . '; the note says: ' . var_export(noteIn($dash), true));
$forAdmin = req($admin['jar'], 'GET', '/admin');
$forOrg   = req($orgAdmin['jar'], 'GET', '/admin');
ok('J15 an Admin and an administrator of one organisation, who can both open the admin dashboard, see the note too', $forAdmin['status'] === 200 && noteIn($forAdmin) === 'assumed' && $forOrg['status'] === 200 && noteIn($forOrg) === 'assumed',
    'Admin: ' . said($forAdmin) . ' note ' . var_export(noteIn($forAdmin), true) . '; organisation administrator: ' . said($forOrg) . ' note ' . var_export(noteIn($forOrg), true));
$shown = [];
foreach (['the member, /admin' => [$member['jar'], '/admin', 403], 'the member, /dashboard' => [$member['jar'], '/dashboard', 200], 'the member, /help' => [$member['jar'], '/help', 200],
    'a visitor, /login' => ['', '/login', 200], 'a visitor, /help' => ['', '/help', 200], 'a visitor, /offline' => ['', '/offline', 200]] as $what => [$jar, $address, $status]) {
    $answer = req($jar, 'GET', $address);
    if ($answer['status'] !== $status || noteIn($answer) !== '' || str_contains($answer['body'], 'has not been told which copy') === true) {
        $shown[] = $what . ' (' . said($answer) . ', note ' . var_export(noteIn($answer), true) . ')';
    }
}
ok('J16 nobody who is not an administrator is shown the note: not the member (refused the admin dashboard, 403; nothing on their own pages), and not a visitor', $shown === [], implode('; ', $shown));
$docs = docsPage('', '/api-docs/');
ok('J17 the API documentation page answers a visitor here as on any live portal, and prints nothing', $docs['page'] === true && $docs['leak'] === false && $docs['reported'] === true, $docs['said']);

// =============================================================================
// K. A WEB FOLDER OF ANOTHER NAME, WITH A FILE
// =============================================================================
head('K. The web folder still named admin_html, and the file says live: nothing looks for the old name');
channelFile("live\n");
logSince();

ok('K01 there is no folder named public_html in this installation', file_exists($child . $sep . 'web' . $sep . 'public_html') === false && is_file($child . $sep . 'web' . $sep . 'admin_html' . $sep . 'index.php') === true);
ok('K02 /health answers: live, decided by the file', healthSays() === '200 prod / file', healthSays());
$form = req('', 'GET', '/login');
ok('K03 the sign-in page answers', $form['status'] === 200 && str_contains($form['body'], 'name="csrf_token"') === true, said($form));
$page = req($member['jar'], 'GET', '/dashboard');
ok('K04 the member, still signed in, gets the dashboard', $page['status'] === 200 && str_contains($page['body'], '<h1') === true, said($page));
$offlineC = req($member['jar'], 'GET', '/offline');
ok('K05 the offline page answers (the router finds it in the renamed folder)', $offlineC['status'] === 200 && str_contains($offlineC['body'], '<title>Offline') === true, said($offlineC));
$varyAll = static fn (array $answer): bool => preg_match('/^Vary:\s*\*\s*$/mi', $answer['head']) === 1;
ok('K06 a signed-in person\'s offline page is still the one page WITHOUT "Vary: *" (the sign-in code finds it in the renamed folder), as before the rename',
    $varyAll($offlineC) === false && $varyAll($offlineB) === false && $varyAll($page) === true, 'offline now: ' . var_export($varyAll($offlineC), true) . '; offline before: ' . var_export($varyAll($offlineB), true) . '; the dashboard (control, must be true): ' . var_export($varyAll($page), true));
$manifestC = req('', 'GET', '/manifest.json');
ok('K07 the manifest answers, byte for byte as before the rename', $manifestC['status'] === 200 && $manifestC['body'] === $manifestB['body'], said($manifestC) . '; the same bytes: ' . var_export($manifestC['body'] === $manifestB['body'], true));
ok('K08 … and it names the brand\'s own icons, which it only does when it finds them on disk in the web folder', str_contains($manifestC['body'], 'brandkit') === true && str_contains($manifestC['body'], 'icon-192.svg') === true, substr($manifestC['body'], 0, 200));
$routedC = routedFromWebFolder();
ok('K09 robots.txt, the sitemap and the API description, whose pages live in the web folder, are each still answered by their OWN page, as before the rename',
    $routedC === $routedB && $routedC === ['/robots.txt' => '200 own page', '/sitemap.xml' => '404 own page', '/openapi.json' => '200 own page'], 'now ' . json_encode($routedC) . '; before ' . json_encode($routedB));
$error = req('', 'GET', '/error.php?code=404');
ok('K10 the web server\'s own error page (/error.php) answers 404 with its themed page', $error['status'] === 404 && str_contains($error['body'], '404') === true && str_contains($error['body'], 'Fatal error') === false, said($error) . '; first bytes: ' . json_encode(substr(trim($error['body']), 0, 90)));
$sites = req($staff['jar'], 'GET', '/admin/sites');
ok('K11 the reserved-key list reads the RENAMED folder: an organisation whose key is the name of a folder in it is flagged',
    $sites['status'] === 200 && str_contains($sites['body'], 'a real file or folder called &quot;' . PARKED_KEY . '&quot; exists in the web root') === true, said($sites) . '; the organisation is listed: ' . var_export(str_contains($sites['body'], PARKED_KEY), true));
$seen = warningOnAPage();
ok('K12 the PHP warning is not printed here either (and was raised)', $seen['page'] === true && $seen['printed'] === false && $seen['reported'] === true, $seen['said']);
$stray = array_values(array_filter(explode("\n", trim(logSince())), static fn (string $line): bool => $line !== '' && str_contains($line, MARKER) === false));
ok('K13 nothing else was written to PHP\'s error log in the renamed folder: no file was looked for and not found, and no line about the channel', $stray === [], implode(' | ', array_slice($stray, 0, 3)));
channelFile("beta\n");
$public = req('', 'GET', '/manifest.json');
$mine   = req($staff['jar'], 'GET', '/dashboard');
ok('K14 the same renamed folder with a file saying beta: beta, decided by the file, gated', healthSays() === '200 beta / file' && sentToSignIn($public) === true && $mine['status'] === 200, healthSays() . '; visitor: ' . said($public) . '; Root Admin: ' . said($mine));
channelFile(null);

// =============================================================================
// L. NAMES THAT ARE NEARLY ONE OF THE THREE
// =============================================================================
head('L. Real web folders whose names are nearly one of the three: none is believed');
$number = 1;
foreach (['public_html_old' => 'it only starts with a known name', 'my_public_html' => 'it only ends with one', 'Public_HTML_Beta' => 'capitals are not folded'] as $name => $why) {
    webFolderBecomes($name);
    $public = req('', 'GET', '/manifest.json');
    ok(sprintf('L%02d a web folder really named %s is not a known name (%s): assumed live, no staff gate', $number, $name, $why), healthSays() === '200 prod / assumed' && $public['status'] === 200, healthSays() . '; the manifest: ' . said($public));
    $number++;
}

// =============================================================================
// M. THE SETTING
// =============================================================================
head('M. The setting says dev while the file says live (the folder is public_html again): the controls');
stopServer();
if (rename($child . $sep . 'web' . $sep . $state['webFolder'], $child . $sep . 'web' . $sep . 'public_html') === false) {
    refuse('could not give the scratch web folder its first name back');
}
$state['webFolder'] = 'public_html';
channelFile("live\n");
startServer('dev', 'public_html');
ok('M01 the setting wins over the file and the folder: /health says dev, decided by the environment', healthSays() === '200 dev / environment', healthSays());
$public = req('', 'GET', '/manifest.json');
ok('M02 the gate is on', sentToSignIn($public) === true, said($public));
$seen = warningOnAPage();
ok('M03 CONTROL: on a pre-release channel that was stated, the planted warning IS printed into the page, so the checks above can see it when it is', $seen['page'] === true && $seen['printed'] === true && $seen['reported'] === true, $seen['said']);
logSince();
req('', 'GET', '/manifest.json');
ok('M04 the error log gains no line about the channel: this one was stated', str_contains(logSince(), 'release channel') === false);
$page   = req($staff['jar'], 'GET', '/dashboard?debug=true&m=' . MARKER);
$thrown = exceptionOnAPage($staff['jar']);
ok('M05 CONTROL for part J: on this stated dev copy ?debug=true DOES give the Root Admin the debug panel and the text of an uncaught exception, so part J would have seen either',
    $page['status'] === 200 && panelIn($page) === true && $thrown['printed'] === true, 'the dashboard: ' . said($page) . ', the panel: ' . var_export(panelIn($page), true) . ' | the exception: ' . $thrown['said']);
$docs    = docsPage($staff['jar'], '/api-docs/');
$docsVia = docsPage($staff['jar'], '/api-docs//');
$other   = req('', 'GET', '/api-docs/');
ok('M06 the API documentation page never prints PHP\'s error text itself, even here where M03 shows other pages do: the Root Admin gets the page with the planted warning raised and NOT printed, both when the web server runs the page and when the portal\'s router includes it; a visitor is sent to sign in',
    $docs['page'] === true && $docs['leak'] === false && $docs['reported'] === true && $docsVia['page'] === true && $docsVia['leak'] === false && $docsVia['reported'] === true && sentToSignIn($other) === true,
    'Root Admin: ' . $docs['said'] . ' | through the router: ' . $docsVia['said'] . ' | visitor: ' . said($other));

head('M. The setting says prod while the file says beta');
channelFile("beta\n");
startServer('prod', 'public_html');
$public = req('', 'GET', '/manifest.json');
$seen   = warningOnAPage();
ok('M07 the setting wins: live, decided by the environment, open, errors hidden', healthSays() === '200 prod / environment' && $public['status'] === 200 && $seen['printed'] === false && $seen['reported'] === true, healthSays() . '; the manifest: ' . said($public) . '; the warning: ' . $seen['said']);

head('M. The setting holds a word nobody knows');
channelFile("beta\n");
startServer('staging', 'public_html');
$public = req('', 'GET', '/manifest.json');
ok('M08 an unknown word in the setting counts as not there: the FILE is asked, and says beta, gated', healthSays() === '200 beta / file' && sentToSignIn($public) === true, healthSays() . '; the manifest: ' . said($public));
channelFile(null);
$public = req('', 'GET', '/manifest.json');
$seen   = warningOnAPage();
ok('M09 … and with no file either, the FOLDER\'s name is asked: public_html, so live, open, errors hidden', healthSays() === '200 prod / folder' && $public['status'] === 200 && $seen['printed'] === false && $seen['reported'] === true, healthSays() . '; the manifest: ' . said($public) . '; the warning: ' . $seen['said']);
stopServer();

// =============================================================================
// N. NO FRONT CONTROLLER
// =============================================================================
head('N. A command-line program that loads start-up by itself (no front controller says which folder is the web folder)');
channelFile(null);
ok('N01 with no setting and no file it is assumed live. The web folder on disk IS named public_html here, and start-up\'s own stand-in name is public_html too: neither reaches the decision',
    is_dir($child . $sep . 'web' . $sep . 'public_html') === true && startUpAlone(null) === 'prod / assumed', startUpAlone(null));
channelFile("beta\n");
ok('N02 the file still speaks to such a program: beta, decided by the file', startUpAlone(null) === 'beta / file', startUpAlone(null));
channelFile(null);
ok('N03 a program that STATES its web folder before loading start-up is answered by that folder\'s name', startUpAlone($child . $sep . 'web' . $sep . 'public_html_beta') === 'beta / folder', startUpAlone($child . $sep . 'web' . $sep . 'public_html_beta'));

// =============================================================================
// 🧹 The clean-up
// =============================================================================
head('Clean-up');
cleanup();
$left = (int) (q('SELECT (SELECT COUNT(*) FROM tblUsers WHERE userID BETWEEN 918001 AND 918099) + (SELECT COUNT(*) FROM tblUserSites WHERE userID BETWEEN 918001 AND 918099) '
    . '+ (SELECT COUNT(*) FROM tblLocalAccounts WHERE userID BETWEEN 918001 AND 918099) + (SELECT COUNT(*) FROM tblSites WHERE siteID = ' . ORG_PARKED . ') '
    . "+ (SELECT COUNT(*) FROM tblActivityLogs WHERE userID BETWEEN 918001 AND 918099 OR activityDescription LIKE '%" . MARKER . "%') + (SELECT COUNT(*) FROM tblErrors WHERE errorTitle LIKE '%" . MARKER . "%' OR errorDetail LIKE '%" . MARKER . "%') AS n")[0]['n'] ?? -1);
$settingsBack = true;
foreach ($before as $key => $row) {
    $settingsBack = $settingsBack && (q('SELECT settingID, settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [(string) $key]) === [$row]);
}
ok('clean-up: this test\'s accounts, organisation, log and error rows are gone, and the three portal-wide settings are as they were, name and value', $left === 0 && $settingsBack === true, 'rows left: ' . $left . '; settings back: ' . ($settingsBack === true ? 'yes' : 'NO'));
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
echo "PASS — all {$pass} checks passed. The run reached its end.\n";
exit(0);
