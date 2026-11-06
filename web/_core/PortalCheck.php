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
     * Each scheduled job, and the setting holding the token that lets it run.
     *
     * ⚠️ AN EMPTY TOKEN MEANS THE JOB ALWAYS ANSWERS 403, so it has never run
     *    and never will. Every one of these pages refuses when its stored token
     *    is empty — deliberately, so an endpoint is inert until somebody sets
     *    a real token. That is right, and it means **a job nobody gave a token
     *    is silently doing nothing**, which is knowable from the database and
     *    was reported by nothing until #477.
     *
     *    Ten jobs carry their own token so it can be rotated independently;
     *    five share `maintenance.cronToken`. The sentence beside each says what
     *    STOPS if it never runs, in terms of what happens to a person — not
     *    what happens to the code.
     */
    public const JOB_TOKENS = [
        'maintenance.cronToken' => 'Backups, the health check, the mail queue and the clearing-out '
            . 'of personal data past its retention period',
        'reminders.cron_token' => 'Event reminders and the morning summary',
        'user_reminders.cron_token' => 'Task and personal reminders',
        'feeds.cron_token' => 'Importing outside calendars',
        'assets.cron_token' => 'Asset reminders',
        'venues.cron_token' => 'Venue agreement reminders',
        'giving.cron_token' => 'Giving statements',
        'discipleship.cron_token' => 'The discipleship sweep',
        'push.cron_token' => 'The "We are live" push',
        'webhooks.cron_token' => 'Retrying payment webhooks that failed',
        'workflows.cron_token' => 'Chasing approvals that have timed out',
    ];

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
     * ⏰ Whether each scheduled job has actually run (#775).
     *
     * UNTIL #775 THIS COULD SAY ALMOST NOTHING. Only the mail queue recorded
     * that it ran, so a portal could silently stop sending reminders, stop
     * clearing out personal data past its retention period, or stop taking
     * backups, and this page could not tell. It said so rather than implying
     * coverage, which was right — and was no use to anybody.
     *
     * Every job now writes a one-row record through `ScheduledJobs`, and this
     * reads it.
     *
     * ⚠️ THE MAIL JOB HAS TWO RECORDS, FOR TWO DIFFERENT QUESTIONS, and this
     *    page reads only one of them on purpose. `MailQueue::jobIsRunning()`
     *    answers the QUEUE's operational question — "may I hand this message
     *    to the job instead of sending it now?" — on a fixed 15-minute window,
     *    and it is still the one place that answers it. This page asks the
     *    different question "has this job stopped?", of all fifteen jobs, by
     *    one rule. Reading both for a job that has a record would let one page
     *    give two answers about one job, which is worse than either.
     *
     * ⚠️ WITH ONE EXCEPTION, ADDED BECAUSE DROPPING IT LOST A REAL WARNING.
     *    `tblMailRouteState.jobLastRanAt` survives an upgrade WITH HISTORY,
     *    while `tblScheduledJobRun` is empty on upgrade day. So a portal whose
     *    mail job had genuinely stopped was downgraded from a warning with an
     *    action to a note saying "Nothing" — with the fact still in the
     *    database. Measured, old page and new, against the same rows.
     *
     *    The older reading is therefore used for the mail queue ALONE and ONLY
     *    while the new record has nothing to say. One job, one answer, always.
     *
     * ⚠️ A JOB WITH NO RECORD IS "NOTHING IS KNOWN YET", NEVER A WARNING
     *    (D-071). On the day these files land no job anywhere has written a
     *    record, so warning would give every correctly-configured portal a
     *    page of warnings about jobs that are running perfectly well, with no
     *    way to clear them but to wait. **A check that cries wolf gets
     *    switched off (#483).** The never-configured case still has a real
     *    answer, from the token check below, which is knowable from the
     *    database.
     */
    private static function scheduledJobs(mysqli $db, int $siteId): array
    {
        $findings = [];

        // 🚨 A JOB WITH NO TOKEN HAS NEVER RUN AND NEVER WILL. Every one of
        //    these pages refuses when its stored token is empty, so this is
        //    knowable from the database whether or not the job ever recorded
        //    anything — which is why this check is FIRST, and why D-071's
        //    "nothing is known yet" gives nothing away.
        $silent = [];
        foreach (self::JOB_TOKENS as $key => $whatStops) {
            $stmt = $db->prepare(
                'SELECT `settingValue` FROM `tblSettings` '
                . 'WHERE `settingKey` = ? AND `siteID` IS NULL LIMIT 1'
            );
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            // ⚠️ NEVER PRINT THE VALUE. It is a secret that lets anybody run
            //    the job. Only whether it is empty is ever said out loud.
            if ($row === null || trim((string) $row['settingValue']) === '') {
                $silent[] = $whatStops;
            }
        }
        if (count($silent) > 0) {
            $findings[] = [
                'severity' => self::PROBLEM,
                'what'     => 'Some scheduled jobs can never run, because no token has been set.',
                'why'      => 'Each of these refuses every request until somebody sets its token, '
                    . 'so it has never run and never will. Nothing anywhere says so — the job '
                    . 'simply does nothing. If the clearing-out of personal data is among them, '
                    . 'this portal is keeping records it told people it would delete.',
                'todo'     => 'Set a token for each in Admin, Settings, then give the address and '
                    . 'that token to whoever runs the scheduled tasks for this portal. The '
                    . 'installation guide has the addresses.',
                'count'    => count($silent),
                'examples' => array_slice($silent, 0, self::EXAMPLES),
            ];
        }

        // ── What each job's own record says ────────────────────────────────
        // ⚠️ A JOB WITH NO TOKEN IS LEFT OUT OF EVERYTHING BELOW, because the
        //    finding above already covers it and `state()` never looks at a
        //    token. Without this, one answer contradicted itself: the same job
        //    appeared under "can never run, because no token has been set" AND
        //    under "Each of these has a token set, so it CAN run". Reached
        //    whenever a token is cleared after the job has run.
        $noToken = [];
        foreach (ScheduledJobs::JOBS as $jobKey => $facts) {
            if (in_array($facts['whatStops'], $silent, true) === true) {
                $noToken[$jobKey] = true;
                continue;
            }
            // JOB_TOKENS is keyed by token and $silent holds its sentences, so
            // match a job to its token through the token itself as well.
            if (array_key_exists($facts['token'], self::JOB_TOKENS) === true
                && in_array(self::JOB_TOKENS[$facts['token']], $silent, true) === true) {
                $noToken[$jobKey] = true;
            }
        }

        $state     = ScheduledJobs::state($db);
        $overdue   = [];
        $diedPart  = [];
        $notKnown  = [];
        $refused   = [];
        $broke     = [];
        $unreadable = [];
        $running   = [];
        foreach ($state as $jobKey => $facts) {
            if (array_key_exists($jobKey, $noToken) === true) {
                continue;
            }
            $words = $facts['whatStops'];
            if ($facts['status'] === ScheduledJobs::RUNNING_NOW) {
                $running[] = $words;
                continue;
            }
            if ($facts['status'] === ScheduledJobs::OVERDUE) {
                $overdue[] = $words . ' — last ran '
                    . ScheduledJobs::howLongAgo((int) $facts['seconds']);
                continue;
            }
            if ($facts['status'] === ScheduledJobs::DIED_PART_WAY) {
                $diedPart[] = $words . ' — started '
                    . ScheduledJobs::howLongAgo((int) $facts['seconds'])
                    . ' and never finished';
                continue;
            }
            if ($facts['status'] === ScheduledJobs::COULD_NOT_READ) {
                $unreadable[] = $words;
                continue;
            }
            if ($facts['status'] === ScheduledJobs::NOT_KNOWN) {
                // ⚠️ THE MAIL QUEUE HAS A SECOND, OLDER RECORD, AND IT SURVIVES
                //    THE UPGRADE WITH REAL HISTORY. `tblScheduledJobRun` is
                //    empty on upgrade day (D-071, correctly) — but
                //    `tblMailRouteState.jobLastRanAt` is not, and the page used
                //    to WARN from it. Dropping that reading downgraded every
                //    portal whose mail job had genuinely stopped, on upgrade
                //    day, from a warning with an action to a note saying
                //    "Nothing" — while the database still held the fact.
                //    Measured against the same database, old page and new.
                //
                //    So for this ONE job, while the new record has nothing to
                //    say, the older one is asked. That does not reopen the
                //    "one page, two answers" problem the new reading was
                //    written to avoid: it applies ONLY when the new source has
                //    no answer at all, and stops the moment the job records
                //    once. D-071 decides what to say about a job with NO
                //    record; it does not say an existing record should be
                //    ignored.
                if ($jobKey === 'cron/mail-queue' && MailQueue::jobIsRunning($db) === false) {
                    $overdue[] = $words . ' — the mail job has not run in the last 15 minutes';
                    continue;
                }
                $notKnown[] = $words;
                continue;
            }
            if ($facts['outcome'] === ScheduledJobs::OUTCOME_FAILED) {
                $broke[] = $words;
                continue;
            }
            if ($facts['outcome'] === ScheduledJobs::OUTCOME_REFUSED) {
                $refused[] = $words;
            }
        }

        if (count($overdue) > 0) {
            $findings[] = [
                'severity' => self::PROBLEM,
                'what'     => 'Some scheduled jobs have not run for longer than they should have.',
                'why'      => 'Each of these has a token set, so it CAN run — it simply has not. '
                    . 'Whatever runs the scheduled tasks for this portal may have been switched '
                    . 'off, moved, or given the wrong address. If the clearing-out of personal '
                    . 'data is among them, this portal is keeping records it told people it '
                    . 'would delete.',
                'todo'     => 'Ask whoever runs the scheduled tasks for this portal to check that '
                    . 'each of these is still being called, and that it is not reporting an '
                    . 'error. The installation guide has the addresses.',
                'count'    => count($overdue),
                'examples' => array_slice($overdue, 0, self::EXAMPLES),
            ];
        }

        if (count($diedPart) > 0) {
            $findings[] = [
                'severity' => self::PROBLEM,
                'what'     => 'Some scheduled jobs started and never finished.',
                'why'      => 'These are being called, so whatever runs the scheduled tasks is '
                    . 'working — but the job itself stopped part-way through and did not get to '
                    . 'the end of its work. That is different from a job nobody is calling, and '
                    . 'usually means it ran out of time or hit an error. Part of its work may '
                    . 'have been done and part not.',
                'todo'     => 'Call the job once by hand with its token and read what it answers. '
                    . 'If it reports nothing, the error log for this portal will have the cause.',
                'count'    => count($diedPart),
                'examples' => array_slice($diedPart, 0, self::EXAMPLES),
            ];
        }

        if (count($broke) > 0) {
            // ⚠️ THIS MUST NOT BE FOLDED INTO THE NOTE BELOW. A first version of
            //    #775 had one word for "switched off" and "ran and failed", so a
            //    nightly backup that FAILED was reported to an administrator as
            //    "switched off" — untrue in the reassuring direction, about
            //    backups.
            $findings[] = [
                'severity' => self::PROBLEM,
                'what'     => 'Some scheduled jobs ran and could not finish their work.',
                'why'      => 'These are being called and they are not switched off: each one '
                    . 'tried, and reported that it could not do what it was asked. That is a '
                    . 'fault in the job itself or in something it depends on, not in whatever '
                    . 'calls it.',
                'todo'     => 'The error log for this portal will have the cause. Calling the job '
                    . 'once by hand with its token will usually reproduce it.',
                'count'    => count($broke),
                'examples' => array_slice($broke, 0, self::EXAMPLES),
            ];
        }

        if (count($refused) > 0) {
            // ⚠️ DO NOT NAME ONE CAUSE HERE. `refused` means any of THREE
            //    things — a setting switches the job off, another run already
            //    holds the lock, or nothing was due yet — and this said "a
            //    setting turns them off" for all three. Every clause was then
            //    false in the commonest state: nothing was switched off, the
            //    work WAS being done, and there was no setting to go and look
            //    at.
            //
            //    It is the commonest state rather than a corner case, measured:
            //    `cron/backup-run` records `refused` whenever the newest
            //    snapshot is not old enough yet, so on a WEEKLY schedule the
            //    nightly call refuses on six nights in seven and on a monthly
            //    one 27 in 28 — the last outcome on record is `refused` nearly
            //    always, and this note told the administrator their backup was
            //    switched off, permanently.
            //
            //    This is #775's own headline fault one step on: one word
            //    carrying several meanings, worded as one of them. The remedy
            //    here is to say what is KNOWN — the job ran and chose to do
            //    nothing — and to list the three reasons rather than assert
            //    one. Recording WHICH reason would need a column and every
            //    caller to pass it, which is #791.
            $findings[] = [
                'severity' => self::NOTE,
                'what'     => 'Some scheduled jobs ran recently and deliberately did nothing.',
                'why'      => 'Each of these was called, decided there was nothing for it to do, '
                    . 'and said so. That is not a fault. There are three ordinary reasons: a '
                    . 'setting switches the job off, another run of it was already in progress, '
                    . 'or its work was not due yet — a nightly backup on a weekly schedule '
                    . 'reports this on six nights out of seven, which is correct.',
                'todo'     => 'Nothing. If you expected one of these to be doing work, check '
                    . 'both its own settings and how often it is being called; this page cannot '
                    . 'tell which of the three reasons applied.',
                'count'    => count($refused),
                'examples' => array_slice($refused, 0, self::EXAMPLES),
            ];
        }

        if (count($unreadable) > 0) {
            // ⚠️ A WARNING, NOT THE "nothing is known yet" NOTE. The record is
            //    THERE and could not be read, so telling an administrator to
            //    wait would be telling them to wait for something that is not
            //    going to happen.
            $findings[] = [
                'severity' => self::WARNING,
                'what'     => 'The record of which scheduled jobs have run could not be read.',
                'why'      => 'The place this is kept exists but this page could not read it, so '
                    . 'it cannot say whether these jobs have run. The usual cause is a database '
                    . 'upgrade that did not finish.',
                'todo'     => 'Open Admin, Migrations and check that every database upgrade has '
                    . 'been run. The error log for this portal will have the cause.',
                'count'    => count($unreadable),
                'examples' => array_slice($unreadable, 0, self::EXAMPLES),
            ];
        }

        if (count($notKnown) > 0) {
            // ⚠️ A NOTE, NEVER A WARNING (D-071). And it must not read as an
            //    all-clear either: it says plainly that nothing is known.
            $findings[] = [
                'severity' => self::NOTE,
                'what'     => 'Nothing is known yet about some scheduled jobs.',
                'why'      => 'Each job records that it ran, and these have not recorded anything '
                    . 'since this portal was last updated. That is expected straight after an '
                    . 'update: each one starts being watched the first time it runs, with no '
                    . 'action from anybody. It is NOT a sign that they are working — only that '
                    . 'this page cannot yet say either way.',
                'todo'     => 'Nothing. If any of these is still listed here a day or two from '
                    . 'now, it is not being called, and that is worth looking into.',
                'count'    => count($notKnown),
                'examples' => array_slice($notKnown, 0, self::EXAMPLES),
            ];
        }

        if (count($running) > 0) {
            // Said separately and NOT counted as finished. It is not a fault —
            // a job that began a moment ago has simply not finished yet — but
            // the all-clear used to sweep it up and claim it had.
            $findings[] = [
                'severity' => self::NOTE,
                'what'     => 'Some scheduled jobs were part-way through when this page was drawn.',
                'why'      => 'Each of these recorded a start and has not yet recorded a finish, '
                    . 'and not enough time has passed for that to be a worry. Usually it means '
                    . 'the job is running at this moment. If one is still listed here after its '
                    . 'own expected gap it will be reported as having started and never '
                    . 'finished, which IS a problem.',
                'todo'     => 'Nothing. Reload this page in a few minutes to see whether they '
                    . 'finished.',
                'count'    => count($running),
                'examples' => array_slice($running, 0, self::EXAMPLES),
            ];
        }

        // ⚠️ THE ALL-CLEAR MUST EXCLUDE A JOB THAT IS STILL RUNNING, AND ITS
        //    SENTENCE MUST NOT COUNT ONE AS FINISHED. It used to say "All N of
        //    them recorded a finish" where N was every job, while a run that
        //    had begun and not finished fell through every test above and was
        //    swept in here — so a nightly backup that died part-way at 02:00
        //    was reported as an all-clear for the next 36 hours, with the
        //    record holding the opposite. Measured.
        //
        //    It counts the jobs it can actually speak for, rather than all of
        //    them, because that is the number the sentence is about.
        $finishedWell = count($state) - count($running) - count($noToken);
        if (count($overdue) === 0 && count($diedPart) === 0 && count($notKnown) === 0
            && count($broke) === 0 && count($unreadable) === 0 && $finishedWell > 0) {
            $findings[] = [
                'severity' => self::NOTE,
                'what'     => count($running) > 0
                    ? 'Every scheduled job that is not mid-run has run recently enough.'
                    : 'Every scheduled job has run recently enough.',
                'why'      => $finishedWell . ' of them recorded a FINISH inside the time expected '
                    . 'for that job, which differs by job: the mail queue is expected every few '
                    . 'minutes, the nightly jobs daily, and giving statements weekly.'
                    . (count($running) > 0
                        ? ' The ones listed above as part-way through are not counted here, '
                            . 'because they have not recorded a finish.'
                        : ''),
                'todo'     => 'Nothing.',
                'examples' => [],
            ];
        }

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
