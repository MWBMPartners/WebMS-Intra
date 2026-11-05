<?php
// Path: tools/portal-check-selftest.php
/**
 * -----------------------------------------------------------------------------
 * "Check this portal" — does it actually find what it claims to? 🔎
 * -----------------------------------------------------------------------------
 * `Portal\Core\PortalCheck` exists to report faults that write no error and no
 * log entry. **So a green run of the page proves nothing on its own** — the
 * whole class of fault it is for is the kind that looks fine. Every check here
 * therefore PLANTS the fault it is about and requires the check to find it,
 * then removes it and requires the finding to go.
 *
 * WHAT IT NEEDS
 *   A MySQL or MariaDB server with `full_schema.sql` loaded, named by the usual
 *   environment variables:
 *       SELFTEST_DB_HOST  SELFTEST_DB_PORT  SELFTEST_DB_USER
 *       SELFTEST_DB_PASS  SELFTEST_DB_NAME
 *   It REFUSES rather than skipping when they are missing. A test that quietly
 *   does nothing is the #646 failure, and this is the test for a page whose
 *   whole subject is things failing quietly.
 *
 * WHAT IT CANNOT DO, said plainly
 *   * **It does not open the page over HTTP.** It drives the class. Whether
 *     `/admin/check` renders, refuses the right people and survives a real
 *     request is NOT covered here — that is #776.
 *   * It cannot prove the checks find every fault of their kind, only the ones
 *     planted. The class header lists what it knows it cannot see.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/477
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$sep  = DIRECTORY_SEPARATOR;
$root = dirname(__DIR__);
define('PORTAL_ROOT',    $root . $sep . 'web');
define('PORTAL_CORE',    PORTAL_ROOT . $sep . '_core');
define('PORTAL_APPS',    PORTAL_ROOT . $sep . '_apps');
define('PORTAL_WEBROOT', PORTAL_ROOT . $sep . 'public_html');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// 🚨 EVERY CLASS THE CHECKS REACH FOR, AND MISSING TWO OF THEM HID A REAL
//    FAULT. There is no autoloader here. The first version required only
//    DbServer and PortalCheck, so `appsWithoutAnEntryPage()` (which needs
//    AppRegistry) and `scheduledJobs()` (which needs MailQueue) threw
//    "class not found" — and `run()`'s own `catch (Throwable)`, which is right
//    in production, turned that into a tidy "This check could not be
//    completed." warning that satisfied every check in section A.
//
//    So two of the eight checks were never executed and the run was still
//    32/32. **That is how the Apps check shipped reading the wrong key and
//    reporting four healthy apps as broken on every installation.** The guard
//    against it is section J below, which refuses to let ANY check report
//    itself as unable to run against a healthy database.
require PORTAL_CORE . $sep . 'App.php';
require PORTAL_CORE . $sep . 'DbServer.php';
require PORTAL_CORE . $sep . 'Maintenance.php';
require PORTAL_CORE . $sep . 'AppRegistry.php';
require PORTAL_CORE . $sep . 'MailQueue.php';
require PORTAL_CORE . $sep . 'PortalCheck.php';

// ⚠️ A class used by its SHORT name needs its `use` line in THIS file, and
//    the line only fails when it RUNS — `php -l` is happy without it.
//    That is #536's recorded lesson, and it cost a run here.
use Portal\Core\Maintenance;
use Portal\Core\PortalCheck;

$passed = 0;
$failed = 0;

function check(string $name, $got, $want): void
{
    global $passed, $failed;
    if ($got === $want) {
        $passed += 1;
        echo "  ✓ {$name}\n";
        return;
    }
    $failed += 1;
    echo "  ✗ {$name}\n";
    echo '      wanted: ' . var_export($want, true) . "\n";
    echo '      got:    ' . var_export($got, true) . "\n";
}

/** The findings for one area, by the area's name. */
function inArea(array $findings, string $area): array
{
    return array_values(array_filter(
        $findings,
        static fn (array $f): bool => $f['area'] === $area
    ));
}

/** Is there a finding of this severity in this area? */
function has(array $findings, string $area, string $severity): bool
{
    foreach (inArea($findings, $area) as $finding) {
        if ($finding['severity'] === $severity) {
            return true;
        }
    }
    return false;
}

// ── Connect, or refuse loudly ───────────────────────────────────────────────
$host = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
$port = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;
$user = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
$pass = getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '';
$name = getenv('SELFTEST_DB_NAME') !== false ? (string) getenv('SELFTEST_DB_NAME') : '';

if ($name === '') {
    fwrite(STDERR, "REFUSED: SELFTEST_DB_NAME is not set.\n");
    fwrite(STDERR, "This test needs a throwaway database with full_schema.sql loaded. It\n");
    fwrite(STDERR, "refuses rather than skipping, because it is the test for a page whose\n");
    fwrite(STDERR, "whole subject is faults that pass quietly.\n");
    exit(2);
}

try {
    $db = new mysqli($host, $user, $pass, $name, $port);
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    fwrite(STDERR, "REFUSED: could not reach the database named by SELFTEST_DB_*.\n");
    exit(2);
}

// 🔌 `AppRegistry::isEnabledForSite()` asks `App::db()`, which falls back to
//    the global `$mysqli` when bootstrap has not run. That fallback exists for
//    exactly this, and it is far lighter than loading bootstrap — which starts
//    sessions and merges settings this test has no use for.
//
//    Without it the Apps check threw, `run()`'s catch turned that into a
//    tidy "could not be completed" warning, and the check was never executed.
//    Section J is what now refuses to let that pass quietly.
$GLOBALS['mysqli'] = $db;

const ORG = 1;

/**
 * Clear anything a previous run left behind, BEFORE measuring anything.
 *
 * ⚠️ THIS IS NOT HOUSEKEEPING — WITHOUT IT A CRASHED RUN POISONS THE NEXT ONE
 *    AND THE FAILURE LOOKS LIKE A CODE FAULT. It happened while this file was
 *    being written: the first version tried to insert a duplicate setting, the
 *    database refused it (which is what proved the check it tested could never
 *    fire), and the run died leaving ONE `zz.selftest.dup` row behind. The next
 *    run then inserted two more, found three rows where it expected two, and
 *    reported a failure that had nothing to do with the code.
 *
 *    Every fixture here is prefixed `zz.selftest` / `zz-selftest` so this can
 *    be exact rather than sweeping. The unique key is put back if a previous
 *    run dropped it and died.
 */
function tidyUp(mysqli $db): void
{
    $db->query("DELETE FROM `tblSettings` WHERE `settingKey` LIKE 'zz.selftest%'");
    $db->query("DELETE FROM `tblSettings` WHERE `settingKey` LIKE 'api.zzselftest%'");
    $db->query("DELETE FROM `tblRoutes` WHERE `routeKey` LIKE 'zz-selftest%'");
    // Section K points the calendar's page at a file that is not there and puts
    // it back; a run that died in between would leave the app unopenable.
    $db->query("UPDATE `tblRoutes` SET `targetFile` = 'calendar/index.php' "
        . "WHERE `routeKey` = 'calendar' AND `targetFile` LIKE '%zz-selftest%'");
    // Section L writes a guide into the help folder.
    $strayGuide = PORTAL_APPS . DIRECTORY_SEPARATOR . 'help' . DIRECTORY_SEPARATOR . 'zz-selftest-guide.php';
    if (is_file($strayGuide) === true) {
        unlink($strayGuide);
    }
    // A run that died between the DROP and the ADD leaves the guard off.
    $has = $db->query("SHOW INDEX FROM `tblSettings` WHERE `Key_name` = 'uq_setting_key_scope'");
    $missing = $has->num_rows === 0;
    $has->free();
    if ($missing === true) {
        $db->query('ALTER TABLE `tblSettings` ADD UNIQUE KEY `uq_setting_key_scope` (`settingKey`, `siteScope`)');
    }
    // Likewise a run that died between the two RENAMEs.
    $renamed = $db->query("SHOW TABLES LIKE 'tblRoutes_zz_selftest'");
    $stranded = $renamed->num_rows > 0;
    $renamed->free();
    if ($stranded === true) {
        $db->query('DROP TABLE IF EXISTS `tblRoutes`');
        $db->query('RENAME TABLE `tblRoutes_zz_selftest` TO `tblRoutes`');
    }
}

tidyUp($db);

echo "A. The checks run at all, against a real loaded schema\n";
$base = PortalCheck::run($db, ORG);
check('A1 it returns findings rather than throwing', is_array($base), true);
check('A2 every finding carries the fields the page prints', array_values(array_unique(array_map(
    static fn (array $f): string => implode(',', array_keys($f)),
    $base
))) === [] ? true : (static function (array $f): bool {
    foreach ($f as $one) {
        foreach (['area', 'severity', 'what', 'why', 'todo', 'count', 'examples'] as $key) {
            if (array_key_exists($key, $one) === false) {
                return false;
            }
        }
    }
    return true;
})($base), true);
check('A3 every severity is one the page knows how to draw', (static function (array $f): bool {
    foreach ($f as $one) {
        if (in_array($one['severity'], [PortalCheck::PROBLEM, PortalCheck::WARNING, PortalCheck::NOTE], true) === false) {
            return false;
        }
    }
    return true;
})($base), true);
check('A4 no finding has an empty sentence — the shape a wrong array key makes',
    (static function (array $f): bool {
        foreach ($f as $one) {
            if (trim((string) $one['what']) === '' || trim((string) $one['why']) === '') {
                return false;
            }
        }
        return true;
    })($base), true);
check('A5 the database version was read and judged', count(inArea($base, 'Database')), 1);

echo "B. The guard against duplicate settings is checked, not the duplicates\n";
// ⚠️ THE FIRST VERSION OF THIS SECTION TRIED TO INSERT A DUPLICATE AND THE
//    DATABASE REFUSED IT. That is how the check it tests was found to be a
//    clause that cannot fire: migration 187's unique key makes a second row
//    impossible, so scanning for duplicates can only ever answer "none".
//    The live question is whether that key is still there — it would not be in
//    a database restored from a pre-187 snapshot. So the plant is DROPPING THE
//    KEY, which is the state the check exists for.
check('B1 with the key in place, it is reported as present and not as a problem',
    has($base, 'Settings', PortalCheck::NOTE), true);
check('B2 and it is not reported as a problem', has($base, 'Settings', PortalCheck::PROBLEM), false);

$db->query('ALTER TABLE `tblSettings` DROP INDEX `uq_setting_key_scope`');
$planted = PortalCheck::run($db, ORG);
check('B3 with the key gone, that is a problem', has($planted, 'Settings', PortalCheck::PROBLEM), true);
check('B4 and it is no longer reported as present', has($planted, 'Settings', PortalCheck::NOTE), false);

// With the guard gone, duplicates become possible — which is the whole point.
$db->query("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`) VALUES (NULL, 'zz.selftest.dup', 'one')");
$db->query("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`) VALUES (NULL, 'zz.selftest.dup', 'two')");
$planted = PortalCheck::run($db, ORG);
$dups = null;
foreach (inArea($planted, 'Settings') as $one) {
    if (str_contains($one['what'], 'already stored more than once') === true) {
        $dups = $one;
    }
}
check('B5 the duplicate that is now possible is found', $dups !== null, true);
check('B6 exactly one key is reported as duplicated', $dups === null ? -1 : (int) $dups['count'], 1);
check('B7 and it is named', $dups !== null
    && in_array('zz.selftest.dup (2 rows)', $dups['examples'], true), true);

$db->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'zz.selftest.dup'");
$db->query('ALTER TABLE `tblSettings` ADD UNIQUE KEY `uq_setting_key_scope` (`settingKey`, `siteScope`)');
check('B8 putting the key back clears the problem',
    has(PortalCheck::run($db, ORG), 'Settings', PortalCheck::PROBLEM), false);

echo "C. An organisation's override is NOT a duplicate\n";
// The whole settings design is an installation row and an organisation row for
// the same key (#668). The key itself allows that, by coalescing a null siteID
// to -1 — so this proves the design still works rather than the check.
$db->query("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`) VALUES (NULL, 'zz.selftest.lvl', 'install')");
$db->query("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`) VALUES (1, 'zz.selftest.lvl', 'org')");
check('C1 two levels of one key are accepted by the database',
    has(PortalCheck::run($db, ORG), 'Settings', PortalCheck::PROBLEM), false);
$db->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'zz.selftest.lvl'");

echo "D. An address with no page behind it is found\n";
check('D1 nothing broken to begin with', has($base, 'Addresses', PortalCheck::PROBLEM), false);
$db->query("INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) "
    . "VALUES ('zz-selftest-nowhere', 'zz/selftest/not-a-real-file.php', 1)");
$planted = PortalCheck::run($db, ORG);
check('D2 the broken address is found', has($planted, 'Addresses', PortalCheck::PROBLEM), true);
$addr = inArea($planted, 'Addresses')[0] ?? ['count' => 0, 'examples' => []];
check('D3 exactly one address is reported', (int) $addr['count'], 1);
check('D4 and it is named', in_array('/zz-selftest-nowhere', $addr['examples'], true), true);
$db->query("DELETE FROM `tblRoutes` WHERE `routeKey` = 'zz-selftest-nowhere'");
check('D5 removing it removes the finding',
    has(PortalCheck::run($db, ORG), 'Addresses', PortalCheck::PROBLEM), false);

echo "E. A real address is NOT reported, whichever folder its file is in\n";
// The router looks under `_apps` first and falls back to the web root. A check
// that looked in one place only would report working addresses as broken —
// which is the crying-wolf direction, and #483 says that gets a check switched
// off.
$db->query("INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) "
    . "VALUES ('zz-selftest-real', 'admin/check/index.php', 1)");
check('E1 an address pointing into _apps is accepted',
    has(PortalCheck::run($db, ORG), 'Addresses', PortalCheck::PROBLEM), false);
$db->query("DELETE FROM `tblRoutes` WHERE `routeKey` = 'zz-selftest-real'");

echo "F. A data endpoint switched on with nothing behind it is found\n";
$db->query("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`) "
    . "VALUES (NULL, 'api.zzselftest.nothing.enabled', 'true')");
$planted = PortalCheck::run($db, ORG);
check('F1 the orphan switch is found', has($planted, 'Data endpoints', PortalCheck::WARNING), true);
$api = inArea($planted, 'Data endpoints');
$orphan = null;
foreach ($api as $one) {
    if ($one['severity'] === PortalCheck::WARNING) {
        $orphan = $one;
    }
}
check('F2 and it is named', $orphan !== null
    && in_array('api/zzselftest/nothing', $orphan['examples'], true), true);
$db->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'api.zzselftest.nothing.enabled'");
check('F3 removing it removes the finding',
    has(PortalCheck::run($db, ORG), 'Data endpoints', PortalCheck::WARNING), false);

echo "G. The recorded version is compared with the files\n";
// 🚨 THE KEY COMES FROM THE CLASS. The first version of this section wrote
//    `portal.version` — a literal of its own — and that is the ONLY reason the
//    check passed while reading a key no installation holds. A test keeping
//    its own copy of the rule tests nothing but itself.
$versionKey = Maintenance::VERSION_SETTING;
check('G0 the key under test is the one the portal records under',
    $versionKey, 'portal.installed_version');

$forget = static function (mysqli $db, string $key): void {
    $stmt = $db->prepare('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->close();
};
$record = static function (mysqli $db, string $key, string $value): void {
    $stmt = $db->prepare(
        'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`) VALUES (NULL, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`)'
    );
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
};

$onDisk = (string) (require PORTAL_CORE . $sep . 'version.php');
$record($db, $versionKey, '0.0.1-selftest');
check('G1 a version that disagrees with the files is a problem',
    has(PortalCheck::run($db, ORG), 'Version', PortalCheck::PROBLEM), true);

$record($db, $versionKey, $onDisk);
check('G2 a version that agrees is not a problem',
    has(PortalCheck::run($db, ORG), 'Version', PortalCheck::PROBLEM), false);
check('G3 and it is reported rather than being silent',
    has(PortalCheck::run($db, ORG), 'Version', PortalCheck::NOTE), true);

$forget($db, $versionKey);
check('G4 no recorded version at all is a warning, not silence',
    has(PortalCheck::run($db, ORG), 'Version', PortalCheck::WARNING), true);

// Put it back the way a real installation holds it, so sections after this
// one see a healthy portal.
$record($db, $versionKey, $onDisk);

echo "H. The page never goes blank because one check failed\n";
// ⚠️ THE CASE THAT MATTERS MOST AND IS EASIEST TO MISS. If one query throwing
//    emptied the whole report, an administrator would see a clean page and
//    conclude all was well — the exact false reassurance this page exists to
//    end. Renaming a table the checks read forces it.
$db->query('RENAME TABLE `tblRoutes` TO `tblRoutes_zz_selftest`');
$hurt = PortalCheck::run($db, ORG);
check('H1 the other checks still ran', count($hurt) > 1, true);
check('H2 and the broken one SAYS it could not run', has($hurt, 'Addresses', PortalCheck::WARNING), true);
check('H3 the fault is not treated as "nothing found"',
    has($hurt, 'Addresses', PortalCheck::NOTE), false);
$db->query('RENAME TABLE `tblRoutes_zz_selftest` TO `tblRoutes`');
check('H4 and it recovers once the table is back',
    has(PortalCheck::run($db, ORG), 'Addresses', PortalCheck::WARNING), false);

echo "J. EVERY check really runs — none reports itself as unable to\n";
// 🚨 THE GUARD AGAINST THE FAULT THAT LET SECTION A PASS ON TWO DEAD CHECKS.
//    `run()` catches a Throwable per check and reports "This check could not
//    be completed." That is correct in production — one broken query must
//    never empty the page. But in a TEST against a healthy database it means a
//    check that cannot even load its class looks exactly like a check that
//    ran. Two did, and the Apps check shipped reading the wrong key because of
//    it. On a healthy database, nothing may report itself as unable to run.
$healthy = PortalCheck::run($db, ORG);
$couldNot = array_values(array_filter(
    $healthy,
    static fn (array $f): bool => str_contains((string) $f['what'], 'could not be completed')
));
check('J1 no check reports itself as unable to run', array_map(
    static fn (array $f): string => (string) $f['area'],
    $couldNot
), []);
// ⚠️ MOST AREAS ARE SILENT ON A HEALTHY PORTAL, and this check's expectation
//    was written twice from memory before being derived from the class. Only
//    FOUR always say something: Version, Database, Settings and Scheduled
//    jobs. `Addresses`, `Apps`, `Help` and `Data endpoints` report only when
//    there is something to report — which is right for the page, and means
//    their absence here proves nothing either way. Each is proved to RUN by
//    its own planting section (D, K, L, F), because **a silent check and a
//    dead check look identical** and J1 above is the only thing that tells
//    them apart.
check('J2 the four areas that always report are all present', (static function (array $f): array {
    $seen = array_values(array_unique(array_map(static fn (array $x): string => (string) $x['area'], $f)));
    sort($seen);
    return array_values(array_intersect($seen, ['Database', 'Scheduled jobs', 'Settings', 'Version']));
})($healthy), ['Database', 'Scheduled jobs', 'Settings', 'Version']);

echo "K. The Apps check reads the landing page, not the route prefix\n";
// Expenses, Kids and Worship all declare a `route` that is NOT a page
// (`/expenses` does not exist; `expenses/submit` does). Reading `route` named
// all three — plus `auth`, which is not a destination at all — as broken on
// every correctly installed portal.
check('K1 a correct portal reports no app as unopenable',
    has($healthy, 'Apps', PortalCheck::PROBLEM), false);

$db->query("UPDATE `tblRoutes` SET `targetFile` = 'calendar/zz-selftest-gone.php' WHERE `routeKey` = 'calendar'");
$planted = PortalCheck::run($db, ORG);
check('K2 an app whose landing page file is missing IS reported',
    has($planted, 'Apps', PortalCheck::PROBLEM), true);
$apps = inArea($planted, 'Apps')[0] ?? ['count' => 0, 'examples' => []];
check('K3 exactly one app is reported, not four', (int) $apps['count'], 1);
$db->query("UPDATE `tblRoutes` SET `targetFile` = 'calendar/index.php' WHERE `routeKey` = 'calendar'");
check('K4 putting it back clears the finding',
    has(PortalCheck::run($db, ORG), 'Apps', PortalCheck::PROBLEM), false);

echo "L. A help guide nobody links to is found, and a mention in a comment is not a link\n";
$helpDir = PORTAL_APPS . $sep . 'help';
$plantGuide = $helpDir . $sep . 'zz-selftest-guide.php';
file_put_contents($plantGuide, "<?php // a guide the Help Centre does not link to\n");
$planted = PortalCheck::run($db, ORG);
check('L1 the unlinked guide is found', has($planted, 'Help', PortalCheck::WARNING), true);
$help = inArea($planted, 'Help')[0] ?? ['examples' => []];
check('L2 and it is named by its address',
    in_array('/help/zz-selftest-guide', $help['examples'], true), true);
unlink($plantGuide);
check('L3 removing it removes the finding',
    has(PortalCheck::run($db, ORG), 'Help', PortalCheck::WARNING), false);

echo "M. A shared helper is not an endpoint, and the NOTE's contents are pinned\n";
// ⚠️ THE UNDERSCORE GUARD WAS PINNED BY NOTHING. Replacing it with
//    `if (false)` left all 42 checks green while the page began reporting
//    `api/assets/_coerce` — a shared helper the router's own
//    `^[a-z0-9\-]+$` pattern refuses, so it is not an endpoint and never
//    will be. Sections F1-F3 pin only the orphan-switch WARNING; nothing
//    looked at the NOTE's contents, which is where the guard shows.
$note = null;
foreach (inArea($healthy, 'Data endpoints') as $one) {
    if ($one['severity'] === PortalCheck::NOTE) {
        $note = $one;
    }
}
check('M1 the unswitched-endpoint note is produced at all', $note !== null, true);
check('M2 no shared helper is listed as an endpoint', (static function (?array $n): array {
    if ($n === null) {
        return ['<no note>'];
    }
    return array_values(array_filter(
        $n['examples'],
        static fn (string $e): bool => str_contains($e, '/_') === true
    ));
})($note), []);

echo "N. A guide whose name is the start of a longer guide's name\n";
// 🚨 `str_contains($centre, 'help/admin')` IS SATISFIED BY
//    `help/admin-first-steps`, and `help/calendar` by `help/calendar-imports`.
//    Both pairs ship. So deleting the Help Centre's card for the MAIN Calendar
//    guide left it unreachable with this check saying nothing — the exact
//    fault (#467) the check exists for, invisible for 2 of the 19 guides.
$helpIndex = PORTAL_APPS . $sep . 'help' . $sep . 'index.php';
$centreWas = (string) file_get_contents($helpIndex);
$siblings = [];
foreach (glob(PORTAL_APPS . $sep . 'help' . $sep . '*.php') ?: [] as $file) {
    $slug = basename($file, '.php');
    if ($slug === 'index') {
        continue;
    }
    foreach (glob(PORTAL_APPS . $sep . 'help' . $sep . '*.php') ?: [] as $other) {
        $otherSlug = basename($other, '.php');
        if ($otherSlug !== $slug && str_starts_with($otherSlug, $slug . '-') === true) {
            $siblings[$slug] = $otherSlug;
        }
    }
}
check('N1 the tree really holds at least one such pair, or this section proves nothing',
    count($siblings) > 0, true);

if (count($siblings) > 0) {
    $shortSlug = array_key_first($siblings);
    // Remove ONLY the short guide's link, leaving its longer sibling's.
    $without = preg_replace(
        '~help/' . preg_quote($shortSlug, '~') . '(?![A-Za-z0-9-])~',
        'help/zz-selftest-removed',
        $centreWas,
        1
    );
    file_put_contents($helpIndex, (string) $without);
    $planted = PortalCheck::run($db, ORG);
    $found = false;
    foreach (inArea($planted, 'Help') as $one) {
        if (in_array('/help/' . $shortSlug, $one['examples'], true) === true) {
            $found = true;
        }
    }
    file_put_contents($helpIndex, $centreWas);
    check('N2 unlinking ' . $shortSlug . ' is found even though ' . $siblings[$shortSlug]
        . ' is still linked', $found, true);
    check('N3 and the Help Centre is byte-identical again',
        (string) file_get_contents($helpIndex) === $centreWas, true);
}

echo "I. The tally matches the findings\n";
$final = PortalCheck::run($db, ORG);
$tally = PortalCheck::tally($final);
check('I1 the three counts add up to the number of findings',
    $tally[PortalCheck::PROBLEM] + $tally[PortalCheck::WARNING] + $tally[PortalCheck::NOTE],
    count($final));

echo "\n";
tidyUp($db);

echo ($passed + $failed) . " checks: {$passed} passed, {$failed} failed.\n";
echo "This drives the CLASS. It does NOT open /admin/check over HTTP — that is #776.\n";
exit($failed === 0 ? 0 : 1);
