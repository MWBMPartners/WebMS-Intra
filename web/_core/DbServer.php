<?php
// Path: _core/DbServer.php
/**
 * -----------------------------------------------------------------------------
 * Database server — what is it, and is it a version we support? 🛢️
 * -----------------------------------------------------------------------------
 * Asks the database which product and version it is, then says in plain English
 * whether that is a version this portal expects to work on.
 *
 * WHY THIS EXISTS
 * ---------------
 * Four places in this codebase already read the database version and print it:
 * the admin dashboard, the health page, every backup file, and now the server
 * information page. Every one of them just showed the raw text and left the
 * reader to work out whether it mattered. Nothing anywhere acted on the answer.
 *
 * That became a real problem in April 2026, when MySQL 8.0 reached the end of
 * its support life and stopped receiving security fixes. Most installs of this
 * portal are on shared hosting, where the customer cannot choose the database
 * version. They still deserve to be TOLD, in words, what they are running and
 * what that means — which is what this class produces.
 *
 * IT ALSO OPENS EVERY CONNECTION, AND MAKES EACH ONE STRICT (12 October 2026)
 * --------------------------------------------------------------------------
 * connect() is the only place in the portal that opens a database connection,
 * and it asks the database to refuse any value that does not fit its column
 * instead of quietly changing it. strictness() reads that setting back and
 * judges it, for the same pages that show the version. The reasons, and what
 * was tried and rejected, are beside the two STRICT_MODE lists below.
 *
 * IT ALSO WORKS DURING INSTALLATION
 * ---------------------------------
 * The installation wizard runs before the portal's normal start-up code, so it
 * cannot use most of the classes in this folder. This class is written to the
 * same "bootstrap-free" contract already used by `version.php` and
 * `brand-defaults.php`: it depends on nothing except the database connection
 * handed to it. The wizard can simply `require_once` this file and call it.
 * Do not add a dependency on App, Settings, Site or any PORTAL_* constant here
 * without also fixing the wizard.
 *
 * WHAT "SUPPORTED" MEANS HERE — read this before trusting the answer
 * -----------------------------------------------------------------
 * There are three answers, and they mean different things:
 *
 *   'ok'    The version is inside its own maker's support window AND is a
 *           version this project expects to work on.
 *
 *   'warn'  It should work, but something about it deserves attention — most
 *           often that the maker has stopped issuing security fixes for it.
 *           A warning NEVER blocks anything. On shared hosting the customer
 *           usually cannot change the version, so blocking would only lock
 *           them out of their own portal.
 *
 *   'crit'  Older than this portal's code can actually run on. The database
 *           changes in `_sql/` use features that version does not have, so
 *           installing would fail part-way through with a confusing error.
 *           This is the one case where the installation wizard stops.
 *
 * A caveat about what is really tested is DERIVED from TESTED_LINES rather
 * than written by hand — see that constant for the customer-facing sentences
 * that were untrue on 3 November 2026 because nobody had updated them.
 *
 * **It goes on THREE kinds of answer, not on every answer, and saying "every"
 * was itself untrue** (found by an independent check on 4 November 2026):
 * every MariaDB answer, every supported-MySQL answer, and the MySQL 8.0
 * answer. It is deliberately absent from the rest, and each absence has a
 * reason: an answer about a version too old to install at all, or about a
 * preview build nobody should put real data on, is not improved by a sentence
 * about which lines the tests run on — it would invite somebody to stay where
 * they are. The same goes for a database this class could not identify.
 *
 * This paragraph was one of the stale ones: it used to say the project's
 * automated tests "only ever run against MySQL", which stopped being true on
 * 28 October 2026 when the migration harness began replaying every database
 * change on MariaDB 11.4 (#487), and 12.3 followed on 3 November (#475).
 *
 * What the caveat says now depends on the LINE, because that is what is true:
 * the harness runs on MySQL 8.0, 8.4 and 9.7 and on MariaDB 11.4 and 12.3, and
 * it runs on none of MariaDB 10.6, 10.11 or 11.8, which this class still
 * accepts. For a line it does run on, the wording is "replays every one of its
 * database changes on it and checks the result" — never "supported", and
 * always with the limit stated out loud: no customer runs this portal on
 * MariaDB in production.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/475
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;
use Throwable;

/**
 * 🛢️ Reads the database product and version, and judges it against the
 *    versions this project supports.
 */
final class DbServer
{
    // 🚧 The oldest MySQL this portal's own database changes can run on.
    //    Below this the install genuinely fails, so the wizard stops.
    public const MIN_MYSQL = '8.0.0';

    // 🚧 The same line for MariaDB.
    public const MIN_MARIADB = '10.6.0';

    // ✅ The MySQL release lines that are actually still supported.
    //
    //    This is a LIST, not a "newer than X" rule, and the difference matters.
    //    MySQL ships two kinds of release. A long-term one is supported for
    //    years. An "innovation" one is replaced roughly every three months and
    //    stops receiving fixes as soon as its successor arrives. They share the
    //    same numbering, so 9.0 is NOT automatically better supported than 8.4 —
    //    8.4 is a long-term release running to 2032, and 9.0 was superseded
    //    within months.
    //
    //    An earlier version of this file used "anything at or above 8.4 is
    //    fine", which told anyone on 9.0 that they were still getting security
    //    fixes when they were not. Keep this as a list.
    public const MYSQL_SUPPORTED_SERIES = ['8.4', '9.7'];

    // 📅 The newest MySQL release line this file knows anything about. A version
    //    above this is too new for the list to judge, so it is reported as fine
    //    with an honest note rather than wrongly warned about.
    //
    //    ⏳ THESE LISTS GO OUT OF DATE. Last checked: 10 September 2026.
    //
    //    That is not a defect so much as a fact about version tables, and the
    //    design already allows for it: a version newer than anything listed here
    //    is reported as fine, with wording that says plainly we cannot vouch for
    //    its support position and that the hosting provider is the one to ask.
    //    So going stale makes this LESS informative, never wrong.
    //
    //    🔎 CONFIRMED ON 4 NOVEMBER 2026, and this comment used to say it had
    //    NOT been: Oracle really has moved MySQL to year-based release lines.
    //    endoflife.date lists 26.10 (current) and 26.7 (support ended
    //    20 October 2026), both above 9.7. `tools/check-end-of-life.py` reports
    //    it on every run, so the two were contradicting each other within one
    //    commit until this was corrected.
    //
    //    **IT IS STILL LEFT AT 9.7 ON PURPOSE**, for two reasons, and this is a
    //    decision rather than an omission. First, 9.7 is the newest LONG-TERM
    //    line (`lts: true`, supported to 30 April 2034, checked), and the 26.x
    //    lines are the short-lived kind — 26.7's support had already ended
    //    within four months. Second, nothing here has been RUN on a 26.x
    //    server, and this constant's job is to say what the file knows about,
    //    not what exists.
    //
    //    ⚠️ The measurable cost, said plainly: a customer on MySQL 26.7 — a
    //    line whose support ended on 20 October 2026 — is told `ok` and
    //    "newer than this portal knows about", with NO warning that it is
    //    finished. That is the documented going-stale-makes-it-less-informative
    //    design working as intended, and it is now a real case rather than a
    //    hypothetical one. Recorded on #475.
    public const MYSQL_NEWEST_KNOWN = '9.7';

    // ✅ The same list for MariaDB. 10.11 runs to February 2028, 11.4 to May
    //    2029, 11.8 and 12.3 later still. The 10.6 line ended in July 2026.
    public const MARIADB_SUPPORTED_SERIES = ['10.11', '11.4', '11.8', '12.3'];

    // 📅 The newest MariaDB release line this file knows about.
    public const MARIADB_NEWEST_KNOWN = '12.3';

    // 🚧 The first FINISHED release of each supported MariaDB line.
    //
    //    MariaDB publishes release candidates carrying the ordinary version
    //    number of the line they belong to — 11.4.0 and 11.4.1 were both
    //    pre-release builds, and 11.4.2 was the first one meant for real use.
    //    Some of those builds do not put "rc" anywhere in the version string, so
    //    looking for that word is not enough on its own. Belonging to a
    //    supported line does not make a build a finished one.
    //
    //    A line that is not listed here is treated as finished, which is the
    //    right way round: it means a new line is trusted rather than warned
    //    about, and only lines we positively know had pre-release builds carry
    //    a threshold.
    //    Source: https://mariadb.org/mariadb-11-4-2-and-mariadb-11-5-1-now-available/
    //    ⚠️ KEEP THIS IN STEP WITH MARIADB_SUPPORTED_SERIES ABOVE. A line listed
    //       as supported but missing from here is treated as though all its
    //       builds were finished ones, which is how 11.8.1 — a release
    //       candidate — was briefly being reported as a supported version.
    public const MARIADB_FIRST_STABLE = [
        '10.11' => '10.11.2',
        '11.4'  => '11.4.2',
        '11.8'  => '11.8.2',
        '12.3'  => '12.3.2',
    ];

    // 🎯 The release line to recommend moving TO, named in the advice text.
    public const SUPPORTED_MYSQL = '8.4';
    public const SUPPORTED_MARIADB = '11.4';

    // 🧪 THE RELEASE LINES AN AUTOMATED TEST REALLY RUNS ON (#475).
    //
    //    WHY THIS EXISTS. Every answer this class gives a customer used to
    //    carry a sentence about what is tested, and those sentences were
    //    WRITTEN BY HAND and went stale without anybody noticing. On
    //    3 November 2026 THREE of them were untrue on a customer-facing page
    //    (the first draft of this comment said two, and the MySQL 8.0 message
    //    is the third -- `_apps/admin/system-info/index.php` and
    //    `_install/db_server_banner.php` both print it):
    //    every MariaDB answer said "this portal's own automated tests only
    //    ever run against MySQL", eight days after the migration harness
    //    started replaying every database change on MariaDB 11.4 (#487); and
    //    every supported-MySQL answer said the tests "run against the MySQL
    //    8.0 line", so somebody on 9.7 was told they were ahead of what is
    //    tested when 9.7 is tested. Both were stale in the modest direction,
    //    which is the safer one — but a customer-facing sentence that is
    //    untrue is a fault whichever way it points.
    //
    //    So the sentence is now DERIVED from this list, and the list is
    //    CHECKED AGAINST THE WORKFLOW that does the running:
    //    `tools/audit-checks/check_tested_db_lines.py` compares it with the
    //    matrix in `.github/workflows/e2e-migrations.yml` and **fails both
    //    ways** — a line tested and not listed, or listed and not tested.
    //    That is what stops this going stale a second time.
    //
    //    ⚠️ IT IS THE MIGRATION HARNESS THIS DESCRIBES, and nothing else. The
    //    calendar self-tests run on their own set of engines, which is a
    //    smaller claim about a smaller body of code, and folding the two
    //    together would overstate both. Say "every database change is
    //    replayed on it", never "it is supported".
    public const TESTED_LINES = [
        'mysql'   => ['8.0', '8.4', '9.7'],
        'mariadb' => ['11.4', '12.3'],
    ];

    // 🔒 HOW STRICT THIS PORTAL MAKES ITS OWN CONNECTION (the `sql_mode` setting).
    //
    //    WHY THE PORTAL SETS THIS ITSELF. Until 12 October 2026 it ran with
    //    whatever the hosting company had chosen. On a loosely set server the
    //    database "helps" with a value that does not fit by changing it and
    //    saying nothing: a text is cut short, a number clipped, a value that is
    //    not on a column's list of choices stored as an empty text. The
    //    expenses "Withdraw" button stored an EMPTY status that way (#587).
    //    Every test this project has runs on MySQL 8's default settings, which
    //    are strict, so nothing here could see it.
    //
    //    THE SETTING IS REPLACED, NOT ADDED TO, so every installation runs
    //    under the same one whatever its host chose. Adding strictness to the
    //    host's own setting was tried and rejected: it keeps anything else the
    //    host switched on, and one such switch (ANSI_QUOTES) makes
    //    `status = "valid"` mean a COLUMN called valid. The portal writes text
    //    that way in many places, a safeguarding check in the sign-in class
    //    (Auth::isCoordinatorOf()) among them.
    //
    //    A server KNOWN to be MySQL gets exactly MySQL 8's own default, which
    //    is what every test here runs under. On a MySQL host that already has
    //    the default, this changes nothing at all. "Known" is decided in
    //    wantedSqlMode() below, and matters: see there.
    public const STRICT_MODE_MYSQL = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,'
                                   . 'ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    // 🍃 MariaDB gets the same list WITHOUT ONLY_FULL_GROUP_BY, and that word is
    //    left out on purpose. With it on, MariaDB 10.6 and 11.4 refuse
    //    `SELECT id, name, COUNT(*) FROM t GROUP BY id` (grouping by a table's
    //    own key and reading its other columns), which MySQL 8 accepts and
    //    which the portal does all over. MariaDB has the word off by default,
    //    so leaving it off keeps what works there today. Values that do not fit
    //    are refused just the same without it (tried on both).
    //
    //    EVERY SERVER THAT IS NOT KNOWN TO BE MYSQL GETS THIS LIST TOO, despite
    //    the constant's name. It is the list that is safe on both products:
    //    without the word MySQL only becomes less fussy about grouping, while
    //    with it a MariaDB answers "error" on pages that worked. See
    //    wantedSqlMode().
    //
    //    One word that must NEVER be added to either list: NO_AUTO_CREATE_USER.
    //    It is in MariaDB's own default, and MySQL 8 does not know it. A server
    //    that does not know ONE word refuses the WHOLE list, and the connection
    //    is left exactly as loose as the host made it.
    public const STRICT_MODE_MARIADB = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,'
                                     . 'ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    // ⏳ MySQL has said these three names will one day be removed and their
    //    effect made part of strict mode itself. A server that has done that
    //    would refuse the lists above because of the unknown names, so
    //    makeStrict() asks a second time without them. See there.
    private const MODE_NAMES_BEING_RETIRED = ['NO_ZERO_IN_DATE', 'NO_ZERO_DATE', 'ERROR_FOR_DIVISION_BY_ZERO'];

    // 🐬 A question only MySQL answers. This setting belongs to MySQL 8's own
    //    catalogue of tables (how long it keeps table statistics). MySQL's
    //    manual lists it from 8.0.3 onward; any account may read it (tried
    //    with an account that has no rights at all, on 8.0.36 and 8.4.11);
    //    and MariaDB has no such setting. wantedSqlMode() uses it; see there
    //    for why.
    //
    //    WHAT "MariaDB has no such setting" RESTS ON: MariaDB's own list of
    //    settings, not a run here. No MariaDB was started when this was
    //    written (12 October 2026). If a MariaDB ever did answer it, that
    //    server would be judged as it was before this question existed, by
    //    its name alone; nothing would get worse.
    //
    //    If a future MySQL drops the setting, every MySQL quietly loses
    //    ONLY_FULL_GROUP_BY. tools/db-strict-mode-selftest.php fails on that
    //    server then: it writes the list it expects out by hand.
    private const QUESTION_ONLY_MYSQL_ANSWERS = 'SELECT @@information_schema_stats_expiry';

    /**
     * 🔌 Open a connection to the database. THE ONLY PLACE THIS PORTAL DOES SO.
     *
     * The portal's start-up code and both of the installation wizard's
     * connections come through here, so all three talk in the same character
     * set and all three are made strict (see makeStrict() below). A connection
     * opened anywhere else with `new mysqli` would run as loosely as the host
     * allows, and nothing would look wrong.
     * `tools/db-strict-mode-selftest.php` fails if it finds one.
     *
     * The arguments are the same as `new mysqli` takes, empty ones included,
     * so swapping this in changed nothing about how a connection is found.
     *
     * WHAT IT DOES NOT DO: stop when the database refuses to be made strict.
     * It writes that to the error log and hands the connection back, because
     * the two callers need different answers. Ask strictness() to find out.
     *
     * @param string|null $host     The database server's address.
     * @param string|null $user     The account to sign in with.
     * @param string|null $password That account's password. Marked sensitive so
     *                              PHP leaves it out of any error report.
     * @param string|null $database The database to open, or '' for none yet.
     * @param int|null    $port     The port the database server listens on.
     * @param int|null    $timeout  Seconds to wait for the server — to connect,
     *                              and for each answer on this connection — or
     *                              null for PHP's own settings, which is what
     *                              every caller got before #649. By default those
     *                              are 60 seconds to connect
     *                              (`default_socket_timeout`) and a whole DAY,
     *                              86,400 seconds, for each answer, the server's
     *                              first one included (`mysqlnd.net_read_timeout`).
     *
     * @return mysqli An open connection.
     *
     * @throws \mysqli_sql_exception When the server cannot be reached or will
     *                               not let this account in. That relies on
     *                               mysqli reporting faults as exceptions, which
     *                               is PHP's default and which both callers ask
     *                               for by name just before calling.
     */
    public static function connect(
        ?string $host,
        ?string $user,
        #[\SensitiveParameter] ?string $password,
        ?string $database,
        ?int $port,
        ?int $timeout = null
    ): mysqli {
        // ⏱️ A connection with its own deadline (#649). mysqlnd takes its
        //    connect deadline from `default_socket_timeout` and its deadline
        //    for each answer from `mysqlnd.net_read_timeout`, both read when
        //    the connection is made, so both are set for this one `new mysqli`
        //    and put back straight after — the rest of the request keeps its
        //    own. BOTH halves are needed, and they cover different faults:
        //      - CONNECT (`default_socket_timeout`, 60 seconds by default): a
        //        server that never answers the attempt to connect at all.
        //        Section L of tools/expense-receipts-selftest.php drives it.
        //      - READ (`mysqlnd.net_read_timeout`, 86,400 seconds — a day — by
        //        default): a server that accepts the connection and then says
        //        nothing. Measured by the independent check on PHP 8.5.12 with
        //        no deadline: still waiting after 150 seconds. Section K4
        //        drives the 3-second deadline ending it; it does not measure
        //        the default, because its stand-in server gives up after 120.
        //    On a PHP built with libmysqlclient instead of mysqlnd, the
        //    `mysqlnd.*` setting does not exist and the READ half does not
        //    apply; only the connect half does. (The request's own connection
        //    has no read deadline either; that is #872, not this method.)
        //
        //    Done with settings rather than `mysqli_init()` and
        //    `real_connect()` on purpose: those are two more ways to open a
        //    connection, and tools/db-strict-mode-selftest.php holds this file
        //    to exactly ONE, so that nothing can open one without passing
        //    through makeStrict() below.
        $restore = [];
        if ($timeout !== null) {
            foreach (['default_socket_timeout', 'mysqlnd.net_read_timeout'] as $setting) {
                $restore[$setting] = ini_get($setting);
                ini_set($setting, (string) $timeout);
            }
        }
        try {
            $db = new mysqli($host, $user, $password, $database, $port);
        } finally {
            foreach ($restore as $setting => $value) {
                if ($value !== false) {
                    ini_set($setting, $value);
                }
            }
        }

        // 🔤 utf8mb4 is the character set that holds every character,
        //    including emoji. See: https://dev.mysql.com/doc/refman/8.0/en/charset-unicode-utf8mb4.html
        $db->set_charset('utf8mb4');

        self::makeStrict($db);

        return $db;
    }

    /**
     * 🔁 A SECOND, NEW connection with the portal's own credentials, for the
     *    rare moment the request's own connection can no longer be trusted
     *    (#649).
     *
     * The case it was written for: a claim's commit could not be confirmed.
     * The server may have committed it or not, and the request's own
     * connection cannot say which. It reads the same credentials file
     * start-up reads and opens the connection through connect() above, so it
     * is just as strict; it never reuses the request's connection.
     *
     * It waits at most `$timeout` seconds (3 by default) for the server, both
     * to connect and for each answer, so a database that cannot be reached —
     * or that is reached and then says nothing — cannot hold the request for
     * a minute (to connect) or a day (for an answer) while the person waits.
     * See connect() for what each half covers and where it does not apply.
     *
     * @param int $timeout Seconds to wait to connect, and for each answer.
     *
     * @return mysqli An open connection.
     *
     * @throws \RuntimeException     When the credentials file cannot be read.
     * @throws \mysqli_sql_exception When the server cannot be reached.
     */
    public static function connectAgain(int $timeout = 3): mysqli
    {
        $authFile = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . 'auth_creds.php';
        if (is_readable($authFile) === false) {
            throw new \RuntimeException('The database credentials file could not be read');
        }
        $creds = require $authFile;
        if (is_array($creds) === false) {
            throw new \RuntimeException('The database credentials file did not hold a list of settings');
        }

        return self::connect(
            isset($creds['db_host']) === true ? (string) $creds['db_host'] : null,
            isset($creds['db_user']) === true ? (string) $creds['db_user'] : null,
            isset($creds['db_pass']) === true ? (string) $creds['db_pass'] : null,
            isset($creds['db_name']) === true ? (string) $creds['db_name'] : null,
            isset($creds['db_port']) === true ? (int) $creds['db_port'] : 3306,
            $timeout
        );
    }

    /**
     * 🔒 Make this connection strict: a value that does not fit is REFUSED
     *    with an error instead of being stored changed.
     *
     * Never throws. It needs no special right: an account with rights on one
     * database only may do it (tried on MySQL 8.0 and 8.4, MariaDB 10.6 and
     * 11.4).
     *
     * WHEN THE SERVER SAYS NO, it asks once more for the same list without the
     * three names MySQL is retiring. A server that has retired them takes that
     * shorter list and still refuses values that do not fit. But it is not
     * silent about it: strictness() judges that connection 'warn' (strict, not
     * the exact list), so every administrator sees an amber alert, the health
     * check is amber, and a line marked CRITICAL is written on every request,
     * until the lists in this file are brought into line with that server.
     * Whatever happens, a refusal is written to PHP's error log, on every
     * request, so it cannot pass unseen.
     *
     * WHAT IT CANNOT PROMISE. A "yes" from the server is not checked here,
     * because that would cost every page another question. strictness() reads
     * the setting back, and the health page, the admin dashboard and the
     * Server Information page all call it, so a server that says yes and does
     * nothing is still found.
     *
     * @param mysqli $db An open database connection.
     *
     * @return string Empty when the server took the full list; otherwise the
     *                reason it gave for refusing it.
     */
    public static function makeStrict(mysqli $db): string
    {
        $wanted  = self::wantedSqlMode($db);
        $refusal = self::setSqlMode($db, $wanted);
        if ($refusal === '') {
            return '';
        }

        $shorter = implode(',', array_diff(explode(',', $wanted), self::MODE_NAMES_BEING_RETIRED));
        $second  = self::setSqlMode($db, $shorter);

        error_log(
            '[WebMS-Intra] CRITICAL: the database would not give this connection the strictness the portal asks for'
            . ' (sql_mode ' . $wanted . '). It said: ' . str_replace(["\r", "\n"], ' ', $refusal) . '. '
            . ($second === ''
                ? 'It did take the same list without the names MySQL is retiring (' . $shorter . ').'
                : 'It refused the shorter list too, so this connection is only as strict as the hosting company set the server.')
            . ' Admin → Server Information shows what the connection is running under.'
        );

        return $refusal;
    }

    /**
     * 🩺 How strict is this connection really? Reads the setting back from the
     *    database and says, in plain English, whether it is the one this
     *    portal asks for.
     *
     * Never throws. Three answers, on the same scale as inspect():
     *
     *   'ok'    Exactly what the portal asks for.
     *   'warn'  Strict, but not that exact list; or the database would not say.
     *   'crit'  Not strict at all: values that do not fit are being changed
     *           quietly. The installation wizard stops on this one.
     *
     * @param mysqli $db An open database connection.
     *
     * @return array{state: string, mode: string, wanted: string, headline: string, detail: string}
     *         `mode` is what the connection is running under ('' when it is
     *         empty or could not be read); `wanted` is what the portal asks for.
     */
    public static function strictness(mysqli $db): array
    {
        $mode = null;
        try {
            $rs = $db->query('SELECT @@SESSION.sql_mode AS m');
            if ($rs !== false) {
                $row = $rs->fetch_assoc();
                if (isset($row['m']) === true) {
                    $mode = (string) $row['m'];
                }
                $rs->free();
            }
        } catch (Throwable $e) {
            $mode = null;
        }

        return self::judgeStrictness($mode, self::wantedSqlMode($db));
    }

    /**
     * 🧮 Compare the setting a connection is running under with the one the
     *    portal asks for. Split out from strictness() so it can be tested
     *    without a database.
     *
     * @param string|null $mode   What the database reported, or null when it
     *                            would not say.
     * @param string      $wanted What the portal asks for on this product.
     *
     * @return array{state: string, mode: string, wanted: string, headline: string, detail: string}
     */
    public static function judgeStrictness(?string $mode, string $wanted): array
    {
        $base = ['mode' => (string) $mode, 'wanted' => $wanted];

        if ($mode === null) {
            return $base + [
                'state'    => 'warn',
                'headline' => 'The database would not say how strict this connection is',
                'detail'   => 'This portal asks the database to refuse any value that does not fit '
                            . 'its column. It could not read back whether that took effect, so it '
                            . 'cannot tell you either way. Your hosting provider can tell you what '
                            . 'the server\'s "sql_mode" is.',
            ];
        }

        // 🔀 The database lists the words in its own order, so compare them as
        //    sets, not as text.
        $have    = array_values(array_filter(explode(',', $mode), static fn (string $word): bool => $word !== ''));
        $want    = explode(',', $wanted);
        $missing = array_values(array_diff($want, $have));
        $extra   = array_values(array_diff($have, $want));

        if ($missing === [] && $extra === []) {
            return $base + [
                'state'    => 'ok',
                'headline' => 'Strict, exactly as this portal asks',
                'detail'   => 'A value that does not fit its column (a text that is too long, a '
                            . 'number that is too large, an empty value where one is needed, or '
                            . 'one that is not on the column\'s list of choices) is refused with an '
                            . 'error instead of being changed quietly. This is the setting read '
                            . 'back from the database just now. The portal asks for it each time '
                            . 'it connects; if the database ever refuses that request, PHP\'s '
                            . 'error log on the web server says so.',
            ];
        }

        $difference = ($missing !== [] ? ' Missing: ' . implode(', ', $missing) . '.' : '')
                    . ($extra !== [] ? ' Not asked for: ' . implode(', ', $extra) . '.' : '');

        // 🚦 Either of these two words is what makes a connection strict.
        if (in_array('STRICT_TRANS_TABLES', $have, true) === true || in_array('STRICT_ALL_TABLES', $have, true) === true) {
            return $base + [
                'state'    => 'warn',
                'headline' => 'Strict, but not the exact setting this portal asks for',
                'detail'   => 'Values that do not fit are still refused, which is the part that '
                            . 'protects your data. But this is not the full setting the portal '
                            . 'asks for, so this installation may behave a little differently from '
                            . 'the ones this portal is tested on.' . $difference . ' If the '
                            . 'database refused the full setting, its reason is in PHP\'s error '
                            . 'log on the web server.',
            ];
        }

        return $base + [
            'state'    => 'crit',
            'headline' => 'NOT strict: the database changes values that do not fit, and says nothing',
            'detail'   => 'This portal asks the database to refuse any value that does not fit '
                        . 'its column, and the database would not do it. Until that is put right, '
                        . 'a text that is too long is cut short, a number that is too large is '
                        . 'clipped, and a value that is not on a column\'s list of choices is '
                        . 'stored as an empty one, all without an error.' . $difference . ' Ask '
                        . 'your hosting provider to allow "SET SESSION sql_mode", or to set the '
                        . 'server\'s sql_mode to ' . $wanted . '. If the database refused the '
                        . 'setting, its reason is in PHP\'s error log on the web server.',
        ];
    }

    /**
     * 📋 The strictness setting the portal asks THIS server for.
     *
     * MySQL's own list goes ONLY to a server known to be MySQL, which takes two
     * things: inspect() (the same answer every page shows) calls it MySQL,
     * AND it answers a question only MySQL answers. Every other server gets
     * the list without ONLY_FULL_GROUP_BY: a MariaDB, a product inspect()
     * cannot name, and one that merely is not CALLED MariaDB.
     *
     * WHY BEING UNSURE GOES THAT WAY. The two mistakes are not equally bad.
     * A MySQL given the shorter list only becomes less fussy about grouping,
     * and still refuses every value that does not fit. A MariaDB given
     * MySQL's list ACCEPTS it and then answers "error" on pages that worked
     * (six of them on an empty MariaDB 10.6, in the check of 12 October
     * 2026), while strictness() calls the connection exactly right.
     *
     * WHAT WAS TRIED AND REJECTED.
     * - Going by inspect() alone, which is how this was first built. It reads
     *   two pieces of text, and neither is proof: MariaDB lets its version
     *   text be replaced (`--version=8.0.36-hosted`), and the other text says
     *   only "Debian 11" or "Source distribution" on builds that Linux
     *   distributions make. Such a server was taken for MySQL.
     * - Asking a question only MARIADB answers, and giving MySQL's list when
     *   it goes unanswered. That fails the dangerous way: a MariaDB that
     *   refuses the question (some hosts do not let an account read server
     *   settings) is taken for MySQL again, and nothing says so.
     * - Reading the product off the connection's opening greeting, to save
     *   questions. A go-between placed in front of the database can greet
     *   with a version of its own.
     *
     * WHAT IT COSTS. One more short question on every connection to a server
     * that inspect() calls MySQL (four statements at start-up, where there
     * were three). And a real MySQL that will not answer the question runs
     * without ONLY_FULL_GROUP_BY: strictness() still says 'ok', because that
     * IS what the portal asks such a server for, and Admin → Server
     * Information shows the list itself.
     *
     * @param mysqli $db An open database connection.
     *
     * @return string A comma-separated list of mode names, no spaces.
     */
    public static function wantedSqlMode(mysqli $db): string
    {
        if (self::inspect($db)['family'] === 'mysql' && self::answersAsOnlyMySqlDoes($db) === true) {
            return self::STRICT_MODE_MYSQL;
        }

        return self::STRICT_MODE_MARIADB;
    }

    /**
     * 🐬 Does this server answer the question only MySQL answers?
     *
     * Never throws. Any refusal, for any reason, counts as "no": that is the
     * safe answer, as wantedSqlMode() explains.
     *
     * @param mysqli $db An open database connection.
     *
     * @return bool True only when the server gave an answer.
     */
    private static function answersAsOnlyMySqlDoes(mysqli $db): bool
    {
        // 🛡️ Works whichever way mysqli is set to report faults: by throwing,
        //    or by handing back false.
        try {
            $rs = $db->query(self::QUESTION_ONLY_MYSQL_ANSWERS);
            if ($rs === false) {
                return false;
            }
            $row = $rs->fetch_row();
            $rs->free();

            return is_array($row) === true && $row[0] !== null;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * ✍️ Ask the database for one strictness setting.
     *
     * @param mysqli $db   An open database connection.
     * @param string $mode Only ever one of this class's own lists, never
     *                     anything a person typed, which is why it is safe to
     *                     put straight into the statement.
     *
     * @return string Empty when the database took it; otherwise its reason.
     */
    private static function setSqlMode(mysqli $db, string $mode): string
    {
        // 🛡️ Works whichever way mysqli is set to report faults: by throwing,
        //    or by handing back false.
        try {
            if ($db->query("SET SESSION sql_mode = '" . $mode . "'") === false) {
                return $db->error !== '' ? $db->error : 'the database gave no reason';
            }
        } catch (Throwable $e) {
            return $e->getMessage() !== '' ? $e->getMessage() : 'the database gave no reason';
        }

        return '';
    }

    /**
     * 🔍 Ask the database what it is.
     *
     * Never throws. If the database cannot answer — a permission problem, a
     * connection that has already gone away — the result comes back with an
     * engine of 'Unknown' and a state of 'warn', so a caller can always print
     * something sensible instead of crashing.
     *
     * @param mysqli $db An open database connection.
     *
     * @return array{
     *     engine: string,
     *     family: string,
     *     raw: string,
     *     version: string,
     *     comment: string,
     *     state: string,
     *     headline: string,
     *     detail: string,
     *     untested: bool
     * }
     */
    public static function inspect(mysqli $db): array
    {
        $raw     = '';
        $comment = '';

        // 🛡️ Two separate try blocks on purpose. A server that refuses
        //    @@version_comment (some managed hosts restrict system variables)
        //    should still give us the version number from VERSION().
        try {
            $rs = $db->query('SELECT VERSION() AS v');
            if ($rs !== false) {
                $row = $rs->fetch_assoc();
                $raw = (string) ($row['v'] ?? '');
                $rs->free();
            }
        } catch (Throwable $e) {
            $raw = '';
        }

        try {
            $rs = $db->query('SELECT @@version_comment AS c');
            if ($rs !== false) {
                $row     = $rs->fetch_assoc();
                $comment = (string) ($row['c'] ?? '');
                $rs->free();
            }
        } catch (Throwable $e) {
            $comment = '';
        }

        // 🪫 If VERSION() gave us nothing, fall back to what the connection
        //    itself reported when it was opened. That value is filled in by the
        //    database driver during the handshake, so it survives a server that
        //    will not run queries for this user.
        if ($raw === '') {
            $raw = (string) ($db->server_info ?? '');
        }

        return self::classify($raw, $comment);
    }

    /**
     * 🧮 Work out the product and verdict from the two strings the server gave
     *    us. Split out from inspect() so it can be reasoned about — and tested —
     *    without a database.
     *
     * @param string $raw     Whatever VERSION() returned.
     * @param string $comment Whatever @@version_comment returned; may be empty.
     *
     * @return array<string, mixed> Same shape as inspect().
     */
    public static function classify(string $raw, string $comment = ''): array
    {
        $raw     = trim($raw);
        $comment = trim($comment);

        $parsed   = self::parseVersion($raw);
        $version  = $parsed['version'];
        $hasPatch = $parsed['hasPatch'];

        // 🏷️ Which product is this? MariaDB and Percona both announce
        //    themselves by name, either in the version string or in the
        //    comment, so those two are easy and certain.
        $haystack = strtolower($raw . ' ' . $comment);

        if (strpos($haystack, 'mariadb') !== false) {
            $engine = 'MariaDB';
            $family = 'mariadb';
        } elseif (strpos($haystack, 'percona') !== false) {
            // Percona Server is a MySQL fork that tracks MySQL's own version
            // numbers, so it is judged by the MySQL rules below.
            $engine = 'Percona Server';
            $family = 'mysql';
        } elseif (strpos($haystack, 'mysql') !== false || $version !== '') {
            // Nothing named itself. Plain MySQL is the only database this
            // portal is built for, and it is also the only one that does not
            // stamp its own name into VERSION(), so a bare version number is
            // very likely MySQL. Requiring a readable version number here
            // matters: without it, any unrecognisable string would be
            // confidently mislabelled "MySQL" on the strength of no evidence
            // at all.
            $engine = 'MySQL';
            $family = 'mysql';
        } else {
            $engine = 'Unknown';
            $family = 'unknown';
        }

        // ❓ We could not read a version number at all. Say so plainly rather
        //    than guessing.
        if ($version === '') {
            return [
                'engine'   => $engine,
                'family'   => $family,
                'raw'      => $raw,
                'version'  => '',
                'comment'  => $comment,
                'state'    => 'warn',
                'headline' => 'Database version could not be read',
                'detail'   => 'The database answered, but it did not report a version number '
                            . 'this portal could understand. Everything will still work; we '
                            . 'simply cannot tell you whether the version is a supported one.',
                'untested' => false,
            ];
        }

        if ($family === 'mariadb') {
            return self::judgeMariaDb($engine, $raw, $version, $comment, $hasPatch);
        }

        if ($family === 'mysql') {
            return self::judgeMySql($engine, $raw, $version, $comment);
        }

        return [
            'engine'   => 'Unknown',
            'family'   => 'unknown',
            'raw'      => $raw,
            'version'  => $version,
            'comment'  => $comment,
            'state'    => 'warn',
            'headline' => 'Unrecognised database, version ' . $version,
            'detail'   => 'This portal could not tell whether this is MySQL, MariaDB or '
                        . 'something else. It is probably fine — but nobody has checked '
                        . 'this portal against it, so treat anything unusual with suspicion.',
            'untested' => true,
        ];
    }

    /**
     * 🍃 Judge a MariaDB version.
     *
     * @param string $engine  Display name of the product.
     * @param string $raw     The full version string.
     * @param string $version  The three-part version number.
     * @param string $comment  The server's own description of itself.
     * @param bool   $hasPatch Whether the server actually stated a patch level,
     *                         rather than us filling in a zero.
     *
     * @return array<string, mixed>
     */
    private static function judgeMariaDb(string $engine, string $raw, string $version, string $comment, bool $hasPatch): array
    {
        // 📌 A sentence goes on every MariaDB answer, whatever the verdict,
        //    saying the honest limit of what this project can claim. It is
        //    DERIVED from TESTED_LINES and differs by release line: the
        //    migration harness really does replay every database change on
        //    11.4 and 12.3, and really does not touch 10.6, 10.11 or 11.8.
        //    Until 3 November 2026 this was ONE hand-written sentence saying
        //    the tests "only ever run against MySQL", which had been untrue
        //    since #487 eight days earlier — see TESTED_LINES.
        //
        //    ⚠️ The line is worked out HERE, before the branches below, because
        //    two of them answer before the old code reached `self::series()`.
        //    Moving the call up is safe: it only parses the version string.
        $series = self::series($version);
        $caveat = self::testedLinesCaveat('mariadb', $series);

        $base = [
            'engine'   => $engine,
            'family'   => 'mariadb',
            'raw'      => $raw,
            'version'  => $version,
            'comment'  => $comment,
            'untested' => true,
        ];

        // 🛑 Too old to hold this portal's schema at all.
        if (version_compare($version, self::MIN_MARIADB, '<') === true) {
            return $base + [
                'state'    => 'crit',
                'headline' => 'MariaDB ' . $version . ' is too old for this portal',
                'detail'   => 'This portal needs MariaDB ' . self::MIN_MARIADB . ' or newer. '
                            . 'Its database changes use features that MariaDB ' . $version
                            . ' does not have, so installing would fail part-way through. '
                            . 'Ask your hosting provider to move you to a newer database '
                            . 'server before going any further.' . $caveat,
            ];
        }

        // 🚧 A preview or release-candidate build. MariaDB and MySQL both put
        //    out builds like these before a release line is ready for real use,
        //    and they carry the same version number as the stable one that
        //    follows. Belonging to a supported release line therefore does NOT
        //    mean a build is a finished one.
        if (self::looksPreRelease($raw) === true) {
            return $base + [
                'state'    => 'warn',
                'headline' => 'MariaDB ' . $version . ' — a preview build, not a finished release',
                'detail'   => 'The version this server reports is marked as a preview or a '
                            . 'release candidate. Those are published for testing before a '
                            . 'release is ready, and are not meant to hold real data. Ask '
                            . 'your hosting provider to move you to a finished release of '
                            . 'the same line.' . $caveat,
            ];
        }

        // 🚧 A build from before the line was finished. See MARIADB_FIRST_STABLE.
        if (array_key_exists($series, self::MARIADB_FIRST_STABLE) === true
            && $hasPatch === true
            && version_compare($version, self::MARIADB_FIRST_STABLE[$series], '<') === true
        ) {
            return $base + [
                'state'    => 'warn',
                'headline' => 'MariaDB ' . $version . ' — an unfinished build of an otherwise good release line',
                'detail'   => 'The ' . $series . ' line is a long-term MariaDB release, but this '
                            . 'particular build came before it was finished: '
                            . self::MARIADB_FIRST_STABLE[$series] . ' was the first one meant '
                            . 'for real use. Earlier builds in a line are release candidates, '
                            . 'published for testing. Ask your hosting provider to move you to '
                            . self::MARIADB_FIRST_STABLE[$series] . ' or newer.' . $caveat,
            ];
        }

        // ✅ A release line that is genuinely still maintained.
        if (in_array($series, self::MARIADB_SUPPORTED_SERIES, true) === true) {
            return $base + [
                'state'    => 'ok',
                'headline' => 'MariaDB ' . $version . ' — a supported version',
                'detail'   => 'The ' . $series . ' line is a long-term MariaDB release and is '
                            . 'still receiving security fixes from its makers.' . $caveat,
            ];
        }

        // 🔭 Newer than anything this file knows about.
        if (version_compare($series . '.0', self::MARIADB_NEWEST_KNOWN . '.0', '>') === true) {
            return $base + [
                'state'    => 'ok',
                'headline' => 'MariaDB ' . $version . ' — newer than this portal knows about',
                'detail'   => 'This is newer than any MariaDB release this portal has been told '
                            . 'about, so it will almost certainly run everything here without '
                            . 'trouble. What this portal cannot tell you is whether the '
                            . $series . ' line is one of the long-term releases. If you want to '
                            . 'be sure you are still getting security fixes, that is the '
                            . 'question to put to your hosting provider.' . $caveat,
            ];
        }

        // ⚠️ Old enough to run, but its line is no longer maintained. MariaDB
        //    10.6 is the common case here: its maintenance ended in July 2026.
        return $base + [
            'state'    => 'warn',
            'headline' => 'MariaDB ' . $version . ' — works, but its release line is no longer maintained',
            'detail'   => 'This portal expects to run on MariaDB ' . $version . ' without '
                        . 'trouble, and there is nothing here that needs a newer one. But the '
                        . $series . ' line is past the end of its maintenance, so security '
                        . 'fixes are no longer being issued for it. MariaDB '
                        . self::SUPPORTED_MARIADB . ' is a long-term release maintained until '
                        . 'May 2029, and is the version worth asking your hosting provider for '
                        . 'when you next have the chance.' . $caveat,
        ];
    }

    /**
     * 🐬 Judge a MySQL (or Percona Server) version.
     *
     * @param string $engine  Display name of the product.
     * @param string $raw     The full version string.
     * @param string $version The three-part version number.
     * @param string $comment The server's own description of itself.
     *
     * @return array<string, mixed>
     */
    private static function judgeMySql(string $engine, string $raw, string $version, string $comment): array
    {
        $base = [
            'engine'   => $engine,
            'family'   => 'mysql',
            'raw'      => $raw,
            'version'  => $version,
            'comment'  => $comment,
            'untested' => false,
        ];

        // 🛑 Too old to hold this portal's schema at all.
        if (version_compare($version, self::MIN_MYSQL, '<') === true) {
            return $base + [
                'state'    => 'crit',
                'headline' => $engine . ' ' . $version . ' is too old for this portal',
                'detail'   => 'This portal needs MySQL ' . self::MIN_MYSQL . ' or newer. Its '
                            . 'database changes use features that version ' . $version . ' does '
                            . 'not have, so installing would fail part-way through and leave '
                            . 'you with a half-built database. Ask your hosting provider to '
                            . 'move you to a newer database server before going any further.',
            ];
        }

        if (self::looksPreRelease($raw) === true) {
            return $base + [
                'state'    => 'warn',
                'headline' => $engine . ' ' . $version . ' — a preview build, not a finished release',
                'detail'   => 'The version this server reports is marked as a preview or a '
                            . 'release candidate. Those are published for testing before a '
                            . 'release is ready, and are not meant to hold real data. Ask '
                            . 'your hosting provider to move you to a finished release of '
                            . 'the same line.',
            ];
        }

        $series = self::series($version);

        // ✅ A release line that is genuinely still supported.
        if (in_array($series, self::MYSQL_SUPPORTED_SERIES, true) === true) {
            return $base + [
                'state'    => 'ok',
                'headline' => $engine . ' ' . $version . ' — a supported version',
                // 📌 The second half of this used to read "this portal's own
                //    automated tests run against the MySQL 8.0 line, so if you
                //    are on something newer you are ahead of what is routinely
                //    tested here". That became untrue when 8.4 joined the
                //    harness and more untrue when 9.7 did — it told somebody on
                //    a tested version that nobody had tested it. Derived from
                //    TESTED_LINES now; see that constant.
                'detail'   => 'The ' . $series . ' line is a long-term MySQL release, so it '
                            . 'receives security fixes for years rather than months. This is a '
                            . 'good version to be on.'
                            . self::testedLinesCaveat('mysql', $series),
            ];
        }

        // 🔭 Newer than anything this file has been told about. Do not warn:
        //    being newer than our list is not evidence of a problem. But do not
        //    claim to know its support status either.
        if (version_compare($series . '.0', self::MYSQL_NEWEST_KNOWN . '.0', '>') === true) {
            return $base + [
                'state'    => 'ok',
                'headline' => $engine . ' ' . $version . ' — newer than this portal knows about',
                'detail'   => 'This is newer than any MySQL release this portal has been told '
                            . 'about, so it will almost certainly run everything here without '
                            . 'trouble. What this portal cannot tell you is whether the '
                            . $series . ' line is a long-term release or one of the short-lived '
                            . 'ones that MySQL replaces every few months. If you want to be '
                            . 'sure you are still getting security fixes, that is the question '
                            . 'to put to your hosting provider.',
            ];
        }

        // ⚠️ The 8.0 line. By far the most common on shared hosting, and the
        //    reason this whole class exists.
        if ($series === '8.0') {
            return $base + [
                'state'    => 'warn',
                'headline' => $engine . ' ' . $version . ' — works, but no longer getting security fixes',
                // 📌 THE TESTED-LINES SENTENCE IS DERIVED HERE TOO, and this
                //    branch is why it matters rather than being tidiness. It
                //    used to say "this is one of the versions tested here" in
                //    its own words, which four documents then described as
                //    coming from the one place that builds it. **#475's stated
                //    direction is to stop accepting MySQL 8.0** — and the day
                //    8.0 leaves `e2e-migrations.yml`, the hard gate FORCES
                //    '8.0' out of TESTED_LINES and goes green, while a
                //    hand-written sentence here would go on telling every 8.0
                //    customer their version is tested. The same staleness, in
                //    the same message, one step along. Found by an independent
                //    check, not by reading.
                'detail'   => 'The whole MySQL 8.0 line reached the end of its support life in '
                            . 'April 2026. It still runs this portal perfectly well, but Oracle '
                            . 'no longer issues security fixes for it, so any flaw found from '
                            . 'now on stays unfixed. On shared hosting you usually cannot change '
                            . 'this yourself. It is worth asking your hosting provider when '
                            . 'they plan to move to MySQL ' . self::SUPPORTED_MYSQL . ', which '
                            . 'is a long-term release supported into 2032.'
                            . self::testedLinesCaveat('mysql', $series),
            ];
        }

        // ⚠️ Everything else below the newest line we know about is one of the
        //    short-lived "innovation" releases — replaced within about three
        //    months and unsupported from that moment.
        return $base + [
            'state'    => 'warn',
            'headline' => $engine . ' ' . $version . ' — a short-lived release, now unsupported',
            'detail'   => 'MySQL puts out two kinds of release. Long-term ones are supported '
                        . 'for years. Short-lived ones — and the ' . $series . ' line is one of '
                        . 'those — are replaced roughly every three months and stop receiving '
                        . 'fixes as soon as the next one arrives. The numbering does not tell '
                        . 'you which is which, which is why a higher number is not always '
                        . 'better. This portal should run on it without trouble, but MySQL '
                        . self::SUPPORTED_MYSQL . ' is a long-term release supported into 2032, '
                        . 'and is the one to ask for.',
        ];
    }

    /**
     * 🧪 Does this version string say it is a preview build?
     *
     * Both MySQL and MariaDB publish preview, alpha, beta and release-candidate
     * builds under the SAME version number as the finished release that follows.
     * So checking the number alone cannot tell a tested release from a trial one,
     * and a server running a trial build should not be told it is on something
     * supported. The words below are the markers both projects actually use.
     *
     * @param string $raw The complete version string from the server.
     *
     * @return bool True if it is marked as a pre-release.
     */
    private static function looksPreRelease(string $raw): bool
    {
        return preg_match('/(?:^|[-_.\s])(alpha|beta|rc\d*|preview|snapshot|unstable)(?:[-_.\s]|$)/i', $raw) === 1;
    }

    /**
     * 🔢 Reduce a version number to its release line — "8.0.36" becomes "8.0".
     *
     * A release line (MySQL and MariaDB both call these "series") is what
     * support dates actually attach to. Support is never promised for one exact
     * build; it is promised for the line, and every patch release within it.
     *
     * @param string $version A three-part version number.
     *
     * @return string The first two parts, or an empty string if it cannot be read.
     */
    private static function series(string $version): string
    {
        if (preg_match('/^(\d+)\.(\d+)/', $version, $m) === 1) {
            return $m[1] . '.' . $m[2];
        }
        return '';
    }

    /**
     * 🧪 The sentence about what is really tested, for one engine and line.
     *
     * Derived from TESTED_LINES, never written by hand — see that constant for
     * the two untrue sentences that made this necessary.
     *
     * @param string $family 'mysql' or 'mariadb'.
     * @param string $series The two-part release line, e.g. '11.4'.
     *
     * @return string A sentence beginning with a space, to append to `detail`.
     */
    private static function testedLinesCaveat(string $family, string $series): string
    {
        $tested = self::TESTED_LINES[$family] ?? [];
        $names  = $family === 'mariadb' ? 'MariaDB' : 'MySQL';

        if (in_array($series, $tested, true) === true) {
            // 📌 Say what was measured, never "it is supported". A customer
            //    running it in production is a different claim again, and the
            //    project has none of those yet.
            // 📌 "ITS DATABASE TESTS", not "its automated tests". The wider
            //    phrasing overstated what TESTED_LINES describes -- the
            //    migration harness alone, not the calendar self-tests, which
            //    run on their own set of engines. An independent check raised
            //    it: the sentence would have gone untrue the day those tests
            //    gained a line the harness does not run, with the hard gate
            //    still green.
            return ' This portal\'s own database tests replay every one of its database '
                 . 'changes on ' . $names . ' ' . $series . ' and check the result, so this is '
                 . 'a line the project really runs against rather than one it merely expects to '
                 . 'work. That is not the same as somebody running it in production.';
        }

        // 🛡️ A BACKSTOP, NOT A REACHABLE PATH. Neither caller can get here:
        //    both pass 'mysql' or 'mariadb' and TESTED_LINES has an entry for
        //    each. It is here so that adding a third engine without adding its
        //    row gives silence rather than a sentence naming no versions at
        //    all. An independent check asked whether it was deliberate; it is,
        //    and nothing said so.
        if (count($tested) === 0) {
            return '';
        }

        return ' Please note: this portal\'s own database tests do not run on the ' . $names
             . ' ' . $series . ' line. They run on ' . $names . ' '
             . implode(' and ', $tested) . '. Its database changes are written to a convention '
             . 'meant to work on both MySQL and MariaDB, and that convention is followed '
             . 'carefully — but no test here proves it for ' . $series . '. That is not the same '
             . 'as saying it is broken; it means nobody has checked.';
    }

    /**
     * 🔢 Read the version number out of whatever the server said.
     *
     * Returns both the number and whether the server actually stated a patch
     * level, because the two are needed together and working them out
     * separately is what caused a bug worth remembering.
     *
     * TWO TRAPS LIVE IN THIS ONE SMALL METHOD.
     *
     * The first is MariaDB's fake `5.5.5-` prefix. It is there so that very old
     * MySQL client programs, which refuse to talk to anything whose version
     * starts with "10.", will still connect. Read literally, every modern
     * MariaDB looks like ancient MySQL 5.5 and gets refused.
     *
     * The second is subtler and was live in this file until a review found it.
     * The version must be read from the FRONT of the string and nowhere else.
     * Linux distributions append their own packaging version, so a server can
     * report `11.4-MariaDB-0ubuntu0.24.04.1`. An earlier version of this method
     * looked for the first three-part number ANYWHERE in the string. There is no
     * three-part number at the front of that one — the server version is only
     * two parts — so the search ran on and found `0.24.04` in Ubuntu's packaging
     * suffix. That reads as version zero, which is below every minimum, so the
     * installer refused to install onto a perfectly good MariaDB 11.4 and gave a
     * reason that made no sense.
     *
     * Anchoring the match at the start, with the third part optional, fixes both:
     * whatever is appended afterwards cannot win, and a two-part version is read
     * as two parts instead of dragging in digits from somewhere else.
     *
     * See: https://mariadb.com/kb/en/mariadb-vs-mysql-compatibility/
     *
     * @param string $raw Whatever VERSION() returned.
     *
     * @return array{version: string, hasPatch: bool} The version as three parts
     *                                                (a missing patch becomes 0),
     *                                                and whether the patch was
     *                                                actually stated.
     */
    private static function parseVersion(string $raw): array
    {
        if (strpos($raw, '5.5.5-') === 0) {
            $raw = substr($raw, 6);
        }

        // ⚓ Anchored at the start. Any leading non-digits are skipped, but once
        //    the number is found nothing later in the string can replace it.
        if (preg_match('/^\D*(\d+)\.(\d+)(?:\.(\d+))?/', $raw, $m) !== 1) {
            return ['version' => '', 'hasPatch' => false];
        }

        $hasPatch = isset($m[3]) === true && $m[3] !== '';

        return [
            'version'  => $m[1] . '.' . $m[2] . '.' . ($hasPatch === true ? $m[3] : '0'),
            'hasPatch' => $hasPatch,
        ];
    }
}
