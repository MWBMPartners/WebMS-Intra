<?php
// Path: tools/outside-services-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Outside services, on the REAL pages — self-test 🛂 (#778; D-058, D-072)
 * -----------------------------------------------------------------------------
 * What a member's browser may tell an outside company about them: a Gravatar
 * picture, the photo a sign-in provider left on its servers, and a map of a
 * member's home. `Portal\Core\OutsideServices` decides, from the STRICTEST of
 * the installation, EVERY organisation the member belongs to, and the member's
 * own choice; for a map of somebody else, BOTH people must allow it.
 *
 * WHAT IT CHECKS
 *   A  the pure rule, every combination, against a separately written oracle,
 *      plus hand-pinned cases (a pin derived from the code proves nothing)
 *   B  an UNREADABLE level is "no": the installation layer never loaded,
 *      `tblUserSites` away, `tblSettings` away, the preference table away —
 *      each with a positive control, so "no" cannot pass on a dead fixture
 *   C  D-058 POINT 4 OVER HTTP: member M belongs to A and B; B's administrator
 *      says "no" THROUGH THE PAGE; M, reading A's pages, is sent no Gravatar
 *      address, sees the silhouette, and A's page rule names no Gravatar host —
 *      in the sign-in-session way AND the own-address way. Controls: a member of
 *      A alone does get it; with B's row removed M gets it; with M's B
 *      membership switched off M gets it
 *   D  the administrator page: an untouched Save changes nothing at either
 *      level (#674, #677), a looser organisation value is refused and the
 *      refusal is recorded, "follow" deletes, an organisation's administrator
 *      cannot post the installation's values, a member gets 403, no form token
 *      writes no log line, and a value the installation has since tightened
 *      below survives an untouched Save
 *   E  the member page: saving one service never touches another (#667), "on"
 *      under "no" is refused and the page names the refusing organisation and
 *      draws no control, "follow" deletes
 *   F  the general settings editor refuses `privacy.outside.*` on change, add
 *      and delete
 *   G  D-072 maps: drawn only when the member shown AND the person looking
 *      allow it; otherwise the location is text, no map, no tile host
 *   P  #809, D-120, D-124: a small group's usual MEETING PLACE is its NAMED
 *      HOST's home. Its map is drawn only where THAT host ticked "a map may be
 *      shown" on their own form — a leader naming the host cannot tick for them
 *      — and the tiers allow it for the host and the viewer, and the host still
 *      belongs to the group; otherwise the written address is still shown, with
 *      no map and no tile host. Everything goes through the REAL edit form and
 *      save handler and is read back from the database
 *   R  #809, D-123, D-124, D-125: a group that takes turns in SEVERAL homes,
 *      each naming its own host. One host's agreement, withdrawal, departure or
 *      switched-off account never touches another's; a member may add only
 *      their own home; each meeting records and shows its own place
 *   N  #809, D-128: a place marked "not anybody's home" (a church hall) shows
 *      its map with no host, and is still hidden by the viewer's, the
 *      organisation's or the installation's own map setting; an unmarked place
 *      with no host shows none; a changed address clears the mark; a host and
 *      the mark together are refused; a plain member cannot mark anything
 *   S  #809, D-126, D-127: members see where the group meets next (the place
 *      following the group's address setting, a map only with that home's
 *      host's agreement), a non-member sees nothing, a planned meeting takes no
 *      roll and no Attendance push and is left out of the report, and removing
 *      a home deletes its address at once
 *
 * WHAT IT CANNOT DO, said plainly
 *   - It cannot show what a real browser fetches. It reads the HTML and the page
 *     rule; a picture that is not in the HTML and not allowed by the rule cannot
 *     be fetched by a browser that obeys the rule, which every current one does.
 *   - It drives ONE worker, so two administrators saving at once is untested.
 *   - Sign-in through Google cannot be driven, so the sign-in photo is given to a
 *     member by writing `avatarPath` directly, which is the state such a sign-in
 *     leaves.
 *
 * SAFETY
 *   Writes rows, so it REFUSES unless the database name starts `selftest_`.
 *   Its people are numbered 918001-918099 and its organisations 918101-918102;
 *   they are removed at the START and the END. It changes the installation's
 *   `multisite.*` and `privacy.outside.*` rows and puts them back. In section B
 *   it RENAMES `tblUserSites`, `tblSettings` and `tblUserOutsideServicePref`
 *   away, one at a time, and back — run it alone on its database.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_outside SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         php tools/outside-services-selftest.php
 *         The database must already hold `web/_sql/full_schema.sql`.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/778
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);

$passed = 0;
$failed = 0;
$state  = ['proc' => null, 'child' => null, 'settings' => [], 'renamed' => [], 'started' => false, 'cleaned' => false];

const ORG_A = 918101;
const ORG_B = 918102;
const HOST_A = 'a.outside.selftest.invalid';
const HOST_B = 'b.outside.selftest.invalid';
const NAME_B = 'Selftest Outside Bee';
const M      = 918001; // member of A and B
const AONLY  = 918002; // member of A alone
const BADMIN = 918003; // administrator of B alone
const AADMIN = 918004; // administrator of A alone (not global)
const GLOBAL_ADMIN = 918005; // global administrator
const PLAIN  = 918006; // ordinary member of A
const SUBJ   = 918007; // a member of A whose home has a map
const VIEWER = 918008; // a member of A who looks at it
const MEMBER_X = 918021; // two members with ADJACENT numbers, for section T's
const MEMBER_Y = 918022; // "two different members saving at once"
const HOSTP  = 918031; // section P: a group's leader, who enters its meeting place
const HOST2  = 918032; // section R: a second host, whose home the group also meets in
const MEMBERP = 918033; // sections R and S: a plain member of the group
const SGROUP = 918201; // section P: the small group

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
        echo '      ' . $detail . "\n";
    }
}

function head(string $title): void
{
    echo "\n{$title}\n";
}

// ── What it needs ────────────────────────────────────────────────────────────
$dbName = (string) getenv('SELFTEST_DB_NAME');
if (preg_match('/^selftest_[A-Za-z0-9_]+$/', $dbName) !== 1) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}')");
}
foreach (['curl_init', 'proc_open', 'token_get_all'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this test needs {$needed}(), which this PHP does not have");
    }
}
$dbHost = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
$dbUser = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
$dbPass = getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '';
$dbPort = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;
$port   = getenv('SELFTEST_WEB_PORT') !== false ? (int) getenv('SELFTEST_WEB_PORT') : 9778;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
} catch (Throwable $e) {
    refuse("could not reach {$dbName} on {$dbHost}:{$dbPort}");
}
$tables = (int) ($db->query('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetch_assoc()['n'] ?? 0);
if ($tables < 100) {
    refuse("the database holds only {$tables} tables — load web/_sql/full_schema.sql first");
}

/** Run one statement with bound values; answers the statement's result or true. */
function q(string $sql, string $types = '', array $values = [])
{
    global $db;
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    return $result === false ? true : $result;
}

/** One row, or null. */
function one(string $sql, string $types = '', array $values = []): ?array
{
    $result = q($sql, $types, $values);
    if ($result === true) {
        return null;
    }
    $row = $result->fetch_assoc();

    return $row === null ? null : $row;
}

/** What is stored for a key at a level (null = installation), or null if no row. */
function stored(string $key, ?int $site): ?string
{
    $row = $site === null
        ? one('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [$key])
        : one('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ?', 'si', [$key, $site]);

    return $row === null ? null : (string) $row['settingValue'];
}

/** Set (or with null, delete) a value at a level. */
function setAt(string $key, ?int $site, ?string $value): void
{
    if ($site === null) {
        q('UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingKey` = ? AND `siteID` IS NULL', 'ss', [(string) $value, $key]);
        return;
    }
    q('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ?', 'si', [$key, $site]);
    if ($value !== null) {
        q('INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, ?, ?, ?, 0)', 'isss', [$site, $key, $value, $value]);
    }
}

/** A member's stored choice, or null. */
function choiceOf(int $user, string $service): ?string
{
    $row = one('SELECT `choice` FROM `tblUserOutsideServicePref` WHERE `userID` = ? AND `serviceKey` = ?', 'is', [$user, $service]);

    return $row === null ? null : (string) $row['choice'];
}

function setChoice(int $user, string $service, ?string $choice): void
{
    q('DELETE FROM `tblUserOutsideServicePref` WHERE `userID` = ? AND `serviceKey` = ?', 'is', [$user, $service]);
    if ($choice !== null) {
        q('INSERT INTO `tblUserOutsideServicePref` (`userID`, `serviceKey`, `choice`) VALUES (?, ?, ?)', 'iss', [$user, $service, $choice]);
    }
}

/** How many activity records of one kind an account has. */
function logged(int $user, string $kind): int
{
    return (int) (one('SELECT COUNT(*) AS n FROM `tblActivityLogs` WHERE `userID` = ? AND `activityType` = ?', 'is', [$user, $kind])['n'] ?? -1);
}

/** Every Outside* record an account has. */
function loggedOutside(int $user): int
{
    return (int) (one("SELECT COUNT(*) AS n FROM `tblActivityLogs` WHERE `userID` = ? AND `activityType` LIKE 'OutsideService%'", 'i', [$user])['n'] ?? -1);
}

const K_GRAV = 'privacy.outside.gravatar';
const K_PHOTO = 'privacy.outside.providerPhoto';
const K_MAP = 'privacy.outside.memberMap';

/** Remove everything of this run's people and organisations, children first. */
function removeWorld(): void
{
    global $db;
    $people = [M, AONLY, BADMIN, AADMIN, GLOBAL_ADMIN, PLAIN, SUBJ, VIEWER, MEMBER_X, MEMBER_Y, HOSTP, HOST2, MEMBERP];
    $list   = implode(',', $people);
    $rs = $db->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'tblUsers'");
    while ($row = $rs->fetch_assoc()) {
        try {
            $db->query('DELETE FROM `' . $row['TABLE_NAME'] . '` WHERE `' . $row['COLUMN_NAME'] . "` IN ({$list})");
        } catch (Throwable $e) {
            // A table whose own parent is already gone, or another shape.
        }
    }
    $db->query("DELETE FROM `tblActivityLogs` WHERE `userID` IN ({$list})");
    $db->query("DELETE FROM `tblUsers` WHERE `userID` IN ({$list})");
    $sites = ORG_A . ',' . ORG_B;
    $rs = $db->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'tblSites'");
    while ($row = $rs->fetch_assoc()) {
        try {
            $db->query('DELETE FROM `' . $row['TABLE_NAME'] . '` WHERE `' . $row['COLUMN_NAME'] . "` IN ({$sites})");
        } catch (Throwable $e) {
        }
    }
    $db->query("DELETE FROM `tblSites` WHERE `siteID` IN ({$sites})");
}

/** Put any renamed table back. Safe to call at any time. */
function putTablesBack(): void
{
    global $db;
    foreach (['tblUserSites', 'tblSettings', 'tblUserOutsideServicePref'] as $table) {
        $away = $table . '_outside_away';
        $hasAway = (int) $db->query("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$away}'")->fetch_assoc()['n'];
        $hasReal = (int) $db->query("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'")->fetch_assoc()['n'];
        if ($hasAway === 1 && $hasReal === 0) {
            $db->query("RENAME TABLE `{$away}` TO `{$table}`");
        }
    }
}

function cleanup(): void
{
    global $state;
    if ($state['started'] === false || $state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
        $state['proc'] = null;
    }
    try {
        putTablesBack();
        foreach ($state['settings'] as $key => $before) {
            if ($before === null) {
                q('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [(string) $key]);
            } else {
                q('UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingID` = ?', 'si', [$before['settingValue'], (int) $before['settingID']]);
            }
        }
        removeWorld();
    } catch (Throwable $e) {
        echo 'FAIL — the clean-up could not put the database back: ' . $e->getMessage() . "\n";
    }
    $child = (string) $state['child'];
    if ($child !== '' && is_dir($child) === true && str_starts_with($child, sys_get_temp_dir()) === true) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $entry) {
            $entry->isDir() === true ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($child);
    }
}

// ── Set-up ──────────────────────────────────────────────────────────────────
$state['started'] = true;
register_shutdown_function('cleanup');
putTablesBack();
removeWorld();

foreach (['multisite.enabled', 'multisite.detectionMode', 'small-groups.enabled', K_GRAV, K_PHOTO, K_MAP] as $key) {
    $state['settings'][$key] = one('SELECT `settingID`, `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [$key]);
}
foreach ([K_GRAV, K_PHOTO, K_MAP] as $key) {
    if ($state['settings'][$key] === null) {
        refuse("the installation has no {$key} row — the database predates migration 255");
    }
}
foreach (['multisite.enabled' => 'true', 'multisite.detectionMode' => 'session'] as $key => $value) {
    if ($state['settings'][$key] === null) {
        q('INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, ?, ?, ?, 0)', 'sss', [$key, $value, $value]);
    }
}

function setWay(string $way): void
{
    q("UPDATE `tblSettings` SET `settingValue` = 'true' WHERE `settingKey` = 'multisite.enabled' AND `siteID` IS NULL");
    q("UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingKey` = 'multisite.detectionMode' AND `siteID` IS NULL", 's', [$way]);
}

q('INSERT INTO `tblSites` (`siteID`, `siteKey`, `siteName`, `hostPattern`, `isActive`) VALUES (?, ?, ?, ?, 1)', 'isss', [ORG_A, 'selftest-outside-a', 'Selftest Outside Ay', HOST_A]);
q('INSERT INTO `tblSites` (`siteID`, `siteKey`, `siteName`, `hostPattern`, `isActive`) VALUES (?, ?, ?, ?, 1)', 'isss', [ORG_B, 'selftest-outside-b', NAME_B, HOST_B]);
$people = [
    M => ['m', 0], AONLY => ['aonly', 0], BADMIN => ['badmin', 0], AADMIN => ['aadmin', 0],
    GLOBAL_ADMIN => ['global', 1], PLAIN => ['plain', 0], SUBJ => ['subj', 0], VIEWER => ['viewer', 0],
    MEMBER_X => ['memberx', 0], MEMBER_Y => ['membery', 0], HOSTP => ['hostp', 0], HOST2 => ['host2', 0], MEMBERP => ['memberp', 0],
];
$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
foreach ($people as $id => [$who, $root]) {
    q('INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `createdAt`) VALUES (?, ?, ?, 1, 0, ?, NOW())',
        'issi', [$id, 'Selftest Outside ' . $who, $who . '.outside@selftest.invalid', $root]);
    q('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-outside-' . $who, $hash]);
}
foreach ([[M, ORG_A, 0], [M, ORG_B, 0], [AONLY, ORG_A, 0], [BADMIN, ORG_B, 1], [AADMIN, ORG_A, 1], [GLOBAL_ADMIN, ORG_A, 0],
          [PLAIN, ORG_A, 0], [SUBJ, ORG_A, 0], [VIEWER, ORG_A, 0], [MEMBER_X, ORG_A, 0], [MEMBER_Y, ORG_A, 0],
          [HOSTP, ORG_A, 0], [HOST2, ORG_A, 0], [MEMBERP, ORG_A, 0]] as [$user, $site, $admin]) {
    q('INSERT INTO `tblUserSites` (`userID`, `siteID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES (?, ?, 1, ?, 0)', 'iii', [$user, $site, $admin]);
}
// The map's member: a home location, shown to all members.
q("UPDATE `tblUsers` SET `latitude` = 52.2053, `longitude` = 0.1218, `visibilityName` = 'members', `visibilityCoords` = 'members' WHERE `userID` = ?", 'i', [SUBJ]);
setWay('session');
foreach ([K_GRAV, K_PHOTO, K_MAP] as $key) {
    setAt($key, null, 'off');
}

// ── A copy of web/, so a planted fault never touches the repository ─────────
$child = sys_get_temp_dir() . $sep . 'outside-services-' . bin2hex(random_bytes(6));
$state['child'] = $child;
mkdir($child, 0700, true);
$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo . $sep . 'web', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
$copied = 0;
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
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\nreturn "
    . var_export(['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort], true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));
file_put_contents($child . $sep . 'router.php', <<<'ROUTER'
<?php
// Written by tools/outside-services-selftest.php for ONE run. Imitates .htaccess.
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
ROUTER);

// =============================================================================
// A. THE PURE RULE — before the server, on the copy's own class
// =============================================================================
$core = $child . $sep . 'web' . $sep . '_core' . $sep;
require $core . 'InstallationSettings.php';
require $core . 'Site.php';
require $core . 'OutsideServices.php';
use Portal\Core\InstallationSettings;
use Portal\Core\OutsideServices;
use Portal\Core\Site;

head('A. The rule, on facts');
// The oracle, written in a different SHAPE from the class on purpose: every
// level that holds a row must be exactly "off" or "on", or nothing is allowed;
// the default is "on" only if EVERY such level says "on".
$oracle = static function (?string $inst, array $orgs, ?string $member): bool {
    $levels = [$inst];
    foreach ($orgs as $o) {
        if ($o !== null) {
            $levels[] = $o;
        }
    }
    foreach ($levels as $l) {
        if ($l === null || in_array(trim($l), ['off', 'on'], true) === false) {
            return false;
        }
    }
    if ($member !== null && trim($member) !== 'on') {
        return false;
    }
    if ($member !== null) {
        return true;
    }
    foreach ($levels as $l) {
        if (trim((string) $l) !== 'on') {
            return false;
        }
    }

    return true;
};
$values  = [null, 'no', 'off', 'on', '', 'ON', 'x'];
$orgSets = [[]];
foreach ([null, 'no', 'off', 'on', 'x'] as $a) {
    $orgSets[] = [$a];
    foreach ([null, 'no', 'off', 'on'] as $b) {
        $orgSets[] = [$a, $b];
    }
}
$members = [null, 'on', 'off', 'x'];
$cases = 0;
$wrong = [];
foreach ($values as $inst) {
    foreach ($orgSets as $orgs) {
        foreach ($members as $member) {
            $cases++;
            $got  = OutsideServices::effectiveFrom($inst, $orgs, $member);
            $want = $oracle($inst, $orgs, $member);
            if ($got !== $want && count($wrong) < 5) {
                $wrong[] = var_export([$inst, $orgs, $member], true) . ' gave ' . var_export($got, true);
            }
        }
    }
}
check("A01 every combination ({$cases}) agrees with the oracle", $wrong === [], implode(' | ', $wrong));
check('A01b the combination count is pinned (a shrunken matrix tests less)', $cases === 7 * 26 * 4, "got {$cases}");
// Hand-pinned cases, written out, not derived.
check('A02 installation on, nobody chose: yes', OutsideServices::effectiveFrom('on', [], null) === true);
check('A03 installation off, nobody chose: no', OutsideServices::effectiveFrom('off', [], null) === false);
check('A04 installation off, member on: yes', OutsideServices::effectiveFrom('off', [], 'on') === true);
check('A05 installation on, ONE of two organisations says no, member on: NO (point 4)', OutsideServices::effectiveFrom('on', [null, 'no'], 'on') === false);
check('A06 installation off, an organisation says on: still only off (cannot loosen)', OutsideServices::effectiveFrom('off', ['on'], null) === false);
check('A07 installation no, organisation on, member on: no', OutsideServices::effectiveFrom('no', ['on'], 'on') === false);
check('A08 member off beats everything on', OutsideServices::effectiveFrom('on', ['on', 'on'], 'off') === false);
check('A09 a missing installation value is no', OutsideServices::effectiveFrom(null, [], 'on') === false);
check('A10 viewer side (D-072): allowed and not switched off is enough', OutsideServices::doesNotObjectFrom('off', [null], null) === true);
check('A11 viewer side: switched off objects', OutsideServices::doesNotObjectFrom('on', [], 'off') === false);
check('A12 viewer side: not allowed objects', OutsideServices::doesNotObjectFrom('on', ['no'], null) === false);
check('A13 the general editor refuses every privacy.outside key, in any capitals', OutsideServices::lockedInGeneralEditor('Privacy.Outside.Gravatar') === true
    && OutsideServices::lockedInGeneralEditor('privacy.outside.memberMap') === true && OutsideServices::lockedInGeneralEditor('privacy.controllerName') === false);
// D-072: a map is never on by default. decide() reads a stored "on" for maps as "off".
$mapFacts = static fn (?string $inst, ?string $org, ?string $member): array => ['readable' => true,
    'installation' => ['memberMap' => $inst], 'organisations' => [['siteID' => 1, 'name' => 'x', 'values' => ['memberMap' => $org]]],
    'member' => $member === null ? [] : ['memberMap' => $member]];
check('A14 maps: an installation "on" (hand-written) with nobody choosing is NOT on', OutsideServices::decide(OutsideServices::MEMBER_MAP, $mapFacts('on', null, null))['effective'] === false);
check('A15 maps: an organisation "on" is not on either', OutsideServices::decide(OutsideServices::MEMBER_MAP, $mapFacts('on', 'on', null))['effective'] === false);
check('A16 maps: the member switching it on still works', OutsideServices::decide(OutsideServices::MEMBER_MAP, $mapFacts('on', null, 'on'))['effective'] === true);
check('A17 control: a PICTURE service "on" with nobody choosing IS on (the cap is for maps only)',
    OutsideServices::decide(OutsideServices::GRAVATAR, ['readable' => true, 'installation' => ['gravatar' => 'on'], 'organisations' => [], 'member' => []])['effective'] === true);
check('A18 the values a level may hold: maps no/off, pictures no/off/on', OutsideServices::levelValuesFor('memberMap') === ['no', 'off']
    && OutsideServices::levelValuesFor('gravatar') === ['no', 'off', 'on']);

// =============================================================================
// B. AN UNREADABLE LEVEL IS "NO"
// =============================================================================
head('B. A level that cannot be read counts as not allowed');
setAt(K_GRAV, null, 'on');
$decideM = static function () use ($db): array {
    OutsideServices::forget();
    Site::reset();

    return OutsideServices::decide(OutsideServices::GRAVATAR, OutsideServices::factsFor($db, AONLY, ORG_A));
};
$d = $decideM();
check('B01 the installation layer never loaded: no', $d['effective'] === false && $d['readable'] === false);
InstallationSettings::remember(['privacy' => ['outside' => ['gravatar' => 'on']]]);
$d = $decideM();
check('B02 control: with the layer loaded and "on", a member of A alone gets it', $d['effective'] === true && $d['readable'] === true);
InstallationSettings::remember(['privacy' => ['outside' => []]]);
$d = $decideM();
check('B02b the layer loaded but the key missing: no', $d['effective'] === false && $d['readable'] === true);
InstallationSettings::remember(['privacy' => ['outside' => ['gravatar' => 'on']]]);
foreach (['tblUserSites', 'tblSettings', 'tblUserOutsideServicePref'] as $i => $table) {
    $db->query("RENAME TABLE `{$table}` TO `{$table}_outside_away`");
    try {
        $d = $decideM();
    } finally {
        $db->query("RENAME TABLE `{$table}_outside_away` TO `{$table}`");
    }
    check('B0' . (3 + $i) . " {$table} unreadable: no, and never 'not chosen'", $d['effective'] === false && $d['readable'] === false);
    $d = $decideM();
    check('B0' . (3 + $i) . 'b control: with it back, yes again', $d['effective'] === true);
}
setAt(K_GRAV, null, 'off');

// =============================================================================
// The server
// =============================================================================
$startServer = static function () use (&$state, $child, $sep, $port): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
        $state['proc'] = null;
        usleep(400000);
    }
    $env = getenv();
    unset($env['PHP_CLI_SERVER_WORKERS']);
    $env['PORTAL_ENV'] = 'prod';
    $state['proc'] = proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log',
         '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'max_execution_time=120', '-d', 'opcache.enable=0',
         '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server.log', 'a'], 2 => ['file', $child . $sep . 'server.log', 'a']],
        $pipes,
        $child,
        $env
    );
    if (is_resource($state['proc']) === false) {
        refuse('could not start PHP\'s web server');
    }
};

/** One request; never follows a redirect. @return array{status:int, location:string, body:string, head:string} */
function req(string $jar, string $method, string $path, ?array $post = null, string $host = ''): array
{
    global $port;
    $handle  = curl_init('http://127.0.0.1:' . $port . $path);
    $headers = ['Expect:', 'Accept-Language: en'];
    if ($host !== '') {
        $headers[] = 'Host: ' . $host;
    }
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => $headers]);
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
        return ['status' => 0, 'location' => '', 'body' => '', 'head' => ''];
    }
    $size = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head = substr((string) $raw, 0, $size);

    return ['status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
            'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $m) === 1 ? $m[1] : '',
            'body' => substr((string) $raw, $size), 'head' => $head];
}

function token(string $html): string
{
    if (preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1) {
        return $m[1];
    }

    return preg_match('/<meta name="csrf-token" content="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

/**
 * 🪟 #815: the hidden "drawn" markers a page carries (`drawn_<service>`, the
 * value each control of the member page was drawn with), as a browser sends
 * them. Only those: other hidden fields belong to forms this helper does not
 * describe.
 *
 * @return array<string,string>
 */
function drawnMarkers(string $html): array
{
    $found = [];
    if (preg_match_all('#<input type="hidden" name="(drawn_[A-Za-z]+)" value="([^"]*)">#', $html, $mm, PREG_SET_ORDER) > 0) {
        foreach ($mm as $m) {
            $found[$m[1]] = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
        }
    }

    return $found;
}

/**
 * POST a form with a fresh token read from $formPage, as a browser would —
 * and, as a browser would, with the page's hidden "drawn" markers (#815) unless
 * the caller sends its own. A browser always sends a form's hidden fields, so a
 * post WITHOUT them is a hand-made request, which the tests ask for explicitly.
 */
function post(string $jar, string $formPage, string $to, array $fields, string $host = ''): array
{
    $page = req($jar, 'GET', $formPage, null, $host)['body'];
    $fields['csrf_token'] = token($page);
    $fields += drawnMarkers($page);

    return req($jar, 'POST', $to, $fields, $host);
}

/** Every control of the member page exactly as drawn: each select's selected value, the markers, the token. */
function memberFormAsDrawn(string $html): array
{
    $fields = [];
    foreach (['gravatar', 'providerPhoto', 'memberMap'] as $svc) {
        if (selectedIn($html, 'choice_' . $svc) !== '(no such select)') {
            $fields['choice_' . $svc] = selectedIn($html, 'choice_' . $svc);
        }
    }

    return $fields + drawnMarkers($html) + ['csrf_token' => token($html)];
}

/** The page rule's img-src directive. */
function imgSrc(array $answer): string
{
    return preg_match('/^Content-Security-Policy:.*?(img-src[^;]*);/mi', $answer['head'], $m) === 1 ? $m[1] : '(none)';
}

/** The value selected in one <select>, or '' when none is. */
function selectedIn(string $html, string $name): string
{
    if (preg_match('#<select[^>]*name="' . preg_quote($name, '#') . '"[^>]*>(.*?)</select>#s', $html, $m) !== 1) {
        return '(no such select)';
    }

    return preg_match('#<option value="([^"]*)"[^>]*\bselected\b#', $m[1], $o) === 1 ? $o[1] : '';
}

/** Which organisation a session is in, from /health. */
function orgSeen(string $jar, string $host = ''): int
{
    $answer = req($jar, 'GET', '/health', null, $host);
    $json   = json_decode($answer['body'], true);

    return ($answer['status'] === 200 && is_array($json) === true) ? (int) ($json['siteID'] ?? 0) : -$answer['status'];
}

/** Sign in through the real form, and choose an organisation if asked to. */
function signIn(string $who, int $site = 0, string $host = ''): string
{
    global $child, $sep, $password;
    $jar = $child . $sep . 'jar-' . $who . '-' . bin2hex(random_bytes(3)) . '.txt';
    $tok = token(req($jar, 'GET', '/login', null, $host)['body']);
    $in  = req($jar, 'POST', '/login', ['csrf_token' => $tok, 'username' => 'selftest-outside-' . $who, 'password' => $password], $host);
    if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
        refuse("signing in as selftest-outside-{$who} did not work (HTTP {$in['status']} {$in['location']})");
    }
    if ($site > 0 && $host === '' && orgSeen($jar) !== $site) {
        $sw = post($jar, '/site/choose', '/site/switch', ['site_id' => $site]);
        if (orgSeen($jar) !== $site) {
            refuse("could not switch selftest-outside-{$who} to organisation {$site} (switch answered {$sw['status']})");
        }
    }

    return $jar;
}

$startServer();
$up = false;
for ($i = 0; $i < 80; $i++) {
    if (req('', 'GET', '/login')['status'] > 0) {
        $up = true;
        break;
    }
    usleep(250000);
}
if ($up === false) {
    refuse('the test server never answered');
}

const PLACEHOLDER = '/assets/images/avatar-placeholder.svg';

// =============================================================================
// C. D-058 POINT 4: ANOTHER ORGANISATION'S "NO" FOLLOWS THE MEMBER
// =============================================================================
head('C. A member of A and B, reading A\'s pages, is held to B\'s "no"');
setAt(K_GRAV, null, 'on');
$jarM     = signIn('m', ORG_A);
$jarAOnly = signIn('aonly', ORG_A);
$jarB     = signIn('badmin', ORG_B);
check('C00 premise: M is reading A, and B\'s administrator is in B', orgSeen($jarM) === ORG_A && orgSeen($jarB) === ORG_B);

$page = req($jarM, 'GET', '/account');
check('C01 control: before B says anything, M IS sent a Gravatar address on A\'s page', str_contains($page['body'], 'gravatar.com/avatar/') === true
    && str_contains(imgSrc($page), 'https://www.gravatar.com') === true, imgSrc($page));

$saved = post($jarB, '/admin/settings/outside-services', '/admin/settings/outside-services/save', ['action' => 'organisation', 'organisation_gravatar' => 'no']);
check('C02 B\'s administrator says "no" THROUGH THE PAGE, and it is stored as B\'s own row', $saved['status'] === 302 && stored(K_GRAV, ORG_B) === 'no',
    'answered ' . $saved['status'] . '; B row: ' . var_export(stored(K_GRAV, ORG_B), true));
check('C02b ...and that save is recorded', logged(BADMIN, 'OutsideServiceOrganisationSaved') === 1);

// Two pages that draw the top bar. NOT the front page: on 6 November 2026 the
// dashboard answered 500 from another package's work in progress (an SQL error
// at `dashboard/index.php` line 129), and a page this test does not own must not
// decide its verdict.
foreach (['/account' => 'the account page', '/directory' => 'the member directory'] as $path => $what) {
    $page = req($jarM, 'GET', $path);
    check("C03 THE WHOLE POINT, on {$what}: M, reading A, is sent NO Gravatar address", $page['status'] === 200 && str_contains($page['body'], 'gravatar.com') === false
        && str_contains($page['body'], '</html>') === true, 'status ' . $page['status']);
    check("C04 ...sees the silhouette instead, on {$what}", str_contains($page['body'], 'src="' . PLACEHOLDER . '"') === true);
    check("C05 ...and A's page rule names no Gravatar host, on {$what}", str_contains(strtolower(imgSrc($page)), 'gravatar') === false, imgSrc($page));
}
$page = req($jarAOnly, 'GET', '/account');
check('C06 control: a member of A ALONE is still sent it (B\'s no is about B\'s members)', str_contains($page['body'], 'gravatar.com/avatar/') === true
    && str_contains(imgSrc($page), 'https://www.gravatar.com') === true);

q('UPDATE `tblUserSites` SET `isActive` = 0 WHERE `userID` = ? AND `siteID` = ?', 'ii', [M, ORG_B]);
$page = req($jarM, 'GET', '/account');
check('C07 control: with M\'s membership of B switched off, M is sent it again', str_contains($page['body'], 'gravatar.com/avatar/') === true);
q('UPDATE `tblUserSites` SET `isActive` = 1 WHERE `userID` = ? AND `siteID` = ?', 'ii', [M, ORG_B]);

setAt(K_GRAV, ORG_B, null);
$page = req($jarM, 'GET', '/account');
check('C08 control: with B\'s row removed, M is sent it again', str_contains($page['body'], 'gravatar.com/avatar/') === true);
setAt(K_GRAV, ORG_B, 'no');

// The same, in the own-address way.
setWay('subdomain');
$jarMH = signIn('m', 0, HOST_A);
check('C09 premise: in the own-address way, M is reading A at A\'s address', orgSeen($jarMH, HOST_A) === ORG_A, 'saw ' . orgSeen($jarMH, HOST_A));
$page = req($jarMH, 'GET', '/account', null, HOST_A);
check('C10 own-address way: M is sent NO Gravatar address, and A\'s rule names none', $page['status'] === 200 && str_contains($page['body'], 'gravatar.com') === false
    && str_contains(strtolower(imgSrc($page)), 'gravatar') === false, 'status ' . $page['status'] . ' ' . imgSrc($page));
setAt(K_GRAV, ORG_B, null);
$page = req($jarMH, 'GET', '/account', null, HOST_A);
check('C11 own-address control: with B\'s row removed, M is sent it', str_contains($page['body'], 'gravatar.com/avatar/') === true);
setWay('session');

// The sign-in provider's photo, which is the same shape on another host.
setAt(K_PHOTO, null, 'on');
setAt(K_GRAV, null, 'off');
q("UPDATE `tblUsers` SET `avatarPath` = 'https://lh3.googleusercontent.com/a/selftest-photo' WHERE `userID` = ?", 'i', [M]);
$page = req($jarM, 'GET', '/account');
check('C12 control: the sign-in photo is drawn where allowed, and the rule allows Google\'s photo hosts', str_contains($page['body'], 'lh3.googleusercontent.com/a/selftest-photo') === true
    && str_contains(imgSrc($page), 'https://*.googleusercontent.com') === true, imgSrc($page));
setAt(K_PHOTO, ORG_B, 'no');
$page = req($jarM, 'GET', '/account');
check('C13 B says no to the photo: M is sent no address on Google\'s servers, and sees the silhouette', str_contains($page['body'], 'googleusercontent') === false
    && str_contains($page['body'], 'src="' . PLACEHOLDER . '"') === true && str_contains(imgSrc($page), 'googleusercontent') === false);
setAt(K_PHOTO, ORG_B, null);
setAt(K_PHOTO, null, 'off');
q('UPDATE `tblUsers` SET `avatarPath` = NULL WHERE `userID` = ?', 'i', [M]);
$page = req($jarM, 'GET', '/account');
check('C14 with everything off (the shipped default), the page rule is exactly what it was before #778', imgSrc($page) === "img-src 'self' data:", imgSrc($page));
setAt(K_PHOTO, null, 'on');
q("UPDATE `tblUsers` SET `avatarPath` = '//evil.selftest.invalid/x.png' WHERE `userID` = ?", 'i', [M]);
$page = req($jarM, 'GET', '/account');
check('C15 a protocol-relative stored address ("//host/...") is another website, not a local file: not drawn, silhouette instead',
    str_contains($page['body'], 'evil.selftest.invalid') === false && str_contains($page['body'], 'src="' . PLACEHOLDER . '"') === true);
q('UPDATE `tblUsers` SET `avatarPath` = NULL WHERE `userID` = ?', 'i', [M]);
setAt(K_PHOTO, null, 'off');

// =============================================================================
// D. THE ADMINISTRATOR PAGE
// =============================================================================
head('D. The administrator page');
$jarG = signIn('global', ORG_A);
setAt(K_GRAV, null, 'on');
setAt(K_GRAV, ORG_A, 'off');
$page = req($jarG, 'GET', '/admin/settings/outside-services');
check('D01 a global administrator sees both cards, and the page finished', $page['status'] === 200 && str_contains($page['body'], 'name="installation_gravatar"') === true
    && str_contains($page['body'], 'name="organisation_gravatar"') === true && str_contains($page['body'], '</html>') === true, 'status ' . $page['status']);
check('D02 #674: the installation box shows the INSTALLATION\'s value, not the open organisation\'s', selectedIn($page['body'], 'installation_gravatar') === 'on',
    selectedIn($page['body'], 'installation_gravatar'));
$fields = ['action' => 'installation'];
foreach (['gravatar', 'providerPhoto', 'memberMap'] as $svc) {
    $fields['installation_' . $svc] = selectedIn($page['body'], 'installation_' . $svc);
}
req($jarG, 'POST', '/admin/settings/outside-services/save', $fields + ['csrf_token' => token($page['body'])]);
check('D03 #674: an untouched Save of the installation card leaves the installation as it was', stored(K_GRAV, null) === 'on' && stored(K_GRAV, ORG_A) === 'off');

setAt(K_GRAV, ORG_A, null);
$page = req($jarG, 'GET', '/admin/settings/outside-services');
check('D04 #677: with no organisation row, the box says "follow the installation"', selectedIn($page['body'], 'organisation_gravatar') === 'inherit');
$fields = ['action' => 'organisation'];
foreach (['gravatar', 'providerPhoto', 'memberMap'] as $svc) {
    $fields['organisation_' . $svc] = selectedIn($page['body'], 'organisation_' . $svc);
}
req($jarG, 'POST', '/admin/settings/outside-services/save', $fields + ['csrf_token' => token($page['body'])]);
check('D05 #677: an untouched Save of the organisation card writes NO organisation row', stored(K_GRAV, ORG_A) === null && stored(K_MAP, ORG_A) === null);

$jarAA = signIn('aadmin', ORG_A);
// A PICTURE service here, because a map can never be "on" at any level
// (D-072) and is refused for that reason before this rule is reached.
setAt(K_GRAV, null, 'off');
$before = logged(AADMIN, 'OutsideServiceOrganisationRefused');
$saved  = post($jarAA, '/admin/settings/outside-services', '/admin/settings/outside-services/save', ['action' => 'organisation', 'organisation_gravatar' => 'on']);
check('D06 an organisation value LOOSER than the installation is refused, not stored', stored(K_GRAV, ORG_A) === null);
check('D07 ...the refusal is RECORDED', logged(AADMIN, 'OutsideServiceOrganisationRefused') === $before + 1);
$page = req($jarAA, 'GET', '/admin/settings/outside-services');
check('D07b ...and the person is told why', str_contains($page['body'], 'never to more') === true);
check('D07c an organisation administrator is not shown the installation card', str_contains($page['body'], 'name="installation_gravatar"') === false
    && str_contains($page['body'], 'name="organisation_gravatar"') === true);
check('D07d ...and is not OFFERED a value above the installation', preg_match('#<select[^>]*name="organisation_gravatar"[^>]*>(.*?)</select>#s', $page['body'], $mm) === 1
    && str_contains($mm[1], 'value="on"') === false && str_contains($mm[1], 'value="off"') === true);

setAt(K_GRAV, ORG_A, 'no');
post($jarAA, '/admin/settings/outside-services', '/admin/settings/outside-services/save', ['action' => 'organisation', 'organisation_gravatar' => 'inherit']);
check('D08 "follow the installation" DELETES the organisation\'s row', stored(K_GRAV, ORG_A) === null);

$before = logged(AADMIN, 'OutsideServiceInstallationRefused');
post($jarAA, '/admin/settings/outside-services', '/admin/settings/outside-services/save', ['action' => 'installation', 'installation_gravatar' => 'on']);
check('D09 an organisation administrator cannot post the installation\'s values', stored(K_GRAV, null) === 'off');
check('D09b ...and the attempt is recorded', logged(AADMIN, 'OutsideServiceInstallationRefused') === $before + 1);

$jarP = signIn('plain', ORG_A);
$answer = req($jarP, 'GET', '/admin/settings/outside-services');
check('D10 an ordinary member is refused the page', $answer['status'] === 403, 'status ' . $answer['status']);
$answer = post($jarP, '/account', '/admin/settings/outside-services/save', ['action' => 'organisation', 'organisation_gravatar' => 'no']);
check('D10b ...and the handler', $answer['status'] === 403 && stored(K_GRAV, ORG_A) === null, 'status ' . $answer['status']);

$before = loggedOutside(AADMIN);
req($jarAA, 'POST', '/admin/settings/outside-services/save', ['action' => 'organisation', 'organisation_gravatar' => 'no', 'csrf_token' => str_repeat('0', 64)]);
check('D11 no form token: nothing stored and NO log line', stored(K_GRAV, ORG_A) === null && loggedOutside(AADMIN) === $before);

setAt(K_GRAV, ORG_A, 'on'); // stored BEFORE the installation tightened to "off"
$page = req($jarAA, 'GET', '/admin/settings/outside-services');
check('D12 a value the installation has since held down is still the SELECTED option (a select with none is a vote for option one)',
    selectedIn($page['body'], 'organisation_gravatar') === 'on' && str_contains($page['body'], 'held down by the installation') === true,
    selectedIn($page['body'], 'organisation_gravatar'));
$fields = ['action' => 'organisation'];
foreach (['gravatar', 'providerPhoto', 'memberMap'] as $svc) {
    $fields['organisation_' . $svc] = selectedIn($page['body'], 'organisation_' . $svc);
}
$before = logged(AADMIN, 'OutsideServiceOrganisationRefused');
req($jarAA, 'POST', '/admin/settings/outside-services/save', $fields + ['csrf_token' => token($page['body'])]);
check('D13 ...and an untouched Save leaves it, without a refusal (#687: judge only what CHANGED)', stored(K_GRAV, ORG_A) === 'on'
    && logged(AADMIN, 'OutsideServiceOrganisationRefused') === $before);
setAt(K_GRAV, ORG_A, null);
setAt(K_GRAV, null, 'off');

// D-072: a map can never be made on by default, on either card.
setAt(K_MAP, null, 'off');
$page = req($jarG, 'GET', '/admin/settings/outside-services');
$optionsOf = static function (string $html, string $name): string {
    return preg_match('#<select[^>]*name="' . $name . '"[^>]*>(.*?)</select>#s', $html, $mm) === 1 ? $mm[1] : '(none)';
};
check('D14 the installation card does not OFFER "on" for maps, and does for a picture service (control)',
    str_contains($optionsOf($page['body'], 'installation_memberMap'), 'value="on"') === false
    && str_contains($optionsOf($page['body'], 'installation_memberMap'), 'value="off"') === true
    && str_contains($optionsOf($page['body'], 'installation_gravatar'), 'value="on"') === true);
$before = logged(GLOBAL_ADMIN, 'OutsideServiceInstallationRefused');
post($jarG, '/admin/settings/outside-services', '/admin/settings/outside-services/save', ['action' => 'installation', 'installation_memberMap' => 'on']);
check('D15 a posted installation "on" for maps is REFUSED, not stored, and recorded', stored(K_MAP, null) === 'off'
    && logged(GLOBAL_ADMIN, 'OutsideServiceInstallationRefused') === $before + 1);
setAt(K_MAP, null, 'on'); // hand-written: what no page can write
$page = req($jarAA, 'GET', '/admin/settings/outside-services');
check('D16 even with a hand-written installation "on", the organisation card does not offer "on" for maps',
    str_contains($optionsOf($page['body'], 'organisation_memberMap'), 'value="on"') === false);
$before = logged(AADMIN, 'OutsideServiceOrganisationRefused');
post($jarAA, '/admin/settings/outside-services', '/admin/settings/outside-services/save', ['action' => 'organisation', 'organisation_memberMap' => 'on']);
check('D17 ...and a posted organisation "on" for maps is REFUSED, not stored, and recorded', stored(K_MAP, ORG_A) === null
    && logged(AADMIN, 'OutsideServiceOrganisationRefused') === $before + 1);
setAt(K_MAP, null, 'off');

// =============================================================================
// E. THE MEMBER PAGE
// =============================================================================
head('E. A member\'s own page');
setAt(K_GRAV, null, 'on');
setAt(K_MAP, null, 'off');
setChoice(M, 'memberMap', 'on');
post($jarM, '/account/outside-services', '/account/outside-services-save', ['choice_gravatar' => 'off']);
check('E01 saving one service stores it', choiceOf(M, 'gravatar') === 'off');
check('E02 #667: ...and does NOT touch a service that was not posted', choiceOf(M, 'memberMap') === 'on');
check('E02b ...and the save is recorded', logged(M, 'OutsideServicePrefsUpdated') >= 1);
post($jarM, '/account/outside-services', '/account/outside-services-save', ['choice_gravatar' => 'follow']);
check('E03 "follow my organisations" deletes the member\'s row', choiceOf(M, 'gravatar') === null);

setAt(K_GRAV, ORG_B, 'no');
$page = req($jarM, 'GET', '/account/outside-services');
check('E04 where B says no, the page names B and draws NO control for it', $page['status'] === 200 && str_contains($page['body'], NAME_B) === true
    && str_contains($page['body'], 'name="choice_gravatar"') === false && str_contains($page['body'], 'name="choice_memberMap"') === true);
$before = logged(M, 'OutsideServicePrefsRefused');
post($jarM, '/account/outside-services', '/account/outside-services-save', ['choice_gravatar' => 'on']);
check('E05 "on" under a "no" is REFUSED and not stored', choiceOf(M, 'gravatar') === null);
check('E05b ...the refusal is recorded', logged(M, 'OutsideServicePrefsRefused') === $before + 1);
check('E05c ...and the reader would ignore such a row anyway (the reader is the enforcement)', (static function (): bool {
    setChoice(M, 'gravatar', 'on');
    global $jarM;
    $page = req($jarM, 'GET', '/account');
    setChoice(M, 'gravatar', null);

    return str_contains($page['body'], 'gravatar.com') === false;
})() === true);
post($jarM, '/account/outside-services', '/account/outside-services-save', ['choice_memberMap' => 'off']);
check('E06 switching OFF is never refused', choiceOf(M, 'memberMap') === 'off');
$download = req($jarM, 'GET', '/account/data-export');
$json     = json_decode((string) $download['body'], true);
$rows     = is_array($json) === true ? ($json['data']['outsideServiceChoices'] ?? null) : null;
check('E07 the member\'s own data download carries their outside-services choices', is_array($rows) === true
    && in_array(['memberMap', 'off'], array_map(static fn ($r): array => [(string) ($r['serviceKey'] ?? ''), (string) ($r['choice'] ?? '')], $rows), true) === true,
    'status ' . $download['status'] . '; rows ' . json_encode($rows));
setAt(K_GRAV, ORG_B, null);
setChoice(M, 'memberMap', null);
setAt(K_GRAV, null, 'off');

// =============================================================================
// F. THE GENERAL SETTINGS EDITOR REFUSES THESE KEYS
// =============================================================================
head('F. The general settings editor');
$instRow = one('SELECT `settingID` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [K_GRAV]);
post($jarG, '/settings', '/settings/save', ['settingID' => (int) $instRow['settingID'], 'settingKey' => K_GRAV, 'settingValue' => 'on']);
check('F01 changing the installation\'s row there is refused', stored(K_GRAV, null) === 'off');
post($jarG, '/settings', '/settings/save', ['settingID' => 0, 'settingKey' => K_MAP, 'settingValue' => 'on']);
check('F02 adding an organisation\'s row there is refused', stored(K_MAP, ORG_A) === null);
post($jarG, '/settings', '/settings', ['action' => 'delete', 'settingID' => (int) $instRow['settingID']]);
check('F03 deleting the installation\'s row there is refused', stored(K_GRAV, null) === 'off');
check('F04 each refusal is recorded', logged(GLOBAL_ADMIN, 'SettingsUpdateRefused') >= 1 && logged(GLOBAL_ADMIN, 'SettingsInsertRefused') >= 1
    && logged(GLOBAL_ADMIN, 'SettingsDeleteRefused') >= 1);

// =============================================================================
// G. D-072: A MAP OF SOMEBODY'S HOME NEEDS BOTH PEOPLE
// =============================================================================
head('G. Maps: the member shown AND the person looking');
$jarV = signIn('viewer', ORG_A);
$profile = static function () use ($jarV): array {
    return req($jarV, 'GET', '/directory/profile?id=' . SUBJ);
};
$mapDrawn = static fn (array $p): bool => str_contains($p['body'], 'portal-location-map') === true;
$tiles    = static fn (array $p): bool => str_contains(imgSrc($p), 'tile.openstreetmap.org') === true;
$text     = static fn (array $p): bool => str_contains($p['body'], 'portal-location-text') === true;

$p = $profile();
check('G00 premise: the profile answers and has a location to show (so "no map" below cannot pass on a profile with nothing to map)',
    $p['status'] === 200 && ($text($p) === true || $mapDrawn($p) === true), 'status ' . $p['status']);
check('G01 nobody chose (shipped default): no map, no tile host, the location as text', $mapDrawn($p) === false && $tiles($p) === false && $text($p) === true);
setChoice(SUBJ, 'memberMap', 'on');
$p = $profile();
check('G02 the member shown switched it on and the viewer did not object: the map IS drawn', $mapDrawn($p) === true && $tiles($p) === true && $text($p) === false, imgSrc($p));
setChoice(VIEWER, 'memberMap', 'off');
$p = $profile();
check('G03 ...but the viewer switched it off: no map, no tile host', $mapDrawn($p) === false && $tiles($p) === false && $text($p) === true);
setChoice(SUBJ, 'memberMap', null);
setChoice(VIEWER, 'memberMap', 'on');
$p = $profile();
check('G04 the viewer wants maps but the member shown never switched it on: no map', $mapDrawn($p) === false && $tiles($p) === false);
setChoice(SUBJ, 'memberMap', 'on');
q('INSERT INTO `tblUserSites` (`userID`, `siteID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES (?, ?, 1, 0, 0)', 'ii', [SUBJ, ORG_B]);
setAt(K_MAP, ORG_B, 'no');
$p = $profile();
check('G05 point 4 for the member SHOWN: their other organisation says no, so no map on A\'s page', $mapDrawn($p) === false && $tiles($p) === false);
setAt(K_MAP, ORG_B, null);
q('DELETE FROM `tblUserSites` WHERE `userID` = ? AND `siteID` = ?', 'ii', [SUBJ, ORG_B]);
setAt(K_MAP, null, 'no');
$p = $profile();
check('G06 the installation says no: no map, whatever both chose', $mapDrawn($p) === false && $tiles($p) === false);
setAt(K_MAP, null, 'off');
$p = $profile();
check('G07 control: back to allowed, both still on: the map is drawn again', $mapDrawn($p) === true);
setChoice(SUBJ, 'memberMap', null);
setChoice(VIEWER, 'memberMap', null);

// D-072 with a hand-written installation "on": still nobody's map until they switch it on.
setAt(K_MAP, null, 'on');
$p = $profile();
check('G08 a hand-written installation "on" for maps does not put a member\'s home on a map they never switched on', $mapDrawn($p) === false && $tiles($p) === false);
setAt(K_MAP, null, 'off');

// Point 4 for a SWITCHED-OFF account (round 1, finding 3): its memberships still count.
setChoice(SUBJ, 'memberMap', 'on');
q('INSERT INTO `tblUserSites` (`userID`, `siteID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES (?, ?, 1, 0, 0)', 'ii', [SUBJ, ORG_B]);
q('UPDATE `tblUsers` SET `isActive` = 0 WHERE `userID` = ?', 'i', [SUBJ]);
setAt(K_MAP, ORG_B, 'no');
$p = $profile();
check('G09 a switched-off account in A and B, B says no: no map on A\'s page (its other organisation still counts)',
    $p['status'] === 200 && $mapDrawn($p) === false && $tiles($p) === false && $text($p) === true, 'status ' . $p['status']);
setAt(K_MAP, ORG_B, null);
$p = $profile();
check('G10 control: the same switched-off account with B silent: the map IS drawn (so G09 is about B, not the switch)', $mapDrawn($p) === true);
q('UPDATE `tblUsers` SET `isActive` = 1 WHERE `userID` = ?', 'i', [SUBJ]);
q('DELETE FROM `tblUserSites` WHERE `userID` = ? AND `siteID` = ?', 'ii', [SUBJ, ORG_B]);

// Point 4 for the VIEWER (round 1, finding 5): M belongs to A and B, B says no for M.
setAt(K_MAP, ORG_B, 'no');
$pM = req($jarM, 'GET', '/directory/profile?id=' . SUBJ);
check('G11 a viewer in A and B, where B says no: no map, no tile host, the location as text', $pM['status'] === 200
    && $mapDrawn($pM) === false && $tiles($pM) === false && $text($pM) === true, 'status ' . $pM['status']);
$p = $profile();
check('G12 control: a viewer of A alone, same page, same moment: the map IS drawn', $mapDrawn($p) === true);
setAt(K_MAP, ORG_B, null);
setChoice(SUBJ, 'memberMap', null);

// The text fallback's precision (round 1, finding 4). The home is at 52.2053, 0.1218.
$p = $profile();
check('G13 an ordinary viewer gets the location COARSENED (52.205, 0.122) and never the exact figures', $text($p) === true
    && str_contains($p['body'], 'Location: 52.205, 0.122') === true && str_contains($p['body'], '52.2053') === false && str_contains($p['body'], '0.1218') === false);
$jarS = signIn('subj', ORG_A);
$pS = req($jarS, 'GET', '/directory/profile?id=' . SUBJ);
check('G14 the member themselves gets the exact figures', str_contains($pS['body'], 'Location: 52.2053, 0.1218') === true);
$pA = req($jarAA, 'GET', '/directory/profile?id=' . SUBJ);
check('G15 an administrator gets the exact figures', str_contains($pA['body'], 'Location: 52.2053, 0.1218') === true);

// =============================================================================
// H. ROUND 2 OF THE INDEPENDENT CHECK
// =============================================================================
head('H. Stored values the pages must show and save truthfully');
/** Every box on the card as it is drawn, as a browser would send it untouched. */
$untouched = static function (string $html, string $prefix, string $action): array {
    $fields = ['action' => $action];
    foreach (['gravatar', 'providerPhoto', 'memberMap'] as $svc) {
        $fields[$prefix . $svc] = selectedIn($html, $prefix . $svc);
    }

    return $fields;
};

// H01/H02: a global administrator is held to EVERY organisation (membershipsOf()).
setAt(K_GRAV, null, 'on');
setAt(K_GRAV, ORG_B, 'no');
$page = req($jarG, 'GET', '/account');
check('H01 a global administrator (a member of A only) is held to B\'s "no" too: no Gravatar address', $page['status'] === 200
    && str_contains($page['body'], 'gravatar.com') === false);
setAt(K_GRAV, ORG_B, null);
$page = req($jarG, 'GET', '/account');
check('H02 control: with B silent, the same administrator IS sent it', str_contains($page['body'], 'gravatar.com/avatar/') === true);
setAt(K_GRAV, null, 'off');

// H03-H05: a stored value that is not plainly a local path is never drawn as one.
setAt(K_PHOTO, null, 'on');
foreach (['/\\selftesthost.invalid/x.png' => 'a backslash after the slash', '\\\\selftesthost.invalid/x.png' => 'two backslashes',
          "/\t/selftesthost.invalid/x.png" => 'a tab between two slashes'] as $value => $what) {
    q('UPDATE `tblUsers` SET `avatarPath` = ? WHERE `userID` = ?', 'si', [$value, M]);
    $page = req($jarM, 'GET', '/account');
    check("H03 {$what} is another website to a browser: not drawn, the silhouette instead",
        str_contains($page['body'], 'selftesthost') === false && str_contains($page['body'], 'src="' . PLACEHOLDER . '"') === true);
}
q("UPDATE `tblUsers` SET `avatarPath` = 'uploads/avatars/selftest-9.jpg' WHERE `userID` = ?", 'i', [M]);
$page = req($jarM, 'GET', '/account');
check('H04 control: a plain local path IS drawn, with its leading slash', str_contains($page['body'], 'src="/uploads/avatars/selftest-9.jpg"') === true);
q('UPDATE `tblUsers` SET `avatarPath` = NULL WHERE `userID` = ?', 'i', [M]);
setAt(K_PHOTO, null, 'off');

// H05-H07: the INSTALLATION card with a stored map "on" (hand-written).
setAt(K_MAP, null, 'on');
$page = req($jarG, 'GET', '/admin/settings/outside-services');
check('H05 the installation card SELECTS the capped value, "off" (never no option, which a browser would send as "no")',
    selectedIn($page['body'], 'installation_memberMap') === 'off', selectedIn($page['body'], 'installation_memberMap'));
check('H06 ...and says what is stored and what it counts as', str_contains($page['body'], 'A map is never on by default, so it counts as') === true);
req($jarG, 'POST', '/admin/settings/outside-services/save', $untouched($page['body'], 'installation_', 'installation') + ['csrf_token' => token($page['body'])]);
check('H07 ...and an untouched Save leaves the installation exactly as stored', stored(K_MAP, null) === 'on' && stored(K_GRAV, null) === 'off');

// H08-H10: the ORGANISATION card, with the installation's map "on" and the organisation's map "on".
setAt(K_MAP, ORG_A, 'on');
$page = req($jarAA, 'GET', '/admin/settings/outside-services');
check('H08 the organisation card selects the capped value for its own stored map "on"', selectedIn($page['body'], 'organisation_memberMap') === 'off',
    selectedIn($page['body'], 'organisation_memberMap'));
check('H09 ...and the installation is described as allowing what it REALLY allows for maps, never "on"',
    str_contains($page['body'], 'The installation allows: <strong>on until') === false
    && str_contains($page['body'], 'The installation allows: <strong>off until a member switches it on</strong>') === true);
$before = logged(AADMIN, 'OutsideServiceOrganisationRefused');
$fields = $untouched($page['body'], 'organisation_', 'organisation');
$fields['organisation_gravatar'] = 'no';
req($jarAA, 'POST', '/admin/settings/outside-services/save', $fields + ['csrf_token' => token($page['body'])]);
check('H10 ...an untouched map with a Gravatar change saves the Gravatar change and leaves the map as stored, with no refusal',
    stored(K_GRAV, ORG_A) === 'no' && stored(K_MAP, ORG_A) === 'on' && logged(AADMIN, 'OutsideServiceOrganisationRefused') === $before);
setAt(K_GRAV, ORG_A, null);

// H11-H13: round 2's finding 1 exactly — the organisation's map "on" under an installation "no".
setAt(K_MAP, null, 'no');
$page = req($jarAA, 'GET', '/admin/settings/outside-services');
check('H11 the card says what was STORED and what applies, never that the organisation chose what it shows',
    str_contains($page['body'], 'Stored for your organisation: "on until a member switches it off"') === true
    && str_contains($page['body'], 'the installation now allows only "not allowed"') === true
    && str_contains($page['body'], 'Your organisation chose "off until') === false);
$before = logged(AADMIN, 'OutsideServiceOrganisationRefused');
$fields = $untouched($page['body'], 'organisation_', 'organisation');
$fields['organisation_gravatar'] = 'no';
req($jarAA, 'POST', '/admin/settings/outside-services/save', $fields + ['csrf_token' => token($page['body'])]);
check('H12 ...and an untouched Save with a Gravatar change is NOT refused: the Gravatar change lands, the map row is as stored',
    stored(K_GRAV, ORG_A) === 'no' && stored(K_MAP, ORG_A) === 'on' && logged(AADMIN, 'OutsideServiceOrganisationRefused') === $before);
setAt(K_GRAV, ORG_A, null);
setAt(K_MAP, ORG_A, null);
$before = logged(AADMIN, 'OutsideServiceOrganisationRefused');
post($jarAA, '/admin/settings/outside-services', '/admin/settings/outside-services/save', ['action' => 'organisation', 'organisation_memberMap' => 'off']);
check('H13 an organisation going LOOSER than the installation for maps ("off" under "no") is refused at the writer, and recorded',
    stored(K_MAP, ORG_A) === null && logged(AADMIN, 'OutsideServiceOrganisationRefused') === $before + 1);
setAt(K_MAP, null, 'off');

// H14-H19: a member's recorded "on" can be WITHDRAWN while it is not allowed.
setAt(K_GRAV, null, 'on');
setChoice(M, 'gravatar', 'on');
setAt(K_GRAV, ORG_B, 'no');
$page = req($jarM, 'GET', '/account/outside-services');
check('H14 with a stored "on" under B\'s "no", the member IS given a control, which keeps the choice by default',
    selectedIn($page['body'], 'choice_gravatar') === 'keep', selectedIn($page['body'], 'choice_gravatar'));
check('H15 ...offering Off and Follow, and never On above what is allowed',
    preg_match('#<select[^>]*name="choice_gravatar"[^>]*>(.*?)</select>#s', $page['body'], $mm) === 1
    && str_contains($mm[1], 'value="off"') === true && str_contains($mm[1], 'value="follow"') === true && str_contains($mm[1], 'value="on"') === false);
$fields = [];
foreach (['gravatar', 'providerPhoto', 'memberMap'] as $svc) {
    if (selectedIn($page['body'], 'choice_' . $svc) !== '(no such select)') {
        $fields['choice_' . $svc] = selectedIn($page['body'], 'choice_' . $svc);
    }
}
$beforeRefused = logged(M, 'OutsideServicePrefsRefused');
req($jarM, 'POST', '/account/outside-services-save', $fields + drawnMarkers($page['body']) + ['csrf_token' => token($page['body'])]);
check('H16 an untouched Save leaves the recorded choice alone, and is not refused', choiceOf(M, 'gravatar') === 'on'
    && logged(M, 'OutsideServicePrefsRefused') === $beforeRefused);
post($jarM, '/account/outside-services', '/account/outside-services-save', ['choice_gravatar' => 'off']);
check('H17 choosing Off withdraws it, while B still says no', choiceOf(M, 'gravatar') === 'off');
setAt(K_GRAV, ORG_B, null);
$page = req($jarM, 'GET', '/account');
check('H18 when B relaxes, the "off" the member set in the meantime HOLDS: no Gravatar address', str_contains($page['body'], 'gravatar.com') === false);
setChoice(M, 'gravatar', 'on');
$page = req($jarM, 'GET', '/account');
check('H19 control: with the member\'s "on" and B relaxed, it IS sent (so H18 is about the withdrawal)', str_contains($page['body'], 'gravatar.com/avatar/') === true);
setChoice(M, 'gravatar', null);
setAt(K_GRAV, null, 'off');

// H20-H22 (round 3): the page was drawn BEFORE B said no, and still holds the
// member's earlier "on"; switching ANOTHER service off must not be refused.
setAt(K_GRAV, null, 'on');
setAt(K_PHOTO, null, 'on');
setChoice(M, 'gravatar', 'on');
$page = req($jarM, 'GET', '/account/outside-services');
check('H20 premise: the page is drawn with Gravatar "on" and the photo control present', selectedIn($page['body'], 'choice_gravatar') === 'on'
    && selectedIn($page['body'], 'choice_providerPhoto') === 'follow');
$fields = [];
foreach (['gravatar', 'providerPhoto', 'memberMap'] as $svc) {
    if (selectedIn($page['body'], 'choice_' . $svc) !== '(no such select)') {
        $fields['choice_' . $svc] = selectedIn($page['body'], 'choice_' . $svc);
    }
}
$fields['choice_providerPhoto'] = 'off';
setAt(K_GRAV, ORG_B, 'no'); // B tightens while the page is open
$beforeRefused = logged(M, 'OutsideServicePrefsRefused');
req($jarM, 'POST', '/account/outside-services-save', $fields + drawnMarkers($page['body']) + ['csrf_token' => token($page['body'])]);
check('H21 the photo switched OFF is saved, though the untouched Gravatar box re-posted "on" under B\'s "no"',
    choiceOf(M, 'providerPhoto') === 'off' && logged(M, 'OutsideServicePrefsRefused') === $beforeRefused);
check('H22 ...and the re-posted "on" was neither refused nor rewritten: the stored choice is as it was', choiceOf(M, 'gravatar') === 'on');
$lastUpdate = (string) (one("SELECT `activityDescription` FROM `tblActivityLogs` WHERE `userID` = ? AND `activityType` = 'OutsideServicePrefsUpdated' ORDER BY `logID` DESC LIMIT 1", 'i', [M])['activityDescription'] ?? '');
check('H22b ...and the activity record of that press names the photo and NOT Gravatar (an untouched "on" is no consent record)',
    str_contains($lastUpdate, 'Your photo from the account you sign in with: off') === true && str_contains($lastUpdate, 'Gravatar') === false, $lastUpdate);
$before = logged(M, 'OutsideServicePrefsRefused');
setChoice(M, 'gravatar', 'off');
post($jarM, '/account/outside-services', '/account/outside-services-save', ['choice_gravatar' => 'on']);
check('H23 control: an "on" that IS a change under B\'s "no" is still refused, and recorded', choiceOf(M, 'gravatar') === 'off'
    && logged(M, 'OutsideServicePrefsRefused') === $before + 1);
// H24-H26 (round 4): a MIXED press — a real "on" under B's "no" beside an "off".
setChoice(M, 'gravatar', null);
setChoice(M, 'providerPhoto', null);
$beforeUpd = logged(M, 'OutsideServicePrefsUpdated');
$beforeRef = logged(M, 'OutsideServicePrefsRefused');
post($jarM, '/account/outside-services', '/account/outside-services-save', ['choice_gravatar' => 'on', 'choice_providerPhoto' => 'off']);
check('H24 a mixed press APPLIES the withdrawal and refuses only the real "on" above the ceiling',
    choiceOf(M, 'providerPhoto') === 'off' && choiceOf(M, 'gravatar') === null);
$shown = req($jarM, 'GET', '/account/outside-services')['body'];
check('H25 ...the member is told BOTH what was saved and what was refused, and why',
    str_contains($shown, 'Saved: Your photo from the account you sign in with: off.') === true
    && str_contains($shown, 'was not switched on, because') === true && str_contains($shown, NAME_B) === true);
check('H26 ...and both are recorded', logged(M, 'OutsideServicePrefsUpdated') === $beforeUpd + 1 && logged(M, 'OutsideServicePrefsRefused') === $beforeRef + 1);
$lastRefused = (string) (one("SELECT `activityDescription` FROM `tblActivityLogs` WHERE `userID` = ? AND `activityType` = 'OutsideServicePrefsRefused' ORDER BY `logID` DESC LIMIT 1", 'i', [M])['activityDescription'] ?? '');
check('H26b ...the refusal record says what was refused, and only that (what was SAVED is in the other record)',
    str_contains($lastRefused, 'was not switched on, because') === true && str_contains($lastRefused, 'Saved:') === false, $lastRefused);
check('H25b ...and the page shows the mixed result as a WARNING, not a green success', str_contains($shown, 'alert alert-warning') === true
    && str_contains($shown, 'alert alert-success') === false);

// H27-H29 (round 4): the ceiling cannot be READ at save time. Called on the
// class directly, because with `tblUserSites` away no page would draw at all.
setChoice(M, 'providerPhoto', null);
setChoice(M, 'gravatar', 'on');
OutsideServices::forget();
$db->query('RENAME TABLE `tblUserSites` TO `tblUserSites_outside_away`');
try {
    $untouchedOut = OutsideServices::saveMemberChoice($db, M, ORG_A, ['gravatar' => 'on', 'providerPhoto' => 'off']);
    setChoice(M, 'gravatar', 'off');
    $realOnOut = OutsideServices::saveMemberChoice($db, M, ORG_A, ['gravatar' => 'on', 'memberMap' => 'off']);
} finally {
    $db->query('RENAME TABLE `tblUserSites_outside_away` TO `tblUserSites`');
}
check('H27 settings unreadable at save: an untouched "on" plus an "off" — the "off" is saved and nothing is refused',
    $untouchedOut['refused'] === [] && in_array('Your photo from the account you sign in with: off', $untouchedOut['changes'], true) === true
    && in_array('Profile pictures from Gravatar: on', $untouchedOut['changes'], true) === false, json_encode($untouchedOut));
check('H28 settings unreadable: a REAL "on" is refused, the "off" beside it applied', choiceOf(M, 'gravatar') === 'off' && choiceOf(M, 'memberMap') === 'off'
    && count($realOnOut['refused']) === 1, json_encode($realOnOut));
check('H29 ...and the message says the settings could not be read', str_contains($realOnOut['message'], 'could not read the settings') === true, $realOnOut['message']);
setChoice(M, 'memberMap', null);

// H32-H34 (round 5): the FOLLOW half of the rule, beside a refused "on".
setChoice(M, 'gravatar', null);
setChoice(M, 'providerPhoto', 'off');
post($jarM, '/account/outside-services', '/account/outside-services-save', ['choice_gravatar' => 'on', 'choice_providerPhoto' => 'follow']);
check('H32 a "follow" beside a refused "on" is APPLIED: the photo row is gone, Gravatar untouched', choiceOf(M, 'providerPhoto') === null && choiceOf(M, 'gravatar') === null);
$shown = req($jarM, 'GET', '/account/outside-services')['body'];
check('H33 ...and the member is told it was saved', str_contains($shown, 'Saved: Your photo from the account you sign in with: follow my organisations.') === true);
$lastUpdate = (string) (one("SELECT `activityDescription` FROM `tblActivityLogs` WHERE `userID` = ? AND `activityType` = 'OutsideServicePrefsUpdated' ORDER BY `logID` DESC LIMIT 1", 'i', [M])['activityDescription'] ?? '');
check('H34 ...and the withdrawal is in the record of what was saved', str_contains($lastUpdate, 'Your photo from the account you sign in with: follow my organisations') === true, $lastUpdate);

// H35-H36 (round 5): the member's STORED choices cannot be read at save time.
setChoice(M, 'gravatar', 'on');
OutsideServices::forget();
$db->query('RENAME TABLE `tblUserOutsideServicePref` TO `tblUserOutsideServicePref_outside_away`');
try {
    $storedFail = OutsideServices::saveMemberChoice($db, M, ORG_A, ['gravatar' => 'on', 'providerPhoto' => 'off']);
} finally {
    $db->query('RENAME TABLE `tblUserOutsideServicePref_outside_away` TO `tblUserOutsideServicePref`');
}
check('H35 stored choices unreadable: the member is told exactly that', $storedFail['message'] === 'Your choices could not be read, so nothing was saved. Please try again.', $storedFail['message']);
check('H36 ...and it is NOT reported as saved, and nothing changed', $storedFail['saved'] === false && $storedFail['changes'] === []
    && choiceOf(M, 'gravatar') === 'on' && choiceOf(M, 'providerPhoto') === null);
setChoice(M, 'gravatar', null);

// H30: more than one level refusing is named in full, and the plural is right.
setAt(K_GRAV, null, 'no');
setChoice(M, 'gravatar', 'on');
$shown = req($jarM, 'GET', '/account/outside-services')['body'];
check('H30 the installation AND B both refuse: both are named, and "if those changed"',
    str_contains($shown, 'whoever runs this portal does not allow it for anybody, and an organisation you belong to, ' . NAME_B) === true
    && str_contains($shown, 'those changed') === true);
// H31: an untouched "off" is not rewritten or recorded as a change either.
setChoice(M, 'gravatar', 'off');
post($jarM, '/account/outside-services', '/account/outside-services-save', ['choice_gravatar' => 'off']);
$lastUpdate = (string) (one("SELECT `activityDescription` FROM `tblActivityLogs` WHERE `userID` = ? AND `activityType` = 'OutsideServicePrefsUpdated' ORDER BY `logID` DESC LIMIT 1", 'i', [M])['activityDescription'] ?? '');
check('H31 an untouched "off" is recorded as nothing different, never as a fresh choice', str_contains($lastUpdate, 'Nothing was different') === true
    && str_contains($lastUpdate, 'Gravatar') === false, $lastUpdate);
setAt(K_GRAV, ORG_B, null);
setChoice(M, 'gravatar', null);
setChoice(M, 'providerPhoto', null);
setAt(K_GRAV, null, 'off');
setAt(K_PHOTO, null, 'off');

// =============================================================================
// O. #815: AN OLDER PAGE CANNOT SWITCH A SERVICE BACK ON
// =============================================================================
// The member page sends, beside each control, the value it was DRAWN with, and
// the writer leaves a control sent back as drawn ALONE. Two sessions of the one
// member, because that is where this really happens: within ONE browser session
// the form token is replaced on every accepted save, so an older tab of the same
// session is already refused by the token check (measured: Auth::verifyCsrf()
// rotates on acceptance). A phone and a laptop are two sessions, each with its
// own token, and nothing but this stops the older page.
head('O. #815: a page opened before a change cannot undo it');
setAt(K_GRAV, null, 'on');
setAt(K_GRAV, ORG_B, null);
setChoice(M, 'gravatar', null);
$jarM2 = signIn('m', ORG_A);
$older = req($jarM2, 'GET', '/account/outside-services')['body'];
check('O00 premise: the older page (a second session of M) draws Gravatar as "follow", with the value it was drawn with beside it',
    selectedIn($older, 'choice_gravatar') === 'follow' && (drawnMarkers($older)['drawn_gravatar'] ?? '') === 'follow',
    selectedIn($older, 'choice_gravatar') . ' / ' . json_encode(drawnMarkers($older)));
post($jarM, '/account/outside-services', '/account/outside-services-save', ['choice_gravatar' => 'off']);
check('O01 premise: M switches Gravatar OFF in the other session', choiceOf(M, 'gravatar') === 'off');
$updatesBefore = logged(M, 'OutsideServicePrefsUpdated');
req($jarM2, 'POST', '/account/outside-services-save', memberFormAsDrawn($older));
check('O02 the older page saved UNTOUCHED leaves the withdrawal in place: Gravatar is still OFF (it used to write "follow" over it, switching it back on)',
    choiceOf(M, 'gravatar') === 'off', var_export(choiceOf(M, 'gravatar'), true));
$told = req($jarM2, 'GET', '/account/outside-services')['body'];
check('O03 ...and the member is TOLD it was changed after the page was opened, and what is in force now, as a warning',
    str_contains($told, '&quot;Profile pictures from Gravatar&quot; was changed after this page was opened, so it was left as it is now: off.') === true
    && str_contains($told, 'alert alert-warning') === true);
$lastUpdate = (string) (one("SELECT `activityDescription` FROM `tblActivityLogs` WHERE `userID` = ? AND `activityType` = 'OutsideServicePrefsUpdated' ORDER BY `logID` DESC LIMIT 1", 'i', [M])['activityDescription'] ?? '');
check('O04 ...and the record of that press holds no change to Gravatar (no consent record for something the member did not do)',
    logged(M, 'OutsideServicePrefsUpdated') === $updatesBefore + 1 && str_contains($lastUpdate, 'Gravatar') === false, $lastUpdate);

// A control the member CHANGED on an older page is a decision made on that page.
$older = req($jarM2, 'GET', '/account/outside-services')['body'];
post($jarM, '/account/outside-services', '/account/outside-services-save', ['choice_gravatar' => 'follow']);
check('O05 premise: drawn as "off" in the older page, then set back to "follow" elsewhere', (drawnMarkers($older)['drawn_gravatar'] ?? '') === 'off'
    && choiceOf(M, 'gravatar') === null);
req($jarM2, 'POST', '/account/outside-services-save', ['choice_gravatar' => 'on'] + memberFormAsDrawn($older));
check('O06 a control CHANGED on the older page IS saved: "on" was chosen there, and is stored', choiceOf(M, 'gravatar') === 'on',
    var_export(choiceOf(M, 'gravatar'), true));

// A post with NO drawn values: a page from before #815, or made by hand.
setChoice(M, 'gravatar', 'off');
$plain = req($jarM2, 'GET', '/account/outside-services')['body'];
req($jarM2, 'POST', '/account/outside-services-save', ['choice_gravatar' => 'on', 'csrf_token' => token($plain)]);
check('O07 with no drawn value beside it, an "on" that would change something is LEFT ALONE (it cannot be told from an older page\'s)',
    choiceOf(M, 'gravatar') === 'off', var_export(choiceOf(M, 'gravatar'), true));
check('O08 ...and the member is told to open the page again',
    str_contains(req($jarM2, 'GET', '/account/outside-services')['body'], 'was not changed, because the page you saved was out of date') === true);
setChoice(M, 'gravatar', 'on');
$plain = req($jarM2, 'GET', '/account/outside-services')['body'];
req($jarM2, 'POST', '/account/outside-services-save', ['choice_gravatar' => 'off', 'csrf_token' => token($plain)]);
check('O09 ...but an "off" with no drawn value IS applied: a withdrawal of consent is never lost', choiceOf(M, 'gravatar') === 'off');

// Round 2 of #815's check: judged by EFFECT, not by the word. Where the
// organisations' default is OFF, "follow" over a stored "on" is a WITHDRAWAL, and
// the first version held it back as "out of date" — the member's picture went on
// being sent. And the other direction: where the default is ON, "follow" over a
// stored "off" switches sharing back on, so it must still be held back.
setAt(K_GRAV, null, 'off');   // allowed, off until a member switches it on
setChoice(M, 'gravatar', 'on');
check('O10 premise: with the default off and the member\'s own "on", Gravatar IS sent',
    str_contains(req($jarM2, 'GET', '/account')['body'], 'gravatar.com/avatar/') === true);
$plain = req($jarM2, 'GET', '/account/outside-services')['body'];
req($jarM2, 'POST', '/account/outside-services-save', ['choice_gravatar' => 'follow', 'csrf_token' => token($plain)]);
check('O11 with no drawn value, "follow" over a stored "on" under a default of off is APPLIED: the "on" is gone and nothing is sent (a withdrawal by effect)',
    choiceOf(M, 'gravatar') === null && str_contains(req($jarM2, 'GET', '/account')['body'], 'gravatar.com') === false,
    var_export(choiceOf(M, 'gravatar'), true));
setAt(K_GRAV, null, 'on');    // allowed, on until a member switches it off
setChoice(M, 'gravatar', 'off');
$plain = req($jarM2, 'GET', '/account/outside-services')['body'];
req($jarM2, 'POST', '/account/outside-services-save', ['choice_gravatar' => 'follow', 'csrf_token' => token($plain)]);
check('O12 ...but with no drawn value, "follow" over a stored "off" under a default of ON is HELD BACK: it would switch sharing back on',
    choiceOf(M, 'gravatar') === 'off'
    && str_contains(req($jarM2, 'GET', '/account/outside-services')['body'], 'was not changed, because the page you saved was out of date') === true,
    var_export(choiceOf(M, 'gravatar'), true));
// O13 (round 3): the same, with the default OFF. Today the "follow" would share
// nothing, but it would delete the member's "off", so the day an organisation's
// default becomes "on" their picture would start going out — the rule judges by
// the stored choice for exactly that, and this check is what pins it.
setAt(K_GRAV, null, 'off');
setChoice(M, 'gravatar', 'off');
$plain = req($jarM2, 'GET', '/account/outside-services')['body'];
req($jarM2, 'POST', '/account/outside-services-save', ['choice_gravatar' => 'follow', 'csrf_token' => token($plain)]);
check('O13 ...and HELD BACK too with the default OFF, where it would share nothing today: it deletes the "off" that keeps the picture back once a default becomes "on"',
    choiceOf(M, 'gravatar') === 'off', var_export(choiceOf(M, 'gravatar'), true));
setChoice(M, 'gravatar', null);
setAt(K_GRAV, null, 'off');

// =============================================================================
// T. TWO SAVES AT ONCE (round 5) — two REAL processes, because one process cannot
//    block itself on its own lock (the erasure-review test's section T shape).
// =============================================================================
head('T. Two saves of one member\'s choices at the same moment');
$worker = $child . $sep . 'outside-worker.php';
file_put_contents($worker, <<<'WORKER'
<?php
// Written by tools/outside-services-selftest.php for ONE run: one save, in its own process.
declare(strict_types=1);
require getenv('OS_CORE') . 'OutsideServices.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(getenv('OS_HOST'), getenv('OS_USER'), getenv('OS_PASS'), getenv('OS_NAME'), (int) getenv('OS_PORT'));
if (getenv('OS_WAIT') !== false) {
    $db->query('SET SESSION innodb_lock_wait_timeout = ' . (int) getenv('OS_WAIT'));
}
try {
    $out = \Portal\Core\OutsideServices::saveMemberChoice($db, (int) getenv('OS_USER_ID'), (int) getenv('OS_SITE'), json_decode((string) getenv('OS_POST'), true));
    echo json_encode($out);
} catch (\Throwable $e) {
    echo json_encode(['escaped' => get_class($e) . ': ' . $e->getMessage()]);
}
WORKER);
$startWorker = static function (array $post, ?int $wait = null, int $who = M) use ($worker, $core, $dbHost, $dbUser, $dbPass, $dbName, $dbPort, $child, $sep): array {
    $env = getenv();
    $env += [];
    $env['OS_CORE'] = $core; $env['OS_HOST'] = $dbHost; $env['OS_USER'] = $dbUser; $env['OS_PASS'] = $dbPass;
    $env['OS_NAME'] = $dbName; $env['OS_PORT'] = (string) $dbPort; $env['OS_USER_ID'] = (string) $who; $env['OS_SITE'] = (string) ORG_A;
    $env['OS_POST'] = json_encode($post);
    if ($wait !== null) {
        $env['OS_WAIT'] = (string) $wait;
    }
    $out  = $child . $sep . 'worker-' . bin2hex(random_bytes(3)) . '.out';
    $proc = proc_open([PHP_BINARY, $worker], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out . '.err', 'w']], $pipes, $child, $env);

    return ['proc' => $proc, 'out' => $out];
};
$finish = static function (array $w): array {
    $until = microtime(true) + 60;
    while (proc_get_status($w['proc'])['running'] === true && microtime(true) < $until) {
        usleep(50000);
    }
    proc_close($w['proc']);
    $json = json_decode((string) @file_get_contents($w['out']), true);

    return is_array($json) === true ? $json : ['escaped' => 'no answer: ' . (string) @file_get_contents($w['out'] . '.err')];
};

/**
 * ⏳ THE PREMISE, ASKED OF THE DATABASE (rounds 7 and 8): wait until at least
 * `$want` NEW transactions are really in LOCK WAIT, or the deadline passes, and
 * answer the waiting rows (so a caller can ask more of them), or null.
 *
 * "The worker process is still running" said nothing — it is running while PHP
 * starts, too. And `information_schema.innodb_trx` IS A CACHE that InnoDB
 * rebuilds only when nobody has read it for 0.1 s; every read resets that
 * clock, and round 7's version read FIRST and slept after — so its first read
 * returned the previous call's snapshot, still listing transactions that had
 * already committed. 17 of 18 calls answered true at once from T10's first
 * run, and a T11 with no hold at all stayed green (round 8, measured). So:
 *   - it sleeps 0.15 s BEFORE every read, never after;
 *   - it counts only connections NOT SEEN by any earlier call
 *     (`trx_mysql_thread_id`: every worker is a new connection), so it
 *     cannot answer with a waiter an earlier call returned. (Waiters no
 *     premise asks about — T04, T06, T08's workers and T09's own connection
 *     — are simply never counted; that is not a gap, as nothing asks.)
 * A deadline that passes answers null, and every premise check requires a list.
 *
 * @return list<array<string,mixed>>|null
 */
$seenWaiters = [];
$lockWaits = static function (int $want, float $seconds = 15.0) use ($db, &$seenWaiters): ?array {
    $until = microtime(true) + $seconds;
    do {
        usleep(150000);
        $rows  = $db->query("SELECT `trx_mysql_thread_id` AS `conn`, `trx_rows_modified` AS `modified` FROM `information_schema`.`innodb_trx` WHERE `trx_state` = 'LOCK WAIT'")->fetch_all(MYSQLI_ASSOC);
        $fresh = array_values(array_filter($rows, static fn (array $r): bool => isset($seenWaiters[(int) $r['conn']]) === false));
        if (count($fresh) >= $want) {
            foreach ($fresh as $r) {
                $seenWaiters[(int) $r['conn']] = true;
            }

            return $fresh;
        }
    } while (microtime(true) < $until);

    return null;
};

setAt(K_GRAV, null, 'off');
setAt(K_PHOTO, null, 'off');
setChoice(M, 'gravatar', 'off');
setChoice(M, 'providerPhoto', 'off');
// Round 5's choreography: hold the photo row so tab A is caught part-way.
$db->begin_transaction();
$db->query('SELECT `choice` FROM `tblUserOutsideServicePref` WHERE `userID` = ' . M . " AND `serviceKey` = 'providerPhoto' FOR UPDATE")->fetch_all();
$tabA = $startWorker(['gravatar' => 'off', 'providerPhoto' => 'on']);
$aHeld = $lockWaits(1) !== null;
$tabB = $startWorker(['gravatar' => 'on', 'providerPhoto' => 'off']);
$bHeld = $lockWaits(1) !== null; // B is the one NEW waiter now; A was seen above
$db->commit();
$outA = $finish($tabA);
$outB = $finish($tabB);
$final = [choiceOf(M, 'gravatar'), choiceOf(M, 'providerPhoto')];
check('T01 premise, asked of the database: tab A was IN A LOCK WAIT before tab B started, and both were waiting before the release',
    $aHeld === true && $bHeld === true);
check('T02 both tabs answered without an escaped error, and both were saved', ($outA['saved'] ?? null) === true && ($outB['saved'] ?? null) === true,
    json_encode([$outA, $outB]));
check('T03 THE POINT: the result is the ONE order T01 proves (A, then B): gravatar on, photo off — never both "on"',
    $final === ['on', 'off'], json_encode($final));
check('T07 ...and tab B, which waited for A, read what A had stored: its record lists BOTH of its changes, the withdrawal included',
    in_array('Your photo from the account you sign in with: off', $outB['changes'] ?? [], true) === true
    && in_array('Profile pictures from Gravatar: on', $outB['changes'] ?? [], true) === true, json_encode($outB['changes'] ?? null));

// A lock wait that runs out is rolled back and said plainly, never escaping.
setChoice(M, 'gravatar', 'on');
$db->begin_transaction();
$db->query('SELECT `userID` FROM `tblUsers` WHERE `userID` = ' . M . ' FOR UPDATE')->fetch_all();
$tabC = $startWorker(['gravatar' => 'off'], 1);
$outC = $finish($tabC);
$db->commit();
check('T04 a save that cannot get the lock in time is refused with an honest message, not an escaped error',
    isset($outC['escaped']) === false && ($outC['saved'] ?? null) === false
    && str_contains((string) ($outC['message'] ?? ''), 'Another change to your account was being saved at the same moment') === true, json_encode($outC));
check('T05 ...and nothing it asked for was written', choiceOf(M, 'gravatar') === 'on');
// The PARENT lock is taken first, whatever is posted. Without it a write would
// still wait (the foreign key checks the parent row), so T04 alone cannot tell
// the explicit lock from the key's; a "follow" with nothing stored writes no
// row and checks no key, so only the explicit lock makes it wait.
setChoice(M, 'memberMap', null);
$db->begin_transaction();
$db->query('SELECT `userID` FROM `tblUsers` WHERE `userID` = ' . M . ' FOR UPDATE')->fetch_all();
$tabD = $startWorker(['memberMap' => 'follow'], 1);
$outD = $finish($tabD);
$db->commit();
check('T06 the member\'s own row is locked FIRST: even a press that writes nothing waits for another save, and says so',
    ($outD['saved'] ?? null) === false && str_contains((string) ($outD['message'] ?? ''), 'Another change to your account') === true, json_encode($outD));

// T08: a wait that runs out at a WRITE, after the press was sorted, keeps the
// refused lines in the member's message.
setAt(K_GRAV, null, 'on');
setAt(K_GRAV, ORG_B, 'no');
setChoice(M, 'gravatar', null);
setChoice(M, 'providerPhoto', 'on');
$db->begin_transaction();
$db->query('SELECT `choice` FROM `tblUserOutsideServicePref` WHERE `userID` = ' . M . " AND `serviceKey` = 'providerPhoto' FOR UPDATE")->fetch_all();
$tabE = $startWorker(['gravatar' => 'on', 'providerPhoto' => 'off'], 1);
$outE = $finish($tabE);
$db->commit();
check('T08 a wait that runs out at a write: "nothing was saved", AND the refused "on" is still named',
    ($outE['saved'] ?? null) === false && str_contains((string) ($outE['message'] ?? ''), 'Another change to your account') === true
    && str_contains((string) ($outE['message'] ?? ''), '"Profile pictures from Gravatar" was not switched on') === true
    && choiceOf(M, 'providerPhoto') === 'on', json_encode($outE));
setAt(K_GRAV, ORG_B, null);
setAt(K_GRAV, null, 'off');
setChoice(M, 'providerPhoto', null);

// T09: after a failed save the connection's transaction is CLOSED, so the next
// write on the SAME connection — in the portal, the refusal's activity record,
// through Logger::db() — really lands. Checked from a second connection, which
// cannot see a write still inside an open transaction (#694 round 3).
$db2 = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
$db2->begin_transaction();
$db2->query('SELECT `userID` FROM `tblUsers` WHERE `userID` = ' . M . ' FOR UPDATE')->fetch_all();
$db->query('SET SESSION innodb_lock_wait_timeout = 1');
OutsideServices::forget();
$sameConn = OutsideServices::saveMemberChoice($db, M, ORG_A, ['gravatar' => 'off']);
$db->query('SET SESSION innodb_lock_wait_timeout = 50');
$db->query('DELETE FROM `tblUserOutsideServicePref` WHERE `userID` = ' . MEMBER_X);
$db->query("INSERT INTO `tblUserOutsideServicePref` (`userID`, `serviceKey`, `choice`) VALUES (" . MEMBER_X . ", 'gravatar', 'off')");
$seen = (int) $db2->query('SELECT COUNT(*) AS n FROM `tblUserOutsideServicePref` WHERE `userID` = ' . MEMBER_X)->fetch_assoc()['n'];
$db2->commit();
$db2->close();
$db->rollback(); // a no-op when the save closed its transaction, as it must
check('T09 the failed save closed its transaction: the next write on the SAME connection is committed (seen from another)',
    ($sameConn['saved'] ?? null) === false && $seen === 1, json_encode($sameConn) . ' seen=' . $seen);
$db->query('DELETE FROM `tblUserOutsideServicePref` WHERE `userID` = ' . MEMBER_X);

// T10: two DIFFERENT members with adjacent numbers, both withdrawing at the
// same moment, many times. Round 5's locking read deadlocked them 36-40 times
// in 40; a member must never be refused because of somebody else.
setAt(K_GRAV, null, 'off');
setAt(K_PHOTO, null, 'off');
setAt(K_MAP, null, 'off');
$runs = 24;
$lost = [];
$heldAll = true;
for ($i = 0; $i < $runs; $i++) {
    $db->query('DELETE FROM `tblUserOutsideServicePref` WHERE `userID` IN (' . MEMBER_X . ', ' . MEMBER_Y . ')');
    // Hold BOTH members' own rows so the two saves start at the same instant.
    $db->begin_transaction();
    $db->query('SELECT `userID` FROM `tblUsers` WHERE `userID` IN (' . MEMBER_X . ', ' . MEMBER_Y . ') FOR UPDATE')->fetch_all();
    // Two shapes, alternately: withdrawals first, and a "follow" FIRST (a
    // DELETE of a row that does not exist, then an insert) — the order that
    // deadlocked when that DELETE still ran.
    $press = $i % 2 === 0
        ? ['gravatar' => 'off', 'providerPhoto' => 'off', 'memberMap' => 'follow']
        : ['memberMap' => 'follow', 'gravatar' => 'off'];
    $wx = $startWorker($press, null, MEMBER_X);
    $wy = $startWorker($press, null, MEMBER_Y);
    $heldAll = $heldAll && $lockWaits(2) !== null;
    $db->commit();
    foreach ([[MEMBER_X, $finish($wx)], [MEMBER_Y, $finish($wy)]] as [$who, $out]) {
        if (($out['saved'] ?? null) !== true) {
            $lost[] = $who . ': ' . (string) ($out['message'] ?? ($out['escaped'] ?? '?'));
        }
    }
}
check('T10 premise, asked of the database: in every run BOTH saves were in a lock wait before the release', $heldAll === true);
check("T10b two different members withdrawing at the same moment, {$runs} times: NO save refused (no deadlock between them)",
    $lost === [], count($lost) . ' refused; first: ' . ($lost[0] ?? ''));
$db->query('DELETE FROM `tblUserOutsideServicePref` WHERE `userID` IN (' . MEMBER_X . ', ' . MEMBER_Y . ')');

// T11 (round 7): "the transaction starts FIRST". Two tabs of ONE member,
// released together BEFORE either has written anything — the window T07 cannot
// reach, because there a write's foreign-key check already holds the member's
// row. A lock taken outside the transaction is released at once, and then both
// tabs read "on/on" and end off/off: an "on" lost, and a wrong record.
setAt(K_GRAV, null, 'on');
setAt(K_PHOTO, null, 'on');
$t11 = [];
for ($i = 0; $i < 6; $i++) {
    setChoice(M, 'gravatar', 'on');
    setChoice(M, 'providerPhoto', 'on');
    $db->begin_transaction();
    $db->query('SELECT `userID` FROM `tblUsers` WHERE `userID` = ' . M . ' FOR UPDATE')->fetch_all();
    $ta = $startWorker(['gravatar' => 'off', 'providerPhoto' => 'on']);
    $tb = $startWorker(['gravatar' => 'on', 'providerPhoto' => 'off']);
    $held = $lockWaits(2) !== null;
    $db->commit();
    $oa = $finish($ta);
    $ob = $finish($tb);
    $both = static fn (array $o): bool => count(array_filter($o['changes'] ?? [], static fn (string $c): bool => str_contains($c, 'Gravatar') || str_contains($c, 'Your photo'))) === 2;
    $t11[] = ['held' => $held, 'final' => [choiceOf(M, 'gravatar'), choiceOf(M, 'providerPhoto')], 'bothNamed' => (int) $both($oa) + (int) $both($ob)];
}
check('T11 premise: in every run both tabs were in a lock wait before the release', array_filter($t11, static fn (array $r): bool => $r['held'] === false) === []);
check('T11b two tabs released together: every final state is ONE serial order (on/off or off/on), never off/off',
    array_filter($t11, static fn (array $r): bool => $r['final'] !== ['on', 'off'] && $r['final'] !== ['off', 'on']) === [], json_encode(array_column($t11, 'final')));
check('T11c ...and in every run exactly ONE tab\'s record names both services (the one that went second)',
    array_filter($t11, static fn (array $r): bool => $r['bothNamed'] !== 1) === [], json_encode(array_column($t11, 'bothNamed')));

// T12 (round 7): a failure AFTER something was written is rolled back. An
// applied "on" is written first, then the next write times out: the member is
// told nothing was saved, so the "on" must NOT be stored.
setChoice(M, 'gravatar', 'off');
setChoice(M, 'providerPhoto', 'on');
$db->begin_transaction();
$db->query('SELECT `choice` FROM `tblUserOutsideServicePref` WHERE `userID` = ' . M . " AND `serviceKey` = 'providerPhoto' FOR UPDATE")->fetch_all();
$tabF = $startWorker(['gravatar' => 'on', 'providerPhoto' => 'off'], 1);
// The premise: while it waits on the photo, it HAS already written one row
// (the Gravatar "on") — or the rollback would have nothing to undo.
$waitF = $lockWaits(1, 5.0);
$outF = $finish($tabF);
$db->commit();
check('T12 premise: the save was caught waiting AFTER it had written a row (its "on")', $waitF !== null && (int) ($waitF[0]['modified'] ?? 0) >= 1,
    json_encode($waitF));
check('T12b a wait that runs out AFTER an "on" was written: told nothing was saved, and the "on" is really NOT stored',
    ($outF['saved'] ?? null) === false && str_contains((string) ($outF['message'] ?? ''), 'Nothing was saved') === true
    && choiceOf(M, 'gravatar') === 'off' && choiceOf(M, 'providerPhoto') === 'on', json_encode($outF) . ' gravatar=' . var_export(choiceOf(M, 'gravatar'), true));
setAt(K_GRAV, null, 'off');
setAt(K_PHOTO, null, 'off');
setChoice(M, 'gravatar', null);
setChoice(M, 'providerPhoto', null);

// =============================================================================
// P. #809: A SMALL GROUP'S MEETING PLACE IS ITS NAMED HOST'S HOME
//    D-120 (treated as the host's home), D-124 (the host is a member NAMED for
//    the place; only that member can agree to a map of it).
// =============================================================================
head('P. A small group\'s usual meeting place: its named host, AND the person looking');
require $core . 'SmallGroups.php';

// ---- P-A. The rules on FACTS, hand-pinned ---------------------------------
$placeA  = ['addressLine1' => '12 Host Lane', 'addressLine2' => null, 'city' => 'Cambridge', 'region' => null,
            'postcode' => 'CB1 1AA', 'countryCode' => 'GB', 'latitude' => 52.21, 'longitude' => 0.13, 'what3words' => null];
$storedA = array_merge($placeA, ['latitude' => '52.2100000', 'longitude' => '0.1300000']);
$placeB  = array_merge($placeA, ['addressLine1' => '40 Other Road']);
$emptyPl = array_merge(array_fill_keys(\Portal\Core\SmallGroups::PLACE_COLUMNS, null), ['countryCode' => 'GB']);
$rowBy   = static fn (?int $host, int $agreed, ?int $by = 9): array => $storedA + ['hostUserID' => $host, 'placeMapAgreed' => $agreed, 'placeEnteredByID' => $by];
$after   = static fn (?array $existing, array $posted, ?int $host, int $saver, bool $drawn, bool $ticked): array
    => \Portal\Core\SmallGroups::placeAgreementAfterSave($existing, $posted, $host, $saver, $drawn, $ticked);
$pinned = [
    'PA01 a new place whose HOST saves it, ticked: agreed'                                       => [$after(null, $placeA, 7, 7, true, true), ['enteredBy' => 7, 'host' => 7, 'agreed' => 1, 'notAHome' => 0]],
    'PA02 a new place named for host 5 by a LEADER (7) who ticks: NOT agreed (only the host can)' => [$after(null, $placeA, 5, 7, true, true), ['enteredBy' => 7, 'host' => 5, 'agreed' => 0, 'notAHome' => 0]],
    'PA03 a new place with NO host, ticked by whoever: not agreed'                               => [$after(null, $placeA, null, 7, true, true), ['enteredBy' => 7, 'host' => null, 'agreed' => 0, 'notAHome' => 0]],
    'PA04 an EMPTY place: nobody, no host, not agreed'                                            => [$after($rowBy(5, 1), $emptyPl, 5, 5, true, true), ['enteredBy' => null, 'host' => null, 'agreed' => 0, 'notAHome' => 0]],
    'PA05 unchanged, host 5 agreed, a LEADER saves (no box): the agreement STANDS'               => [$after($rowBy(5, 1), $placeA, 5, 7, false, false), ['enteredBy' => 9, 'host' => 5, 'agreed' => 1, 'notAHome' => 0]],
    'PA06 unchanged, host 5 agreed, a leader POSTS an unticked box: still stands (not theirs)'  => [$after($rowBy(5, 1), $placeA, 5, 7, true, false), ['enteredBy' => 9, 'host' => 5, 'agreed' => 1, 'notAHome' => 0]],
    'PA07 unchanged, host 5 not agreed, a leader POSTS a tick: still NOT agreed'                => [$after($rowBy(5, 0), $placeA, 5, 7, true, true), ['enteredBy' => 9, 'host' => 5, 'agreed' => 0, 'notAHome' => 0]],
    'PA08 unchanged, the HOST unticks: withdrawn (stored 52.2100000, typed 52.21: unchanged)'    => [$after($rowBy(5, 1), $placeA, 5, 5, true, false), ['enteredBy' => 9, 'host' => 5, 'agreed' => 0, 'notAHome' => 0]],
    'PA09 unchanged, the host saves a form that did NOT draw the box: kept (#677)'              => [$after($rowBy(5, 1), $placeA, 5, 5, false, false), ['enteredBy' => 9, 'host' => 5, 'agreed' => 1, 'notAHome' => 0]],
    'PA10 the ADDRESS changed by a leader: the host\'s agreement is gone, the leader typed it'  => [$after($rowBy(5, 1), $placeB, 5, 7, false, false), ['enteredBy' => 7, 'host' => 5, 'agreed' => 0, 'notAHome' => 0]],
    'PA11 the address changed by the HOST, ticked: agreed to the NEW address'                   => [$after($rowBy(5, 1), $placeB, 5, 5, true, true), ['enteredBy' => 5, 'host' => 5, 'agreed' => 1, 'notAHome' => 0]],
    'PA12 the HOST changed (5 to 6), place unchanged: the old agreement does NOT move to 6'     => [$after($rowBy(5, 1), $placeA, 6, 7, false, false), ['enteredBy' => 9, 'host' => 6, 'agreed' => 0, 'notAHome' => 0]],
    'PA13 the host named is removed (to nobody): not agreed'                                     => [$after($rowBy(5, 1), $placeA, null, 7, false, false), ['enteredBy' => 9, 'host' => null, 'agreed' => 0, 'notAHome' => 0]],
    // Rule 5b (check round 1 of #809, X1): a host only just NAMED on an existing place starts not agreed, even when they named themselves and ticked.
    'PA13b a place with NO host: somebody names THEMSELVES (7) and ticks in the same save: NOT agreed (5b)' => [$after($rowBy(null, 0), $placeA, 7, 7, true, true), ['enteredBy' => 9, 'host' => 7, 'agreed' => 0, 'notAHome' => 0]],
    'PA13c host 5, address unchanged, 6 is named and 6 ticks in that save: NOT agreed (5b)'          => [$after($rowBy(5, 1), $placeA, 6, 6, true, true), ['enteredBy' => 9, 'host' => 6, 'agreed' => 0, 'notAHome' => 0]],
];
foreach ($pinned as $name => [$got, $want]) {
    check($name, $got === $want, 'got ' . json_encode($got));
}
// Who may take a place away from its named host (check round 1 of #809, X1/X2).
$takeOver = static fn (?array $existing, array $posted, ?int $newHost, bool $mark, int $saver): bool
    => \Portal\Core\SmallGroups::whyHostChangeRefused($existing, $posted, $newHost, $mark, $saver, 'start again') !== '';
check('PA13d X1 on facts: host 5, address unchanged, 7 names THEMSELVES: refused', $takeOver($rowBy(5, 1), $placeA, 7, false, 7) === true);
check('PA13e X2 on facts: host 5, address unchanged, 7 picks "nobody" and marks it a hall: refused', $takeOver($rowBy(5, 1), $placeA, null, true, 7) === true
    && $takeOver($rowBy(5, 1), $placeA, null, false, 7) === true && $takeOver($rowBy(5, 1), $placeA, 5, true, 7) === true);
check('PA13f ...the stored HOST themselves may hand it over, remove themselves, or mark it', $takeOver($rowBy(5, 1), $placeA, 6, false, 5) === false
    && $takeOver($rowBy(5, 1), $placeA, null, true, 5) === false);
// Check round 2 of #809: a CHANGED address is NOT a way past the rule (it was in round 1, and five takeovers went through it).
$tiny = array_merge($placeA, ['latitude' => 52.2100001]);
check('PA13g ...a CHANGED address does NOT let somebody else change the host: a new street, a tiny move of the position, a changed country',
    $takeOver($rowBy(5, 1), $placeB, 7, false, 7) === true && $takeOver($rowBy(5, 1), $tiny, null, false, 7) === true
    && $takeOver($rowBy(5, 1), array_merge($placeA, ['countryCode' => 'IE']), null, true, 7) === true);
// Check rounds 3 and 4 of #809: "empty" means EVERY part but the country. The
//    "keep only this part" cases are GENERATED from SmallGroups::PLACE_COLUMNS —
//    one per column except the country, the two coordinates as one pair — so a
//    column added there later gets its case here, and in P20h and R07f5, with
//    nobody having to remember. Round 4 listed five by hand and missed both
//    address lines; three plants then left everything green.
$placeParts    = array_values(array_diff(\Portal\Core\SmallGroups::PLACE_COLUMNS, ['countryCode']));
$keepOnlyCases = [];
foreach ($placeParts as $col) {
    if ($col === 'longitude' && in_array('latitude', $placeParts, true) === true) {
        continue;
    }
    $keepOnlyCases[$col] = $col === 'latitude' ? ['latitude', 'longitude'] : [$col];
}
$partSample = static fn (string $col): string|float => match ($col) {
    'latitude' => 52.21, 'longitude' => 0.13, 'what3words' => 'filled.count.soap', default => 'Kept ' . $col,
};
$unlabelled = array_values(array_diff($placeParts, array_keys(\Portal\Core\SmallGroups::PLACE_PART_LABELS)));
check('PA13g2b every place column except the country has a label in PLACE_PART_LABELS, so the route sentences name every part',
    $unlabelled === [] && str_contains(\Portal\Core\SmallGroups::placePartsInWords(), 'the map position') === true, 'no label: ' . implode(', ', $unlabelled));
check('PA13g3a premise: one "keep only this part" case for every place column except the country, the position as one pair',
    count($keepOnlyCases) === count(\Portal\Core\SmallGroups::PLACE_COLUMNS) - 2 && isset($keepOnlyCases['addressLine1'], $keepOnlyCases['addressLine2']) === true,
    implode(', ', array_keys($keepOnlyCases)));
$partlyLetThrough = [];
foreach ($keepOnlyCases as $label => $keep) {
    $left = [];
    foreach ($keep as $col) {
        $left[$col] = $partSample($col);
    }
    if ($takeOver($rowBy(5, 1), array_merge($emptyPl, $left), null, false, 7) === false
        || $takeOver($rowBy(5, 1), array_merge($emptyPl, $left), null, true, 7) === false) {
        $partlyLetThrough[] = $label;
    }
}
check('PA13g3 ...a PARTLY emptied place is not empty: with any ONE part left, a non-host still cannot drop the host or mark it ('
    . implode(', ', array_keys($keepOnlyCases)) . ')', $partlyLetThrough === [], 'let through: ' . implode(', ', $partlyLetThrough));
check('PA13g2 ...a non-host may still CORRECT the address with the host kept, and EMPTYING the place is the one way to start again',
    $takeOver($rowBy(5, 1), $placeB, 5, false, 7) === false && $takeOver($rowBy(5, 1), $emptyPl, null, false, 7) === false);
check('PA13h ...the host left as it is, nothing marked: allowed; a place with NO host, or a NEW one: allowed', $takeOver($rowBy(5, 1), $placeA, 5, false, 7) === false
    && $takeOver($rowBy(null, 0), $placeA, 7, false, 7) === false && $takeOver(null, $placeA, 7, false, 7) === false);
$takeOverWords = \Portal\Core\SmallGroups::whyHostChangeRefused($rowBy(5, 1), $placeA, 7, false, 7, 'remove this home from the group\'s list');
check('PA13i ...and the refusal says plainly what to do instead, and that nothing was saved', str_contains($takeOverWords, 'remove this home from the group\'s list, then enter the new place afresh; that deletes this address') === true
    && str_contains($takeOverWords, 'Nothing was saved') === true, $takeOverWords);
$yes = ['readable' => true, 'doesNotObject' => true];
$no  = ['readable' => true, 'doesNotObject' => false];
$bad = ['readable' => false, 'doesNotObject' => true];
check('PA14 the map rule: agreed, host still counts, neither objects -> yes', OutsideServices::meetingPlaceMapFrom(true, true, $yes, $yes) === true);
check('PA15 ...not agreed -> no', OutsideServices::meetingPlaceMapFrom(false, true, $yes, $yes) === false);
check('PA16 ...the host no longer counts -> no', OutsideServices::meetingPlaceMapFrom(true, false, $yes, $yes) === false);
check('PA17 ...the host objects -> no', OutsideServices::meetingPlaceMapFrom(true, true, $no, $yes) === false);
check('PA18 ...the viewer objects -> no', OutsideServices::meetingPlaceMapFrom(true, true, $yes, $no) === false);
check('PA19 ...either side unreadable -> no', OutsideServices::meetingPlaceMapFrom(true, true, $bad, $yes) === false
    && OutsideServices::meetingPlaceMapFrom(true, true, $yes, $bad) === false);
$may = static fn (bool $manage, bool $member, ?array $place, int $user): bool => \Portal\Core\SmallGroups::mayEditHome($manage, $member, $place, $user);
check('PA20 D-125 on facts: a leader may add or change ANY home', $may(true, true, null, 7) === true && $may(true, false, ['hostUserID' => 5], 7) === true);
check('PA21 ...a member may add a home (their own) and change one whose host they are', $may(false, true, null, 7) === true && $may(false, true, ['hostUserID' => 7], 7) === true);
check('PA22 ...a member may NOT change somebody else\'s home', $may(false, true, ['hostUserID' => 5], 7) === false);
check('PA23 ...somebody who is not an active member may do neither', $may(false, false, null, 7) === false && $may(false, false, ['hostUserID' => 7], 7) === false);
// D-128: the "not anybody's home" mark.
$markAfter = static fn (?array $existing, array $posted, ?int $host, bool $drawn, bool $ticked): int
    => \Portal\Core\SmallGroups::placeAgreementAfterSave($existing, $posted, $host, 7, false, false, $drawn, $ticked)['notAHome'];
$hall = static fn (int $mark, ?int $host = null): array => $storedA + ['hostUserID' => $host, 'placeMapAgreed' => 0, 'placeEnteredByID' => 7, 'notAHome' => $mark];
check('PA24 D-128 on facts: a NEW place with no host, marked: marked', $markAfter(null, $placeA, null, true, true) === 1);
check('PA25 ...a place WITH a host is never marked, whatever is posted', $markAfter(null, $placeA, 5, true, true) === 0 && $markAfter($hall(1), $placeA, 5, true, true) === 0);
check('PA26 ...a CHANGED address clears the mark even with the box still ticked', $markAfter($hall(1), $placeB, null, true, true) === 0);
check('PA27 ...unchanged: the drawn box decides; a form without it keeps the mark (#677)', $markAfter($hall(1), $placeA, null, true, false) === 0
    && $markAfter($hall(1), $placeA, null, false, false) === 1 && $markAfter($hall(0), $placeA, null, true, true) === 1);
check('PA28 ...an EMPTY place is never marked', $markAfter($hall(1), $emptyPl, null, true, true) === 0);
check('PA28b ...but a place that held NO address before and is now entered counts as NEW: a ticked mark is kept',
    $markAfter(array_merge($emptyPl, ['hostUserID' => null, 'notAHome' => 0]), $placeA, null, true, true) === 1);
check('PA29 the hall rule: marked, no host, viewer allows -> yes', OutsideServices::markedPlaceMapFrom(true, false, $yes) === true);
check('PA30 ...UNMARKED with no host -> no', OutsideServices::markedPlaceMapFrom(false, false, $yes) === false);
check('PA31 ...marked but WITH a host -> no (it is somebody\'s home)', OutsideServices::markedPlaceMapFrom(true, true, $yes) === false);
check('PA32 ...the viewer objects, or it cannot be read -> no', OutsideServices::markedPlaceMapFrom(true, false, $no) === false
    && OutsideServices::markedPlaceMapFrom(true, false, $bad) === false);

// ---- P-B. The real pages --------------------------------------------------
$sgEnabledBefore = $state['settings']['small-groups.enabled'];
if ($sgEnabledBefore === null) {
    q("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, 'small-groups.enabled', '1', '0', 0)");
} else {
    q("UPDATE `tblSettings` SET `settingValue` = '1' WHERE `settingKey` = 'small-groups.enabled' AND `siteID` IS NULL");
}
setAt('small-groups.enabled', ORG_A, null);
// The group as migration 275 leaves an OLD one: a place, no host, not agreed.
q("INSERT INTO `tblSmallGroups` (`groupID`, `siteID`, `groupName`, `groupSlug`, `addressLine1`, `city`, `postcode`, `countryCode`, "
    . "`latitude`, `longitude`, `locationVisibility`, `isActive`) VALUES (?, ?, 'Selftest Host Group', 'selftest-host-group', "
    . "'12 Host Lane', 'Cambridge', 'CB1 1AA', 'GB', 52.21, 0.13, 'site', 1)", 'ii', [SGROUP, ORG_A]);
foreach ([[HOSTP, 'leader'], [HOST2, 'co-leader'], [MEMBERP, 'member']] as [$who, $role]) {
    q("INSERT INTO `tblSmallGroupMembers` (`siteID`, `groupID`, `userID`, `memberRole`, `status`, `joinedAt`) VALUES (?, ?, ?, ?, 'active', NOW())",
        'iiis', [ORG_A, SGROUP, $who, $role]);
}
setAt(K_MAP, null, 'off');

$jarHost   = signIn('hostp', ORG_A);
$jarHost2  = signIn('host2', ORG_A);
$jarMember = signIn('memberp', ORG_A);
$groupPage = static fn (string $jar): array => req($jar, 'GET', '/small-groups/group?id=' . SGROUP);
$sgMap     = static fn (array $g): bool => str_contains($g['body'], 'id="sgMap"') === true;
$tileRule  = static fn (array $g): bool => str_contains(imgSrc($g), 'tile.openstreetmap.org') === true;
$sgTiles   = static fn (array $g): bool => $tileRule($g) === true || str_contains($g['body'], 'tile.openstreetmap.org') === true;
$sgAddress = static fn (array $g): bool => str_contains($g['body'], '12 Host Lane') === true;
$recorded  = static fn (): array => one('SELECT `hostUserID` AS `host`, `placeEnteredByID` AS `by`, `placeMapAgreed` AS `ok` FROM `tblSmallGroups` WHERE `groupID` = ?', 'i', [SGROUP]) ?? [];

/** Any form on a page, as a browser would send it untouched; `$change` overrides fields, null removes one. */
$formAt = static function (string $jar, string $url, string $actionHas, array $change = []): array {
    $page = req($jar, 'GET', $url);
    $dom  = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $page['body']);
    libxml_clear_errors();
    $fields = [];
    $xpath  = '//form[contains(@action, "' . $actionHas . '")][.//input[@name="action" and @value!="remove"] or not(.//input[@name="action"])]'
        . '//*[self::input or self::select or self::textarea]';
    foreach ((new DOMXPath($dom))->query($xpath) as $el) {
        $name = $el->getAttribute('name');
        if ($name === '' || $el->hasAttribute('disabled') === true) {
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
        } elseif ($el->getAttribute('type') === 'checkbox') {
            if ($el->hasAttribute('checked') === true) {
                $fields[$name] = $el->getAttribute('value');
            }
        } else {
            $fields[$name] = $el->getAttribute('value');
        }
    }
    $fields['_status'] = $page['status'];
    $fields['_html']   = $page['body'];
    foreach ($change as $k => $v) {
        if ($v === null) {
            unset($fields[$k]);
        } else {
            $fields[$k] = $v;
        }
    }

    return $fields;
};
$postTo = static function (string $jar, string $to, array $fields): array {
    unset($fields['_status'], $fields['_html']);

    return req($jar, 'POST', $to, $fields);
};
$manageUrl  = '/small-groups/manage?id=' . SGROUP;
$groupForm  = static fn (string $jar, array $change = []): array => $formAt($jar, $manageUrl, 'small-groups/save', $change);
$saveGroup  = static fn (string $jar, array $fields): array => $postTo($jar, '/small-groups/save', $fields);

$g = $groupPage($jarV);
check('P00 premise: the group page answers, and VIEWER (not in the group; the address is visible to the whole organisation) sees the written address',
    $g['status'] === 200 && $sgAddress($g) === true, 'status ' . $g['status']);
check('P01 an OLD group (no host named, nothing agreed, as migration 275 leaves it): no map, no tile host, the written address still shown',
    $sgMap($g) === false && $sgTiles($g) === false && $sgAddress($g) === true && str_contains($g['body'], 'portal-place-no-map') === true, imgSrc($g));

$fA = $groupForm($jarAA);
check('P02 premise: an administrator\'s edit form offers a host chooser (with the group\'s members) and NO agreement box — it is not their home',
    $fA['_status'] === 200 && array_key_exists('hostUserID', $fA) === true && str_contains((string) $fA['_html'], '>Selftest Outside hostp</option>') === true
    && array_key_exists('placeMapAsked', $fA) === false && str_contains((string) $fA['_html'], 'No host is named for this place yet') === true);
$sv = $saveGroup($jarAA, $groupForm($jarAA, ['hostUserID' => (string) HOSTP, 'placeMapAsked' => '1', 'placeMapAgreed' => '1']));
$r  = $recorded();
check('P03 D-124: the administrator names the leader as host AND posts a forged tick: the host is recorded, the tick counts for NOTHING',
    $sv['status'] === 302 && (int) ($r['host'] ?? 0) === HOSTP && (int) ($r['ok'] ?? -1) === 0, json_encode($r) . ' status ' . $sv['status']);
$g = $groupPage($jarV);
check('P04 a named host who has not agreed: no map', $sgMap($g) === false && $sgTiles($g) === false && $sgAddress($g) === true);
$fH = $groupForm($jarHost);
check('P05 premise: the HOST\'s own form draws the agreement box, unticked, with its marker', ($fH['placeMapAsked'] ?? '') === '1'
    && isset($fH['placeMapAgreed']) === false && str_contains((string) $fH['_html'], 'This is my home') === true);
$saveGroup($jarHost, $groupForm($jarHost, ['placeMapAgreed' => '1']));
$r = $recorded();
check('P06 the host ticks it on their own form: agreed', (int) ($r['host'] ?? 0) === HOSTP && (int) ($r['ok'] ?? -1) === 1, json_encode($r));
$g = $groupPage($jarV);
check('P07 the host agreed and nobody objects: the map IS drawn and the page rule names the tile host',
    $sgMap($g) === true && $tileRule($g) === true && $sgAddress($g) === true, imgSrc($g));

setChoice(VIEWER, 'memberMap', 'off');
$g = $groupPage($jarV);
check('P08 the VIEWER switched maps off: no map, no tile host, the written address still shown', $sgMap($g) === false && $sgTiles($g) === false && $sgAddress($g) === true);
setChoice(VIEWER, 'memberMap', null);
setChoice(HOSTP, 'memberMap', 'off');
$g = $groupPage($jarV);
check('P09 the HOST switched maps off for themselves: no map (their tick cannot loosen their own "off")', $sgMap($g) === false && $sgTiles($g) === false);
$hostForm = (string) $groupForm($jarHost)['_html'];
check('P09b ...and their own form says plainly that no map will be shown from their agreement', str_contains($hostForm, 'No map will be shown from your agreement') === true
    && str_contains($hostForm, 'switched maps off for yourself') === true);
setChoice(HOSTP, 'memberMap', null);
setAt(K_MAP, ORG_A, 'no');
$g = $groupPage($jarV);
check('P10 the ORGANISATION says no: no map, no tile host, the written address still shown', $sgMap($g) === false && $sgTiles($g) === false && $sgAddress($g) === true);
setAt(K_MAP, ORG_A, null);
q('INSERT INTO `tblUserSites` (`userID`, `siteID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES (?, ?, 1, 0, 0)', 'ii', [HOSTP, ORG_B]);
setAt(K_MAP, ORG_B, 'no');
$g = $groupPage($jarV);
check('P11 D-058 point 4 for the HOST: another organisation of theirs says no, so no map on A\'s page', $sgMap($g) === false && $sgTiles($g) === false);
setAt(K_MAP, ORG_B, null);
q('DELETE FROM `tblUserSites` WHERE `userID` = ? AND `siteID` = ?', 'ii', [HOSTP, ORG_B]);
setAt(K_MAP, null, 'no');
$g = $groupPage($jarV);
check('P12 the INSTALLATION says no: no map, whatever was agreed', $sgMap($g) === false && $sgTiles($g) === false && $sgAddress($g) === true);
setAt(K_MAP, null, 'off');
$g = $groupPage($jarV);
check('P13 control: everything back, the map IS drawn again (so P08-P12 were each about their own change)', $sgMap($g) === true && $tileRule($g) === true);

// Saves by the host and by somebody else.
$saveGroup($jarAA, $groupForm($jarAA, ['groupName' => 'Selftest Host Group (renamed)']));
$r = $recorded();
check('P14 an administrator saves the form untouched apart from the name: the host and their agreement STAND',
    (int) ($r['host'] ?? 0) === HOSTP && (int) ($r['ok'] ?? -1) === 1, json_encode($r));
$aaHtml = (string) $groupForm($jarAA)['_html'];
check('P14b ...and their form says whose home it is, that they have agreed, and that only they can change it',
    str_contains($aaHtml, "This is Selftest Outside hostp's home") === true && str_contains($aaHtml, 'has agreed') === true
    && str_contains($aaHtml, 'Only Selftest Outside hostp can change that') === true);
$saveGroup($jarAA, $groupForm($jarAA, ['placeMapAsked' => '1']));
$r = $recorded();
check('P15 an administrator posts the box UNTICKED (forged marker): the host\'s agreement still stands — not theirs to withdraw',
    (int) ($r['ok'] ?? -1) === 1, json_encode($r));
$saveGroup($jarAA, $groupForm($jarAA, ['hostUserID' => null]));
$r = $recorded();
check('P15b an administrator\'s save that did not send the host field at all keeps the host (#677), never "nobody"',
    (int) ($r['host'] ?? 0) === HOSTP && (int) ($r['ok'] ?? -1) === 1, json_encode($r));
$hostUntouched = $groupForm($jarHost);
check('P16 the host\'s own form draws the box TICKED, so their untouched Save keeps their agreement', ($hostUntouched['placeMapAgreed'] ?? '') === '1');
$saveGroup($jarHost, $groupForm($jarHost, ['placeMapAgreed' => null]));
$r = $recorded();
$g = $groupPage($jarV);
check('P17 the host unticks: withdrawn, and the map is gone', (int) ($r['ok'] ?? -1) === 0 && $sgMap($g) === false && $sgTiles($g) === false, json_encode($r));
$saveGroup($jarHost, $groupForm($jarHost, ['placeMapAgreed' => '1']));
$saveGroup($jarHost, $groupForm($jarHost, ['placeMapAsked' => null, 'placeMapAgreed' => null]));
$r = $recorded();
check('P18 the host agrees again, then saves from a form that did NOT draw the box: kept, not withdrawn (#677)', (int) ($r['ok'] ?? -1) === 1, json_encode($r));
$saveGroup($jarAA, $groupForm($jarAA, ['addressLine1' => '40 Other Road']));
$r = $recorded();
$g = $groupPage($jarV);
check('P19 an administrator CHANGES the address: the host stays named, but their agreement is GONE (it was about another address); they typed it',
    (int) ($r['host'] ?? 0) === HOSTP && (int) ($r['ok'] ?? -1) === 0 && (int) ($r['by'] ?? 0) === AADMIN && $sgMap($g) === false, json_encode($r));
// 🛡️ Check round 1 of #809 (X1, X2): with the address UNCHANGED, only the named
//    host may change who lives there or mark the place "not anybody's home".
//    The host's agreement is set to 0 first, so an attack that got through would
//    show a map nobody agreed to — which is the harm being refused.
q("UPDATE `tblSmallGroups` SET `addressLine1` = '12 Host Lane', `placeMapAgreed` = 0 WHERE `groupID` = ?", 'i', [SGROUP]);
$pageText     = static fn (array $page): string => html_entity_decode((string) ($page['_html'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
// The two route sentences, word for word (the list of parts is built from
// SmallGroups::PLACE_PART_LABELS; a new place column changes it on purpose).
$usualRoute  = "To meet somewhere else, clear every part of this place except the country (the first address line, the second address line, the town, the region, the postcode, the map position and the what3words address) and save, then enter the new place.";
$listedRoute = "To meet somewhere else, remove this home from the group's list, then add the new one.";
$takeOverSays = static fn (array $page): bool => str_contains($pageText($page), 'Only they can name a different host') === true
    && str_contains($pageText($page), "To meet somewhere else, clear every part of the meeting place except the country (the first address line, the second address line, the town, the region, the postcode, the map position and the what3words address) and save, then enter the new place afresh; that deletes this address. Nothing was saved.") === true;
$x1 = $saveGroup($jarHost2, $groupForm($jarHost2, ['hostUserID' => (string) HOST2]));
$r  = $recorded();
$fx = $groupForm($jarHost2);
check('P20 X1 THROUGH THE FORM: a co-leader names THEMSELVES as host of the leader\'s home (address unchanged): refused, the host is unchanged, and the form says why',
    $x1['status'] === 302 && (int) ($r['host'] ?? 0) === HOSTP && (int) ($r['ok'] ?? -1) === 0 && $takeOverSays($fx) === true, json_encode($r) . ' status ' . $x1['status']);
check('P20a the HOSTED usual-place form, as a co-leader sees it, gives the usual place\'s route TWICE (the chooser\'s hint AND under the host\'s name) and never the listed-home route (check round 5)',
    substr_count($pageText($fx), $usualRoute) === 2 && str_contains($pageText($fx), $listedRoute) === false
    && str_contains($pageText($fx), 'remove this home from the group') === false);
$saveGroup($jarHost2, array_merge($groupForm($jarHost2), ['hostUserID' => (string) HOST2, 'placeMapAsked' => '1', 'placeMapAgreed' => '1']));
$r = $recorded();
$g = $groupPage($jarV);
check('P20b X1 AS A FORGED POST: the same, with the agreement box posted ticked: refused, still the leader\'s home, still not agreed, NO map',
    (int) ($r['host'] ?? 0) === HOSTP && (int) ($r['ok'] ?? -1) === 0 && $sgMap($g) === false && $sgTiles($g) === false, json_encode($r));
$saveGroup($jarHost2, $groupForm($jarHost2, ['hostUserID' => '', 'notAHome' => '1']));
$r  = one('SELECT `hostUserID`, `placeMapAgreed`, `notAHome` FROM `tblSmallGroups` WHERE `groupID` = ?', 'i', [SGROUP]) ?? [];
$g  = $groupPage($jarV);
check('P20c X2 THROUGH THE FORM: a co-leader picks "nobody" and ticks "not anybody\'s home" on the leader\'s home: refused, host unchanged, NOT marked, NO map',
    (int) ($r['hostUserID'] ?? 0) === HOSTP && (int) ($r['notAHome'] ?? -1) === 0 && $sgMap($g) === false && $sgTiles($g) === false, json_encode($r));
$saveGroup($jarAA, array_merge($groupForm($jarAA), ['hostUserID' => '', 'notAHomeAsked' => '1', 'notAHome' => '1']));
$r  = one('SELECT `hostUserID`, `placeMapAgreed`, `notAHome` FROM `tblSmallGroups` WHERE `groupID` = ?', 'i', [SGROUP]) ?? [];
check('P20d X2 AS A FORGED POST by an administrator: refused the same way — not even an administrator can turn a member\'s home into a "hall"',
    (int) ($r['hostUserID'] ?? 0) === HOSTP && (int) ($r['notAHome'] ?? -1) === 0 && $sgMap($groupPage($jarV)) === false, json_encode($r));
// 🛡️ Check round 2 of #809: the five takeovers the checker drove through
//    round 1's "a changed address is a new place" exemption (Y1, Y2, Y2b, Z1,
//    Z2). Each is two saves — change something tiny while dropping or
//    replacing the host, then put it back and agree or mark it — sent once
//    through the attacker's own FORM (only fields it draws) and once as a
//    FORGED post by an administrator carrying the boxes too. Every one must
//    leave the stored place exactly as it was, and draw no map.
$takeoverAttacks = static function (string $other, string $line1, string $lat): array {
    return [
        'Y1 one letter\'s case changed and somebody else named, then the address put back and agreed' => [
            ['addressLine1' => strtolower($line1), 'hostUserID' => $other],
            ['addressLine1' => $line1, 'hostUserID' => $other],
            ['placeMapAsked' => '1', 'placeMapAgreed' => '1'],
        ],
        'Y2 the country changed with nobody named, then marked "not anybody\'s home"' => [
            ['countryCode' => 'IE', 'hostUserID' => ''],
            ['countryCode' => 'IE', 'hostUserID' => '', 'notAHome' => '1'],
            ['notAHomeAsked' => '1'],
        ],
        'Y2b the country changed with nobody named, then put BACK and marked: the identical address' => [
            ['countryCode' => 'IE', 'hostUserID' => ''],
            ['countryCode' => 'GB', 'hostUserID' => '', 'notAHome' => '1'],
            ['notAHomeAsked' => '1'],
        ],
        'Z1 the position moved by 0.0000001 and somebody else named, then put back and agreed' => [
            ['latitude' => $lat . '00001', 'hostUserID' => $other],
            ['latitude' => $lat, 'hostUserID' => $other],
            ['placeMapAsked' => '1', 'placeMapAgreed' => '1'],
        ],
        'Z2 the country changed with nobody named, then marked (the round-2 listed-home shape)' => [
            ['countryCode' => 'IE', 'hostUserID' => ''],
            ['countryCode' => 'GB', 'hostUserID' => '', 'notAHome' => '1', 'latitude' => $lat],
            ['notAHomeAsked' => '1'],
        ],
    ];
};
// Each attack starts from the SAME stored place: `$reset` writes the row back
// before every one. Without it a plant that let ONE attack through left the
// place hostless, and every later attack then started from a place with no
// host, which the rule rightly allows, so their checks stayed green (found by
// planting a narrower "empty" in check round 3: only 3 of 10 went red).
$rowWriter = static function (string $table, string $keyCol, int $id, array $row): callable {
    return static function () use ($table, $keyCol, $id, $row): void {
        $sets = [];
        foreach (array_keys($row) as $col) {
            $sets[] = '`' . $col . '` = ?';
        }
        q('UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE `' . $keyCol . '` = ?', str_repeat('s', count($row)) . 'i',
            array_merge(array_values($row), [$id]));
    };
};
$runTakeovers = static function (string $prefix, callable $snap, callable $mapShown, callable $form, callable $send, string $formJar, string $forgeJar, array $attacks, callable $reset): void {
    foreach ($attacks as $name => [$step1, $step2, $boxes]) {
        foreach (['THROUGH THE FORM' => [$formJar, []], 'AS A FORGED POST' => [$forgeJar, $boxes]] as $how => [$jar, $extra]) {
            $reset();
            $before = $snap();
            $send($jar, $form($jar, $step1 + $extra));
            $send($jar, $form($jar, $step2 + $extra));
            $after = $snap();
            check($prefix . ' ' . substr($name, 0, strpos($name, ' ')) . ' ' . $how . ': ' . substr($name, strpos($name, ' ') + 1)
                . ' — refused, the place stored exactly as it was, and NO map', $after === $before && $mapShown() === false,
                json_encode(['before' => $before, 'after' => $after]));
        }
    }
};
$placeColsSql = '`' . implode('`, `', \Portal\Core\SmallGroups::PLACE_COLUMNS) . '`';
$usualSnap = static fn (): array => one('SELECT ' . $placeColsSql . ', `hostUserID`, `placeMapAgreed`, `notAHome`, '
    . '`placeEnteredByID` FROM `tblSmallGroups` WHERE `groupID` = ?', 'i', [SGROUP]) ?? [];
$usualReset = $rowWriter('tblSmallGroups', 'groupID', SGROUP, $usualSnap());
$runTakeovers('P20e', $usualSnap, static fn (): bool => $sgMap($groupPage($jarV)) === true || $tileRule($groupPage($jarV)) === true,
    static fn (string $jar, array $change): array => $groupForm($jar, $change), $saveGroup, $jarHost2, $jarAA,
    $takeoverAttacks((string) HOST2, '12 Host Lane', '52.21'), $usualReset);
$usualReset();
// Check round 3 of #809: the same two saves, but each first save EMPTIES ALL
//    BUT ONE PART of the place while dropping the host — then the second marks
//    it "not anybody's home". "Empty" means every part except the country, so
//    each must be refused like any other change.
$partialAttacks = static function () use ($placeParts, $keepOnlyCases): array {
    $out = [];
    foreach ($keepOnlyCases as $label => $keep) {
        $clear = array_fill_keys(array_values(array_diff($placeParts, $keep)), '');
        $out['K-' . $label . ' everything cleared but ' . implode(' and ', $keep) . ', with nobody named, then marked "not anybody\'s home"'] = [
            $clear + ['hostUserID' => ''],
            $clear + ['hostUserID' => '', 'notAHome' => '1'],
            ['notAHomeAsked' => '1'],
        ];
    }

    return $out;
};
// Every part must HOLD something before the attacks, or keeping it would keep
// nothing: a part that is empty is filled with its sample, and put back after.
$fillParts = static function (string $table, string $keyCol, int $id) use ($placeParts, $partSample): callable {
    $was  = one('SELECT `' . implode('`, `', $placeParts) . '` FROM `' . $table . '` WHERE `' . $keyCol . '` = ?', 'i', [$id]) ?? [];
    foreach ($placeParts as $col) {
        if (($was[$col] ?? null) === null || (string) $was[$col] === '') {
            q('UPDATE `' . $table . '` SET `' . $col . '` = ? WHERE `' . $keyCol . '` = ?', 'si', [(string) $partSample($col), $id]);
        }
    }

    return static function () use ($table, $keyCol, $id, $was): void {
        foreach ($was as $col => $value) {
            q('UPDATE `' . $table . '` SET `' . $col . '` = ? WHERE `' . $keyCol . '` = ?', 'si', [$value, $id]);
        }
    };
};
$usualPutBack = $fillParts('tblSmallGroups', 'groupID', SGROUP);
check('P20h0 premise: before the "keep only this part" attacks, EVERY part of the usual place holds something',
    array_filter(array_intersect_key($usualSnap(), array_flip($placeParts)), static fn ($v): bool => $v === null || trim((string) $v) === '') === [], json_encode($usualSnap()));
$usualResetParts = $rowWriter('tblSmallGroups', 'groupID', SGROUP, $usualSnap());
$runTakeovers('P20h', $usualSnap, static fn (): bool => $sgMap($groupPage($jarV)) === true || $tileRule($groupPage($jarV)) === true,
    static fn (string $jar, array $change): array => $groupForm($jar, $change), $saveGroup, $jarHost2, $jarAA, $partialAttacks(), $usualResetParts);
$usualResetParts();
$usualPutBack();
// The ONE way past for somebody who is not the host: EMPTY the place. That
// deletes the address with the host (D-126); a new place is then entered afresh.
$beforeEmpty = $usualSnap();
$saveGroup($jarAA, $groupForm($jarAA, ['addressLine1' => '', 'city' => '', 'postcode' => '', 'latitude' => '', 'longitude' => '', 'hostUserID' => '']));
$r = one('SELECT `addressLine1`, `latitude`, `hostUserID` FROM `tblSmallGroups` WHERE `groupID` = ?', 'i', [SGROUP]) ?? [];
check('P20f ...but somebody who is not the host MAY empty the place: the address and the host go together, so a fresh place can be entered',
    array_key_exists('addressLine1', $r) === true && $r['addressLine1'] === null && $r['latitude'] === null && $r['hostUserID'] === null, json_encode($r));
q("UPDATE `tblSmallGroups` SET `addressLine1` = '12 Host Lane', `city` = 'Cambridge', `postcode` = 'CB1 1AA', `countryCode` = 'GB', `latitude` = 52.21, "
    . "`longitude` = 0.13, `hostUserID` = ?, `placeMapAgreed` = 0, `notAHome` = 0, `placeEnteredByID` = ? WHERE `groupID` = ?", 'iii',
    [HOSTP, $beforeEmpty['placeEnteredByID'] ?? null, SGROUP]);
check('P20g premise: the place is back as it was before P20f', $usualSnap() === $beforeEmpty, json_encode($usualSnap()));
q("UPDATE `tblSmallGroups` SET `placeMapAgreed` = 1 WHERE `groupID` = ?", 'i', [SGROUP]);
$saveGroup($jarHost, $groupForm($jarHost, ['hostUserID' => (string) HOST2]));
$r = $recorded();
check('P21 the HOST themselves may hand it over to another member — and the new host starts NOT agreed: an agreement belongs to the person who gave it',
    (int) ($r['host'] ?? 0) === HOST2 && (int) ($r['ok'] ?? -1) === 0, json_encode($r));
$saveGroup($jarHost2, array_merge($groupForm($jarHost2), ['hostUserID' => (string) HOSTP]));
q("UPDATE `tblSmallGroups` SET `hostUserID` = NULL, `placeMapAgreed` = 0 WHERE `groupID` = ?", 'i', [SGROUP]);
$saveGroup($jarHost, array_merge($groupForm($jarHost), ['hostUserID' => (string) HOSTP, 'placeMapAsked' => '1', 'placeMapAgreed' => '1']));
$r = $recorded();
check('P21b on a place with NO host, somebody naming THEMSELVES and posting a tick in the same save still starts NOT agreed (the agreement comes on a later save)',
    (int) ($r['host'] ?? 0) === HOSTP && (int) ($r['ok'] ?? -1) === 0, json_encode($r));
$saveGroup($jarAA, $groupForm($jarAA, ['hostUserID' => (string) VIEWER, 'addressLine1' => '13 Host Lane']));
$r = $recorded();
check('P21c naming somebody who is NOT a member of the group is refused, and nothing is saved', (int) ($r['host'] ?? 0) === HOSTP
    && $sgAddress($groupPage($jarV)) === true, json_encode($r));
$saveGroup($jarHost, $groupForm($jarHost, ['hostUserID' => '', 'notAHome' => '1']));
$r = one('SELECT `hostUserID`, `notAHome` FROM `tblSmallGroups` WHERE `groupID` = ?', 'i', [SGROUP]) ?? [];
check('P21d the HOST may say their own place is not anybody\'s home: no host, marked (the host keeps full control)',
    array_key_exists('hostUserID', $r) === true && $r['hostUserID'] === null && (int) ($r['notAHome'] ?? -1) === 1, json_encode($r));
q("UPDATE `tblSmallGroups` SET `hostUserID` = ?, `placeMapAgreed` = 1, `notAHome` = 0 WHERE `groupID` = ?", 'ii', [HOSTP, SGROUP]);
$g = $groupPage($jarV);
check('P22 premise for P23-P25: with the leader named and agreed, the map is drawn', $sgMap($g) === true);
q("UPDATE `tblSmallGroupMembers` SET `status` = 'ended' WHERE `groupID` = ? AND `userID` = ?", 'ii', [SGROUP, HOSTP]);
$g = $groupPage($jarV);
check('P23 the host LEAVES THE GROUP: no map, though the row still says agreed', $sgMap($g) === false && $sgTiles($g) === false && $sgAddress($g) === true);
q("UPDATE `tblSmallGroupMembers` SET `status` = 'active' WHERE `groupID` = ? AND `userID` = ?", 'ii', [SGROUP, HOSTP]);
q('UPDATE `tblUsers` SET `isActive` = 0 WHERE `userID` = ?', 'i', [HOSTP]);
$g = $groupPage($jarV);
check('P24 the host\'s account is switched off (as an erasure leaves it): no map', $sgMap($g) === false && $sgTiles($g) === false && $sgAddress($g) === true);
q('UPDATE `tblUsers` SET `isActive` = 1 WHERE `userID` = ?', 'i', [HOSTP]);
q('UPDATE `tblUserSites` SET `isActive` = 0 WHERE `userID` = ? AND `siteID` = ?', 'ii', [HOSTP, ORG_A]);
$g = $groupPage($jarV);
check('P25 the host is no longer a member of the ORGANISATION: no map', $sgMap($g) === false && $sgTiles($g) === false);
q('UPDATE `tblUserSites` SET `isActive` = 1 WHERE `userID` = ? AND `siteID` = ?', 'ii', [HOSTP, ORG_A]);
$g = $groupPage($jarV);
check('P26 control: the host counts again, the map is drawn again', $sgMap($g) === true && $tileRule($g) === true);
$saveGroup($jarHost, $groupForm($jarHost, ['addressLine1' => '', 'city' => '', 'postcode' => '', 'latitude' => '', 'longitude' => '', 'placeMapAgreed' => '1']));
$r = $recorded();
check('P27 the place is emptied: no host, nobody, nothing agreed, whatever was ticked',
    array_key_exists('host', $r) === true && $r['host'] === null && $r['by'] === null && (int) ($r['ok'] ?? -1) === 0, json_encode($r));

// =============================================================================
// R. #809, D-123 + D-124 + D-125: A GROUP THAT TAKES TURNS IN SEVERAL HOMES
//    Each home names its HOST; only that host can agree to a map of it; one
//    host's agreement never touches another's; a member may add only their own.
// =============================================================================
head('R. Several hosts\' homes: each named host decides their own home\'s map');
$placeUrl = static fn (int $placeId = 0): string => '/small-groups/place?id=' . SGROUP . ($placeId > 0 ? '&placeID=' . $placeId : '');
$homeForm = static fn (string $jar, int $placeId = 0, array $change = []): array => $formAt($jar, $placeUrl($placeId), 'small-groups/place-save', $change);
$saveHome = static fn (string $jar, array $fields): array => $postTo($jar, '/small-groups/place-save', $fields);
$placeRow = static fn (int $placeId): array => one('SELECT * FROM `tblSmallGroupPlaces` WHERE `placeID` = ?', 'i', [$placeId]) ?? [];
$placeMap = static fn (array $g, int $placeId): bool => str_contains($g['body'], 'id="sgPlaceMap' . $placeId . '"') === true;
$placeBy  = static fn (string $label): int => (int) (one('SELECT `placeID` FROM `tblSmallGroupPlaces` WHERE `groupID` = ? AND `placeLabel` = ?', 'is', [SGROUP, $label])['placeID'] ?? 0);

// ---- R-A. Adding homes, D-124 and D-125 ----------------------------------
$fNew = $homeForm($jarHost);
check('R00 premise: a leader opens "Add a host\'s home" — a host chooser, and no agreement box until a host is named',
    $fNew['_status'] === 200 && array_key_exists('hostUserID', $fNew) === true && array_key_exists('placeMapAsked', $fNew) === false && ($fNew['action'] ?? '') === 'create',
    json_encode(['status' => $fNew['_status']]));
// The leader types in the SECOND host's home, names them, and forges a tick.
$saveHome($jarHost, $homeForm($jarHost, 0, [
    'hostUserID' => (string) HOST2, 'placeLabel' => 'Beta home', 'addressLine1' => '2 Beta Road', 'city' => 'Cambridge', 'countryCode' => 'GB',
    'latitude' => '52.40', 'longitude' => '0.25', 'placeMapAsked' => '1', 'placeMapAgreed' => '1',
]));
$placeB = $placeBy('Beta home');
$rowB = $placeRow($placeB);
check('R01 D-124: a leader types in another member\'s home and names them: THEY are the host, the leader only typed it, and the leader\'s forged tick counts for NOTHING',
    $placeB > 0 && (int) ($rowB['hostUserID'] ?? 0) === HOST2 && (int) ($rowB['placeEnteredByID'] ?? 0) === HOSTP && (int) ($rowB['placeMapAgreed'] ?? -1) === 0,
    json_encode($rowB));
$g = $groupPage($jarV);
check('R02 so that home shows its written address and NO map', str_contains($g['body'], '2 Beta Road') === true && $placeMap($g, $placeB) === false);
$fB = $homeForm($jarHost2, $placeB);
check('R03 the named host opens THEIR home: the agreement box is drawn for them', $fB['_status'] === 200 && ($fB['placeMapAsked'] ?? '') === '1');
$saveHome($jarHost2, $homeForm($jarHost2, $placeB, ['placeMapAgreed' => '1']));
$g = $groupPage($jarV);
check('R04 the host agrees on their own form: their home\'s map is drawn', (int) ($placeRow($placeB)['placeMapAgreed'] ?? -1) === 1
    && $placeMap($g, $placeB) === true && $tileRule($g) === true);
// The leader adds their OWN home, as its host, ticked.
$saveHome($jarHost, $homeForm($jarHost, 0, [
    'hostUserID' => (string) HOSTP, 'placeLabel' => 'Alpha home', 'addressLine1' => '1 Alpha Road', 'city' => 'Cambridge', 'countryCode' => 'GB',
    'latitude' => '52.30', 'longitude' => '0.20',
]));
$placeA = $placeBy('Alpha home');
$saveHome($jarHost, $homeForm($jarHost, $placeA, ['placeMapAgreed' => '1']));
$g = $groupPage($jarV);
check('R05 two homes, each agreed by its own host: both maps drawn', $placeMap($g, $placeA) === true && $placeMap($g, $placeB) === true);
$saveHome($jarHost, $homeForm($jarHost, $placeA, ['placeMapAgreed' => null]));
$g = $groupPage($jarV);
check('R06 ONE HOST WITHDRAWING DOES NOT AFFECT THE OTHER: the first host withdraws, the second\'s map stays',
    $placeMap($g, $placeA) === false && $placeMap($g, $placeB) === true && (int) ($placeRow($placeB)['placeMapAgreed'] ?? -1) === 1);
$saveHome($jarHost, $homeForm($jarHost, $placeB, ['placeMapAsked' => '1']));
check('R07 the first host (a leader) posts an unticked box on the SECOND host\'s home: that host\'s agreement stands',
    (int) ($placeRow($placeB)['placeMapAgreed'] ?? -1) === 1 && (int) ($placeRow($placeB)['hostUserID'] ?? 0) === HOST2);
// 🛡️ Check round 1 of #809 (X1, X2) on a LISTED home: with the address
//    unchanged, only its named host may change who lives there or mark it
//    "not anybody's home". The agreement is set to 0 first, so a take-over
//    that got through would put a map up that its host never agreed to.
$routeOnly = static fn (array $form, string $mine, string $other): bool
    => str_contains($pageText($form), $mine) === true && str_contains($pageText($form), $other) === false
    && str_contains($pageText($form), $mine === $usualRoute ? 'remove this home from the group' : 'clear every part of this place') === false;
$fUsual = $groupForm($jarAA);
$fNewHome = $homeForm($jarHost);
$fEditHome = $homeForm($jarHost, $placeB);
check('R07c0 each form offers ONLY the route that works on it: the group\'s own form says to clear every part and save (it has no "remove")',
    $routeOnly($fUsual, $usualRoute, $listedRoute) === true);
check('R07c0b ...the ADD-a-home form draws no route at all (a home not yet on the list cannot be removed from it)',
    $fNewHome['_status'] === 200 && str_contains($pageText($fNewHome), 'To meet somewhere else') === false);
check('R07c0c ...the HOSTED edit-a-home form says to remove it from the list, in the chooser\'s hint AND under the host\'s name, and never the usual place\'s route',
    $routeOnly($fEditHome, $listedRoute, $usualRoute) === true && substr_count($pageText($fEditHome), $listedRoute) === 2);
q('UPDATE `tblSmallGroupPlaces` SET `placeMapAgreed` = 0 WHERE `placeID` = ?', 'i', [$placeB]);
$rx = $saveHome($jarHost, $homeForm($jarHost, $placeB, ['hostUserID' => (string) HOSTP]));
$rowB = $placeRow($placeB);
$fx   = $homeForm($jarHost, $placeB);
check('R07c X1 THROUGH THE FORM: the leader names THEMSELVES as host of the second host\'s home (address unchanged): refused, still the second host\'s, and the form says why',
    $rx['status'] === 302 && (int) ($rowB['hostUserID'] ?? 0) === HOST2 && (int) ($rowB['placeMapAgreed'] ?? -1) === 0
    && str_contains($pageText($fx), 'Only they can name a different host') === true
    && str_contains($pageText($fx), "To meet somewhere else, remove this home from the group's list, then enter the new place afresh; that deletes this address. Nothing was saved.") === true, json_encode($rowB) . ' status ' . $rx['status']);
$saveHome($jarHost, array_merge($homeForm($jarHost, $placeB), ['hostUserID' => (string) HOSTP, 'placeMapAsked' => '1', 'placeMapAgreed' => '1']));
$rowB = $placeRow($placeB);
$g    = $groupPage($jarV);
check('R07d X1 AS A FORGED POST, with the agreement box posted ticked: refused, still the second host\'s, not agreed, NO map',
    (int) ($rowB['hostUserID'] ?? 0) === HOST2 && (int) ($rowB['placeMapAgreed'] ?? -1) === 0 && $placeMap($g, $placeB) === false, json_encode($rowB));
$saveHome($jarHost, $homeForm($jarHost, $placeB, ['hostUserID' => '', 'notAHome' => '1']));
$rowB = $placeRow($placeB);
$g    = $groupPage($jarV);
check('R07e X2 THROUGH THE FORM: the leader picks "nobody" and ticks "not anybody\'s home": refused, host unchanged, NOT marked, NO map',
    (int) ($rowB['hostUserID'] ?? 0) === HOST2 && (int) ($rowB['notAHome'] ?? -1) === 0 && $placeMap($g, $placeB) === false, json_encode($rowB));
$saveHome($jarAA, array_merge($homeForm($jarAA, $placeB), ['hostUserID' => '', 'notAHomeAsked' => '1', 'notAHome' => '1']));
$rowB = $placeRow($placeB);
$g    = $groupPage($jarV);
check('R07f X2 AS A FORGED POST by an administrator: refused the same way, NO map',
    (int) ($rowB['hostUserID'] ?? 0) === HOST2 && (int) ($rowB['notAHome'] ?? -1) === 0 && $placeMap($g, $placeB) === false, json_encode($rowB));
$listedSnap = static fn (): array => one('SELECT ' . $placeColsSql . ', `hostUserID`, `placeMapAgreed`, `notAHome`, '
    . '`placeEnteredByID`, `isActive` FROM `tblSmallGroupPlaces` WHERE `placeID` = ?', 'i', [$placeB]) ?? [];
$listedReset = $rowWriter('tblSmallGroupPlaces', 'placeID', $placeB, $listedSnap());
$runTakeovers('R07f2', $listedSnap, static fn (): bool => $placeMap($groupPage($jarV), $placeB) === true,
    static fn (string $jar, array $change): array => $homeForm($jar, $placeB, $change), $saveHome, $jarHost, $jarAA,
    $takeoverAttacks((string) HOSTP, '2 Beta Road', '52.40'), $listedReset);
$listedReset();
// A listed home's update that empties EVERY part is refused by place-save.php
// before the rule is asked ("Enter at least part of the address…": a home
// with no address is not a home; removing it is the way). A PARTLY emptied
// one does reach the rule, so the same generated cases run here.
$listedPutBack = $fillParts('tblSmallGroupPlaces', 'placeID', $placeB);
check('R07f5a premise: before the "keep only this part" attacks, EVERY part of the listed home holds something',
    array_filter(array_intersect_key($listedSnap(), array_flip($placeParts)), static fn ($v): bool => $v === null || trim((string) $v) === '') === [], json_encode($listedSnap()));
$listedResetParts = $rowWriter('tblSmallGroupPlaces', 'placeID', $placeB, $listedSnap());
$runTakeovers('R07f5', $listedSnap, static fn (): bool => $placeMap($groupPage($jarV), $placeB) === true,
    static fn (string $jar, array $change): array => $homeForm($jar, $placeB, $change), $saveHome, $jarHost, $jarAA, $partialAttacks(), $listedResetParts);
$listedResetParts();
$listedPutBack();
// Legitimate: the listed home's own host hands it over, then it is handed back.
$saveHome($jarHost2, $homeForm($jarHost2, $placeB, ['hostUserID' => (string) HOSTP]));
$rowB = $placeRow($placeB);
check('R07f3 the listed home\'s own HOST may hand it over to another member, who starts NOT agreed',
    (int) ($rowB['hostUserID'] ?? 0) === HOSTP && (int) ($rowB['placeMapAgreed'] ?? -1) === 0, json_encode($rowB));
$saveHome($jarHost, $homeForm($jarHost, $placeB, ['hostUserID' => (string) HOST2]));
check('R07f4 ...and the new host may hand it back', (int) ($placeRow($placeB)['hostUserID'] ?? 0) === HOST2);
q('UPDATE `tblSmallGroupPlaces` SET `placeMapAgreed` = 1 WHERE `placeID` = ?', 'i', [$placeB]);
$exportOf = static function (string $jar): array {
    $answer = req($jar, 'GET', '/account/data-export');
    $json   = json_decode($answer['body'], true);

    return is_array($json) === true ? $json : ['_status' => $answer['status']];
};
$findHome = static function (array $export, string $line1): bool {
    foreach ((array) ($export['smallGroupListedHomesHosted'] ?? ($export['data']['smallGroupListedHomesHosted'] ?? [])) as $row) {
        if (($row['addressLine1'] ?? '') === $line1) {
            return true;
        }
    }

    return false;
};
$exportHost2 = $exportOf($jarHost2);
$exportLeader = $exportOf($jarHost);
check('R07b a host\'s own data export lists THEIR home as the group holds it; the leader who only typed it in is not given it as theirs',
    $findHome($exportHost2, '2 Beta Road') === true && $findHome($exportLeader, '2 Beta Road') === false && $findHome($exportLeader, '1 Alpha Road') === true,
    json_encode(['host2 keys' => array_keys($exportHost2)]));
// Check round 1 of #809 (C4): the USUAL place is the named HOST's in their
//    export, matched on `hostUserID` — never on who typed it in. The second
//    host is named and the leader is recorded as having typed it, so an
//    export matching the typist would give it to the wrong person.
$usualBefore = one('SELECT `addressLine1`, `hostUserID`, `placeEnteredByID` FROM `tblSmallGroups` WHERE `groupID` = ?', 'i', [SGROUP]) ?? [];
q("UPDATE `tblSmallGroups` SET `addressLine1` = '9 Usual Close', `hostUserID` = ?, `placeEnteredByID` = ? WHERE `groupID` = ?", 'iii', [HOST2, HOSTP, SGROUP]);
$findUsual = static function (array $export, string $line1): bool {
    foreach ((array) ($export['smallGroupHomeHosted'] ?? ($export['data']['smallGroupHomeHosted'] ?? [])) as $row) {
        if (($row['addressLine1'] ?? '') === $line1) {
            return true;
        }
    }

    return false;
};
check('R07g the usual place is in its NAMED HOST\'s export, and NOT in the export of the leader who only typed it in',
    $findUsual($exportOf($jarHost2), '9 Usual Close') === true && $findUsual($exportOf($jarHost), '9 Usual Close') === false);
q('UPDATE `tblSmallGroups` SET `addressLine1` = ?, `hostUserID` = ?, `placeEnteredByID` = ? WHERE `groupID` = ?', 'siii',
    [$usualBefore['addressLine1'] ?? null, $usualBefore['hostUserID'] ?? null, $usualBefore['placeEnteredByID'] ?? null, SGROUP]);
$saveHome($jarHost, $homeForm($jarHost, $placeA, ['placeMapAgreed' => '1']));
q('UPDATE `tblUsers` SET `isActive` = 0 WHERE `userID` = ?', 'i', [HOST2]);
$g = $groupPage($jarV);
check('R08 the second host\'s account is switched off: THEIR home\'s map goes, the first host\'s stays',
    $placeMap($g, $placeB) === false && $placeMap($g, $placeA) === true && str_contains($g['body'], '2 Beta Road') === true);
q('UPDATE `tblUsers` SET `isActive` = 1 WHERE `userID` = ?', 'i', [HOST2]);
q("UPDATE `tblSmallGroupMembers` SET `status` = 'ended' WHERE `groupID` = ? AND `userID` = ?", 'ii', [SGROUP, HOST2]);
$g = $groupPage($jarV);
check('R09 the second host LEAVES THE GROUP: their home\'s map goes, the first host\'s stays', $placeMap($g, $placeB) === false && $placeMap($g, $placeA) === true);
q("UPDATE `tblSmallGroupMembers` SET `status` = 'active' WHERE `groupID` = ? AND `userID` = ?", 'ii', [SGROUP, HOST2]);
setChoice(VIEWER, 'memberMap', 'off');
$g = $groupPage($jarV);
check('R10 the viewer switched maps off: neither home\'s map, no tile host, both written addresses shown',
    $placeMap($g, $placeA) === false && $placeMap($g, $placeB) === false && $tileRule($g) === false
    && str_contains($g['body'], '1 Alpha Road') === true && str_contains($g['body'], '2 Beta Road') === true);
setChoice(VIEWER, 'memberMap', null);
$saveHome($jarHost, $homeForm($jarHost, $placeB, ['addressLine1' => '3 Gamma Road']));
$rowB = $placeRow($placeB);
check('R11 a leader CHANGES the second host\'s address: still THEIR home, but their agreement is gone (it was about another address)',
    (int) ($rowB['hostUserID'] ?? 0) === HOST2 && (int) ($rowB['placeMapAgreed'] ?? -1) === 0 && (int) ($placeRow($placeA)['placeMapAgreed'] ?? -1) === 1, json_encode($rowB));
q("UPDATE `tblSmallGroupPlaces` SET `addressLine1` = '2 Beta Road', `placeMapAgreed` = 1 WHERE `placeID` = ?", 'i', [$placeB]);

// D-125: a plain member.
$fM = $homeForm($jarMember);
check('R12 D-125: a plain member may open "add a home", is told it is THEIR home, and gets no host chooser',
    $fM['_status'] === 200 && array_key_exists('hostUserID', $fM) === false && str_contains((string) $fM['_html'], 'Yours: you are its host') === true);
$saveHome($jarMember, $homeForm($jarMember, 0, [
    'hostUserID' => (string) HOST2, 'placeLabel' => 'Member home', 'addressLine1' => '7 Member Way', 'city' => 'Cambridge', 'countryCode' => 'GB',
    'latitude' => '52.50', 'longitude' => '0.30',
]));
$placeM = $placeBy('Member home');
check('R13 ...and a member who posts SOMEBODY ELSE as the host still adds only their OWN home', $placeM > 0 && (int) ($placeRow($placeM)['hostUserID'] ?? 0) === MEMBERP,
    json_encode($placeRow($placeM)));
check('R14 a member may NOT open somebody else\'s home', req($jarMember, 'GET', $placeUrl($placeB))['status'] === 403);
$before = $placeRow($placeB);
$saveHome($jarMember, ['csrf_token' => token(req($jarMember, 'GET', $placeUrl($placeM))['body']), 'action' => 'update', 'groupID' => (string) SGROUP,
    'placeID' => (string) $placeB, 'addressLine1' => '66 Hijack Street', 'placeMapAsked' => '1', 'placeMapAgreed' => '1']);
$saveHome($jarMember, ['csrf_token' => token(req($jarMember, 'GET', $placeUrl($placeM))['body']), 'action' => 'remove', 'groupID' => (string) SGROUP,
    'placeID' => (string) $placeB]);
check('R15 ...nor change or remove it by posting directly', $placeRow($placeB) === $before);
check('R16 somebody who is NOT a member of the group may not add a home at all', req($jarV, 'GET', $placeUrl())['status'] === 403);

// ---- R-B. Each meeting's own place ---------------------------------------
$meetingUrl = '/small-groups/meeting?id=' . SGROUP;
$mForm = $formAt($jarHost, $meetingUrl, 'small-groups/meeting-save');
check('R17 premise: the meeting form offers the usual place and the homes', $mForm['_status'] === 200
    && str_contains((string) $mForm['_html'], '>Alpha home</option>') === true && str_contains((string) $mForm['_html'], '>Beta home</option>') === true);
$saveMeeting = static fn (array $change, string $url = ''): array => $postTo($jarHost, '/small-groups/meeting-save', $formAt($jarHost, $url !== '' ? $url : $meetingUrl, 'small-groups/meeting-save', $change));
$saveMeeting(['meetingDate' => '2026-11-02', 'placeID' => (string) $placeB]);
q('UPDATE `tblSmallGroupPlaces` SET `placeMapAgreed` = 0 WHERE `placeID` = ?', 'i', [$placeA]);
$saveMeeting(['meetingDate' => '2026-11-03', 'placeID' => (string) $placeA]);
$saveMeeting(['meetingDate' => '2026-11-04', 'placeID' => '']);
$meetAt = static fn (string $date): array => one('SELECT `meetingID`, `placeID` FROM `tblSmallGroupMeetings` WHERE `groupID` = ? AND `meetingDate` = ?', 'is', [SGROUP, $date]) ?? [];
$mB = $meetAt('2026-11-02');
$mA = $meetAt('2026-11-03');
$mU = $meetAt('2026-11-04');
check('R18 three meetings, each stored with its own place', (int) ($mB['placeID'] ?? 0) === $placeB && (int) ($mA['placeID'] ?? 0) === $placeA
    && array_key_exists('placeID', $mU) === true && $mU['placeID'] === null, json_encode([$mB, $mA, $mU]));
$pageB = req($jarHost, 'GET', $meetingUrl . '&meetingID=' . (int) ($mB['meetingID'] ?? 0));
check('R19 the meeting held in the second host\'s home (agreed) shows that home\'s address and its map', str_contains($pageB['body'], '2 Beta Road') === true
    && str_contains($pageB['body'], 'id="sgMeetingMap"') === true && $tileRule($pageB) === true && preg_match('#<option value="' . $placeB . '" selected>#', $pageB['body']) === 1);
$pageA = req($jarHost2, 'GET', $meetingUrl . '&meetingID=' . (int) ($mA['meetingID'] ?? 0));
check('R20 the meeting held in the first host\'s home (not agreed) shows that address and NO map', str_contains($pageA['body'], '1 Alpha Road') === true
    && str_contains($pageA['body'], 'id="sgMeetingMap"') === false && $tileRule($pageA) === false);
$gm = req($jarHost, 'GET', '/small-groups/group?id=' . SGROUP);
check('R21 the recent meetings name each meeting\'s own place', str_contains($gm['body'], 'Beta home</div>') === true
    && str_contains($gm['body'], 'Alpha home</div>') === true && str_contains($gm['body'], 'The usual place</div>') === true);
q("INSERT INTO `tblSmallGroups` (`groupID`, `siteID`, `groupName`, `groupSlug`, `isActive`) VALUES (?, ?, 'Selftest Other Group', 'selftest-other-group', 1)", 'ii', [SGROUP + 1, ORG_A]);
q("INSERT INTO `tblSmallGroupPlaces` (`siteID`, `groupID`, `placeLabel`, `addressLine1`) VALUES (?, ?, 'Elsewhere', '5 Elsewhere Road')", 'ii', [ORG_A, SGROUP + 1]);
$foreign = (int) (one('SELECT `placeID` FROM `tblSmallGroupPlaces` WHERE `groupID` = ?', 'i', [SGROUP + 1])['placeID'] ?? 0);
$bUrl = $meetingUrl . '&meetingID=' . (int) ($mB['meetingID'] ?? 0);
$saveMeeting(['placeID' => (string) $foreign], $bUrl);
check('R22 a posted home of ANOTHER group is refused: the meeting keeps its own place', (int) ($meetAt('2026-11-02')['placeID'] ?? 0) === $placeB);
$saveMeeting(['placeID' => null], $bUrl);
check('R23 a form that did not send the place leaves the meeting\'s place as it was (#677)', (int) ($meetAt('2026-11-02')['placeID'] ?? 0) === $placeB);
q('DELETE FROM `tblSmallGroups` WHERE `groupID` = ?', 'i', [SGROUP + 1]);

// =============================================================================
// S. #809, D-127: MEMBERS SEE WHERE THEIR GROUP MEETS NEXT
// =============================================================================
head('S. The next meeting, for the group\'s members');
$future1 = '2099-01-05';
$future2 = '2099-02-01';
$saveMeeting(['meetingDate' => $future2, 'placeID' => '']);
$saveMeeting(['meetingDate' => $future1, 'placeID' => (string) $placeB, 'topic' => 'Selftest planned topic']);
$planned = $meetAt($future1);
check('S00 premise: two planned meetings stored, the earlier one at the second host\'s home', (int) ($planned['placeID'] ?? 0) === $placeB);
$nextWords = (new DateTimeImmutable($future1, new DateTimeZone('UTC')))->format('l j F Y');
$gMember = $groupPage($jarMember);
$nextPanel = static function (array $page): string {
    $at = strpos($page['body'], 'portal-next-meeting');
    if ($at === false) {
        return '';
    }
    $end = strpos($page['body'], 'card-header">About', $at);

    return substr($page['body'], $at, ($end === false ? 4000 : $end - $at));
};
$panel = $nextPanel($gMember);
check('S01 a MEMBER sees the next meeting: the EARLIER planned date, its topic, and the second host\'s address',
    $panel !== '' && str_contains($panel, $nextWords) === true && str_contains($panel, 'Selftest planned topic') === true
    && str_contains($panel, '2 Beta Road') === true && str_contains($panel, (new DateTimeImmutable($future2))->format('l j F Y')) === false, mb_substr($panel, 0, 300));
check('S02 ...with its map, because THAT home\'s host agreed', str_contains($panel, 'id="sgNextMap"') === true && $tileRule($gMember) === true);
q('UPDATE `tblSmallGroupPlaces` SET `placeMapAgreed` = 0 WHERE `placeID` = ?', 'i', [$placeB]);
$panel = $nextPanel($groupPage($jarMember));
check('S03 the host withdraws: the member still sees where, as text, and no map', str_contains($panel, '2 Beta Road') === true && str_contains($panel, 'id="sgNextMap"') === false);
q('UPDATE `tblSmallGroupPlaces` SET `placeMapAgreed` = 1 WHERE `placeID` = ?', 'i', [$placeB]);
q("UPDATE `tblSmallGroups` SET `locationVisibility` = 'leaders' WHERE `groupID` = ?", 'i', [SGROUP]);
$gMember = $groupPage($jarMember);
$panel = $nextPanel($gMember);
check('S04 the group\'s setting hides addresses from members: the member sees the date but NOT where, and no map anywhere on the page',
    str_contains($panel, $nextWords) === true && str_contains($panel, 'portal-next-meeting-hidden') === true && str_contains($gMember['body'], '2 Beta Road') === false
    && str_contains($gMember['body'], 'sgNextMap') === false && $tileRule($gMember) === false);
q("UPDATE `tblSmallGroups` SET `locationVisibility` = 'site' WHERE `groupID` = ?", 'i', [SGROUP]);
$gV = $groupPage($jarV);
check('S05 somebody who is NOT in the group sees no next meeting at all, even where addresses are shown to the whole organisation',
    $gV['status'] === 200 && str_contains($gV['body'], 'portal-next-meeting') === false && str_contains($gV['body'], $nextWords) === false);
// Check round 1 of #809 (C1): a next meeting at the USUAL place shows a map
//    only on the usual place's own rule — a named host who has not agreed
//    means NO map, though the place has a pin. Planned EARLIER than the other
//    two so it is the one the panel shows; removed again before S08 counts.
$usualSaved = one('SELECT `addressLine1`, `latitude`, `longitude`, `hostUserID`, `placeMapAgreed`, `notAHome` FROM `tblSmallGroups` WHERE `groupID` = ?', 'i', [SGROUP]) ?? [];
q("UPDATE `tblSmallGroups` SET `addressLine1` = '12 Host Lane', `latitude` = 52.21, `longitude` = 0.13, `hostUserID` = ?, `placeMapAgreed` = 0, `notAHome` = 0 WHERE `groupID` = ?",
    'ii', [HOSTP, SGROUP]);
$saveMeeting(['meetingDate' => '2098-12-01', 'placeID' => '']);
$panel = $nextPanel($groupPage($jarMember));
check('S05c the next meeting is at the USUAL place, whose host has NOT agreed: the member sees its address and NO map (a pin alone is not an agreement)',
    str_contains($panel, '12 Host Lane') === true && str_contains($panel, 'id="sgNextMap"') === false, mb_substr($panel, 0, 300));
q('UPDATE `tblSmallGroups` SET `placeMapAgreed` = 1 WHERE `groupID` = ?', 'i', [SGROUP]);
$panel = $nextPanel($groupPage($jarMember));
check('S05d control: the usual place\'s host agrees, and the next meeting\'s map IS drawn', str_contains($panel, 'id="sgNextMap"') === true);
q("DELETE FROM `tblSmallGroupMeetings` WHERE `groupID` = ? AND `meetingDate` = '2098-12-01'", 'i', [SGROUP]);
q('UPDATE `tblSmallGroups` SET `addressLine1` = ?, `latitude` = ?, `longitude` = ?, `hostUserID` = ?, `placeMapAgreed` = ?, `notAHome` = ? WHERE `groupID` = ?',
    'sddiiii', [$usualSaved['addressLine1'] ?? null, $usualSaved['latitude'] ?? null, $usualSaved['longitude'] ?? null, $usualSaved['hostUserID'] ?? null,
    (int) ($usualSaved['placeMapAgreed'] ?? 0), (int) ($usualSaved['notAHome'] ?? 0), SGROUP]);
q("INSERT INTO `tblAttendanceServiceTypes` (`siteID`, `typeName`, `typeSlug`) VALUES (?, 'Selftest Groups', 'selftest-groups')", 'i', [ORG_A]);
$serviceType = (int) (one("SELECT `serviceTypeID` FROM `tblAttendanceServiceTypes` WHERE `siteID` = ? AND `typeSlug` = 'selftest-groups'", 'i', [ORG_A])['serviceTypeID'] ?? 0);
q('UPDATE `tblSmallGroups` SET `serviceTypeID` = ? WHERE `groupID` = ?', 'ii', [$serviceType, SGROUP]);
$saveMeeting(['meetingDate' => '2026-11-04', 'placeID' => '', 'pushAttendance' => '1'], $meetingUrl . '&meetingID=' . (int) ($mU['meetingID'] ?? 0));
$heldPushed = one('SELECT `attendanceSessionID` FROM `tblSmallGroupMeetings` WHERE `meetingID` = ?', 'i', [(int) ($mU['meetingID'] ?? 0)]);
check('S05b premise: with an attendance service type, a HELD meeting\'s headcount IS pushed (so S06 can tell)', $serviceType > 0
    && is_array($heldPushed) === true && $heldPushed['attendanceSessionID'] !== null, json_encode($heldPushed));
$saveMeeting(['meetingDate' => $future1, 'placeID' => (string) $placeB, 'present[]' => (string) MEMBERP, 'pushAttendance' => '1'], $meetingUrl . '&meetingID=' . (int) ($planned['meetingID'] ?? 0));
$marks = (int) (one('SELECT COUNT(*) AS `n` FROM `tblSmallGroupMeetingAttendance` WHERE `meetingID` = ?', 'i', [(int) ($planned['meetingID'] ?? 0)])['n'] ?? -1);
$pushed = one('SELECT `attendanceSessionID` FROM `tblSmallGroupMeetings` WHERE `meetingID` = ?', 'i', [(int) ($planned['meetingID'] ?? 0)]);
check('S06 a PLANNED meeting takes no roll and pushes nothing to Attendance, whatever is posted', $marks === 0
    && is_array($pushed) === true && array_key_exists('attendanceSessionID', $pushed) === true && $pushed['attendanceSessionID'] === null,
    'marks ' . $marks . ' ' . json_encode($pushed));
$report = req($jarHost, 'GET', '/small-groups/report?id=' . SGROUP . '&from=2026-01-01&to=2099-12-31');
check('S07 the attendance report counts held meetings only: the planned one is not in it, the held one is', $report['status'] === 200
    && str_contains($report['body'], 'Mon, 5 Jan 2099') === false && str_contains($report['body'], 'Mon, 2 Nov 2026') === true);
$gm = req($jarHost, 'GET', '/small-groups/group?id=' . SGROUP);
check('S08 the leaders\' recent meetings mark the planned ones', substr_count($gm['body'], '>planned</span>') === 2);

// Removing a home (D-126): a manager removes the second host's.
$saveHome($jarHost, ['action' => 'remove', 'groupID' => (string) SGROUP, 'placeID' => (string) $placeB,
    'csrf_token' => token(req($jarHost, 'GET', $placeUrl($placeB))['body'])]);
$rowB = $placeRow($placeB);
$g  = $groupPage($jarV);
$gm = req($jarHost, 'GET', '/small-groups/group?id=' . SGROUP);
check('S09 D-126: removing a home deletes its address, host and agreement at once, and takes it off the list',
    (int) ($rowB['isActive'] ?? -1) === 0 && $rowB['addressLine1'] === null && $rowB['latitude'] === null && $rowB['hostUserID'] === null
    && (int) $rowB['placeMapAgreed'] === 0 && str_contains($g['body'], '2 Beta Road') === false, json_encode($rowB));
check('S10 ...meetings held or planned there say "a place no longer recorded", never "the usual place"',
    str_contains($gm['body'], 'A place no longer recorded</div>') === true && str_contains($nextPanel($groupPage($jarMember)), 'a place no longer recorded') === true);

// =============================================================================
// N. #809, D-128: A PLACE MARKED "NOT ANYBODY'S HOME" (A CHURCH HALL)
//    May show a map with no host, subject only to the installation's, the
//    organisation's and the viewer's own map settings. Never with a host;
//    a changed address clears the mark; only whoever may set the place marks it.
// =============================================================================
head('N. A place marked "not anybody\'s home"');
$hallFields = ['addressLine1' => '1 Church Street', 'city' => 'Cambridge', 'postcode' => 'CB2 2BB', 'countryCode' => 'GB',
               'latitude' => '52.20', 'longitude' => '0.11', 'hostUserID' => ''];
$fHall = $groupForm($jarAA);
check('N00 premise: the administrator\'s form draws the "not anybody\'s home" box, with its marker and its plain words',
    ($fHall['notAHomeAsked'] ?? '') === '1' && str_contains((string) $fHall['_html'], "This is not anybody's home (for example, a church hall). A map of it may be shown to whoever can see its address.") === true);
check('N00b D-131: the tick\'s own words WARN that ticking it for somebody\'s home maps it without them agreeing — on the RENDERED usual-place form and the RENDERED listed-home form',
    str_contains((string) $fHall['_html'], "Ticking this for somebody's home shows that home on a map without them agreeing.") === true
    && str_contains((string) $homeForm($jarAA)['_html'], "Ticking this for somebody's home shows that home on a map without them agreeing.") === true
    && str_contains((string) $homeForm($jarHost)['_html'], "Ticking this for somebody's home shows that home on a map without them agreeing.") === true);
$saveGroup($jarAA, $groupForm($jarAA, $hallFields + ['notAHome' => '1']));
$r = one('SELECT `hostUserID`, `notAHome` FROM `tblSmallGroups` WHERE `groupID` = ?', 'i', [SGROUP]) ?? [];
$g = $groupPage($jarV);
check('N01 an administrator marks the usual place as a hall, with no host: stored marked, and its map IS drawn with no host anywhere',
    $r['hostUserID'] === null && (int) ($r['notAHome'] ?? -1) === 1 && $sgMap($g) === true && $tileRule($g) === true, json_encode($r) . ' ' . imgSrc($g));
setChoice(VIEWER, 'memberMap', 'off');
$g = $groupPage($jarV);
check('N02 the VIEWER switched maps off: the hall\'s map is hidden, its address still shown', $sgMap($g) === false && $tileRule($g) === false
    && str_contains($g['body'], '1 Church Street') === true);
setChoice(VIEWER, 'memberMap', null);
setAt(K_MAP, ORG_A, 'no');
$g = $groupPage($jarV);
check('N03 the ORGANISATION says no: the hall\'s map is hidden', $sgMap($g) === false && $tileRule($g) === false);
setAt(K_MAP, ORG_A, null);
setAt(K_MAP, null, 'no');
$g = $groupPage($jarV);
check('N04 the INSTALLATION says no: the hall\'s map is hidden', $sgMap($g) === false && $tileRule($g) === false);
setAt(K_MAP, null, 'off');
$g = $groupPage($jarV);
check('N05 control: the hall\'s map is back', $sgMap($g) === true);
$saveGroup($jarAA, $groupForm($jarAA, ['notAHome' => null]));
$g = $groupPage($jarV);
check('N06 UNMARKED, with no host: no map (nobody has said it is not a home, and nobody has agreed)',
    (int) (one('SELECT `notAHome` FROM `tblSmallGroups` WHERE `groupID` = ?', 'i', [SGROUP])['notAHome'] ?? -1) === 0 && $sgMap($g) === false && $tileRule($g) === false);
$saveGroup($jarAA, $groupForm($jarAA, ['notAHome' => '1']));
$fMarked = $groupForm($jarAA, ['addressLine1' => '9 Moved Street']);
check('N07 premise: the marked place\'s form draws the box TICKED, so an address change is sent with the tick on', ($fMarked['notAHome'] ?? '') === '1');
$saveGroup($jarAA, $fMarked);
$r = one('SELECT `addressLine1`, `notAHome` FROM `tblSmallGroups` WHERE `groupID` = ?', 'i', [SGROUP]) ?? [];
$g = $groupPage($jarV);
check('N08 CHANGING THE ADDRESS CLEARS THE MARK, even with the box still ticked, and the map goes',
    ($r['addressLine1'] ?? '') === '9 Moved Street' && (int) ($r['notAHome'] ?? -1) === 0 && $sgMap($g) === false, json_encode($r));
$saveGroup($jarAA, $groupForm($jarAA, ['notAHome' => '1']));
check('N09 ...and ticking it again on a later save marks it again', (int) (one('SELECT `notAHome` FROM `tblSmallGroups` WHERE `groupID` = ?', 'i', [SGROUP])['notAHome'] ?? -1) === 1);
$saveGroup($jarAA, $groupForm($jarAA, ['hostUserID' => (string) HOSTP, 'notAHome' => '1', 'groupName' => 'Selftest Both At Once']));
$r = one('SELECT `groupName`, `hostUserID`, `notAHome` FROM `tblSmallGroups` WHERE `groupID` = ?', 'i', [SGROUP]) ?? [];
check('N10 naming a host AND ticking "not anybody\'s home" is refused, and NOTHING is saved',
    $r['hostUserID'] === null && (int) ($r['notAHome'] ?? -1) === 1 && ($r['groupName'] ?? '') !== 'Selftest Both At Once', json_encode($r));
$saveGroup($jarAA, $groupForm($jarAA, ['hostUserID' => (string) HOSTP, 'notAHome' => null]));
$r = one('SELECT `hostUserID`, `notAHome` FROM `tblSmallGroups` WHERE `groupID` = ?', 'i', [SGROUP]) ?? [];
check('N11 naming a host (and unticking) makes it somebody\'s home: unmarked, and needing that host\'s agreement',
    (int) ($r['hostUserID'] ?? 0) === HOSTP && (int) ($r['notAHome'] ?? -1) === 0 && $sgMap($groupPage($jarV)) === false, json_encode($r));
q('UPDATE `tblSmallGroups` SET `hostUserID` = ?, `notAHome` = 1, `placeMapAgreed` = 0 WHERE `groupID` = ?', 'ii', [HOSTP, SGROUP]);
check('N12 a row holding BOTH a host and the mark (written by hand) is read as a HOME: no map without that host\'s agreement',
    $sgMap($groupPage($jarV)) === false);
q('UPDATE `tblSmallGroups` SET `hostUserID` = NULL, `notAHome` = 0 WHERE `groupID` = ?', 'i', [SGROUP]);

// A listed home marked as a hall, and who may mark.
$saveHome($jarHost, $homeForm($jarHost, 0, [
    'hostUserID' => '', 'notAHome' => '1', 'placeLabel' => 'Parish hall', 'addressLine1' => '4 Hall Lane', 'city' => 'Cambridge',
    'countryCode' => 'GB', 'latitude' => '52.60', 'longitude' => '0.35',
]));
$placeH = $placeBy('Parish hall');
$g = $groupPage($jarV);
check('N13 a LEADER adds a listed place marked as a hall, with no host: its map is drawn', $placeH > 0
    && (int) ($placeRow($placeH)['notAHome'] ?? -1) === 1 && $placeRow($placeH)['hostUserID'] === null && $placeMap($g, $placeH) === true);
setChoice(VIEWER, 'memberMap', 'off');
check('N14 ...and the viewer\'s own switch still hides it', $placeMap($groupPage($jarV), $placeH) === false);
setChoice(VIEWER, 'memberMap', null);
$fOwn = $homeForm($jarMember, $placeM);
check('N15 a plain member\'s form for their OWN home offers no "not anybody\'s home" box', $fOwn['_status'] === 200
    && array_key_exists('notAHomeAsked', $fOwn) === false && str_contains((string) $fOwn['_html'], "not anybody's home (for example") === false);
$saveHome($jarMember, $homeForm($jarMember, $placeM, ['notAHomeAsked' => '1', 'notAHome' => '1']));
$rowM = $placeRow($placeM);
check('N16 ...and a forged mark posted for it is ignored: still their home, still unmarked', (int) ($rowM['hostUserID'] ?? 0) === MEMBERP
    && (int) ($rowM['notAHome'] ?? -1) === 0, json_encode($rowM));
$hallBefore = $placeRow($placeH);
$saveHome($jarMember, ['csrf_token' => token(req($jarMember, 'GET', $placeUrl($placeM))['body']), 'action' => 'update', 'groupID' => (string) SGROUP,
    'placeID' => (string) $placeH, 'addressLine1' => '4 Hall Lane', 'notAHomeAsked' => '1']);
$fUsual = req($jarMember, 'GET', $manageUrl);
check('N17 a member who is not a leader cannot unmark (or otherwise change) a place that is not theirs, nor reach the group\'s own form',
    $placeRow($placeH) === $hallBefore && $fUsual['status'] === 403);

q('UPDATE `tblSmallGroupMeetings` SET `attendanceSessionID` = NULL WHERE `groupID` = ?', 'i', [SGROUP]);
q('DELETE FROM `tblAttendanceSessions` WHERE `siteID` = ? AND `serviceTypeID` = ?', 'ii', [ORG_A, $serviceType]);
q('DELETE FROM `tblSmallGroups` WHERE `groupID` = ?', 'i', [SGROUP]);
q('DELETE FROM `tblAttendanceServiceTypes` WHERE `serviceTypeID` = ?', 'i', [$serviceType]);
if ($sgEnabledBefore === null) {
    q("DELETE FROM `tblSettings` WHERE `settingKey` = 'small-groups.enabled' AND `siteID` IS NULL");
} else {
    q('UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingID` = ?', 'si', [$sgEnabledBefore['settingValue'], (int) $sgEnabledBefore['settingID']]);
}

$errors = (string) @file_get_contents($child . $sep . 'php-errors.log');
check('Z01 the server logged no PHP error or warning during the run', preg_match('/PHP (Fatal|Warning|Parse|Deprecated)/', $errors) !== 1, mb_substr($errors, 0, 600));

cleanup();
echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
