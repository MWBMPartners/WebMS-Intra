<?php
// Path: tools/consent-retention-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The consent-record clear-out, driven against a real database 🍪🧹 (#934)
 * -----------------------------------------------------------------------------
 * Proves the nightly clear-out step `consent_sweep()` (in
 * `web/_apps/cron/_retention-sweep.php`) deletes from `tblConsentLog`
 * (D-166, D-210):
 *   - a record a LATER record of the same organisation, kind of choice and
 *     person has replaced, once the LATER record is older than the period;
 *   - a visitor's record nobody replaced, once it is older than the cookie's
 *     365 days PLUS the period;
 * and NOTHING ELSE: a signed-in person's latest record of a kind is never
 * deleted however old, a record is never replaced by another organisation's
 * record or another kind of choice, two organisations follow their own
 * figures, "keep for ever" deletes nothing, the surviving rows are compared
 * WHOLE before and after, the count shown first equals what is deleted, a
 * second run deletes nothing, and a big backlog is deleted in batches (the
 * database's own count of DELETE statements says so).
 *
 * THEN EACH GUARD IS PLANTED OUT OF A COPY of the files and the scenario that
 * depends on it must go red (and be green on the real files first, or the
 * plant proves nothing). Each scenario runs in its own PHP process because a
 * class cannot be loaded twice into one process.
 *
 * WHAT IT CANNOT SHOW: the nightly job page calling the step (one line,
 * `run_retention_sweep()`), nor the Retention page's forms (see
 * tools/activity-log-retention-selftest.php section N), nor the privacy
 * page's words (tools/erasure-pages-selftest.php). A member who has LEFT
 * (D-211, package 2h) is driven by sections L01-L16 below.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_… SELFTEST_DB_HOST=… SELFTEST_DB_PORT=… \
 *         SELFTEST_DB_USER=… SELFTEST_DB_PASS=… php tools/consent-retention-selftest.php
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/934
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const CR_EXPECTED_CHECKS = 44;

$crDbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($crDbName, 'selftest_') === false) {
    echo "REFUSED — set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$crDbName}').\n";
    exit(1);
}
$crEnv = [
    'SELFTEST_DB_NAME' => $crDbName,
    'SELFTEST_DB_HOST' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    'SELFTEST_DB_USER' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    'SELFTEST_DB_PASS' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    'SELFTEST_DB_PORT' => getenv('SELFTEST_DB_PORT') !== false ? (string) getenv('SELFTEST_DB_PORT') : '3306',
];

/** The classes the sweep file loads, in the copy a plant is made from. */
const CR_CLASSES = ['InstallationSettings', 'App', 'SettingLimit', 'RetentionPeriod', 'ActivityLogRetention',
    'NetworkAddressRetention', 'ConsentRetention', 'GeocodeCacheRetention', 'Memberships', 'AccountMerge'];

// =============================================================================
// 🧒 THE CHILD: run ONE scenario against the files under `$argv[3]`.
// =============================================================================
if (($argv[1] ?? '') === '--scenario') {
    $web = (string) ($argv[3] ?? '');
    $sep = DIRECTORY_SEPARATOR;
    foreach (CR_CLASSES as $class) {
        require_once $web . $sep . '_core' . $sep . $class . '.php';
    }
    require_once $web . $sep . '_apps' . $sep . 'cron' . $sep . '_retention-sweep.php';

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($crEnv['SELFTEST_DB_HOST'], $crEnv['SELFTEST_DB_USER'], $crEnv['SELFTEST_DB_PASS'],
        $crEnv['SELFTEST_DB_NAME'], (int) $crEnv['SELFTEST_DB_PORT']);
    $db->set_charset('utf8mb4');
    // 🕙 The database session's zone is TEN HOURS from UTC for every scenario, set here rather
    //    than by a server flag so any database the test is pointed at behaves the same. `NOW()` and
    //    a `DEFAULT CURRENT_TIMESTAMP` follow the session zone while `UTC_TIMESTAMP()` does not;
    //    that gap is what lets a clear-out that mixed the two clocks FAIL (#934, D-211, plant P19).
    $db->query("SET time_zone = '+10:00'");
    \Portal\Core\App::init($db, []);

    try {
        $answer = cr_scenario($db, (string) $argv[2]);
    } catch (\Throwable $e) {
        $answer = ['ok' => false, 'detail' => 'threw ' . get_class($e) . ': ' . $e->getMessage()];
    }
    echo json_encode($answer);
    exit(0);
}

/** Set the figure at one level (null = installation; value null = remove the row) and hand start-up's layer over. */
function cr_set(mysqli $db, ?int $siteId, ?string $value): void
{
    $key = \Portal\Core\ConsentRetention::KEY;
    $del = $db->prepare('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND ' . ($siteId === null ? '`siteID` IS NULL' : '`siteID` = ?'));
    if ($siteId === null) {
        $del->bind_param('s', $key);
    } else {
        $del->bind_param('si', $key, $siteId);
    }
    $del->execute();
    $del->close();
    if ($value !== null) {
        $ins = $db->prepare('INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, ?, ?, \'\', 0)');
        $ins->bind_param('iss', $siteId, $key, $value);
        $ins->execute();
        $ins->close();
    }
    $inst = $db->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL');
    $inst->bind_param('s', $key);
    $inst->execute();
    $row = $inst->get_result()->fetch_assoc();
    $inst->close();
    \Portal\Core\InstallationSettings::remember($row === null ? [] : ['privacy' => ['dataRetentionDays' => (string) $row['settingValue']]]);
}

/**
 * 🌍 A fresh world. Each spec is [label, siteID, userID|null, session|null, kind, age in days, expected: 'del'|'keep'];
 *    rows go in OLDEST FIRST, because "later" means the higher number.
 *    Two organisations: 1 with its own figure of 30 days, 2 following the
 *    installation's 100. The cookie lasts 365, so a visitor's unreplaced
 *    record goes after 395 days at organisation 1 and 465 at organisation 2.
 *
 * @return array<string, array{id: int, expect: string}> label => row
 */
function cr_world(mysqli $db): array
{
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    $db->query("INSERT INTO `tblSites` (`siteID`, `siteName`, `siteKey`) VALUES (2, 'Second organisation', 'second') "
        . 'ON DUPLICATE KEY UPDATE `siteName` = VALUES(`siteName`)');
    $db->query('DELETE FROM `tblConsentLog`');
    cr_set($db, null, '100');
    cr_set($db, 1, '30');
    cr_set($db, 2, null);
    // 🚪 Package 2h's worlds put people and memberships in; start every world without them.
    foreach (['tblUserSites', 'tblAccountMergeSide', 'tblAccountMergeRequest'] as $gone) {
        $db->query('DELETE FROM `' . $gone . '`');
    }
    $db->query('DELETE FROM `tblUsers` WHERE `userID` >= 20');

    $specs = [
        // Organisation 1 (30 days): a signed-in person's chain of three. Two are replaced, one by a record 100 days old (past the period), one by 5 (not).
        ['u1c-200', 1, 11, 's-u1', 'cookies', 200, 'del'],
        ['u1c-100', 1, 11, 's-u1', 'cookies', 100, 'keep'],
        ['u1c-5',   1, 11, 's-u1', 'cookies', 5,   'keep'],
        // A signed-in person's only record of a kind, however old (the live consent): never deleted. Also tests the visitor rule does not reach it.
        ['u1p-400', 1, 11, 's-u1', 'privacy_policy', 400, 'keep'],
        // A visitor's session: the older record replaced by one 40 days old (past 30), and a different KIND of choice by the same session that nothing replaces.
        ['v1a-100', 1, null, 's-v1', 'analytics', 100, 'keep'],
        ['v1-60',   1, null, 's-v1', 'cookies', 60, 'del'],
        ['v1-40',   1, null, 's-v1', 'cookies', 40, 'keep'],
        // A visitor nobody replaced: gone only past 365 + 30 = 395 days.
        ['s2a-400', 1, null, 's-s2', 'cookies', 400, 'del'],
        ['s3b-390', 1, null, 's-s3', 'cookies', 390, 'keep'],
        ['s4-460',  1, null, 's-s4', 'cookies', 460, 'del'],
        ['null-500', 1, null, null, 'cookies', 500, 'del'],
        // Records with an empty session name nobody, so they replace nobody (but they are visitors, so only age counts).
        ['e1-50',   1, null, '', 'marketing', 50, 'keep'],
        ['e2-45',   1, null, '', 'marketing', 45, 'keep'],
        // A second person at organisation 1 and the SAME figures at organisation 2: gone at 1, kept at 2.
        ['u2-50',   1, 12, 's-u2', 'cookies', 50, 'del'],
        ['u2-40',   1, 12, 's-u2', 'cookies', 40, 'keep'],
        ['x2-50',   2, 12, 's-u2', 'cookies', 50, 'keep'],
        ['x2-40',   2, 12, 's-u2', 'cookies', 40, 'keep'],
        // Organisation 2 (100 days), the same person as u1: the 200-day record is replaced by a 120-day one.
        ['w1-200',  2, 11, 's-u1', 'cookies', 200, 'del'],
        ['w1-120',  2, 11, 's-u1', 'cookies', 120, 'keep'],
        ['w1-5',    2, 11, 's-u1', 'cookies', 5,   'keep'],
        // ANOTHER ORGANISATION'S newer record never replaces this one: 3's record at 2 is 300 days old; its only newer record is at 1, 150 days old (past 100).
        ['y3-300',  2, 13, 's-u3', 'cookies', 300, 'keep'],
        ['y3-150',  1, 13, 's-u3', 'cookies', 150, 'keep'],
        // A visitor at organisation 2: past 465 goes, 460 stays (the same 460 goes at organisation 1, see s4-460).
        ['sv-470',  2, null, 's-sv', 'cookies', 470, 'del'],
        ['sw-460',  2, null, 's-sw', 'cookies', 460, 'keep'],
    ];
    usort($specs, static fn (array $a, array $b): int => $b[5] <=> $a[5]);

    $rows = [];
    $ins  = $db->prepare('INSERT INTO `tblConsentLog` (`siteID`, `userID`, `sessionID`, `consentType`, `decision`, `policyHash`, `ipAddress`, `userAgent`, `createdAt`) '
        . 'VALUES (?, ?, ?, ?, \'accept\', ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? DAY))');
    foreach ($specs as [$label, $site, $user, $session, $kind, $age, $expect]) {
        $hash = hash('sha256', $label);
        $ip   = 'addr-' . $label;
        $ua   = 'agent-' . $label;
        $ins->bind_param('iisssssi', $site, $user, $session, $kind, $hash, $ip, $ua, $age);
        $ins->execute();
        $rows[$label] = ['id' => (int) $db->insert_id, 'expect' => $expect];
    }
    $ins->close();
    $db->query('SET FOREIGN_KEY_CHECKS = 1');

    return $rows;
}

/** @return array<int, string> every row of the table, whole, keyed by its number. */
function cr_fingerprint(mysqli $db): array
{
    $out = [];
    $res = $db->query('SELECT * FROM `tblConsentLog` ORDER BY `consentID`');
    while ($row = $res->fetch_assoc()) {
        $out[(int) $row['consentID']] = json_encode($row);
    }
    $res->free();

    return $out;
}

/** Number of DELETE statements this connection has run (the database's own counter). */
function cr_deletes(mysqli $db): int
{
    $res = $db->query("SHOW SESSION STATUS LIKE 'Com\\_delete'");

    return (int) $res->fetch_row()[1];
}

/** Set or remove the portal-wide organisation-mode setting (a stored text, exactly as the installer writes it). */
function cr_mode(mysqli $db, ?string $value): void
{
    $db->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'multisite.enabled' AND `siteID` IS NULL");
    if ($value !== null) {
        $ins = $db->prepare("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, 'multisite.enabled', ?, '', 0)");
        $ins->bind_param('s', $value);
        $ins->execute();
        $ins->close();
    }
}

/**
 * 🚪 Package 2h (#934, D-211): the member-has-left rule, driven through the REAL step.
 *    Every fixture is a state production can reach: offboarding leaves a membership
 *    off with a UTC moment, rehire empties it, a merge closes the duplicate's
 *    account under a made-up address. Dates are set by UPDATE as those would.
 *    Organisation 1 keeps records 30 days, organisation 2 follows 100.
 *
 * @return array{ok: bool, detail: string}
 */
function cr_left_scenario(mysqli $db, string $name): array
{
    cr_world($db);
    $db->query('DELETE FROM `tblConsentLog`');
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    cr_mode($db, $name === 'left-single' ? 'false' : ($name === 'left-single-upper' ? 'TRUE' : 'true'));
    if ($name === 'left-never') {
        cr_set($db, null, '0');
        cr_set($db, 1, '0');
        cr_set($db, 2, '0');
    }

    // [userID, account on, global administrator, email or null for an ordinary one]
    $people = [];
    // [userID, siteID, membership on, hours since it was switched off (null = no date)]
    $member = [];
    // [label, siteID, userID, kind, age in days, expected]
    $recs = [];
    $merges = [];
    if ($name === 'left-single' || $name === 'left-single-upper') {
        $people = [[51, 1, 0, null], [52, 0, 0, null]];
        $member = [[51, 1, 0, 24 * 60], [52, 1, 0, 24 * 60]];
        // L07: one organisation, membership off with an old date, account ON: kept. L08: account OFF too: deleted.
        $recs = [['L07-on-kept', 1, 51, 'cookies', 200, 'keep'], ['L08-off-gone', 1, 52, 'cookies', 200, 'del']];
        if ($name === 'left-single-upper') {
            // L09: the stored text TRUE is read as ONE organisation by PHP, so the account being on keeps the records.
            $recs = [['L09-upper-on-kept', 1, 51, 'cookies', 200, 'keep'], ['L09b-upper-off-gone', 1, 52, 'cookies', 200, 'del']];
        }
    } elseif ($name === 'left-never') {
        $people = [[21, 1, 0, null]];
        $member = [[21, 1, 0, 24 * 60]];
        $recs   = [['L13-never-kept', 1, 21, 'cookies', 200, 'keep']];
    } else {
        $people = [[21, 1, 0, null], [22, 1, 0, null], [23, 1, 0, null], [24, 1, 0, null], [25, 1, 1, null], [26, 1, 0, null],
            [27, 1, 0, null], [28, 0, 0, 'merged-28@merged.invalid'], [29, 1, 0, null], [30, 0, 0, 'merged-30@merged.invalid'],
            [31, 1, 0, null], [32, 1, 0, null], [40, 1, 0, null]];
        // 32 was merged into 31 and then REOPENED by hand (on, an ordinary address): no longer as the merge closed it.
        $merges = [[1, 27, 28], [2, 29, 30], [3, 31, 32]];
        $member = [
            [21, 1, 0, 31 * 24], [21, 2, 1, null],            // left organisation 1 31 days ago, still in 2
            [22, 1, 0, 29 * 24],                               // left 29 days ago
            [23, 1, 0, null],                                  // off with NO date
            [24, 1, 1, 90 * 24],                               // ON with a stray old date
            [25, 1, 0, 90 * 24],                               // a global administrator
            [26, 1, 0, 30 * 24 - 5],                           // left 5 hours short of the period, by UTC
            [27, 1, 0, 31 * 24], [29, 1, 1, null], [31, 1, 0, 31 * 24],
            [40, 1, 0, 31 * 24],
        ];
        $recs = [
            ['L01-old-gone', 1, 21, 'cookies', 200, 'del'], ['L03-recent-kept', 1, 21, 'analytics', 2, 'keep'],
            ['L01-T-kept', 2, 21, 'cookies', 200, 'keep'],
            ['L02-kept', 1, 22, 'cookies', 200, 'keep'], ['L04-nodate-kept', 1, 23, 'cookies', 200, 'keep'],
            ['L05-on-kept', 1, 24, 'cookies', 200, 'keep'], ['L06-global-kept', 1, 25, 'cookies', 200, 'keep'],
            ['L16-utc-kept', 1, 26, 'cookies', 200, 'keep'],
            ['L10-K-gone', 1, 27, 'cookies', 200, 'del'], ['L10-D-gone', 1, 28, 'cookies', 200, 'del'],
            ['L11-D2-kept', 1, 30, 'cookies', 200, 'keep'], ['L12-D3-kept', 1, 32, 'cookies', 200, 'keep'],
            // L14: both rules want these (the older is replaced by the newer, which is past the period; the person has left)
            ['L14-a-gone', 1, 40, 'cookies', 300, 'del'], ['L14-b-gone', 1, 40, 'cookies', 100, 'del'],
        ];
    }
    foreach ($people as [$id, $on, $root, $email]) {
        $address = $email ?? ('person-' . $id . '@example.invalid');
        $ins = $db->prepare('INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isRootAdmin`) VALUES (?, ?, ?, ?, ?)');
        $nm = 'Person ' . $id;
        $ins->bind_param('issii', $id, $nm, $address, $on, $root);
        $ins->execute();
        $ins->close();
    }
    foreach ($merges as [$req, $kept, $dup]) {
        $db->query('INSERT INTO `tblAccountMergeRequest` (`requestID`, `siteID`, `requestedAtUtc`, `expiresAtUtc`, `notBeforeUtc`, `status`) '
            . "VALUES ($req, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'completed')");
        $db->query("INSERT INTO `tblAccountMergeSide` (`requestID`, `userID`, `side`) VALUES ($req, $kept, 'kept'), ($req, $dup, 'duplicate')");
    }
    foreach ($member as [$user, $site, $on, $hours]) {
        $ins = $db->prepare('INSERT INTO `tblUserSites` (`userID`, `siteID`, `isActive`, `leftAtUtc`) '
            . 'VALUES (?, ?, ?, ' . ($hours === null ? 'NULL' : 'DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . (int) $hours . ' HOUR)') . ')');
        $ins->bind_param('iii', $user, $site, $on);
        $ins->execute();
        $ins->close();
    }
    usort($recs, static fn (array $a, array $b): int => $b[4] <=> $a[4]);
    $out = [];
    $ins = $db->prepare('INSERT INTO `tblConsentLog` (`siteID`, `userID`, `sessionID`, `consentType`, `decision`, `policyHash`, `createdAt`) '
        . 'VALUES (?, ?, ?, ?, \'accept\', ?, DATE_SUB(NOW(), INTERVAL ? DAY))');
    foreach ($recs as [$label, $site, $user, $kind, $age, $expect]) {
        $sess = 's-' . $label;
        $hash = hash('sha256', $label);
        $ins->bind_param('iisssi', $site, $user, $sess, $kind, $hash, $age);
        $ins->execute();
        $out[$label] = ['id' => (int) $db->insert_id, 'expect' => $expect];
    }
    $ins->close();
    $db->query('SET FOREIGN_KEY_CHECKS = 1');

    $before  = cr_fingerprint($db);
    $should  = count(array_filter($out, static fn (array $r): bool => $r['expect'] === 'del'));
    $counted = consent_sweep(false);
    $countChangedNothing = cr_fingerprint($db) === $before;
    $deleted = consent_sweep(true);
    $after   = cr_fingerprint($db);
    $wronglyKept = [];
    $wronglyGone = [];
    foreach ($out as $label => $row) {
        $present = array_key_exists($row['id'], $after);
        if ($row['expect'] === 'del' && $present === true) {
            $wronglyKept[] = $label;
        }
        if ($row['expect'] === 'keep' && $present === false) {
            $wronglyGone[] = $label;
        }
    }
    $same = true;
    foreach ($after as $id => $json) {
        if ($before[$id] !== $json) {
            $same = false;
        }
    }
    $second = consent_sweep(true);
    $ok = $wronglyKept === [] && $wronglyGone === [] && $counted === $should && $deleted === $should
        && $countChangedNothing === true && $same === true && $second === 0;

    return ['ok' => $ok, 'detail' => 'counted ' . $counted . ', deleted ' . $deleted . ', expected ' . $should
        . '; wrongly kept: ' . implode(',', $wronglyKept) . '; wrongly gone: ' . implode(',', $wronglyGone)
        . '; survivors unchanged: ' . var_export($same, true) . '; second run deleted ' . $second];
}

/**
 * 🚪 Package 2h round 2 (#934, D-211, D-213): a person who RETURNS, then is PAUSED.
 *    One organisation: offboarding left their membership off with a date; they come back
 *    through the Active tick on Admin, Users (which runs Memberships::SWITCH_ON_ONE_IF_SINGLE_SQL
 *    in the same transaction as the account write); a later untick pauses them; the real clear-out
 *    must then delete NOTHING of theirs. Control: the same person where the statement is NOT run
 *    loses their records (proves the harm is real and the world can show it). Several
 *    organisations: the statement does nothing and the person who left stays left.
 *
 * @return array{ok: bool, detail: string}
 */
function cr_return_scenario(mysqli $db, string $name): array
{
    cr_world($db);
    $db->query('DELETE FROM `tblConsentLog`');
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    $single = $name === 'return-single';
    cr_mode($db, $single === true ? 'false' : 'true');
    // [userID, runs the statement]
    $people = $single === true ? [[61, true], [62, false]] : [[64, true]];
    foreach ($people as [$id, $_]) {
        $ins = $db->prepare('INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`) VALUES (?, ?, ?, 0)');
        $nm = 'Person ' . $id;
        $mail = 'person-' . $id . '@example.invalid';
        $ins->bind_param('iss', $id, $nm, $mail);
        $ins->execute();
        $ins->close();
        // As offboarding leaves them: membership off, dated 60 days ago (UTC), account off.
        $db->query("INSERT INTO `tblUserSites` (`userID`, `siteID`, `isActive`, `leftAtUtc`) VALUES ({$id}, 1, 0, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 DAY))");
        $db->query("INSERT INTO `tblConsentLog` (`siteID`, `userID`, `sessionID`, `consentType`, `decision`, `policyHash`, `createdAt`) "
            . "VALUES (1, {$id}, 's-{$id}', 'cookies', 'accept', 'h{$id}', DATE_SUB(NOW(), INTERVAL 200 DAY))");
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
    $affected = [];
    foreach ($people as [$id, $runs]) {
        // The Active tick: the account goes on; the page then runs the statement.
        $db->query("UPDATE `tblUsers` SET `isActive` = 1 WHERE `userID` = {$id}");
        if ($runs === true) {
            $st = $db->prepare(\Portal\Core\Memberships::SWITCH_ON_ONE_IF_SINGLE_SQL);
            $site = 1;
            $st->bind_param('ii', $id, $site);
            $st->execute();
            $affected[$id] = (int) $st->affected_rows;
            $st->close();
        }
        // Later an administrator unticks Active to PAUSE them.
        $db->query("UPDATE `tblUsers` SET `isActive` = 0 WHERE `userID` = {$id}");
    }
    $before  = (int) $db->query('SELECT COUNT(*) FROM `tblConsentLog`')->fetch_row()[0];
    $counted = consent_sweep(false);
    $deleted = consent_sweep(true);
    $left    = (int) $db->query('SELECT COUNT(*) FROM `tblConsentLog`')->fetch_row()[0];
    $dateOf  = static function (int $id) use ($db): string {
        $r = $db->query("SELECT `isActive`, `leftAtUtc` FROM `tblUserSites` WHERE `userID` = {$id}")->fetch_assoc();
        return $r['isActive'] . '/' . ($r['leftAtUtc'] === null ? 'empty' : 'dated');
    };
    if ($single === true) {
        // 61 came back through the statement: kept, membership on and empty. 62 did not: deleted (the premise).
        $ok = $before === 2 && $counted === 1 && $deleted === 1 && $left === 1 && ($affected[61] ?? -1) === 1
            && $dateOf(61) === '1/empty' && $dateOf(62) === '0/dated'
            && (int) $db->query('SELECT COUNT(*) FROM `tblConsentLog` WHERE `userID` = 61')->fetch_row()[0] === 1;
    } else {
        // Several organisations: the statement changes nothing; the person left and is cleared out.
        $ok = $before === 1 && ($affected[64] ?? -1) === 0 && $dateOf(64) === '0/dated' && $counted === 1 && $deleted === 1 && $left === 0;
    }

    return ['ok' => $ok, 'detail' => 'before ' . $before . ', counted ' . $counted . ', deleted ' . $deleted . ', left ' . $left
        . ', statement affected ' . json_encode($affected) . ', 61 ' . ($single === true ? $dateOf(61) : '-') . ', 62 ' . ($single === true ? $dateOf(62) : '-')];
}

/**
 * A clear-out that cannot read something must neither stop the rest of the sweep nor delete more.
 * The settings table is renamed for the call (put back in every case), so the membership test's
 * own read of the organisation mode fails.
 *
 * @return array{ok: bool, detail: string}
 */
function cr_failed_read_scenario(mysqli $db): array
{
    cr_left_world_only($db);
    $before = (int) $db->query('SELECT COUNT(*) FROM `tblConsentLog`')->fetch_row()[0];
    $db->query('RENAME TABLE `tblSettings` TO `tblSettings_cr_away`');
    try {
        try {
            $counted = run_consent_sweep(false);
            $deleted = run_consent_sweep(true);
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => 'run_consent_sweep let an exception out: ' . get_class($e)];
        }
    } finally {
        $db->query('RENAME TABLE `tblSettings_cr_away` TO `tblSettings`');
    }
    $left = (int) $db->query('SELECT COUNT(*) FROM `tblConsentLog`')->fetch_row()[0];

    return ['ok' => $counted === -1 && $deleted === -1 && $left === $before && $before > 0,
        'detail' => 'counted ' . $counted . ', deleted ' . $deleted . ', rows ' . $before . ' -> ' . $left];
}

/** One left member with old records, in the several-organisation mode (enough to make the clear-out do real work). */
function cr_left_world_only(mysqli $db): void
{
    cr_world($db);
    $db->query('DELETE FROM `tblConsentLog`');
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    cr_mode($db, 'true');
    $db->query("INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`) VALUES (71, 'Person 71', 'person-71@example.invalid', 1)");
    $db->query('INSERT INTO `tblUserSites` (`userID`, `siteID`, `isActive`, `leftAtUtc`) VALUES (71, 1, 0, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 DAY))');
    $db->query("INSERT INTO `tblConsentLog` (`siteID`, `userID`, `sessionID`, `consentType`, `decision`, `policyHash`, `createdAt`) "
        . "VALUES (1, 71, 's-71', 'cookies', 'accept', 'h71', DATE_SUB(NOW(), INTERVAL 200 DAY))");
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
}

function cr_scenario(mysqli $db, string $name): array
{
    $CR = \Portal\Core\ConsentRetention::class;

    switch ($name) {
        case 'two-organisations':
            $rows   = cr_world($db);
            $before = cr_fingerprint($db);
            $should = array_keys(array_filter($rows, static fn (array $r): bool => $r['expect'] === 'del'));
            $counted = consent_sweep(false);
            $unchangedByCount = cr_fingerprint($db) === $before;
            $deleted = consent_sweep(true);
            $after   = cr_fingerprint($db);
            $gone    = [];
            $wronglyKept = [];
            $wronglyGone = [];
            foreach ($rows as $label => $row) {
                $present = array_key_exists($row['id'], $after);
                if ($row['expect'] === 'del' && $present === true) {
                    $wronglyKept[] = $label;
                }
                if ($row['expect'] === 'keep' && $present === false) {
                    $wronglyGone[] = $label;
                }
                if ($present === false) {
                    $gone[] = $label;
                }
            }
            // Every surviving row is exactly as it was: nothing but deletion happened.
            $survivorsSame = true;
            foreach ($after as $id => $json) {
                if ($before[$id] !== $json) {
                    $survivorsSame = false;
                }
            }
            $second = consent_sweep(true);
            $ok = $wronglyKept === [] && $wronglyGone === [] && $counted === count($should) && $deleted === count($should)
                && $unchangedByCount === true && $survivorsSame === true && $second === 0;
            return ['ok' => $ok, 'detail' => 'counted ' . $counted . ', deleted ' . $deleted . ', expected ' . count($should)
                . '; wrongly kept: ' . implode(',', $wronglyKept) . '; wrongly gone: ' . implode(',', $wronglyGone)
                . '; count changed nothing: ' . var_export($unchangedByCount, true) . '; survivors unchanged: ' . var_export($survivorsSame, true)
                . '; second run deleted ' . $second];

        case 'own-never-under-never':
            $rows = cr_world($db);
            cr_set($db, null, '0');
            cr_set($db, 1, '0');
            cr_set($db, 2, '0');
            $n = consent_sweep(true);
            $left = (int) $db->query('SELECT COUNT(*) FROM `tblConsentLog`')->fetch_row()[0];
            return ['ok' => $n === 0 && $left === count($rows), 'detail' => 'deleted ' . $n . ', left ' . $left . ' of ' . count($rows)];

        case 'own-never-under-days':
            // The installation says 100; an organisation's stored "for ever" (SQL only) is held to 100: 'u1c-100' style rows older than 100 go.
            $rows = cr_world($db);
            cr_set($db, 1, '0');
            cr_set($db, 2, '0');
            consent_sweep(true);
            $after = cr_fingerprint($db);
            // Under 100 days: u2-50 (replaced by a 40-day-old record) stays; u1c-200 (replaced by a 100-day-old record, not older than 100 exactly) is judged at 100.
            $okKept = array_key_exists($rows['u2-50']['id'], $after) === true && array_key_exists($rows['u1c-5']['id'], $after) === true;
            // Visitor at organisation 1 aged 460: past 100 + 365 = 465? No, so it STAYS; aged 400 stays too. The 500-day one goes.
            $okVisitors = array_key_exists($rows['s4-460']['id'], $after) === true && array_key_exists($rows['null-500']['id'], $after) === false;
            return ['ok' => $okKept && $okVisitors, 'detail' => 'u2-50 and u1c-5 kept: ' . var_export($okKept, true) . '; 460 kept and 500 gone: ' . var_export($okVisitors, true)];

        case 'own-above-installation':
            // An organisation's own figure ABOVE the installation's (only SQL or a restore stores one) is held to the installation's.
            $rows = cr_world($db);
            cr_set($db, null, '40');
            cr_set($db, 1, '3000');
            $days = $CR::daysForSite(1);
            consent_sweep(true);
            $after = cr_fingerprint($db);
            // At 40 days u2-50 (replaced by a record 40 days old, NOT older than 40) stays; v1-60 (replaced by one 40 old) stays; u1c-200 goes (replaced by 100).
            $ok = $days === 40 && array_key_exists($rows['u1c-200']['id'], $after) === false && array_key_exists($rows['u1c-100']['id'], $after) === true;
            return ['ok' => $ok, 'detail' => 'days in force ' . $days];

        case 'caps-refused':
            cr_world($db);
            cr_set($db, null, '100');
            $above = $CR::saveForSite($db, 1, 200, true);
            $never = $CR::saveForSite($db, 1, 0, true);
            $fine  = $CR::saveForSite($db, 1, 50, true);
            $notAdmin = $CR::saveForSite($db, 2, 10, false);
            $inst  = $CR::saveInstallation($db, 20, true);   // below organisation 1's own 50: refused
            $ok = $above['outcome'] === 'refused' && $never['outcome'] === 'refused' && $fine['outcome'] === 'stored'
                && $notAdmin['outcome'] === 'refused' && $inst['outcome'] === 'refused'
                && $CR::daysForSite(1) === 50
                && (string) \Portal\Core\App::settingForSite('audit.retentionDays', 1) !== '50'; // its own key only
            return ['ok' => $ok, 'detail' => json_encode([$above['outcome'], $never['outcome'], $fine['outcome'], $notAdmin['outcome'], $inst['outcome'], $CR::daysForSite(1)])];

        case 'batches':
            // 2 batches and a part: BATCH * 2 + 7 old visitor records at organisation 1, 4 at organisation 2 that must stay.
            cr_world($db);
            $db->query('DELETE FROM `tblConsentLog`');
            cr_set($db, 1, '30');
            $total = $CR::BATCH * 2 + 7;
            $db->query('SET FOREIGN_KEY_CHECKS = 0');
            $ins = $db->prepare("INSERT INTO `tblConsentLog` (`siteID`, `userID`, `sessionID`, `consentType`, `decision`, `createdAt`) VALUES (1, NULL, ?, 'cookies', 'accept', DATE_SUB(NOW(), INTERVAL 2000 DAY))");
            for ($i = 0; $i < $total; $i++) {
                $s = 'bulk-' . $i;
                $ins->bind_param('s', $s);
                $ins->execute();
            }
            $ins->close();
            for ($i = 0; $i < 4; $i++) {
                $db->query("INSERT INTO `tblConsentLog` (`siteID`, `userID`, `sessionID`, `consentType`, `decision`, `createdAt`) VALUES (2, NULL, 'keep-" . $i . "', 'cookies', 'accept', DATE_SUB(NOW(), INTERVAL 5 DAY))");
            }
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
            $d0 = cr_deletes($db);
            $n  = consent_sweep(true);
            $statements = cr_deletes($db) - $d0;
            $left = (int) $db->query('SELECT COUNT(*) FROM `tblConsentLog`')->fetch_row()[0];
            // Every statement is bounded: at least ceil(total / BATCH) of them.
            $ok = $n === $total && $left === 4 && $statements >= (int) ceil($total / $CR::BATCH);
            return ['ok' => $ok, 'detail' => 'deleted ' . $n . ' of ' . $total . ', left ' . $left . ', DELETE statements ' . $statements];

        case 'premise':
            $diff = (int) $db->query('SELECT TIMESTAMPDIFF(HOUR, UTC_TIMESTAMP(), NOW())')->fetch_row()[0];
            return ['ok' => $diff === 10, 'detail' => 'NOW() is ' . $diff . ' hours ahead of UTC_TIMESTAMP()'];

        case 'return-single':
        case 'return-multi':
            return cr_return_scenario($db, $name);

        case 'left-failed-read':
            return cr_failed_read_scenario($db);

        case 'left-multi':
        case 'left-never':
        case 'left-single':
        case 'left-single-upper':
            return cr_left_scenario($db, $name);

        case 'own-key-only':
            $db->query("DELETE FROM `tblSettings` WHERE `siteID` = 1 AND `settingKey` IN ('audit.retentionDays', 'privacy.dataRetentionDays', 'privacy.networkAddressDays')");
            cr_set($db, null, '730');
            $before = \Portal\Core\App::settingForSite('audit.retentionDays', 1);
            $before2 = \Portal\Core\App::settingForSite('privacy.networkAddressDays', 1);
            $r = $CR::saveForSite($db, 1, 60, true);
            $row = $db->query("SELECT `settingKey` FROM `tblSettings` WHERE `siteID` = 1 AND `settingKey` IN ('audit.retentionDays', 'privacy.dataRetentionDays', 'privacy.networkAddressDays')")->fetch_all();
            $keys = array_map(static fn (array $x): string => (string) $x[0], $row);
            return ['ok' => $r['outcome'] === 'stored' && $keys === ['privacy.dataRetentionDays']
                && \Portal\Core\App::settingForSite('audit.retentionDays', 1) === $before
                && \Portal\Core\App::settingForSite('privacy.networkAddressDays', 1) === $before2,
                'detail' => 'keys written: ' . implode(',', $keys)];
    }

    return ['ok' => false, 'detail' => 'unknown scenario ' . $name];
}

// =============================================================================
// 🧑‍⚖️ THE PARENT
// =============================================================================
$crPass  = 0;
$crFail  = 0;
$crCheck = static function (string $what, bool $ok, string $detail = '') use (&$crPass, &$crFail): void {
    $ok === true ? $crPass++ : $crFail++;
    echo ($ok === true ? 'PASS' : 'FAIL') . ' — ' . $what . ($ok === true || $detail === '' ? '' : "\n       " . $detail) . "\n";
};
$crRun = static function (string $scenario, string $web) use ($crEnv): array {
    $cmd   = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --scenario ' . escapeshellarg($scenario) . ' ' . escapeshellarg($web);
    $pipes = [];
    $proc  = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__, $crEnv + ['PATH' => (string) getenv('PATH')]);
    $out   = stream_get_contents($pipes[1]);
    $err   = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $answer = json_decode((string) $out, true);

    return is_array($answer) === true ? $answer : ['ok' => false, 'detail' => 'no answer: ' . trim($out . ' ' . $err)];
};
$crWeb = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web';

echo "A. The real files\n";
$crScenarios = [
    'two-organisations'      => 'A two organisations (own 30, following 100), replaced and visitor records inside and outside each period, by account and by session: exactly the due rows go, the count matches and changes nothing, survivors are whole, a second run deletes nothing, a live signed-in record and another organisation\'s or kind\'s newer record never count',
    'own-never-under-never'  => 'B1 "keep for ever" everywhere deletes nothing',
    'own-never-under-days'   => 'B2 an organisation\'s stored "keep for ever" under an installation of 100 days is held to 100',
    'own-above-installation' => 'C an organisation\'s own figure above the installation\'s (SQL or a restore) is held to the installation\'s',
    'caps-refused'           => 'D a figure above the installation\'s, "keep for ever" under a number of days, a non-administrator, and an installation figure below an organisation\'s own are all refused; a figure within the cap is stored',
    'batches'                => 'E a backlog of two batches and a part is deleted in at least that many bounded statements and another organisation\'s young records stay',
    'premise'                => 'L15 premise: this database\'s NOW() is ten hours ahead of UTC_TIMESTAMP() (without that the clock plant below could not fail)',
    'left-multi'             => 'L01-L06, L10-L12, L14, L16 several organisations: a member who left an organisation more than its period ago has every old record there deleted (merged accounts followed only while still closed); still-a-member, left too recently (by the UTC clock), no leaving date, switched on, a global administrator, a record made recently and another organisation\'s records all stay; the preview equals the deletion',
    'left-never'             => 'L13 an organisation that keeps records for ever deletes nothing for a member who left',
    'left-single'            => 'L07-L08 ONE organisation: a left membership with the account still on keeps the records, with the account off deletes them',
    'left-single-upper'      => 'L09 the one-organisation setting stored as TRUE is read as one organisation (as PHP reads it)',
    'return-single'          => 'R1 ONE organisation (D-213): offboarded, brought back with the Active tick (which runs the Memberships statement), later paused with the tick: the real clear-out deletes NOTHING of theirs, while the same person where the statement was not run loses their records',
    'return-multi'           => 'R2 several organisations: switching the account on does not give back an organisation they left, so the date stands and the records are cleared out',
    'left-failed-read'       => 'R3 a clear-out that cannot read the organisation mode answers "could not" (-1) for the count and the clear-out, throws nothing out, and deletes nothing',
    'own-key-only'           => 'F saving the figure writes its own setting and never the activity log\'s or the network-address one',
];
foreach ($crScenarios as $scenario => $label) {
    $answer = $crRun($scenario, $crWeb);
    $crCheck($label, $answer['ok'] === true, (string) ($answer['detail'] ?? ''));
}

// 🍪 The cookie's life, from the file that sets it, so the two cannot drift.
$crConsentPage = (string) file_get_contents($crWeb . '/_apps/privacy/consent.php');
$crCheck('G consent.php sets its cookie for exactly COOKIE_DAYS days', preg_match('/time\(\) \+ \((\d+) \* 86400\)/', $crConsentPage, $crM) === 1
    && (int) $crM[1] === 365 && str_contains((string) file_get_contents($crWeb . '/_core/ConsentRetention.php'), 'COOKIE_DAYS = 365;') === true,
    'cookie lifetime and ConsentRetention::COOKIE_DAYS disagree');
// 🚫 No unbounded delete of the log anywhere in the sweep file.
$crSweepSrc = (string) file_get_contents($crWeb . '/_apps/cron/_retention-sweep.php');
$crCheck('H the sweep deletes from tblConsentLog only by number and organisation, in bounded batches', substr_count($crSweepSrc, 'DELETE FROM `tblConsentLog`') === 2
    && str_contains($crSweepSrc, "`siteID` = ? AND `consentID` IN (") === true
    && str_contains($crSweepSrc, "DELETE FROM `tblConsentLog` WHERE `siteID` = ? AND `userID` = ? ") === true && str_contains($crSweepSrc, 'LIMIT \' . (int) ConsentRetention::BATCH') === true);

echo "P. Each guard planted out of a copy: its scenario must go red\n";
/** Copy the files a scenario loads, apply ONE edit, run, and answer whether the edit applied and the scenario still passed. */
$crPlant = static function (string $relPath, string $from, string $to, string $scenario) use ($crWeb, $crRun): array {
    $copy = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cr-plant-' . bin2hex(random_bytes(4));
    $sep  = DIRECTORY_SEPARATOR;
    foreach (['_core', '_apps' . $sep . 'cron'] as $dir) {
        mkdir($copy . $sep . $dir, 0700, true);
    }
    foreach (CR_CLASSES as $class) {
        copy($crWeb . $sep . '_core' . $sep . $class . '.php', $copy . $sep . '_core' . $sep . $class . '.php');
    }
    copy($crWeb . $sep . '_apps' . $sep . 'cron' . $sep . '_retention-sweep.php', $copy . $sep . '_apps' . $sep . 'cron' . $sep . '_retention-sweep.php');
    copy($crWeb . $sep . '_core' . $sep . 'version.php', $copy . $sep . '_core' . $sep . 'version.php');
    $path    = $copy . $sep . str_replace('/', $sep, $relPath);
    $before  = (string) file_get_contents($path);
    $applied = substr_count($before, $from) === 1;
    if ($applied === true) {
        file_put_contents($path, str_replace($from, $to, $before));
    }
    $answer = $applied === true ? $crRun($scenario, $copy) : ['ok' => null, 'detail' => ''];
    // 🚫 A plant that CRASHED the copy proves nothing: only a real verdict of "not ok" counts.
    $detail = (string) ($answer['detail'] ?? '');
    if ($answer['ok'] === false && (str_starts_with($detail, 'no answer') === true || str_starts_with($detail, 'threw ') === true)) {
        $answer['ok'] = 'crashed: ' . substr($detail, 0, 200);
    }
    foreach (glob($copy . $sep . '_core' . $sep . '*') ?: [] as $f) {
        unlink($f);
    }
    unlink($copy . $sep . '_apps' . $sep . 'cron' . $sep . '_retention-sweep.php');
    rmdir($copy . $sep . '_apps' . $sep . 'cron');
    rmdir($copy . $sep . '_apps');
    rmdir($copy . $sep . '_core');
    rmdir($copy);
    if (getenv('CR_SHOW_PLANTS') === '1') {
        echo '       planted run saw: ' . substr($detail, 0, 600) . "\n";
    }

    return [$applied, $answer['ok']];
};
$crPlants = [
    ['P1 the replacing record\'s age test turned round (a record counted as replaced by a record YOUNGER than the period)', '_core/ConsentRetention.php',
        "`userID` IS NOT NULL AND `createdAt` < DATE_SUB", "`userID` IS NOT NULL AND `createdAt` > DATE_SUB", 'two-organisations'],
    ['P2 organisation ignored when finding the replacing record (both its filter and its join)', '_core/ConsentRetention.php',
        "   WHERE `siteID` = ? AND `userID` IS NOT NULL AND `createdAt` < DATE_SUB(NOW(), INTERVAL ? DAY)'\n            . '   GROUP BY `siteID`, `consentType`, `userID`) AS `mu`'\n            . '   ON `mu`.`siteID` = `t`.`siteID` AND `mu`.`consentType`",
        "   WHERE ? > 0 AND `userID` IS NOT NULL AND `createdAt` < DATE_SUB(NOW(), INTERVAL ? DAY)'\n            . '   GROUP BY `siteID`, `consentType`, `userID`) AS `mu`'\n            . '   ON `mu`.`consentType`", 'two-organisations'],
    ['P3 kind of choice ignored when finding the replacing record', '_core/ConsentRetention.php',
        "AND `mu`.`consentType` = `t`.`consentType` AND `mu`.`userID`", "AND `mu`.`userID`", 'two-organisations'],
    ['P4 "later" ignored (a record may be replaced by one that is not newer, so the newest goes too)', '_core/ConsentRetention.php',
        "`t`.`consentID` < `mu`.`newest`", "`t`.`consentID` <> `mu`.`newest`", 'two-organisations'],
    ['P5 an empty session counts as a person', '_core/ConsentRetention.php',
        " AND `sessionID` <> \\'\\'", "", 'two-organisations'],
    ['P6 the cookie\'s 365 days left out of the visitor rule', '_core/ConsentRetention.php',
        '$days + self::COOKIE_DAYS]', '$days]', 'two-organisations'],
    ['P7 the visitor rule reaching a signed-in person\'s live record', '_core/ConsentRetention.php',
        "OR (`t`.`userID` IS NULL AND `t`.`createdAt` < DATE_SUB", "OR (1 = 1 AND `t`.`createdAt` < DATE_SUB", 'two-organisations'],
    ['P8 the "keep for ever" skip taken out', '_apps/cron/_retention-sweep.php',
        "        if (\$days === ConsentRetention::NEVER) {\n            continue; // ♾️ This organisation keeps these records", "        if (false) {\n            continue; // ♾️ This organisation keeps these records", 'own-never-under-never'],
    ['P9 the delete unbounded (no LIMIT on the selection)', '_apps/cron/_retention-sweep.php',
        " . ' ORDER BY `t`.`consentID` LIMIT ' . (int) ConsentRetention::BATCH);", " . ' ORDER BY `t`.`consentID`');", 'batches'],
    ['P12 the switched-on rule dropped (a switched-on row with a stray old date counts as left)', '_core/Memberships.php',
        "'(' . \$us . '.`isActive` = 0 AND '", "'(1 = 1 AND '", 'left-multi'],
    ['P13 the global-administrator rule dropped', '_core/Memberships.php',
        "' AND ' . \$p . '.`isRootAdmin` = 0'", "' AND 1 = 1'", 'left-multi'],
    ['P14 the one-organisation term dropped (an account still switched on no longer protects the records)', '_core/Memberships.php',
        "' AND (' . \$p . '.`isActive` = 0 OR EXISTS (", "' AND (1 = 1 OR EXISTS (", 'left-single'],
    ['P15 the exact-text reading of the setting dropped (TRUE read as several organisations)', '_core/Memberships.php',
        "CAST(`msx`.`settingValue` AS BINARY) = CAST(\\'true\\' AS BINARY))))'", "`msx`.`settingValue` = \\'true\\')))'", 'left-single-upper'],
    ['P16 merge following skipped', '_apps/cron/_retention-sweep.php',
        '$kept = AccountMerge::survivorOf($db, $u);', '$kept = null;', 'left-multi'],
    ['P17 the still-closed test skipped', '_apps/cron/_retention-sweep.php',
        "if (\$stillClosed === false) {\n                continue;", "if (false) {\n                continue;", 'left-multi'],
    ['P18 the record\'s own date test dropped from the delete', '_apps/cron/_retention-sweep.php',
        "AND `createdAt` < DATE_SUB(NOW(), INTERVAL ? DAY) '\n                        . 'AND EXISTS", "AND ? > 0 '\n                        . 'AND EXISTS", 'left-multi'],
    ['P19 the UTC clock swapped for the database session clock', '_core/Memberships.php',
        "DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)'", "DATE_SUB(NOW(), INTERVAL ? DAY)'", 'left-multi'],
    ['P20 the organisation ignored in the left rule\'s delete', '_apps/cron/_retention-sweep.php',
        "'DELETE FROM `tblConsentLog` WHERE `siteID` = ? AND `userID` = ? '", "'DELETE FROM `tblConsentLog` WHERE ? > 0 AND `userID` = ? '", 'left-multi'],
    ['P21 the preview counts a row due under both rules twice', '_apps/cron/_retention-sweep.php',
        '$leftIds = ($reallyDelete === false) ? array_keys($left) : [];', '$leftIds = [];', 'left-multi'],
    ['P22 the return statement no longer empties the date', '_core/Memberships.php',
        "SET `isActive` = 1, `leftAtUtc` = NULL '\n        . 'WHERE `userID` = ? AND `siteID` = ? AND `isActive` = 0 '", "SET `isActive` = 1 '\n        . 'WHERE `userID` = ? AND `siteID` = ? AND `isActive` = 0 '", 'return-single'],
    ['P23 the return statement scoped to the WRONG organisation', '_core/Memberships.php',
        "WHERE `userID` = ? AND `siteID` = ? AND `isActive` = 0 '", "WHERE `userID` = ? AND `siteID` <> ? AND `isActive` = 0 '", 'return-single'],
    ['P24 the return statement ignores the organisation mode (clears a date where the person really left)', '_core/Memberships.php',
        "'AND NOT EXISTS (SELECT 1 FROM `tblSettings` `msx` WHERE `msx`.`settingKey` = \\'multisite.enabled\\' '", "'AND EXISTS (SELECT 1 FROM `tblSettings` `msx` WHERE `msx`.`settingKey` = \\'multisite.enabled\\' '", 'return-multi'],
    ['P25 the clear-out lets a failure out (the wrapper rethrows)', '_apps/cron/_retention-sweep.php',
        "'cleared' : 'counted') . ' (' . get_class(\$e) . ').');\n\n        return -1;", "'cleared' : 'counted') . ' (' . get_class(\$e) . ').');\n\n        throw \$e;", 'left-failed-read'],
    ['P10 the reader\'s cap taken out (an organisation\'s own figure obeyed above the installation\'s)', '_core/RetentionPeriod.php',
        "        return SettingLimit::cappedForSite((int) \$text, static::KEY, static::DEFAULT_DAYS, self::HARD_MAX_DAYS, true);",
        "        return (int) \$text;", 'own-above-installation'],
    ['P11 the base class writing the activity log\'s key instead of the subclass\'s', '_core/RetentionPeriod.php',
        "        \$key = static::KEY;\n        // The installation's row keeps the built-in figure",
        "        \$key = \\Portal\\Core\\ActivityLogRetention::KEY;\n        // The installation's row keeps the built-in figure", 'own-key-only'],
];
foreach ($crPlants as [$label, $file, $from, $to, $scenario]) {
    [$applied, $stillOk] = $crPlant($file, $from, $to, $scenario);
    $crCheck($label . ': applied, and scenario "' . $scenario . '" goes red', $applied === true && $stillOk === false,
        'applied: ' . var_export($applied, true) . ', scenario still passed: ' . var_export($stillOk, true));
}

$crCheck('P0 with every plant gone again, the first scenario is green once more', $crRun('two-organisations', $crWeb)['ok'] === true);
$crCheck('the run made the number of checks it should (' . CR_EXPECTED_CHECKS . ')', $crPass + $crFail + 1 === CR_EXPECTED_CHECKS,
    'made ' . ($crPass + $crFail + 1));

echo "\n" . $crPass . ' passed, ' . $crFail . ' failed, ' . ($crPass + $crFail) . " checks.\n";
if ($crFail > 0) {
    echo "FAIL — see above.\n";
    exit(1);
}
echo 'PASS — all ' . ($crPass + $crFail) . " checks passed.\n";
exit(0);
