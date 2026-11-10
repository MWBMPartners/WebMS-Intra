<?php
// Path: tools/attendance-access-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Who may see an organisation's attendance totals — self-test 📊 (#529, D-106)
 * -----------------------------------------------------------------------------
 * Standalone and dependency-free: no database, no network, no start-up code.
 *
 *   1. The rule itself, `AttendanceAccess::decide()`, for EVERY combination of
 *      its three facts (a member? an administrator? holding the permission?),
 *      each expected answer written out by hand rather than worked out here.
 *   2. The three pages that show the totals ask the ONE method, with the
 *      whole condition pinned — comments removed with PHP's own tokeniser, so
 *      an explanation quoting the call cannot satisfy the check — and each
 *      refuses with the ordinary "page not found".
 *   3. The setting this replaced (`attend.reports.visibleTo`) is read by
 *      nothing under web/ any more. A setting nothing reads is a lie with a
 *      Save button (#671).
 *
 * WHY THE PAGES ARE PINNED AS TEXT AS WELL AS DRIVEN. Section Q of
 * `tools/access-pages-selftest.php` drives every page over real HTTP and is
 * the proof that the rule RUNS. It needs a database server and runs only on
 * a pull request; this file runs in under a second with nothing, so a
 * "fixed back" gate is caught before the push as well. Pinning the WHOLE
 * condition is the house remedy for the one-word bypass (`&& false`,
 * `if (false)`), which a check matching only the call walks straight past.
 *
 * WHAT THIS CANNOT DO: prove the membership query or the permission lookup
 * (both need a database — access-pages section Q does that), or that a page's
 * refusal looks like a page that does not exist (also section Q).
 *
 * Usage:  php tools/attendance-access-selftest.php
 * Exit:   0 if every check passes, 1 if any fails.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/529
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// The class loads on its own: `decide()` touches nothing else, and the other
// two methods are never called here.
require_once __DIR__ . '/../web/_core/AttendanceAccess.php';

use Portal\Core\AttendanceAccess;

$failures = 0;
$passes   = 0;

/**
 * ✅ Report one check.
 */
function check(string $what, bool $isOk, string $detail = ''): void
{
    global $failures, $passes;
    if ($isOk === true) {
        $passes++;
        printf("  PASS  %s\n", $what);
        return;
    }
    $failures++;
    printf("  FAIL  %s\n", $what);
    if ($detail !== '') {
        printf("          %s\n", $detail);
    }
}

/** A PHP file with every comment removed by PHP's own tokeniser. */
function codeOnly(string $path): string
{
    $code = '';
    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) === true && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) === true) {
            continue;
        }
        $code .= is_array($token) === true ? $token[1] : $token;
    }

    return $code;
}

echo "\n" . str_repeat('=', 78) . "\n";
echo "Who may see an organisation's attendance totals — self-test (#529, D-106)\n";
echo str_repeat('=', 78) . "\n\n";

// -----------------------------------------------------------------------------
echo "1. The rule, for every combination of its three facts\n";
// -----------------------------------------------------------------------------
// [is a member, is an administrator, holds the permission, expected]
// Written out by hand: deriving the expected answer here would only prove the
// rule agrees with itself.
$table = [
    [false, false, false, false], // a stranger
    [false, false, true,  false], // holds the role in ANOTHER organisation: still a stranger here
    [false, true,  false, false], // an administrator of another organisation
    [false, true,  true,  false], // both, elsewhere: still nothing here
    [true,  false, false, false], // an ordinary member — D-106 rules this out
    [true,  false, true,  true],  // a member holding the permission
    [true,  true,  false, true],  // an administrator, no role needed
    [true,  true,  true,  true],  // an administrator who also holds it
];
foreach ($table as [$member, $admin, $holds, $expected]) {
    $label = sprintf(
        'member %s, administrator %s, holds the permission %s → %s',
        $member ? 'yes' : 'no',
        $admin ? 'yes' : 'no',
        $holds ? 'yes' : 'no',
        $expected ? 'SEES the totals' : 'refused'
    );
    $actual = AttendanceAccess::decide($member, $admin, $holds);
    check($label, $actual === $expected, 'got ' . var_export($actual, true));
}
check('the table covers all eight combinations, each once', count(array_unique(array_map(
    static fn (array $row): string => json_encode(array_slice($row, 0, 3)),
    $table
))) === 8);

// -----------------------------------------------------------------------------
echo "\n2. Every page that shows the totals asks the ONE method, and refuses with 404\n";
// -----------------------------------------------------------------------------
$web   = dirname(__DIR__) . '/web';
$asked = 'AttendanceAccess::mayViewTotals($mysqli, ';
$pages = [
    'the reports page' => [
        '_apps/attendance/report.php',
        "if (AttendanceAccess::mayViewTotals(\$mysqli, \$viewerId, \$siteId, App::isAdmin(), App::isRootAdmin()) === false) {\n    Router::renderError(404);\n    return;\n}",
    ],
    'the spreadsheet download' => [
        '_apps/attendance/export.php',
        "if (AttendanceAccess::mayViewTotals(\$mysqli, (int) (\$_SESSION['user_id'] ?? 0), Site::id(), App::isAdmin(), App::isRootAdmin()) === false) {\n    Router::renderError(404);\n    exit();\n}",
    ],
    'the attendance page\'s cards' => [
        '_apps/attendance/index.php',
        "\$mayViewReports = AttendanceAccess::mayViewTotals(\$mysqli, \$viewerId, \$siteId, App::isAdmin(), App::isRootAdmin());",
    ],
    // D-109 (#917): the form for an EXISTING session, and its update action.
    'the form for an existing session' => [
        '_apps/attendance/record.php',
        "if (\$editId > 0\n    && AttendanceAccess::mayViewTotals(\$mysqli, (int) (\$_SESSION['user_id'] ?? 0), Site::id(), App::isAdmin(), App::isRootAdmin()) === false) {\n    Router::renderError(404);\n    return;\n}",
    ],
    'the update action' => [
        '_apps/attendance/record/save.php',
        "    if (AttendanceAccess::mayViewTotals(\$mysqli, (int) (\$_SESSION['user_id'] ?? 0), \$siteId, App::isAdmin(), App::isRootAdmin()) === false) {\n        \$_SESSION['flash_msg']  = 'Attendance session not found.';\n        \$_SESSION['flash_type'] = 'danger';\n        header('Location: ' . Site::url('attendance'));\n        exit();\n    }",
    ],
];
foreach ($pages as $what => [$file, $pinned]) {
    $code = codeOnly($web . '/' . $file);
    check($what . ' (' . $file . ') holds the WHOLE gate, exactly', str_contains($code, $pinned) === true, 'expected, comments removed: ' . $pinned);
    check($what . ' asks it exactly once', substr_count($code, $asked) === 1, 'found ' . substr_count($code, $asked));
}
$export = codeOnly($web . '/_apps/attendance/export.php');
$save     = codeOnly($web . '/_apps/attendance/record/save.php');
$update   = strpos($save, "if (\$action === 'update')");
$askedAt  = $update === false ? false : strpos($save, $asked, $update);
$lookedUp = $update === false ? false : strpos($save, 'SELECT 1 FROM tblAttendanceSessions WHERE sessionID = ?', $update);
check(
    'the update action refuses BEFORE it looks the session up, so a refusal writes nothing',
    $askedAt !== false && $lookedUp !== false && $askedAt < $lookedUp
);
check(
    'the download refuses BEFORE it looks at the form token, so a refused member is never told the download exists',
    strpos($export, $asked) !== false && strpos($export, 'Auth::verifyCsrf(') !== false
        && strpos($export, $asked) < strpos($export, 'Auth::verifyCsrf(')
);
$index = codeOnly($web . '/_apps/attendance/index.php');
// D-109 (#917): each row's headcount and each row's Edit button too.
foreach (['Total Headcount This Month', "Site::url('attendance/export')", "Site::url('attendance/report')", "\$sess['totalCount']", "?edit=<?php echo (int) \$sess['sessionID']"] as $shown) {
    $at    = strpos($index, $shown);
    $guard = $at === false ? false : strrpos(substr($index, 0, $at), '<?php if (');
    check(
        'on the attendance page, "' . $shown . '" sits inside a block opened by "if ($mayViewReports === true)"',
        $guard !== false && str_starts_with(substr($index, $guard), '<?php if ($mayViewReports === true): ?>'),
        $at === false ? 'not found at all' : 'the nearest block above it opens differently'
    );
}

// -----------------------------------------------------------------------------
echo "\n3. The old setting is read by nothing\n";
// -----------------------------------------------------------------------------
$readers = [];
$tree    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($web, FilesystemIterator::SKIP_DOTS));
foreach ($tree as $path => $info) {
    if ($info->isFile() === false || str_ends_with((string) $path, '.php') === false) {
        continue;
    }
    if (str_contains(codeOnly((string) $path), 'attend.reports.visibleTo') === true) {
        $readers[] = substr((string) $path, strlen($web) + 1);
    }
}
check('no PHP under web/ names attend.reports.visibleTo outside a comment', $readers === [], implode(', ', $readers));
$migration = (string) file_get_contents($web . '/_sql/271_attendance_reports_permission.sql');
check(
    'migration 271 deletes the old setting at every level',
    str_contains($migration, "DELETE FROM `tblSettings` WHERE `settingKey` = 'attend.reports.visibleTo';") === true
);

echo "\n" . str_repeat('=', 78) . "\n";
printf("%d passed, %d failed\n", $passes, $failures);

if ($failures > 0) {
    echo "\nSomething about who may see the attendance totals has changed and is now\n";
    echo "wrong. Check web/_core/AttendanceAccess.php and the three attendance pages.\n";
    exit(1);
}

echo "\nAll good. The rule behaves as D-106 says.\n";
exit(0);
