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
//
// 🚨 AND IT HAPPENED AGAIN, WHICH IS WHY SECTION J EARNS ITS PLACE TWICE.
//    #775 gave `scheduledJobs()` a new dependency — `ScheduledJobs`, the one
//    place a job's run is recorded and read — and did not add it here. The
//    same `catch (Throwable)` turned "class not found" into a tidy warning,
//    and FIVE checks went red: section J by name, plus O3, O4, O6 and O7,
//    which read a finding that was no longer being produced at all.
//
//    **The lesson is for whoever ADDS a dependency, not for this file: a class
//    reached by a check must be required here in the same change.** Section J
//    is what makes the omission loud instead of silent — without it the run
//    would have been 50 of 50 with the whole scheduled-jobs check absent.
//
//    It is also why #695 matters: this test is in no workflow that has ever
//    run on GitHub (#754), and nothing runs the whole set locally, so #775's
//    own green run said nothing about it.
require PORTAL_CORE . $sep . 'App.php';
require PORTAL_CORE . $sep . 'DbServer.php';
require PORTAL_CORE . $sep . 'Maintenance.php';
require PORTAL_CORE . $sep . 'AppRegistry.php';
require PORTAL_CORE . $sep . 'MailRoutes.php';
require PORTAL_CORE . $sep . 'MailQueue.php';
require PORTAL_CORE . $sep . 'ScheduledJobs.php';
require PORTAL_CORE . $sep . 'PortalCheck.php';

// ⚠️ A class used by its SHORT name needs its `use` line in THIS file, and
//    the line only fails when it RUNS — `php -l` is happy without it.
//    That is #536's recorded lesson, and it cost a run here.
use Portal\Core\Maintenance;
use Portal\Core\PortalCheck;
// ⚠️ A class used by its SHORT name needs its `use` line in THIS file, and
//    the line only fails when it RUNS — `php -l` is happy without it (#536).
use Portal\Core\ScheduledJobs;

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

/**
 * Set an app's installation-wide switch. Used for `giving.enabled` and
 * `discipleship.enabled`; tidyUp() puts both back to their seeded values.
 */
function setSwitch(mysqli $db, string $key, string $value): void
{
    $stmt = $db->prepare(
        'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`) VALUES (NULL, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`)'
    );
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
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
    // Section O gives every job a token; put them back as a fresh install has
    // them, so a run that died mid-section does not leave the next one
    // measuring a portal that looks configured when it is not.
    $db->query("UPDATE `tblSettings` SET `settingValue` = '' "
        . "WHERE `settingValue` = 'zz-selftest-token' AND `siteID` IS NULL");
    $db->query("DELETE FROM `tblRoutes` WHERE `routeKey` LIKE 'zz-selftest%'");
    // Section P writes a run for every scheduled job; a fresh install has none.
    $db->query('DELETE FROM `tblScheduledJobRun`');
    // Section Q renames two tables away to break a read; put them back first.
    foreach (['zz_selftest_793_sites' => 'tblSites', 'zz_selftest_793_gsl' => 'tblGivingStatementLog'] as $away => $home) {
        $there = $db->query("SHOW TABLES LIKE '" . $away . "'");
        if ($there->num_rows > 0) {
            $db->query('RENAME TABLE `' . $away . '` TO `' . $home . '`');
        }
        $there->free();
    }
    // Section Q's donor (its statement goes with it, ON DELETE CASCADE) and
    // its retired organisation.
    $db->query("DELETE FROM `tblUsers` WHERE `emailAddress` LIKE 'zz-selftest-793%'");
    $retired = $db->query("SELECT `siteID` FROM `tblSites` WHERE `siteKey` = 'zz-selftest-793'");
    while (($row = $retired->fetch_assoc()) !== null) {
        $db->query('DELETE FROM `tblSettings` WHERE `siteID` = ' . (int) $row['siteID']);
        $db->query('DELETE FROM `tblSites` WHERE `siteID` = ' . (int) $row['siteID']);
    }
    $retired->free();
    // Sections O and Q switch Giving and Discipleship on and off, and Q gives
    // Giving a per-organisation row. Put back what `full_schema.sql` seeds
    // (`giving.enabled` '0', `discipleship.enabled` 'true'), because O3's
    // figure of 14 depends on a fresh install having Giving off.
    $db->query("DELETE FROM `tblSettings` WHERE `settingKey` IN ('giving.enabled', 'discipleship.enabled') "
        . "AND `siteID` IS NOT NULL");
    $db->query("UPDATE `tblSettings` SET `settingValue` = '0' WHERE `settingKey` = 'giving.enabled' AND `siteID` IS NULL");
    $db->query("UPDATE `tblSettings` SET `settingValue` = 'true' WHERE `settingKey` = 'discipleship.enabled' "
        . "AND `siteID` IS NULL");
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

echo "O. A job with no token has never run, and the page says so\n";
// 🚨 EVERY ONE OF THESE PAGES REFUSES WHEN ITS STORED TOKEN IS EMPTY, so a job
//    nobody gave a token is silently doing nothing — for ever. On a fresh
//    install that is ALL ELEVEN, including the one that clears personal data
//    past its retention period. Knowable from the database, and reported by
//    nothing before #477.
$jobs = PortalCheck::JOB_TOKENS;
check('O1 the table names every job gate', count($jobs) >= 11, true);
check('O2 it names the shared maintenance token, which the retention sweep uses',
    array_key_exists('maintenance.cronToken', $jobs), true);

$silentFinding = static function (array $f): ?array {
    foreach (inArea($f, 'Scheduled jobs') as $one) {
        if (str_contains($one['what'], 'can never run') === true) {
            return $one;
        }
    }
    return null;
};

// ⚠️ THE COUNT IS JOBS, NOT TOKENS, AND THAT CHANGED DELIBERATELY (#775
//    round 3). There are eleven tokens and fifteen jobs, and
//    `maintenance.cronToken` alone covers five — so this finding used to
//    report ONE while the all-clear beside it said "the 5 with no token …
//    each is reported above". One page, two numbers for one fact, differing
//    fivefold. A token is not a job, which is the whole reason that package
//    has its own table, and the number an administrator needs is how many
//    JOBS are dead.
$fresh = $silentFinding(PortalCheck::run($db, ORG));
// ⚠️ FOURTEEN, NOT FIFTEEN, AND THAT IS #793 ROUND 1's RULE. `full_schema.sql`
//    seeds `giving.enabled` as '0', so on a fresh install Giving is off for
//    every organisation and its statements job has nothing to do
//    (`PortalCheck::JOB_NEEDS_FEATURE`). Typed, not counted from the class.
check('O3 a fresh install reports every JOB it needs as unable to run, not every token — 14, '
    . 'because Giving ships switched off and its job is not needed',
    $fresh === null ? -1 : (int) $fresh['count'], 14);
check('O4 and it is a PROBLEM, not a note',
    $fresh === null ? '' : $fresh['severity'], PortalCheck::PROBLEM);
// Switch Giving on, as a portal that uses it has it: now all fifteen are
// needed. Left on through sections O and P; section Q switches it off again
// and tidyUp() puts the seeded value back.
setSwitch($db, 'giving.enabled', '1');
$freshGiving = $silentFinding(PortalCheck::run($db, ORG));
check('O3b with Giving switched on, all 15 jobs are reported as unable to run',
    $freshGiving === null ? -1 : (int) $freshGiving['count'], 15);

// Give every job a token and the finding must go entirely.
foreach (array_keys($jobs) as $key) {
    $stmt = $db->prepare(
        'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`) VALUES (NULL, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`)'
    );
    $value = 'zz-selftest-token';
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
}
check('O5 with every token set, the finding goes', $silentFinding(PortalCheck::run($db, ORG)), null);

// Take ONE away again — the count must be exact, not just non-zero.
$stmt = $db->prepare('UPDATE `tblSettings` SET `settingValue` = \'\' WHERE `settingKey` = ? AND `siteID` IS NULL');
$one = 'giving.cron_token';
$stmt->bind_param('s', $one);
$stmt->execute();
$stmt->close();
$justOne = $silentFinding(PortalCheck::run($db, ORG));
// `giving.cron_token` covers exactly one job, so one token and one job agree
// here — which is why this check could not see the token/job confusion. O6b
// below uses the token that covers FIVE.
check('O6 emptying a token that covers one job reports exactly one',
    $justOne === null ? -1 : (int) $justOne['count'], 1);
check('O7 and it names what stops, in the JOB\'s own words, not the setting key',
    $justOne !== null && in_array(
        ScheduledJobs::JOBS['cron/giving-statements']['whatStops'],
        $justOne['examples'],
        true
    ), true);

// ── O6b. The token that covers FIVE jobs ────────────────────────────────
// This is the state that exposed the fault, and no check reached it: every
// fixture used a single-job token, where a token count and a job count are
// the same number.
$stmt = $db->prepare('UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingKey` = ? AND `siteID` IS NULL');
$put  = 'tok';
$stmt->bind_param('ss', $put, $one);
$stmt->execute();
$stmt->close();
$shared = 'maintenance.cronToken';
$stmt = $db->prepare('UPDATE `tblSettings` SET `settingValue` = \'\' WHERE `settingKey` = ? AND `siteID` IS NULL');
$stmt->bind_param('s', $shared);
$stmt->execute();
$stmt->close();
$sharedBy = 0;
foreach (ScheduledJobs::JOBS as $facts) {
    if ($facts['token'] === $shared) {
        $sharedBy++;
    }
}
$many = $silentFinding(PortalCheck::run($db, ORG));
check('O6b emptying the token FIVE jobs share reports ' . $sharedBy . ', not 1',
    $many === null ? -1 : (int) $many['count'], $sharedBy);

// ⚠️ THE TOKEN IS A SECRET — anybody holding it can run the job. The page must
//    never print it, and this is the check that would notice if it did.
$leaked = false;
foreach (PortalCheck::run($db, ORG) as $finding) {
    $all = $finding['what'] . ' ' . $finding['why'] . ' ' . $finding['todo']
        . ' ' . implode(' ', $finding['examples']);
    if (str_contains($all, 'zz-selftest-token') === true) {
        $leaked = true;
    }
}
check('O8 no token value appears anywhere on the page', $leaked, false);

echo "P. A job called every few minutes is not given a night's grace (#793, D-076)\n";
// ⚠️ THE MEASURED FAULT, ON THE PAGE. Under D-070's "everything else, 36 hours"
//    row the webhook retry — called every 5 to 15 minutes — could stop
//    for a day and a half while this page said every job had run in time and
//    there was nothing to do. D-076 gives it 45 minutes. Two hours late, it
//    must now be a PROBLEM on the page, while the nightly retention sweep, two
//    hours since its last run, must not be.
//
//    Every expected sentence is typed out here rather than asked of
//    `ScheduledJobs`, because a test that asks the code under test what it
//    should say agrees with it however wrong it is (#795).
foreach (array_keys($jobs) as $key) {
    $stmt = $db->prepare(
        'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`) VALUES (NULL, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`)'
    );
    $value = 'zz-selftest-token';
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
}
$db->query('DELETE FROM `tblScheduledJobRun`');
ScheduledJobs::forgetTableCheck();
foreach (array_keys(ScheduledJobs::JOBS) as $job) {
    ScheduledJobs::began($db, $job);
    ScheduledJobs::finished($db, $job);
}
$db->query(
    'UPDATE `tblScheduledJobRun` SET `startedAtUtc` = UTC_TIMESTAMP() - INTERVAL 7200 SECOND, '
    . '`finishedAtUtc` = UTC_TIMESTAMP() - INTERVAL 7200 SECOND '
    . "WHERE `jobKey` IN ('cron/webhook-retry', 'cron/retention-sweep')"
);
$lateFinding = null;
$allClear    = null;
foreach (inArea(PortalCheck::run($db, ORG), 'Scheduled jobs') as $one) {
    if (str_contains($one['what'], 'have not run for longer') === true) {
        $lateFinding = $one;
    }
    if (str_contains($one['what'], 'has run recently enough') === true) {
        $allClear = $one;
    }
}
check('P1 the retry sweep two hours late is a PROBLEM on the page',
    $lateFinding === null ? '' : $lateFinding['severity'], PortalCheck::PROBLEM);
check('P2 and it is the ONLY job listed — the nightly sweep, equally two hours since it ran, is not',
    $lateFinding === null ? [] : $lateFinding['examples'],
    ['Sending again the messages this portal sends to other systems (its webhooks) that failed first time — last ran 2 hours ago']);
check('P3 and the page does not also say every job has run recently enough', $allClear, null);

// Back to every job just finished: the all-clear, word for word.
$db->query('DELETE FROM `tblScheduledJobRun`');
foreach (array_keys(ScheduledJobs::JOBS) as $job) {
    ScheduledJobs::began($db, $job);
    ScheduledJobs::finished($db, $job);
}
$allClear = null;
foreach (inArea(PortalCheck::run($db, ORG), 'Scheduled jobs') as $one) {
    if (str_contains($one['what'], 'has run recently enough') === true) {
        $allClear = $one;
    }
}
check('P4 with every job just finished, the all-clear gives every allowance, as typed here',
    $allClear === null ? '' : $allClear['why'],
    '15 of the 15 recorded a FINISH inside the time allowed for it. '
    . 'That allowance is set for each job from what it is for and how often it is meant to run: '
    . '15 minutes (the mail queue), 30 minutes (the live-stream push), 45 minutes (2 jobs), '
    . '2 hours (event reminders), 3 hours (4 jobs), 6 hours (feed imports), 36 hours (4 jobs) '
    . 'and 8 days (giving statements).');
$db->query('DELETE FROM `tblScheduledJobRun`');

echo "Q. A job whose app is switched off everywhere is neither a problem nor counted (#793 round 1)\n";
// ⚠️ THE STATE THE SETUP GUIDE RECOMMENDS MUST NOT BE REPORTED AS A FAULT.
//    `docs/INSTALL.md` step 6 says the giving-statements and reading-plan jobs
//    may be left out, token and all, while their app is switched off for every
//    organisation. Before `PortalCheck::JOB_NEEDS_FEATURE` the page then said
//    "Some scheduled jobs can never run" — a check crying wolf on advice the
//    portal itself gives (#483). Both states are driven: off (not reported,
//    not counted) and on, including on for ONE organisation through its own
//    row (reported).
$putToken = static function (mysqli $db, string $key, string $value): void {
    $stmt = $db->prepare('UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingKey` = ? AND `siteID` IS NULL');
    $stmt->bind_param('ss', $value, $key);
    $stmt->execute();
    $stmt->close();
};
$pageFor = static function (mysqli $db): array {
    return inArea(PortalCheck::run($db, ORG), 'Scheduled jobs');
};
$named = static function (array $found, string $needle): ?array {
    foreach ($found as $one) {
        if (str_contains($one['what'], $needle) === true) {
            return $one;
        }
    }
    return null;
};
foreach (array_keys(ScheduledJobs::JOBS) as $job) {
    ScheduledJobs::began($db, $job);
    ScheduledJobs::finished($db, $job);
}
setSwitch($db, 'giving.enabled', '0');
$putToken($db, 'giving.cron_token', '');
$found = $pageFor($db);
check('Q1 Giving off everywhere and its token empty: NOT reported as unable to run',
    $named($found, 'can never run'), null);
$clear = $named($found, 'has run recently enough');
check('Q2 and the all-clear says it can speak only for the jobs it counts',
    $clear === null ? '' : $clear['what'],
    'Every scheduled job this page can speak for has run recently enough.');
check('Q2b and says, word for word, which job it left out and why',
    $clear === null ? '' : $clear['why'],
    '14 of the 15 recorded a FINISH inside the time allowed for it. '
    . 'That allowance is set for each job from what it is for and how often it is meant to run: '
    . '15 minutes (the mail queue), 30 minutes (the live-stream push), 45 minutes (2 jobs), '
    . '2 hours (event reminders), 3 hours (4 jobs), 6 hours (feed imports) and 36 hours (4 jobs). '
    . 'Also not counted, because the feature it serves is switched off for every active '
    . 'organisation here and none of its work is waiting, so there is nothing for it to do: '
    . 'Sending giving statements.');
$db->query(
    "UPDATE `tblScheduledJobRun` SET `startedAtUtc` = UTC_TIMESTAMP() - INTERVAL 10 DAY, "
    . "`finishedAtUtc` = UTC_TIMESTAMP() - INTERVAL 10 DAY WHERE `jobKey` = 'cron/giving-statements'"
);
check('Q3 with Giving off, its job ten days quiet (past its 8 days) is NOT reported as overdue either',
    $named($pageFor($db), 'have not run for longer'), null);

// On for ONE organisation, through its own row, while the installation-wide
// switch stays off: the job serves that organisation, so it is needed.
$stmt = $db->prepare("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`) VALUES (?, 'giving.enabled', '1')");
$org = ORG;
$stmt->bind_param('i', $org);
$stmt->execute();
$stmt->close();
$found = $pageFor($db);
$dead = $named($found, 'can never run');
check('Q4 Giving switched on for ONE organisation by its own row: the empty token IS a problem again',
    $dead === null ? [] : [$dead['severity'], $dead['examples']],
    [PortalCheck::PROBLEM, ['Sending giving statements']]);
// With its token set again, the same quiet job is overdue again. (While the
// token is empty it is deliberately left out of the overdue finding: the
// no-token problem above already covers it, and listing it twice once said a
// job both could and could not run.)
$putToken($db, 'giving.cron_token', 'zz-selftest-token');
$late = $named($pageFor($db), 'have not run for longer');
check('Q4b and with its token set, the job ten days quiet is overdue again',
    $late === null ? [] : $late['examples'],
    ['Sending giving statements — last ran 10 days ago']);
$db->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'giving.enabled' AND `siteID` IS NOT NULL");

// The second job on the list, both ways.
$putToken($db, 'discipleship.cron_token', '');
setSwitch($db, 'discipleship.enabled', 'false');
check('Q5 Discipleship off everywhere and its token empty: NOT reported',
    $named($pageFor($db), 'can never run'), null);
setSwitch($db, 'discipleship.enabled', 'true');
$dead = $named($pageFor($db), 'can never run');
check('Q6 Discipleship on: its empty token IS reported, and nothing else is',
    $dead === null ? [] : $dead['examples'],
    ['Moving people on through their reading plans']);

// ── Q7. Giving off, but a statement still waiting: the job is NEEDED ────
// ⚠️ ROUND 2 OF #793's CHECK MEASURED THIS STATE HIDDEN. `cron/giving-statements`
//    never asks `giving.enabled`: it sends every statement a treasurer queued.
//    With Giving switched off after a run was queued, the page said "there is
//    nothing for it to do" about a job ten days quiet with a statement nine
//    days in the queue — a dead job, hidden by an untrue sentence.
$putToken($db, 'discipleship.cron_token', 'zz-selftest-token');   // Q5/Q6 left it empty
$putToken($db, 'giving.cron_token', 'zz-selftest-token');
$db->query("INSERT INTO `tblUsers` (`emailAddress`) VALUES ('zz-selftest-793-donor@example.invalid')");
$donor = (int) $db->insert_id;
$stmt  = $db->prepare(
    'INSERT INTO `tblGivingStatementLog` (`siteID`, `donorID`, `periodKey`, `fromDate`, `toDate`, `queuedAt`) '
    . "VALUES (?, ?, 'zz-selftest-793', '2026-01-01', '2026-03-31', UTC_TIMESTAMP() - INTERVAL 9 DAY)"
);
$stmt->bind_param('ii', $org, $donor);
$stmt->execute();
$stmt->close();
$db->query(
    "UPDATE `tblScheduledJobRun` SET `startedAtUtc` = UTC_TIMESTAMP() - INTERVAL 10 DAY, "
    . "`finishedAtUtc` = UTC_TIMESTAMP() - INTERVAL 10 DAY WHERE `jobKey` = 'cron/giving-statements'"
);
$late = $named($pageFor($db), 'have not run for longer');
check('Q7 Giving off but a statement waiting to be sent: the job ten days quiet IS overdue',
    $late === null ? [] : $late['examples'],
    ['Sending giving statements — last ran 10 days ago']);
$putToken($db, 'giving.cron_token', '');
$dead = $named($pageFor($db), 'can never run');
check('Q7b and with its token empty, it IS reported as unable to run — it has work it cannot do',
    $dead === null ? [] : $dead['examples'],
    ['Sending giving statements']);
$db->query("UPDATE `tblGivingStatementLog` SET `emailedAt` = UTC_TIMESTAMP() WHERE `periodKey` = 'zz-selftest-793'");
check('Q7c once that statement has gone, Giving off means nothing to do again: not reported',
    $named($pageFor($db), 'can never run'), null);

// The work-waiting query must be the JOB's own selection. Two copies of one
// condition drift (the round-2 fault was exactly a page and a job disagreeing
// about what the job would do), so the job's WHERE clause and the class's are
// compared as text, backticks aside.
$jobPage = (string) file_get_contents(PORTAL_APPS . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR
    . 'giving-statements.php');
$jobWhere = preg_match("/'WHERE (queuedAt[^']*?) '/", $jobPage, $m) === 1 ? $m[1] : '(not found)';
// The class adds ONE condition the job's WHERE clause does not carry — that
// the organisation is active — because the job skips such rows a step later
// (`Site::forceContext()` refuses them). It is taken off before comparing, and
// Q8b requires it to be there; Q9c/Q9d drive it.
$classFull  = str_replace('`', '', PortalCheck::JOB_WORK_WAITING['cron/giving-statements']);
$activeOnly = str_replace('`', '', PortalCheck::JOB_WORK_WAITING_ACTIVE_ONLY);
$classWhere = preg_match('/WHERE (.*)$/', str_replace($activeOnly, '', $classFull), $m) === 1
    ? $m[1] : '(not found)';
check('Q8 the page counts waiting statements by exactly the condition the giving job sends them by',
    [$classWhere, $jobWhere],
    ['queuedAt IS NOT NULL AND emailedAt IS NULL AND errorMsg IS NULL',
     'queuedAt IS NOT NULL AND emailedAt IS NULL AND errorMsg IS NULL']);
check('Q8b and the class adds exactly one condition beyond it: the organisation is active',
    str_ends_with($classFull, $activeOnly) && $activeOnly === ' AND siteID IN (SELECT siteID FROM tblSites WHERE isActive = 1)',
    true);

// ── Q9. An INACTIVE organisation using Giving does not make the job needed ──
// `featureInUse()` asks only about active organisations: a retired one keeps
// its rows, and its own switch must not keep a job alive for nobody.
$db->query("INSERT INTO `tblSites` (`siteName`, `siteKey`, `isActive`) VALUES ('zz selftest 793', 'zz-selftest-793', 0)");
$retired = (int) $db->insert_id;
$stmt = $db->prepare("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`) VALUES (?, 'giving.enabled', '1')");
$stmt->bind_param('i', $retired);
$stmt->execute();
$stmt->close();
check('Q9 Giving on only for a RETIRED organisation: the job is not needed, so its empty token is not reported',
    $named($pageFor($db), 'can never run'), null);
$db->query("UPDATE `tblSites` SET `isActive` = 1 WHERE `siteID` = " . $retired);
$dead = $named($pageFor($db), 'can never run');
check('Q9b and the same organisation made active again: it IS reported',
    $dead === null ? [] : $dead['examples'], ['Sending giving statements']);

// ── Q9c, Q9d. A statement queued for a RETIRED organisation is not work ──
// The job skips it (`Site::forceContext()` refuses an inactive organisation),
// so it must not make the job needed — before #793 round 3 it did, and the
// page reported a problem that could never clear.
$db->query("UPDATE `tblSites` SET `isActive` = 0 WHERE `siteID` = " . $retired);
$db->query("DELETE FROM `tblSettings` WHERE `siteID` = " . $retired);
$stmt = $db->prepare(
    'INSERT INTO `tblGivingStatementLog` (`siteID`, `donorID`, `periodKey`, `fromDate`, `toDate`, `queuedAt`) '
    . "VALUES (?, ?, 'zz-selftest-793b', '2026-01-01', '2026-03-31', UTC_TIMESTAMP() - INTERVAL 9 DAY)"
);
$stmt->bind_param('ii', $retired, $donor);
$stmt->execute();
$stmt->close();
check('Q9c a statement queued for a RETIRED organisation is not work: Giving off, empty token, not reported',
    $named($pageFor($db), 'can never run'), null);
$db->query("UPDATE `tblSites` SET `isActive` = 1 WHERE `siteID` = " . $retired);
$dead = $named($pageFor($db), 'can never run');
check('Q9d and the same organisation made active again: its statement IS work, so the empty token IS reported',
    $dead === null ? [] : $dead['examples'], ['Sending giving statements']);
$db->query("DELETE FROM `tblGivingStatementLog` WHERE `periodKey` = 'zz-selftest-793b'");
$db->query("DELETE FROM `tblSettings` WHERE `siteID` = " . $retired);
$db->query("DELETE FROM `tblSites` WHERE `siteID` = " . $retired);

// ── Q10, Q11. A read that FAILS answers "needed", never "nothing to do" ───
// ⚠️ THE DIRECTION IS THE POINT. Answering "not needed" on a failed read would
//    hide a dead job behind a database fault. Each read is broken by renaming
//    its table away; tidyUp() renames it back if a run dies in between.
check('Q10-0 premise: with both reads working, Giving off and no work waiting, the job is not reported',
    $named($pageFor($db), 'can never run'), null);
$db->query('RENAME TABLE `tblSites` TO `zz_selftest_793_sites`');
$dead = $named($pageFor($db), 'can never run');
$db->query('RENAME TABLE `zz_selftest_793_sites` TO `tblSites`');
check('Q10 when the organisations cannot be read, the job counts as needed and its empty token IS reported',
    $dead === null ? [] : $dead['examples'], ['Sending giving statements']);
$db->query('RENAME TABLE `tblGivingStatementLog` TO `zz_selftest_793_gsl`');
$dead = $named($pageFor($db), 'can never run');
$db->query('RENAME TABLE `zz_selftest_793_gsl` TO `tblGivingStatementLog`');
check('Q11 when the waiting statements cannot be counted, the job counts as needed and its empty token IS reported',
    $dead === null ? [] : $dead['examples'], ['Sending giving statements']);
$db->query("DELETE FROM `tblUsers` WHERE `emailAddress` = 'zz-selftest-793-donor@example.invalid'");
$db->query('DELETE FROM `tblScheduledJobRun`');

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
