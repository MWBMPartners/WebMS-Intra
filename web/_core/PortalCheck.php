<?php
// Path: web/_core/PortalCheck.php
/**
 * -----------------------------------------------------------------------------
 * Check this portal — the consistency checks, run against the live database 🔎
 * -----------------------------------------------------------------------------
 * Answers "is this installation set up consistently?" for an administrator, in
 * plain English. It REPORTS and never fixes.
 *
 * WHY THIS EXISTS (#477)
 *   Almost every fault the September 2026 audit found was invisible from inside
 *   the product. The notification preferences page could not be opened for
 *   months; six help guides existed that nobody could reach; six menu links led
 *   to "page not found"; every fresh install carried 479 duplicate settings
 *   rows and reported itself as version 0.1.0. **Not one produced an error, a
 *   warning or a log entry.** They were found by a person reading the code
 *   against what it claims to do.
 *
 *   The checks that would have caught most of them already exist in
 *   `tools/audit-checks/`. Those read the FILES, on a developer's machine. They
 *   have never run against a real installation with real data, which is where
 *   several of these only become visible — a route row, a settings row and a
 *   switch all live in the database, not in the repository.
 *
 * HOW THIS DIFFERS FROM THE HEALTH PAGE, because the two are easily confused.
 *   `/admin/maintenance/health` asks **is the server well** — database
 *   reachable, disk space, backups fresh, errors, mail. This asks **is the
 *   installation consistent with itself**. A portal can be perfectly healthy
 *   and still have a menu link going nowhere. Each page links to the other.
 *
 * WHAT IT CANNOT DO, said plainly, because an overstated guarantee is worse
 * than none
 *   * **A clean result does not mean the portal is correct.** These are the
 *     faults somebody already thought of. The September audit found its faults
 *     by reading, and no list of checks replaces that.
 *   * **It cannot tell whether a page WORKS**, only whether the file it points
 *     at exists. A page that exists and throws looks fine here.
 *   * **It knows about one scheduled job's runs and no others.** Only the mail
 *     queue records that it ran (`tblMailRouteState`). Every other job could
 *     have been silent for months and nothing here would know — that is #775,
 *     and `scheduledJobs()` says so rather than implying coverage.
 *   * It reads this installation only. It says nothing about other
 *     organisations' data beyond the rows this organisation can see.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/477
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;
use Throwable;

final class PortalCheck
{
    /** A fault: something is wrong now and somebody should act. */
    public const PROBLEM = 'problem';
    /** Worth knowing, but the portal still works. */
    public const WARNING = 'warning';
    /** A fact, reported so the page is not silent about it. */
    public const NOTE = 'note';

    /**
     * How many examples a finding lists before it stops.
     *
     * A finding naming 400 rows is a finding nobody reads. The COUNT is always
     * exact; the examples are a sample, and the wording says which is which.
     */
    public const EXAMPLES = 8;

    /**
     * Switch names that are not apps with a page.
     *
     * Copied from `_core/templates/nav.php`, which skips exactly these before
     * drawing a menu entry. `auth` is the one that matters most here: it is a
     * CORE app, so `isEnabledForSite()` always answers true for it, and
     * `/auth` is not a page — so without this it was reported as broken on
     * every installation.
     */
    public const NOT_A_DESTINATION = ['site', 'auth', 'portal', 'features', 'api', 'email', 'i18n'];

    /**
     * Run every check.
     *
     * ⚠️ ONE CHECK FAILING MUST NEVER STOP THE OTHERS. A page whose whole
     *    report disappears because one query threw is worse than no page: the
     *    administrator sees a blank result and concludes all is well. Each
     *    check is wrapped, and a check that could not run SAYS SO as a finding
     *    of its own rather than being quietly absent.
     *
     * @return array<int, array{area: string, severity: string, what: string, why: string, todo: string, count: int, examples: array<int, string>}>
     */
    public static function run(mysqli $db, int $siteId): array
    {
        $findings = [];
        foreach (self::checks() as $area => $method) {
            try {
                foreach (self::$method($db, $siteId) as $finding) {
                    $findings[] = $finding + ['area' => $area, 'count' => 0, 'examples' => []];
                }
            } catch (Throwable $e) {
                // ⚠️ NEVER PUT THE FAULT'S OWN WORDS ON THE PAGE. A database
                //    message can quote the value that caused it, and this page
                //    is read by an administrator, not a developer. The area is
                //    enough to find it in the error log.
                $findings[] = [
                    'area'     => $area,
                    'severity' => self::WARNING,
                    'what'     => 'This check could not be completed.',
                    'why'      => 'Its result is unknown — treat it as unanswered rather than clear.',
                    'todo'     => 'Look in Admin, Errors for a recent entry, and try again.',
                    'count'    => 0,
                    'examples' => [],
                ];
            }
        }
        return $findings;
    }

    /** The checks, in the order an administrator should read them. */
    private static function checks(): array
    {
        return [
            'Version'          => 'versionAgrees',
            'Database'         => 'databaseVersion',
            'Settings'         => 'duplicateSettings',
            'Addresses'        => 'routesWithoutAFile',
            'Data endpoints'   => 'apiSwitchesAndHandlers',
            'Apps'             => 'appsWithoutAnEntryPage',
            'Help'             => 'helpGuidesNotLinked',
            'Scheduled jobs'   => 'scheduledJobs',
        ];
    }

    /**
     * The version the portal reports, against the file it comes from.
     *
     * 🚨 THE KEY COMES FROM `Maintenance`, AND READING IT FROM HERE WAS WRONG
     *    ON EVERY INSTALLATION. The first version of this check read
     *    `portal.version`. That is the OLD key: migration 187 DELETES it
     *    (`DELETE FROM tblSettings WHERE settingKey = 'portal.version'`), and
     *    the portal records its version under
     *    `Maintenance::VERSION_SETTING` — `portal.installed_version` — which
     *    is written only by `Maintenance::recordVersion()`.
     *
     *    So every correctly-upgraded portal was told, for ever, that it had
     *    not recorded its version, and the advice given with it could not
     *    work: pressing the Upgrade button writes the key this check was not
     *    reading. **And the PROBLEM branch could never fire**, because the
     *    recorded value was always empty — so the stale-version fault this
     *    check exists for (#466, a portal reporting itself as 0.1.0) was
     *    unreachable. A clause that cannot fire, in the check written to
     *    congratulate itself on finding one.
     *
     *    It passed the self-test only because the test wrote `portal.version`
     *    ITSELF — a test keeping its own copy of the rule, which this
     *    repository already records as proving nothing. **Ask the class.**
     */
    private static function versionAgrees(mysqli $db, int $siteId): array
    {
        $onDisk = (string) (require PORTAL_CORE . DIRECTORY_SEPARATOR . 'version.php');
        $key    = Maintenance::VERSION_SETTING;
        $stmt = $db->prepare(
            'SELECT `settingValue` FROM `tblSettings` '
            . 'WHERE `settingKey` = ? AND `siteID` IS NULL LIMIT 1'
        );
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $recorded = $row === null ? '' : trim((string) $row['settingValue']);

        if ($recorded === '') {
            return [[
                'severity' => self::WARNING,
                'what'     => 'This portal has not recorded which version it is running.',
                'why'      => 'Until it does, the upgrade page cannot tell whether a database '
                    . 'upgrade is owed, so one may be waiting without anybody being told.',
                'todo'     => 'Open Admin, Upgrade and press the button there.',
                'examples' => ['The files on disk are version ' . $onDisk . '.'],
            ]];
        }
        if ($recorded !== $onDisk) {
            return [[
                'severity' => self::PROBLEM,
                'what'     => 'The version this portal reports is not the version of its files.',
                'why'      => 'The files have been replaced but the upgrade step has not been run, '
                    . 'so any database change that came with them is still waiting. Features may '
                    . 'be half-present until it is.',
                'todo'     => 'Open Admin, Upgrade and press the button there.',
                'examples' => ['Recorded: ' . $recorded, 'Files on disk: ' . $onDisk],
            ]];
        }
        return [[
            'severity' => self::NOTE,
            'what'     => 'This portal is running version ' . $onDisk . ', and says so.',
            'why'      => 'The recorded version and the files agree, so no database upgrade is owed.',
            'todo'     => 'Nothing.',
            'examples' => [],
        ]];
    }

    /**
     * Whether the database server is one this portal still supports.
     *
     * `Portal\Core\DbServer` is the ONE place that decides this (#489); this
     * only puts its answer on the page. Do not add a second version test here.
     */
    private static function databaseVersion(mysqli $db, int $siteId): array
    {
        // ⚠️ `headline`, NOT `label`. classify() returns headline/detail/state
        //    (plus engine, family, version, raw, comment, untested); a first
        //    version of this read `label`, which is the mail probes' key, not
        //    this one. It would have printed an empty sentence on the page with
        //    no error anywhere — the shape this whole page exists to catch.
        $verdict = DbServer::classify($db->server_info);
        $severity = $verdict['state'] === 'ok' ? self::NOTE
            : ($verdict['state'] === 'crit' ? self::PROBLEM : self::WARNING);
        return [[
            'severity' => $severity,
            'what'     => (string) $verdict['headline'],
            'why'      => (string) $verdict['detail'],
            'todo'     => $verdict['state'] === 'ok'
                ? 'Nothing.'
                : 'Ask your hosting company which database versions they offer.',
            'examples' => [],
        ]];
    }

    /**
     * Whether the guard against duplicate settings is still in place — and,
     * only if it is not, whether any duplicates have appeared.
     *
     * ⚠️ ASKING "ARE THERE DUPLICATES?" ON ITS OWN IS A QUESTION THAT CAN NO
     *    LONGER ANSWER YES, and the first version of this check did exactly
     *    that. Migration 187 put a unique key on
     *    (`settingKey`, `siteScope`) — where `siteScope` is a worked-out
     *    column holding the site number, or -1 for an installation-wide row,
     *    because MySQL never treats one empty value as equal to another
     *    (#487). With that key present the database REFUSES a second row, so
     *    the scan is a clause that cannot fire. The self-test found this by
     *    trying to plant a duplicate and being refused by the database.
     *
     *    The live question is therefore the other one: **is the key still
     *    there?** It would not be in a database restored from a snapshot taken
     *    before migration 187, nor in one somebody has edited by hand — and
     *    the moment it is absent, 479-duplicate-rows (#466) is possible again
     *    with nothing to stop it.
     *
     *    So the scan is kept as a BACKSTOP and only reported when the key is
     *    missing, because that is the only state in which it can find
     *    anything. Reporting "no duplicates found" while the key is doing the
     *    work would be the false reassurance this page exists to end.
     */
    private static function duplicateSettings(mysqli $db, int $siteId): array
    {
        $result = $db->query(
            "SHOW INDEX FROM `tblSettings` WHERE `Key_name` = 'uq_setting_key_scope'"
        );
        $keyIsThere = $result->num_rows > 0;
        $result->free();

        if ($keyIsThere === true) {
            return [[
                'severity' => self::NOTE,
                'what'     => 'The database itself refuses a setting stored twice.',
                'why'      => 'Every fresh install once carried 479 duplicate rows, and which copy '
                    . 'the portal read was decided by nothing. A rule in the database now makes a '
                    . 'second copy impossible rather than merely unlikely.',
                'todo'     => 'Nothing.',
                'examples' => [],
            ]];
        }

        // Only reachable on a database that predates migration 187 or has been
        // edited: here the scan can genuinely find something.
        $result = $db->query(
            'SELECT `settingKey`, COUNT(*) AS `howMany` FROM `tblSettings` '
            . 'GROUP BY `siteID`, `settingKey` HAVING COUNT(*) > 1 '
            . 'ORDER BY `howMany` DESC, `settingKey` ASC'
        );
        $examples = [];
        $total = 0;
        while (($row = $result->fetch_assoc()) !== null) {
            $total += 1;
            if (count($examples) < self::EXAMPLES) {
                $examples[] = $row['settingKey'] . ' (' . $row['howMany'] . ' rows)';
            }
        }
        $result->free();

        $findings = [[
            'severity' => self::PROBLEM,
            'what'     => 'The rule that stops a setting being stored twice is missing.',
            'why'      => 'Without it, the same setting can be saved twice and the portal will '
                . 'read whichever copy the database happens to return first — so a setting can '
                . 'appear to change on its own. This is normally a database restored from a very '
                . 'old backup, or one edited by hand.',
            'todo'     => 'Open Admin, Upgrade and press the button there, which puts the rule '
                . 'back. Report it if the page says there is nothing to upgrade.',
            'examples' => [],
        ]];
        if ($total > 0) {
            $findings[] = [
                'severity' => self::PROBLEM,
                'what'     => 'Some settings are already stored more than once.',
                'why'      => 'Which copy the portal uses is not decided by anything. Editing one '
                    . 'of these may change the copy nobody reads.',
                'todo'     => 'Report this with the list below. Putting it right needs a database '
                    . 'change, and this page deliberately does not make changes.',
                'count'    => $total,
                'examples' => $examples,
            ];
        }
        return $findings;
    }

    /**
     * Addresses the portal answers that point at a file which is not there.
     *
     * Six menu links led to "page not found" (#468) and nothing said so. The
     * router looks under `_apps` first and falls back to the web root, so both
     * are checked — looking in one place only would report working addresses
     * as broken.
     */
    private static function routesWithoutAFile(mysqli $db, int $siteId): array
    {
        $result = $db->query('SELECT `routeKey`, `targetFile` FROM `tblRoutes` ORDER BY `routeKey`');
        $examples = [];
        $total = 0;
        while (($row = $result->fetch_assoc()) !== null) {
            $target = (string) $row['targetFile'];
            // The router resolves under _apps first, then the web root.
            $underApps = PORTAL_APPS . DIRECTORY_SEPARATOR . $target;
            $underWeb  = PORTAL_WEBROOT . DIRECTORY_SEPARATOR . $target;
            if (is_file($underApps) === true || is_file($underWeb) === true) {
                continue;
            }
            $total += 1;
            if (count($examples) < self::EXAMPLES) {
                $examples[] = '/' . $row['routeKey'];
            }
        }
        $result->free();
        if ($total === 0) {
            return [];
        }
        return [[
            'severity' => self::PROBLEM,
            'what'     => 'Some addresses this portal answers have no page behind them.',
            // ⚠️ "NOTHING IS WRITTEN TO THE ERROR LOG" WAS FALSE FOR EVERY
            //    FINDING THIS CHECK CAN PRODUCE, and it pointed the reader
            //    away from the one place that already has the answer. This
            //    check reports a row in `tblRoutes` whose target file is
            //    missing, and that is precisely the branch the router LOGS
            //    (`Router.php`: "Route target file not found", with the route
            //    key and the target file). The unlogged 404 is the other
            //    branch — no route row at all — which produces no finding
            //    here, because this iterates `tblRoutes`.
            'why'      => 'Anybody following one of these — from a menu, a link in an e-mail, or a '
                . 'bookmark — gets "page not found". The portal does record each one, so this is '
                . 'usually a file that did not finish uploading rather than a mystery.',
            'todo'     => 'Look in Admin, Errors for "Route target file not found": each entry names '
                . 'the address and the file it expected. If the file should be there, upload it '
                . 'again; if the address should be gone, report it.',
            'count'    => $total,
            'examples' => $examples,
        ]];
    }

    /**
     * Data endpoints switched on with nothing behind them, and the reverse.
     *
     * A handler under `_apps/{app}/api/{action}.php` answers at
     * `api/{app}/{action}` ONLY once `api.{app}.{action}.enabled` is seeded —
     * `ApiRouter` ignores `tblRoutes` entirely. So the two can disagree in both
     * directions, and each direction has its own consequence.
     */
    private static function apiSwitchesAndHandlers(mysqli $db, int $siteId): array
    {
        $result = $db->query(
            'SELECT DISTINCT `settingKey` FROM `tblSettings` '
            . "WHERE `settingKey` LIKE 'api.%.enabled' ORDER BY `settingKey`"
        );
        $switched = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $parts = explode('.', (string) $row['settingKey']);
            if (count($parts) === 4) {
                $switched[$parts[1] . '/' . $parts[2]] = true;
            }
        }
        $result->free();

        $handlers = [];
        foreach (glob(PORTAL_APPS . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'api'
            . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $action = basename($file, '.php');
            // A leading underscore is how a shared helper marks itself
            // unreachable, and the router's own pattern refuses it — so it is
            // not a missing endpoint and must not be reported as one.
            if (str_starts_with($action, '_') === true) {
                continue;
            }
            $app = basename(dirname($file, 2));
            $handlers[$app . '/' . $action] = true;
        }

        $findings = [];
        $orphanSwitches = array_diff_key($switched, $handlers);
        if (count($orphanSwitches) > 0) {
            $findings[] = [
                'severity' => self::WARNING,
                'what'     => 'Some data endpoints are switched on with nothing behind them.',
                'why'      => 'Anything calling one gets an error rather than an answer. This '
                    . 'usually means a feature was removed and its switch was left behind.',
                'todo'     => 'Report this with the list below.',
                'count'    => count($orphanSwitches),
                'examples' => array_slice(array_map(
                    static fn (string $k): string => 'api/' . $k,
                    array_keys($orphanSwitches)
                ), 0, self::EXAMPLES),
            ];
        }
        $unswitched = array_diff_key($handlers, $switched);
        if (count($unswitched) > 0) {
            $findings[] = [
                'severity' => self::NOTE,
                'what'     => 'Some data endpoints exist but are not switched on.',
                'why'      => 'They answer "forbidden" to everybody. That is often deliberate — a '
                    . 'feature not in use here — so this is reported rather than treated as a fault.',
                'todo'     => 'Nothing, unless you expected one of these to be working.',
                'count'    => count($unswitched),
                'examples' => array_slice(array_map(
                    static fn (string $k): string => 'api/' . $k,
                    array_keys($unswitched)
                ), 0, self::EXAMPLES),
            ];
        }
        return $findings;
    }

    /**
     * Apps switched on for this organisation whose own entry page is missing.
     *
     * 🚨 `landing` IS THE PAGE. `route` IS A PREFIX, AND READING IT REPORTED
     *    FOUR HEALTHY APPS AS BROKEN ON EVERY INSTALLATION. The portal's own
     *    two menu builders — `_core/templates/nav.php` and the dashboard —
     *    both use `landing` and both say why in their own comments: "'route'
     *    is only a prefix used to work out which app owns a page, and for a
     *    few apps that prefix is not a page at all (Expenses, Kids,
     *    Worship)." Expenses opens at `expenses/submit`, Kids at
     *    `kids/checkin`, Worship at `worship/plans`; `/expenses` is not a page.
     *
     *    So the only "needs attention" line a correct portal ever showed was
     *    noise — and a check that cries wolf gets switched off (#483), after
     *    which it catches nothing. The reason printed beside it was untrue as
     *    well: both menu builders call `Router::routeExists()` and skip an app
     *    with no page, so these could not "appear in the menu and lead
     *    nowhere".
     *
     *    `auth` is skipped for the same reason `nav.php` skips it: it is a
     *    core app that exists to hold settings, not a destination.
     */
    private static function appsWithoutAnEntryPage(mysqli $db, int $siteId): array
    {
        $dead = [];      // a link the menu DRAWS that goes nowhere
        $hidden = [];    // an app the menu hides because it has no address
        foreach (AppRegistry::all() as $slug => $meta) {
            // The same meta-settings the menu skips: they are switches, not
            // destinations. Taken from `nav.php` rather than invented, so the
            // two cannot drift.
            if (in_array($slug, self::NOT_A_DESTINATION, true) === true) {
                continue;
            }
            if (AppRegistry::isEnabledForSite($slug, $siteId) === false) {
                continue;
            }
            // `landing ?? route ?? slug`, exactly as `nav.php` and the
            // dashboard resolve it. Only 3 of 47 apps declare a `landing`, so
            // the fallback is the ordinary case — reading `landing` ALONE
            // skipped every other app and was how the broken-page branch came
            // to be unreachable.
            $landing = trim((string) ($meta['landing'] ?? $meta['route'] ?? $slug));
            if ($landing === '') {
                continue;
            }
            $stmt = $db->prepare('SELECT `targetFile` FROM `tblRoutes` WHERE `routeKey` = ? LIMIT 1');
            $stmt->bind_param('s', $landing);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $name = (string) ($meta['name'] ?? $slug);
            if ($row === null) {
                $hidden[] = $name . ' — /' . $landing . ' is not registered';
                continue;
            }
            $target = (string) $row['targetFile'];
            if (is_file(PORTAL_APPS . DIRECTORY_SEPARATOR . $target) === false
                && is_file(PORTAL_WEBROOT . DIRECTORY_SEPARATOR . $target) === false) {
                $dead[] = $name . ' — /' . $landing . ' has no page behind it';
            }
        }

        // ⚠️ TWO BRANCHES WITH OPPOSITE CONSEQUENCES, AND ONE MESSAGE FOR BOTH
        //    WAS UNTRUE FOR WHICHEVER IT WAS NOT WRITTEN ABOUT.
        //    `Router::routeExists()` reads only `routeKey` from `tblRoutes` —
        //    it never looks for the file. So:
        //      * no route row  → routeExists is false → the menu HIDES the app;
        //      * row, no file  → routeExists is TRUE  → the menu DRAWS the link
        //                        and clicking it gives a 404 the router logs.
        //    The first version said the menu hides it, which is false for the
        //    second branch — and that is the branch the self-test plants. The
        //    version before THAT said members click a dead link, which is false
        //    for the first. Each correction was true of the branch it was
        //    written about and wrong about the other, so they are now separate
        //    findings with their own advice.
        $findings = [];
        if (count($dead) > 0) {
            $findings[] = [
                'severity' => self::PROBLEM,
                'what'     => 'Some apps have a menu link that leads nowhere.',
                'why'      => 'The address is registered, so the menu draws the link, but the page '
                    . 'itself is not there — anybody clicking it gets "page not found". This is '
                    . 'nearly always a file that did not finish uploading.',
                'todo'     => 'Upload the missing file again. Admin, Errors names it under "Route '
                    . 'target file not found". Do NOT switch the app off: it is wanted, it is the '
                    . 'file that is missing.',
                'count'    => count($dead),
                'examples' => array_slice($dead, 0, self::EXAMPLES),
            ];
        }
        if (count($hidden) > 0) {
            $findings[] = [
                'severity' => self::PROBLEM,
                'what'     => 'Some apps are switched on for this organisation but have no address.',
                'why'      => 'The menu hides an app with no registered address rather than showing '
                    . 'a link that fails, so somebody who was told the feature is switched on '
                    . 'simply cannot find it, and nothing explains why.',
                'todo'     => 'Switch the app off in Admin, Apps if it is not wanted here, or report '
                    . 'this if it should be working — it usually means a database upgrade has not '
                    . 'been run.',
                'count'    => count($hidden),
                'examples' => array_slice($hidden, 0, self::EXAMPLES),
            ];
        }
        return $findings;
    }

    /**
     * Help guides that exist but are not linked from the Help Centre.
     *
     * Six guides existed that nobody could find (#467). A guide nobody can
     * reach is work already paid for and thrown away.
     */
    private static function helpGuidesNotLinked(mysqli $db, int $siteId): array
    {
        $helpDir = PORTAL_APPS . DIRECTORY_SEPARATOR . 'help';
        $index = $helpDir . DIRECTORY_SEPARATOR . 'index.php';
        if (is_file($index) === false) {
            return [];
        }
        $centre = (string) file_get_contents($index);
        $examples = [];
        $total = 0;
        foreach (glob($helpDir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $slug = basename($file, '.php');
            if ($slug === 'index') {
                continue;
            }
            // The Help Centre links a guide by its address, so the slug appears
            // in the page's source. This asks only whether the NAME is there —
            // see the class header for what that cannot tell you.
            // ⚠️ THE SECOND CLAUSE WAS DELETED, AND IT COULD ONLY EVER HIDE A
            //    FAULT. It also accepted a quoted slug ANYWHERE in the Help
            //    Centre's source — including inside a PHP comment. Planted: a
            //    guide correctly reported as unlinked disappeared from the
            //    report the moment a comment mentioning its name was added,
            //    while the guide was still unreachable.
            //
            //    And it rescued nothing. Of the guides shipped today, the
            //    number matched ONLY by that clause is ZERO — every one is
            //    linked as `help/<slug>`. So it bought no accuracy and could
            //    silence a real finding, which is the worst trade a check can
            //    make. A link is an address; look for the address.
            // 🚨 THE NEEDLE MUST BE ANCHORED, AND AN UNANCHORED ONE MISSED A
            //    GENUINELY UNREACHABLE GUIDE FOR 2 OF THE 19 SHIPPED TODAY.
            //    `str_contains($centre, 'help/admin')` is satisfied by
            //    `help/admin-first-steps`, and `help/calendar` by
            //    `help/calendar-imports`. Both pairs ship. Measured: delete the
            //    Help Centre's card for the main Calendar guide and this check
            //    said NOTHING, because the imports link still matched.
            //
            //    A slug ends where a slug character stops. Requiring the next
            //    character to be something other than a letter, digit or
            //    hyphen — a quote, a space, a `?`, the end of the file — is
            //    what makes `help/admin` stop matching `help/admin-first-steps`.
            if (preg_match('~help/' . preg_quote($slug, '~') . '(?![A-Za-z0-9-])~', $centre) === 1) {
                continue;
            }
            $total += 1;
            if (count($examples) < self::EXAMPLES) {
                $examples[] = '/help/' . $slug;
            }
        }
        if ($total === 0) {
            return [];
        }
        return [[
            'severity' => self::WARNING,
            'what'     => 'Some help guides are not linked from the Help Centre.',
            'why'      => 'They can only be reached by typing the address, so in practice nobody '
                . 'reads them. Six guides were in that state in September 2026.',
            'todo'     => 'Report this with the list below so they can be added to the Help Centre.',
            'count'    => $total,
            'examples' => $examples,
        ]];
    }

    /**
     * What is known about the scheduled jobs — which is very little, and this
     * says so rather than implying coverage.
     *
     * ⚠️ ONLY ONE JOB RECORDS THAT IT RAN. `MailQueue::jobIsRunning()` reads
     *    `tblMailRouteState`, and nothing equivalent exists for the others. A
     *    site can silently stop sending reminders, clearing old data or taking
     *    backups and nothing here would know. That is #775. **Reporting "all
     *    clear" for the jobs we cannot see would be exactly the false
     *    reassurance this page exists to end.**
     */
    private static function scheduledJobs(mysqli $db, int $siteId): array
    {
        $mailRunning = MailQueue::jobIsRunning($db);
        $findings = [];
        $findings[] = [
            'severity' => $mailRunning === true ? self::NOTE : self::WARNING,
            'what'     => $mailRunning === true
                ? 'The mail job has run in the last 15 minutes.'
                : 'The mail job has not run in the last 15 minutes.',
            'why'      => $mailRunning === true
                ? 'Messages that cannot be sent at once are being picked up.'
                : 'Anything waiting in the mail queue is not being sent. If this portal has never '
                    . 'had its scheduled jobs set up, that is the likeliest reason.',
            'todo'     => $mailRunning === true
                ? 'Nothing.'
                : 'Check with whoever set this portal up that the scheduled jobs are running.',
            'examples' => [],
        ];
        $findings[] = [
            'severity' => self::NOTE,
            'what'     => 'Nothing here can tell you whether the other scheduled jobs have run.',
            'why'      => 'Only the mail job records that it ran. Reminders, clearing out old '
                . 'personal data, and the nightly tidy-up leave no trace this page can read, so '
                . 'they could have been silent for months without anything showing it here.',
            'todo'     => 'Treat this as unknown rather than clear. Making every job record its '
                . 'own runs is tracked as issue #775.',
            'examples' => [],
        ];
        return $findings;
    }

    /** How many findings of each severity, for the summary line. */
    public static function tally(array $findings): array
    {
        $tally = [self::PROBLEM => 0, self::WARNING => 0, self::NOTE => 0];
        foreach ($findings as $finding) {
            $tally[$finding['severity']] = ($tally[$finding['severity']] ?? 0) + 1;
        }
        return $tally;
    }
}
