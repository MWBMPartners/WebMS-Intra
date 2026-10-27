<?php
// Path: tools/db-strict-mode-selftest.php
/**
 * -----------------------------------------------------------------------------
 * "The portal makes its own database connection strict" self-test 🔒🧪
 * -----------------------------------------------------------------------------
 * Proves, against a real database server that has been started LOOSE, that the
 * portal's own connections refuse a value that does not fit its column, where
 * the server left to itself would store it changed.
 *
 * WHY IT EXISTS
 * -------------
 * Until 12 October 2026 the portal ran with whatever strictness (`sql_mode`)
 * its host had chosen. On a loose host a text is cut short, a number clipped,
 * and a value that is not on a column's list of choices stored as an empty
 * one, all without an error; the expenses "Withdraw" button stored an EMPTY
 * status that way (#587). Every other test here runs on a strict server, so
 * none of them could see it. This one starts from the loose server and shows
 * the difference the portal's own code makes.
 *
 * WHAT IT CHECKS
 * --------------
 *   A  Nothing under web/ opens a database connection except
 *      Portal\Core\DbServer::connect(). A connection opened anywhere else
 *      would run loose and look fine.
 *   F  The judge (DbServer::judgeStrictness) gives the right verdict for a
 *      list of settings, with no database involved.
 *   B  THE INSTALLER'S CONNECTION, got by asking for the web folder's own
 *      entry page, which hands the request to the real installer (see "HOW
 *      THE INSTALLER IS REACHED" below), and then calling the installer's own
 *      installGetDb(): it runs under the setting the portal asks for, the
 *      install script loads through it, and four bad values are each
 *      REFUSED: a text too long, a number too large, nothing given for a
 *      column that must have a value, and a value not on a column's list of
 *      choices.
 *   S  THE INSTALLER'S STEP 2, by sending it the form as a visitor who has
 *      typed the ownership code: it accepts this loose server, which it only
 *      does when its test connection came out strict.
 *   C  THE PORTAL'S OWN START-UP (web/_core/bootstrap.php, in a separate PHP
 *      process): the same four are refused, the health check says "Strict",
 *      and nothing is written to the error log.
 *   D  THE CONTROL: a plain connection that does not go through the portal
 *      stores all four CHANGED. Without this the test could pass on a server
 *      that was strict anyway.
 *   R  THE SETTING IS REPLACED, NOT ADDED TO. The throwaway server's own
 *      setting is changed to one that is loose but NOT empty
 *      (ANSI_QUOTES,PIPES_AS_CONCAT), a new plain connection is shown to get
 *      it, and parts B and C run again: both connections must run under the
 *      portal's list and nothing else. Then the server's setting is put
 *      back. (Added 12 October 2026. On an empty setting "added to" and
 *      "replaced" give the same answer, so this test used to pass with the
 *      list added to the host's, and with the request skipped whenever the
 *      host had any setting of its own.)
 *   P  WHAT THE PAGES SAY WHEN ALL IS WELL. The admin dashboard and Admin →
 *      Server Information are asked for through the portal's own entry page,
 *      as a signed-in administrator: the dashboard shows no alert, and Server
 *      Information shows the setting read back and calls it strict.
 *   M  A SERVER THAT CANNOT BE KNOWN TO BE MYSQL. In the scratch copy only,
 *      the question only MySQL answers is changed to one no server answers,
 *      which is how a MariaDB that hides its name looks to the portal. The
 *      connection must then run WITHOUT ONLY_FULL_GROUP_BY, still refuse the
 *      four, and accept the grouping that word forbids.
 *   E1 A SERVER THAT REFUSES THE SETTING. In the scratch copy only, the lists
 *      the portal asks for are given a word no server knows, so the real
 *      server really refuses them. The portal must still start, must say so
 *      in the error log and on the health check ("NOT strict"), and the
 *      installer must refuse to go on, at step 2 and in installGetDb(). The
 *      health check, the dashboard and Server Information must each show the
 *      setting the connection is REALLY under, not the one that was asked for.
 *   E2 A SERVER THAT HAS RETIRED A NAME. Same trick, but the unknown word is
 *      on the list of names being retired: the portal's second, shorter
 *      request is accepted, the four are refused, and the health check says
 *      "strict, but not the exact setting". The health check and Server
 *      Information must show that shorter list, the one really in force.
 *
 * HOW THE INSTALLER IS REACHED (changed 13 October 2026)
 * ----------------------------------------------------
 * This test used to load web/_install/index.php directly. Since the installer
 * work of 13 October 2026 (#499, #500) the installer REFUSES that: it lets
 * itself be reached in one way only, by the web folder's own index.php
 * handing the request over when there is no database login yet, and it acts
 * on no form until the visitor has typed the ownership code it wrote into a
 * file. From that day every part of this test that touches the installer
 * failed, for a reason that had nothing to do with strictness, and the test
 * stopped early. It now does what a person's browser does:
 *   - every installer request asks for the scratch copy's
 *     public_html/index.php, which hands it to the installer;
 *   - before step 2's form is sent, the first page is asked for (the
 *     installer writes its code file and its form's token), then the code is
 *     READ FROM THAT FILE, with the installer's own reader, and sent to the
 *     installer's own form. Step 2 is sent in the session that was let in.
 *     Parts S and E1 are each let in as a new visitor (see
 *     sm_installerLetIn() for why one session for both gave a wrong answer).
 * Nothing is put into the session by this test for the installer, and the
 * installer itself is not changed. tools/installer-selftest.php is the test
 * of the code and the gate; here they are only passed through honestly.
 *
 * WHAT IT CANNOT PROVE
 * --------------------
 * - Part B calls installGetDb() on a first visit, before any code is typed.
 *   That function hands out the connection and does not ask about the code
 *   (the installer's form handling does), so nothing about strictness is
 *   lost; but it is not how step 3 would come to call it.
 * - That a page reached through a web server behaves the same. Part C loads
 *   the start-up file from the command line. Parts P and E1 load the portal's
 *   entry page from the command line too, so the router does run, but no web
 *   server, no browser, and no sign-in page: the test puts the
 *   administrator's account number into the session itself.
 * - Anything about MariaDB unless it is run against one, which no automated
 *   run does. In particular, a fault that hands MySQL's list to EVERY server
 *   passes here on MySQL, where that list is the right one. Part M covers
 *   only the server that cannot be told apart; it cannot show that a real
 *   MariaDB leaves the "only MySQL answers" question unanswered.
 * - That the connection is set to utf8mb4 by the portal. Both connections
 *   are checked for it, but a MySQL 8 server hands every connection utf8mb4
 *   anyway, so that check can only fail on a server started with another
 *   default (for example --character-set-server=latin1).
 * - Anything about a server that says yes to the setting and then ignores it.
 *   No real server here does that. The health check would report it, because
 *   it reads the setting back, but that path is only exercised by E1.
 * - Anything on a server it was not run against.
 *
 * SAFETY
 * ------
 * - It needs a THROWAWAY server whose own setting is loose (start it with
 *   --sql-mode=""). On a strict server it REFUSES, because the control could
 *   not then show anything.
 * - Part R CHANGES THAT SERVER'S OWN SETTING (SET GLOBAL sql_mode) for about
 *   a minute, so every other connection opened to it in that minute gets the
 *   odd setting. It is put back at the end of part R, and again as the test
 *   shuts down if it stopped early. It cannot be put back if the test is
 *   killed outright. Never point this at a server anything else uses.
 * - It makes its OWN two databases, SELFTEST_DB_NAME and SELFTEST_DB_NAME with
 *   `_step2` on the end, refuses if either already exists, and drops both at
 *   the end. The name must start with `selftest_`. It adds one administrator
 *   account to its own database, for the pages it reads; that goes with the
 *   database.
 * - It never reads web/_auth_keys. It copies web/ (without _auth_keys,
 *   _uploads, _backups and _libraries) into a NEW folder under
 *   SELFTEST_WEB_DIR, or the system's temporary folder, and removes that
 *   folder at the end. That folder and the repository must not contain each
 *   other.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_strict SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             SELFTEST_WEB_DIR=/a/quiet/folder php tools/db-strict-mode-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default root).
 *         The account must be allowed to create and drop databases, and to
 *         change the server's own setting (SET GLOBAL), as a throwaway
 *         server's root account is.
 * Exit:   0 when every check passes and the run reached its end; 1 otherwise,
 *         including when it refuses: a test that did not run proved nothing.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.2.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/587
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/593
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const SM_EXPECTED_CHECKS = 134;

/** The line a child process prints its findings on. */
const SM_RESULT_MARK = 'SELFTEST-RESULT:';

/** A mode name no database server knows, planted in the scratch copy for parts E1 and E2. */
const SM_UNKNOWN_WORD = 'SELFTEST_WORD_NO_SERVER_KNOWS';

/**
 * The setting part R gives the throwaway server: loose (neither strict word),
 * but NOT empty, and made of two words that change how a statement is read.
 */
const SM_ODD_SERVER_MODE = ['ANSI_QUOTES', 'PIPES_AS_CONCAT'];

/** The two pages parts P and E1 read, by the address a browser would ask for. */
const SM_PAGE_DASHBOARD   = '/admin';
const SM_PAGE_SERVER_INFO = '/admin/system-info';

/**
 * A grouping that the word ONLY_FULL_GROUP_BY forbids (error 1055) and that
 * every server takes without it: it reads a column that is neither grouped by
 * nor decided by what is. It is how this test SEES whether that word is in
 * force, rather than taking the setting's own text for it.
 */
const SM_GROUPING = 'SELECT id, COUNT(*) FROM `%s` GROUP BY shortText';

/**
 * The four bad values. Each has: the statement, its values, and the number the
 * database gives when it refuses (the same four numbers on MySQL and MariaDB).
 */
const SM_BAD_VALUES = [
    'a text too long for its column' => [
        'sql' => 'INSERT INTO `%s` (id, shortText, smallNumber, choice, mustHave) VALUES (1, ?, 1, \'draft\', \'x\')',
        'value' => 'abcdefghij', 'refusal' => 1406,
    ],
    'a number too large for its column' => [
        'sql' => 'INSERT INTO `%s` (id, shortText, smallNumber, choice, mustHave) VALUES (2, \'ok\', ?, \'draft\', \'x\')',
        'value' => '70000', 'refusal' => 1264,
    ],
    'nothing given for a column that must have a value' => [
        'sql' => 'INSERT INTO `%s` (id, shortText, smallNumber, choice) VALUES (3, ?, 1, \'draft\')',
        'value' => 'ok', 'refusal' => 1364,
    ],
    'a value not on the column\'s list of choices' => [
        'sql' => 'INSERT INTO `%s` (id, shortText, smallNumber, choice, mustHave) VALUES (4, \'ok\', 1, ?, \'x\')',
        'value' => 'withdrawn', 'refusal' => 1265,
    ],
];

/**
 * Try the four bad values on one connection.
 *
 * @return array<string, array{refused: bool, code: int, message: string}>
 */
function sm_tryFour(mysqli $db, string $table): array
{
    $out = [];
    foreach (SM_BAD_VALUES as $label => $case) {
        try {
            $stmt  = $db->prepare(sprintf($case['sql'], $table));
            $value = $case['value'];
            $stmt->bind_param('s', $value);
            $stmt->execute();
            $stmt->close();
            $out[$label] = ['refused' => false, 'code' => 0, 'message' => ''];
        } catch (mysqli_sql_exception $e) {
            $out[$label] = ['refused' => true, 'code' => (int) $e->getCode(), 'message' => $e->getMessage()];
        }
    }

    return $out;
}

/** The setting a connection is running under, read straight from the database. */
function sm_mode(mysqli $db): string
{
    $rs   = $db->query('SELECT @@SESSION.sql_mode');
    $mode = (string) ($rs->fetch_row()[0] ?? '');
    $rs->free();

    return $mode;
}

/**
 * Change the server's OWN setting, the one every NEW connection starts with.
 * Only ever given this file's own words or what the server itself reported,
 * and sent as a bound value all the same.
 */
function sm_setServerMode(mysqli $plain, string $mode): void
{
    $stmt = $plain->prepare('SET GLOBAL sql_mode = ?');
    $stmt->bind_param('s', $mode);
    $stmt->execute();
    $stmt->close();
}

/** The connection details every process in this test uses, from the environment. */
function sm_creds(): array
{
    return [
        'host' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
        'user' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
        'pass' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
        'name' => (string) getenv('SELFTEST_DB_NAME'),
        'port' => getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306,
    ];
}

// =============================================================================
// 🧒 THE CHILD PROCESSES
// =============================================================================
// This same file is run again, as `php db-strict-mode-selftest.php --child
// <which> <scratch web folder>`, for each piece of portal code under test. The
// portal's start-up file and the installer both define constants and functions
// that can only be defined once, so each needs a PHP process of its own. They
// run at the TOP LEVEL of this file, not inside a function, because that is
// where the portal's own entry page loads its start-up file from.
if (($argv[1] ?? '') === '--child') {
    $smKind = (string) ($argv[2] ?? '');
    $smWeb  = (string) ($argv[3] ?? '');
    $smOut  = [];

    if ($smKind === 'portal') {
        // 🚪 The portal's own start-up. It reads the scratch copy's connection
        //    file and leaves the connection in $mysqli.
        require $smWeb . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

        $smOut['sameConnection'] = \Portal\Core\App::db() === $mysqli;
        $smOut['mode']           = sm_mode($mysqli);
        $smOut['strictness']     = \Portal\Core\DbServer::strictness($mysqli);
        require_once PORTAL_APPS . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . '_health-probes.php';
        $smHealth         = maintenance_health_probes($mysqli);
        $smOut['probe']   = $smHealth['probes']['Database strictness'] ?? null;
        $smOut['overall'] = $smHealth['overall'];
        $smOut['charset'] = $mysqli->character_set_name();
        $smOut['four']    = sm_tryFour($mysqli, (string) getenv('SELFTEST_PROBE_TABLE'));
        try {
            $mysqli->query(sprintf(SM_GROUPING, (string) getenv('SELFTEST_PROBE_TABLE')))->free();
            $smOut['grouping'] = 0;
        } catch (mysqli_sql_exception $e) {
            $smOut['grouping'] = (int) $e->getCode();
        }
    } elseif ($smKind === 'page') {
        // 📄 One real page, asked for the way the web server asks: through the
        //    portal's own entry page, so start-up, the router and the page all
        //    run. The one thing faked is the sign-in: the session is started
        //    here and given the administrator's account number, which is all
        //    the portal looks for (Auth::check()).
        //
        //    The findings are printed as PHP shuts down, because a page is
        //    free to stop the process itself. `finished` is set only when the
        //    entry page returned, so a page that died half way is not mistaken
        //    for one that had nothing to say.
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = (string) getenv('SELFTEST_PAGE');
        $_SERVER['HTTP_HOST']      = 'localhost';
        $smPage = ['finished' => false];
        register_shutdown_function(static function () use (&$smPage): void {
            $html = '';
            while (ob_get_level() > 0) {
                $html = (string) ob_get_clean() . $html;
            }
            echo "\n" . SM_RESULT_MARK . json_encode(
                ['finished' => $smPage['finished'], 'status' => http_response_code(), 'html' => $html],
                JSON_INVALID_UTF8_SUBSTITUTE
            ) . "\n";
        });
        ob_start();
        session_start();
        $_SESSION['user_id'] = (int) getenv('SELFTEST_ADMIN_ID');
        require $smWeb . DIRECTORY_SEPARATOR . 'public_html' . DIRECTORY_SEPARATOR . 'index.php';
        $smPage['finished'] = true;
        exit(0);
    } elseif ($smKind === 'installer-connection') {
        // 🧙 The real wizard, for its own installGetDb(), reached the ONE way
        //    it lets itself be reached: the web folder's entry page finds no
        //    database login and hands the request over. That page stops the
        //    process when the wizard is done, so the work is done as PHP
        //    shuts down, when the wizard's functions exist.
        //
        //    This used to say `require …/_install/index.php`. The wizard
        //    looks at which file loaded it, and since 13 October 2026 it
        //    answers "the website is pointed at the wrong folder" to any
        //    file that is not in a web folder of its own installation.
        //    `reached` below is false when that happens, so that refusal can
        //    never again be mistaken for "no connection".
        $_SERVER['REQUEST_METHOD'] = 'GET';
        register_shutdown_function(static function (): void {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $smOut = ['reached' => defined('INSTALL_WEB_FOLDER') === true && INSTALL_WEB_FOLDER !== null && function_exists('installGetDb') === true];
            if ($smOut['reached'] === true) {
                try {
                    // What step 2 keeps for the later steps, and nothing else.
                    $_SESSION['install_db'] = sm_creds();
                    $smDb = installGetDb();
                    $smOut['connection'] = $smDb !== null;
                    if ($smDb !== null) {
                        $smOut['mode']       = sm_mode($smDb);
                        $smOut['charset']    = $smDb->character_set_name();
                        $smOut['strictness'] = \Portal\Core\DbServer::strictness($smDb);

                        if ((string) getenv('SELFTEST_LOAD_SCHEMA') === '1') {
                            // 🏗️ The install script, through the installer's connection,
                            //    the same way step 3 sends it: the whole file in one go.
                            $smOut['schemaError'] = '';
                            try {
                                $smDb->multi_query((string) file_get_contents(INSTALL_SQL . DIRECTORY_SEPARATOR . 'full_schema.sql'));
                                do {
                                    $smResult = $smDb->store_result();
                                    if ($smResult !== false) {
                                        $smResult->free();
                                    }
                                } while ($smDb->more_results() === true && $smDb->next_result());
                            } catch (mysqli_sql_exception $e) {
                                $smOut['schemaError'] = $e->getCode() . ': ' . $e->getMessage();
                            }
                            // Read on a NEW plain connection: after a refusal part way
                            // through, the installer's own may still hold unread answers.
                            $smCreds = sm_creds();
                            $smCount = new mysqli($smCreds['host'], $smCreds['user'], $smCreds['pass'], $smCreds['name'], $smCreds['port']);
                            $smOut['tables'] = (int) $smCount->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetch_row()[0];
                            $smCount->close();
                        }
                        if (($smOut['schemaError'] ?? '') === '') {
                            $smOut['four'] = sm_tryFour($smDb, (string) getenv('SELFTEST_PROBE_TABLE'));
                        }
                    }
                } catch (\Throwable $e) {
                    // Nothing may be thrown out of a shutdown function: PHP would
                    // print it in its own words and the findings would be lost.
                    $smOut['stopped'] = get_class($e) . ': ' . $e->getMessage();
                }
            }
            echo "\n" . SM_RESULT_MARK . json_encode($smOut) . "\n";
        });
        ob_start();
        require $smWeb . DIRECTORY_SEPARATOR . 'public_html' . DIRECTORY_SEPARATOR . 'index.php';
        exit(0);
    } elseif ($smKind === 'installer-visit') {
        // 👋 A first visit, as a browser makes it: the installer writes its
        //    code file, starts a session and draws the page that asks for the
        //    code. What a browser would keep is handed back: the session's
        //    number (its cookie) and the token the form carries.
        $_SERVER['REQUEST_METHOD'] = 'GET';
        register_shutdown_function(static function (): void {
            $html = '';
            while (ob_get_level() > 0) {
                $html = (string) ob_get_clean() . $html;
            }
            echo "\n" . SM_RESULT_MARK . json_encode([
                'reached'      => defined('INSTALL_WEB_FOLDER') === true && INSTALL_WEB_FOLDER !== null,
                'askedForCode' => str_contains($html, 'name="owner_code"'),
                'letIn'        => function_exists('installOwnerPassed') === true && installOwnerPassed() === true,
                'session'      => (string) session_id(),
                'token'        => is_string($_SESSION['install_form_token'] ?? null) === true ? $_SESSION['install_form_token'] : '',
            ]) . "\n";
        });
        ob_start();
        require $smWeb . DIRECTORY_SEPARATOR . 'public_html' . DIRECTORY_SEPARATOR . 'index.php';
        exit(0);
    } elseif ($smKind === 'installer-code') {
        // 🔑 Type the ownership code. It is read from the file the installer
        //    wrote, with the installer's own reader (which waits for the
        //    writer's lock), exactly as a person opens the file and copies
        //    it; then it goes to the installer's own form, in the session of
        //    the first visit, with that form's token. On the right code the
        //    installer gives the session a new number and stops the process.
        require_once $smWeb . DIRECTORY_SEPARATOR . '_install' . DIRECTORY_SEPARATOR . 'owner_code.php';
        $smCode = installCodeRead($smWeb . DIRECTORY_SEPARATOR . '_auth_keys');
        session_id((string) getenv('SELFTEST_SESSION'));
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'action'        => 'owner_code',
            'owner_code'    => (string) ($smCode['code'] ?? ''),
            'install_token' => (string) getenv('SELFTEST_TOKEN'),
        ];
        register_shutdown_function(static function () use ($smCode): void {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            echo "\n" . SM_RESULT_MARK . json_encode([
                'codeRead' => $smCode !== null,
                'letIn'    => function_exists('installOwnerPassed') === true && installOwnerPassed() === true,
                'session'  => (string) session_id(),
                'error'    => (string) ($GLOBALS['error'] ?? ''),
            ]) . "\n";
        });
        ob_start();
        require $smWeb . DIRECTORY_SEPARATOR . 'public_html' . DIRECTORY_SEPARATOR . 'index.php';
        exit(0);
    } elseif ($smKind === 'installer-step2') {
        // 📮 Send step 2 its form, in the session that was let in, with the
        //    form's token. On success the wizard redirects and stops the
        //    process itself, so the findings are printed as PHP shuts down.
        //    `letIn` says whether the installer still counted this visitor as
        //    let in: if not, it acted on nothing, and "no error" would mean
        //    nothing.
        $smCreds = sm_creds();
        session_id((string) getenv('SELFTEST_SESSION'));
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'action'        => 'db_config',
            'step'          => '2',
            'install_token' => (string) getenv('SELFTEST_TOKEN'),
            'db_host'       => $smCreds['host'],
            'db_user'       => $smCreds['user'],
            'db_pass'       => $smCreds['pass'],
            'db_name'       => (string) getenv('SELFTEST_STEP2_DB'),
            'db_port'       => (string) $smCreds['port'],
        ];
        register_shutdown_function(static function (): void {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            echo "\n" . SM_RESULT_MARK . json_encode([
                'letIn'       => function_exists('installOwnerPassed') === true && installOwnerPassed() === true,
                'keptDetails' => isset($_SESSION['install_db']),
                'error'       => (string) ($GLOBALS['error'] ?? ''),
            ]) . "\n";
        });
        ob_start();
        require $smWeb . DIRECTORY_SEPARATOR . 'public_html' . DIRECTORY_SEPARATOR . 'index.php';
        exit(0);
    } else {
        echo "unknown child kind\n";
        exit(1);
    }

    echo "\n" . SM_RESULT_MARK . json_encode($smOut) . "\n";
    exit(0);
}

// =============================================================================
// 🧪 THE TEST ITSELF
// =============================================================================

$smState = ['checks' => 0, 'failures' => 0, 'child' => null, 'token' => bin2hex(random_bytes(16)), 'databases' => [], 'plain' => null, 'putBackMode' => null];

function sm_refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

/** Record one check. */
function sm_check(string $label, bool $ok, string $detail = ''): void
{
    global $smState;
    $smState['checks']++;
    if ($ok === true) {
        echo 'PASS — ' . $label . "\n";
        return;
    }
    $smState['failures']++;
    echo 'FAIL — ' . $label . ($detail !== '' ? ' (' . $detail . ')' : '') . "\n";
}

/** Remove a folder this run made. Only ever called on the scratch folder. */
function sm_removeTree(string $dir): void
{
    $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($tree as $path => $info) {
        if ($info->isDir() === true && $info->isLink() === false) {
            rmdir((string) $path);
        } else {
            unlink((string) $path);
        }
    }
    rmdir($dir);
}

// 🧹 Whatever happens, drop this run's databases and remove its scratch folder.
//    The folder is removed only when it still holds the marker this run wrote
//    in it first, so nothing else can ever be deleted from here.
register_shutdown_function(static function () use (&$smState): void {
    // 🔙 The server's own setting, if part R changed it and the run stopped
    //    before part R could put it back.
    try {
        if ($smState['plain'] instanceof mysqli && is_string($smState['putBackMode']) === true) {
            sm_setServerMode($smState['plain'], $smState['putBackMode']);
            echo "NOTE — the run stopped early; the server's own sql_mode was put back to [" . $smState['putBackMode'] . "].\n";
        }
    } catch (Throwable $e) {
        echo 'WARNING — the server\'s own sql_mode could NOT be put back to [' . $smState['putBackMode'] . ']: ' . $e->getMessage() . "\n";
    }
    try {
        if ($smState['plain'] instanceof mysqli) {
            foreach ($smState['databases'] as $name) {
                $smState['plain']->query('DROP DATABASE IF EXISTS `' . $name . '`');
            }
        }
    } catch (Throwable $e) {
        echo 'NOTE — could not drop this run\'s databases: ' . $e->getMessage() . "\n";
    }
    $child = $smState['child'];
    if (is_string($child) === true && is_dir($child) === true
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.db-strict-mode-selftest-owner') === $smState['token']) {
        sm_removeTree($child);
    }
});

// Anything thrown outside a check would end PHP with code 255 and a stack
// trace; the header promises 0 or 1.
set_exception_handler(static function (Throwable $e): void {
    echo 'FAIL — the self-test stopped early: ' . get_class($e) . ': ' . $e->getMessage() . ' (line ' . $e->getLine() . ")\n";
    exit(1);
});

// ---- Guards -----------------------------------------------------------------
$smCreds = sm_creds();
$smName  = $smCreds['name'];
if (str_starts_with($smName, 'selftest_') === false || preg_match('/\A[A-Za-z0-9_]{1,50}\z/', $smName) !== 1) {
    sm_refuse("set SELFTEST_DB_NAME to a name that starts with selftest_ and holds only letters, digits and underscores (got '{$smName}'). This test makes and drops that database");
}
if (function_exists('proc_open') === false) {
    sm_refuse('this PHP has no proc_open()');
}
$smRepo      = (string) realpath(dirname(__DIR__));
$smWebSrc    = $smRepo . DIRECTORY_SEPARATOR . 'web';
$smParentRaw = getenv('SELFTEST_WEB_DIR');
$smParent    = realpath(($smParentRaw === false || $smParentRaw === '') ? sys_get_temp_dir() : (string) $smParentRaw);
if ($smParent === false || is_dir($smParent) === false) {
    sm_refuse('the scratch parent folder (SELFTEST_WEB_DIR) does not exist');
}
if (str_starts_with($smRepo . DIRECTORY_SEPARATOR, $smParent . DIRECTORY_SEPARATOR) === true
    || str_starts_with($smParent . DIRECTORY_SEPARATOR, $smRepo . DIRECTORY_SEPARATOR) === true) {
    sm_refuse("the scratch parent folder '{$smParent}' and the repository must not contain each other; choose another with SELFTEST_WEB_DIR");
}

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

// 🔌 THE PLAIN CONNECTION. Opened here with `new mysqli`, on purpose: it is the
//    control, and the independent witness that reads back what each part
//    really stored. It must never go through the portal's code.
$smPlain = new mysqli($smCreds['host'], $smCreds['user'], $smCreds['pass'], '', $smCreds['port']);
$smPlain->set_charset('utf8mb4');
$smState['plain'] = $smPlain;

$smVersion    = (string) $smPlain->query('SELECT VERSION()')->fetch_row()[0];
$smGlobalMode = (string) $smPlain->query('SELECT @@GLOBAL.sql_mode')->fetch_row()[0];
echo "Server: {$smVersion}; the server's own sql_mode: [{$smGlobalMode}]; this connection's: [" . sm_mode($smPlain) . "]\n";
if (str_contains($smGlobalMode, 'STRICT_') === true || str_contains(sm_mode($smPlain), 'STRICT_') === true) {
    sm_refuse('this server is strict by itself, so the control could show nothing. Start a throwaway server with --sql-mode=""');
}

// ✍️ What the portal should ask THIS server for, written out by hand here and
//    never read from the class being tested.
$smIsMaria  = stripos($smVersion, 'mariadb') !== false;
$smExpected = $smIsMaria === true
    ? ['STRICT_TRANS_TABLES', 'NO_ZERO_IN_DATE', 'NO_ZERO_DATE', 'ERROR_FOR_DIVISION_BY_ZERO', 'NO_ENGINE_SUBSTITUTION']
    : ['ONLY_FULL_GROUP_BY', 'STRICT_TRANS_TABLES', 'NO_ZERO_IN_DATE', 'NO_ZERO_DATE', 'ERROR_FOR_DIVISION_BY_ZERO', 'NO_ENGINE_SUBSTITUTION'];

/** Are two settings the same words, in any order? */
function sm_sameWords(string $mode, array $expected): bool
{
    $have = array_values(array_filter(explode(',', $mode), static fn (string $w): bool => $w !== ''));
    sort($have);
    sort($expected);

    return $have === $expected;
}

$smStep2Name = $smName . '_step2';
foreach ([$smName, $smStep2Name] as $smOne) {
    $smExists = (int) $smPlain->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '" . $smOne . "'")->fetch_row()[0];
    if ($smExists !== 0) {
        sm_refuse("the database '{$smOne}' already exists; this test only ever makes its own");
    }
}
// No collation named: the database takes the server's own default.
$smPlain->query('CREATE DATABASE `' . $smName . '`');
$smState['databases'] = [$smName, $smStep2Name];
$smPlain->select_db($smName);

/** A fresh, empty table for one part's four bad values. */
function sm_probeTable(mysqli $plain, string $suffix): string
{
    $table = 'selftest_strict_probe_' . $suffix;
    $plain->query('CREATE TABLE `' . $table . '` ('
        . 'id INT NOT NULL PRIMARY KEY, '
        . 'shortText VARCHAR(5) NOT NULL, '
        . 'smallNumber SMALLINT NOT NULL, '
        . "choice ENUM('draft','sent') NOT NULL, "
        . 'mustHave VARCHAR(10) NOT NULL'
        . ') ENGINE=InnoDB');

    return $table;
}

/** What a probe table holds, as the plain connection sees it. */
function sm_stored(mysqli $plain, string $table): array
{
    return $plain->query('SELECT id, shortText, smallNumber, choice, mustHave FROM `' . $table . '` ORDER BY id')->fetch_all(MYSQLI_NUM);
}

// ---- The scratch copy of web/ ------------------------------------------------
$smSep   = DIRECTORY_SEPARATOR;
$smChild = $smParent . $smSep . 'db-strict-mode-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($smChild) === true || @mkdir($smChild, 0700) === false) {
    sm_refuse("could not make a NEW scratch folder '{$smChild}'");
}
$smState['child'] = $smChild;
file_put_contents($smChild . $smSep . '.db-strict-mode-selftest-owner', $smState['token']); // the marker FIRST
$smWeb = $smChild . $smSep . 'web';
mkdir($smWeb, 0700);
$smCopied = 0;
$smTree   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($smWebSrc, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($smTree as $smPath => $smInfo) {
    $smRel = substr((string) $smPath, strlen($smWebSrc) + 1);
    if (in_array(explode($smSep, $smRel)[0], ['_auth_keys', '_uploads', '_backups', '_libraries'], true) === true) {
        continue;
    }
    if ($smInfo->isLink() === true) {
        sm_refuse('web/ holds a link (' . $smRel . '); refusing to copy it');
    }
    if ($smInfo->isDir() === true) {
        mkdir($smWeb . $smSep . $smRel, 0700);
    } else {
        copy((string) $smPath, $smWeb . $smSep . $smRel);
        $smCopied++;
    }
}
if ($smCopied < 100) {
    sm_refuse("the copy of web/ holds only {$smCopied} files");
}
mkdir($smWeb . $smSep . '_auth_keys', 0700);
mkdir($smChild . $smSep . 'sessions', 0700);
file_put_contents($smWeb . $smSep . '_auth_keys' . $smSep . 'enc.key', bin2hex(random_bytes(32)));
$smCredsFile = $smWeb . $smSep . '_auth_keys' . $smSep . 'auth_creds.php';
$smErrorLog  = $smChild . $smSep . 'php-error.log';
$smDbServer  = $smWeb . $smSep . '_core' . $smSep . 'DbServer.php';
$smPristine  = (string) file_get_contents($smDbServer);

/**
 * Run one child process and hand back what it found.
 *
 * The portal's start-up needs the connection file (so do the pages, which
 * start the portal); the installer refuses to run when that file exists
 * ("already installed"). So it is written for one and removed for the other,
 * every time.
 *
 * @return array{found: array|null, output: string, log: string}
 */
function sm_child(string $kind, array $env = []): array
{
    global $smWeb, $smCredsFile, $smErrorLog, $smChild, $smCreds;
    if ($kind === 'portal' || $kind === 'page') {
        $forPortal = ['db_host' => $smCreds['host'], 'db_user' => $smCreds['user'], 'db_pass' => $smCreds['pass'], 'db_name' => $smCreds['name'], 'db_port' => $smCreds['port']];
        file_put_contents($smCredsFile, "<?php\n// Written by tools/db-strict-mode-selftest.php for one run; deleted with its folder.\nreturn " . var_export($forPortal, true) . ";\n");
    } elseif (is_file($smCredsFile) === true) {
        unlink($smCredsFile);
    }
    file_put_contents($smErrorLog, '');

    $command = [
        PHP_BINARY, '-d', 'log_errors=1', '-d', 'error_log=' . $smErrorLog,
        '-d', 'session.save_path=' . $smChild . DIRECTORY_SEPARATOR . 'sessions',
        __FILE__, '--child', $kind, $smWeb,
    ];
    // 🧭 The child is told which release channel it is: live. Without that,
    //    the scratch copy has neither the PORTAL_ENV setting nor a `.channel`
    //    file, and the child is a command-line program, so the portal cannot
    //    go by the web folder's name either and would ASSUME it is live
    //    (web/_core/Channel.php). The answer would be the same; this is set
    //    so the test says what it runs as instead of leaning on a last
    //    resort.
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, array_merge(getenv(), ['PORTAL_ENV' => 'prod'], $env));
    if (is_resource($process) === false) {
        return ['found' => null, 'output' => 'the child process could not be started', 'log' => ''];
    }
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($process);

    $found = null;
    $at    = strrpos($output, SM_RESULT_MARK);
    if ($at !== false) {
        $line  = strtok(substr($output, $at + strlen(SM_RESULT_MARK)), "\n");
        $found = json_decode((string) $line, true);
    }

    return ['found' => is_array($found) === true ? $found : null, 'output' => $output, 'log' => (string) file_get_contents($smErrorLog)];
}

/** The last few hundred characters of a child's output, for a failure message. */
function sm_tail(array $run): string
{
    return 'child said: ' . substr(trim(preg_replace('/\s+/', ' ', strip_tags($run['output'])) ?? ''), -300);
}

/** Check that all four bad values were refused, each for its own reason, and that nothing was stored. */
function sm_checkRefused(string $who, ?array $four, mysqli $plain, string $table): void
{
    foreach (SM_BAD_VALUES as $label => $case) {
        $got = $four[$label] ?? null;
        sm_check(
            $who . ': ' . $label . ' is REFUSED (error ' . $case['refusal'] . ')',
            is_array($got) === true && $got['refused'] === true && $got['code'] === $case['refusal'],
            $got === null ? 'not tried' : ($got['refused'] === true ? 'refused with ' . $got['code'] . ': ' . $got['message'] : 'the database took it')
        );
    }
    $stored = sm_stored($plain, $table);
    sm_check($who . ': nothing was stored', $stored === [], json_encode($stored));
}

/** What a loose connection turns the four bad values into. */
const SM_STORED_CHANGED = [
    ['1', 'abcde', '1', 'draft', 'x'],
    ['2', 'ok', '32767', 'draft', 'x'],
    ['3', 'ok', '1', 'draft', ''],
    ['4', 'ok', '1', '', 'x'],
];

// ---- Part A: one place opens connections --------------------------------------
echo "\nPart A — nothing under web/ opens a database connection except DbServer::connect()\n";
$smOpeners = [];
$smFiles   = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($smWebSrc, FilesystemIterator::SKIP_DOTS)) as $smPath => $smInfo) {
    if ($smInfo->isFile() === false || str_ends_with((string) $smPath, '.php') === false) {
        continue;
    }
    // The four folders a server fills in for itself hold no portal code.
    if (in_array(explode($smSep, substr((string) $smPath, strlen($smWebSrc) + 1))[0], ['_auth_keys', '_uploads', '_backups', '_libraries'], true) === true) {
        continue;
    }
    $smFiles++;
    // Code only: comments, text in quotes and page markup are left out, so a
    // sentence that mentions `new mysqli` does not count.
    $smCode = '';
    foreach (token_get_all((string) file_get_contents((string) $smPath)) as $smToken) {
        if (is_array($smToken) === true) {
            if (in_array($smToken[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true) === true) {
                continue;
            }
            $smCode .= $smToken[1];
        } else {
            $smCode .= $smToken;
        }
    }
    $smHits = preg_match_all('/\bnew\s+\\\\?(mysqli|PDO)\b|\bmysqli_(connect|init|real_connect)\s*\(|->\s*real_connect\s*\(/i', $smCode);
    if ($smHits > 0) {
        $smOpeners[substr((string) $smPath, strlen($smWebSrc) + 1)] = $smHits;
    }
}
sm_check('the search really read the portal\'s PHP files (' . $smFiles . ')', $smFiles > 500);
sm_check(
    'exactly one place opens a connection, and it is _core/DbServer.php',
    $smOpeners === ['_core' . $smSep . 'DbServer.php' => 1],
    json_encode($smOpeners)
);

// ---- Part F: the judge, with no database ---------------------------------------
echo "\nPart F — the judge gives the right verdict for each setting\n";
require $smWebSrc . $smSep . '_core' . $smSep . 'DbServer.php';
$smWantedText = implode(',', $smExpected);
foreach ([
    'exactly what was asked for, in the database\'s own order' => [implode(',', array_reverse($smExpected)), 'ok'],
    'an empty setting'                                         => ['', 'crit'],
    'a setting with no strict word in it'                      => ['NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY', 'crit'],
    'strict, with one word missing'                            => [implode(',', array_diff($smExpected, ['NO_ZERO_DATE'])), 'warn'],
    'strict, with a word nobody asked for'                     => [$smWantedText . ',ANSI_QUOTES', 'warn'],
    'the other strict word only'                               => ['STRICT_ALL_TABLES', 'warn'],
    'a database that would not say'                            => [null, 'warn'],
] as $smLabel => [$smGiven, $smVerdict]) {
    $smJudged = \Portal\Core\DbServer::judgeStrictness($smGiven, $smWantedText);
    sm_check('the judge says "' . $smVerdict . '" for ' . $smLabel, $smJudged['state'] === $smVerdict, 'said ' . $smJudged['state']);
}

/**
 * 🔑 Be let in to the installer the way a person is: ask for its first page,
 * then type the code it wrote into its file. Two checks.
 *
 * Each call is a NEW visitor with a new session, on purpose. Part E1 sends
 * step 2 a second time and looks at whether the installer KEPT the database
 * details it was given; in the session part S used, the details of part S's
 * accepted database are still there, so a refusal that kept nothing would
 * look as if it had kept something. (The first version of this repair used
 * one session for both and failed on exactly that, on its first run.)
 *
 * @param string $for Which part is asking, for the check's wording.
 *
 * @return array{SELFTEST_SESSION: string, SELFTEST_TOKEN: string} What a
 *         browser would carry to the next request: the session's number and
 *         the form's token. Handed to the step-2 child as it stands.
 */
function sm_installerLetIn(string $for): array
{
    $run   = sm_child('installer-visit');
    $visit = $run['found'];
    sm_check(
        'for ' . $for . ': a first visit reached the installer through the web folder\'s own entry page, and was asked for the ownership code',
        ($visit['reached'] ?? false) === true && ($visit['askedForCode'] ?? false) === true && ($visit['letIn'] ?? true) === false
            && strlen((string) ($visit['token'] ?? '')) === 64 && (string) ($visit['session'] ?? '') !== '',
        sm_tail($run)
    );
    $run   = sm_child('installer-code', ['SELFTEST_SESSION' => (string) ($visit['session'] ?? ''), 'SELFTEST_TOKEN' => (string) ($visit['token'] ?? '')]);
    $typed = $run['found'];
    sm_check(
        'for ' . $for . ': the code in the file the installer wrote, typed into its own form, let this visitor in',
        ($typed['codeRead'] ?? false) === true && ($typed['letIn'] ?? false) === true && (string) ($typed['session'] ?? '') !== '',
        (string) ($typed['error'] ?? '') !== '' ? (string) $typed['error'] : sm_tail($run)
    );

    return ['SELFTEST_SESSION' => (string) ($typed['session'] ?? ''), 'SELFTEST_TOKEN' => (string) ($visit['token'] ?? '')];
}

// ---- Parts B and C as functions: part R runs both a second time ----------------

/**
 * Part B's checks, on the installer's own connection.
 *
 * @param string $suffix     Names this run's probe table.
 * @param bool   $loadSchema Also load the install script through it. Done the
 *                           first time only: it is slow, and the second time
 *                           the tables are already there.
 */
function sm_installerConnectionChecks(string $suffix, bool $loadSchema): void
{
    global $smPlain, $smExpected;
    $table = sm_probeTable($smPlain, $suffix);
    $run   = sm_child('installer-connection', ['SELFTEST_PROBE_TABLE' => $table, 'SELFTEST_LOAD_SCHEMA' => $loadSchema === true ? '1' : '0']);
    $found = $run['found'];
    sm_check('the installer was reached through the web folder\'s own entry page (it refuses any other way in)', ($found['reached'] ?? false) === true, sm_tail($run));
    sm_check('the installer handed over a connection', ($found['connection'] ?? false) === true, (string) ($found['stopped'] ?? sm_tail($run)));
    sm_check('it runs under the setting the portal asks for, and nothing else', sm_sameWords((string) ($found['mode'] ?? ''), $smExpected), 'running under [' . ($found['mode'] ?? '?') . ']');
    sm_check('it talks utf8mb4', ($found['charset'] ?? '') === 'utf8mb4', 'talks ' . ($found['charset'] ?? '?'));
    sm_check('DbServer judges it "ok"', ($found['strictness']['state'] ?? '') === 'ok', (string) ($found['strictness']['headline'] ?? '?'));
    if ($loadSchema === true) {
        sm_check('the install script loads through it with nothing refused', ($found['schemaError'] ?? 'not run') === '' && ($found['tables'] ?? 0) > 150, 'error [' . ($found['schemaError'] ?? 'not run') . '], tables ' . ($found['tables'] ?? 0));
    }
    sm_checkRefused('installer', $found['four'] ?? null, $smPlain, $table);
    sm_check('nothing about the setting was written to the error log', str_contains($run['log'], 'strictness the portal asks for') === false, $run['log']);
}

/**
 * Part C's checks, on the portal's own start-up.
 *
 * @param string $suffix Names this run's probe table.
 */
function sm_portalChecks(string $suffix): void
{
    global $smPlain, $smExpected, $smIsMaria;
    $table = sm_probeTable($smPlain, $suffix);
    $run   = sm_child('portal', ['SELFTEST_PROBE_TABLE' => $table]);
    $found = $run['found'];
    sm_check('the portal started and answered', $found !== null, sm_tail($run));
    sm_check('the connection the portal hands its pages is the one start-up opened', ($found['sameConnection'] ?? false) === true);
    sm_check('it runs under the setting the portal asks for, and nothing else', sm_sameWords((string) ($found['mode'] ?? ''), $smExpected), 'running under [' . ($found['mode'] ?? '?') . ']');
    sm_check('it talks utf8mb4', ($found['charset'] ?? '') === 'utf8mb4', 'talks ' . ($found['charset'] ?? '?'));
    sm_check('DbServer judges it "ok"', ($found['strictness']['state'] ?? '') === 'ok', (string) ($found['strictness']['headline'] ?? '?'));
    sm_check('the health check says "Strict", in green', ($found['probe']['state'] ?? '') === 'ok' && ($found['probe']['label'] ?? '') === 'Strict', json_encode($found['probe'] ?? null));
    sm_check('the health check shows the setting it read back', ($found['mode'] ?? '') !== '' && str_contains((string) ($found['probe']['detail'] ?? ''), 'running under: ' . $found['mode']) === true, (string) ($found['probe']['detail'] ?? ''));
    // 🧮 ONLY_FULL_GROUP_BY is asked of a server known to be MySQL, and of no
    //    other. This sees the word at work, not just its name in a list.
    sm_check(
        $smIsMaria === true
            ? 'the grouping ONLY_FULL_GROUP_BY forbids is TAKEN (a MariaDB is not asked for that word)'
            : 'the grouping ONLY_FULL_GROUP_BY forbids is REFUSED (error 1055): this server is known to be MySQL',
        ($found['grouping'] ?? -1) === ($smIsMaria === true ? 0 : 1055),
        'the database answered ' . json_encode($found['grouping'] ?? null) . ' (0 means it took it)'
    );
    sm_checkRefused('portal', $found['four'] ?? null, $smPlain, $table);
    sm_check('nothing about the setting was written to the error log', str_contains($run['log'], 'strictness the portal asks for') === false, $run['log']);
}

/**
 * Ask for one page as the signed-in administrator, through the portal's own
 * entry page.
 *
 * `whole` is the guard against a page that died part way: a page that stopped
 * early shows no alert either, and must not pass as "no alert was shown". It
 * is true only when the answer was not an error and the page's last tag is
 * there. (From the command line PHP reports no status at all for an ordinary
 * answer, which is why "none" is accepted beside 200.)
 *
 * @return array{whole: bool, html: string, why: string}
 */
function sm_page(string $address): array
{
    global $smAdminId;
    $run    = sm_child('page', ['SELFTEST_PAGE' => $address, 'SELFTEST_ADMIN_ID' => (string) $smAdminId]);
    $found  = $run['found'] ?? [];
    $html   = (string) ($found['html'] ?? '');
    $status = $found['status'] ?? 'none read';

    return [
        'whole' => ($status === false || $status === 200) && str_contains($html, '</html>') === true,
        'html'  => $html,
        'why'   => 'status ' . json_encode($status) . ', ' . strlen($html) . ' characters; ' . ($found === [] ? sm_tail($run) : 'ends: ' . substr(trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? ''), -200)),
    ];
}

/** A piece of a page as a person reads it: no tags, entities turned back into characters. */
function sm_words(string $html): string
{
    return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
}

/** What a page shows beside this label in a list of facts, or null when it has no such row. */
function sm_rowValue(string $html, string $label): ?string
{
    if (preg_match('~>' . preg_quote($label, '~') . '</div>\s*<div[^>]*>(.*?)</div>~s', $html, $m) !== 1) {
        return null;
    }

    return sm_words($m[1]);
}

/**
 * The admin dashboard's strictness alert, or null when the page shows none.
 * It is told from the page's other alerts by its last line, which no other
 * alert has.
 *
 * @return array{colour: string, headline: string}|null
 */
function sm_dashboardAlert(string $html): ?array
{
    if (preg_match('~<div class="alert alert-(\w+)" role="alert">\s*<h2[^>]*>((?:(?!</h2>).)*)</h2>(?:(?!<div class="alert ).)*shows the setting this connection is running under~s', $html, $m) !== 1) {
        return null;
    }

    return ['colour' => $m[1], 'headline' => sm_words($m[2])];
}

// ---- Part B: the installer's connection ----------------------------------------
echo "\nPart B — the installer's own connection (installGetDb), on this loose server\n";
sm_installerConnectionChecks('b', true);

// ---- Part S: the installer's step 2 ---------------------------------------------
echo "\nPart S — the installer's step 2 accepts this loose server (its test connection came out strict)\n";
// 🔑 Be let in first, as a person is: see "HOW THE INSTALLER IS REACHED" at the
//    top.
$smLetIn = sm_installerLetIn('part S');

$smRun   = sm_child('installer-step2', $smLetIn + ['SELFTEST_STEP2_DB' => $smStep2Name]);
$smFound = $smRun['found'];
sm_check('step 2 answered, to a visitor it still counted as let in', $smFound !== null && ($smFound['letIn'] ?? false) === true, sm_tail($smRun));
sm_check('step 2 raised no error', ($smFound['error'] ?? 'none read') === '', (string) ($smFound['error'] ?? 'none read'));
sm_check('step 2 kept the connection details for the next step', ($smFound['keptDetails'] ?? false) === true);
$smPlain->query('DROP DATABASE IF EXISTS `' . $smStep2Name . '`');

// ---- Part C: the portal's own start-up --------------------------------------------
echo "\nPart C — the portal's own start-up (_core/bootstrap.php), on this loose server\n";
sm_portalChecks('c');

// ---- Part D: the control ------------------------------------------------------------
echo "\nPart D — the control: a plain connection that does not go through the portal\n";
$smTable = sm_probeTable($smPlain, 'd');
$smFour  = sm_tryFour($smPlain, $smTable);
foreach (SM_BAD_VALUES as $smLabel => $smCase) {
    sm_check('plain connection: ' . $smLabel . ' is TAKEN', ($smFour[$smLabel]['refused'] ?? true) === false, (string) ($smFour[$smLabel]['message'] ?? ''));
}
sm_check('and all four were stored CHANGED (cut short, clipped, empty, empty)', sm_stored($smPlain, $smTable) === SM_STORED_CHANGED, json_encode(sm_stored($smPlain, $smTable)));

// ---- Part R: the setting is replaced, not added to --------------------------------
// On the server as it was started, its own setting is empty (or nearly), and on
// an empty setting ADDING the portal's list and REPLACING the setting with it
// give the same answer. So parts B and C could not tell a portal that replaces
// from one that adds, or from one that leaves a host's own setting alone
// whenever there is one. Both faults passed this test until this part existed.
echo "\nPart R — the setting is REPLACED, not added to: parts B and C again, with the server's own setting changed to " . implode(',', SM_ODD_SERVER_MODE) . "\n";
$smState['putBackMode'] = $smGlobalMode; // from here on the clean-up puts it back, should this stop early
sm_setServerMode($smPlain, implode(',', SM_ODD_SERVER_MODE));
// The control for this part: a NEW plain connection, opened with `new mysqli`
// on purpose. Without it, a server that ignored the change would let both
// parts pass for the same reason they passed before.
$smNew = new mysqli($smCreds['host'], $smCreds['user'], $smCreds['pass'], $smName, $smCreds['port']);
sm_check('the control: a NEW plain connection really does start under the server\'s odd setting', sm_sameWords(sm_mode($smNew), SM_ODD_SERVER_MODE), 'running under [' . sm_mode($smNew) . ']');
$smNew->close();
sm_installerConnectionChecks('rb', false);
sm_portalChecks('rc');
sm_setServerMode($smPlain, $smGlobalMode);
$smBack = (string) $smPlain->query('SELECT @@GLOBAL.sql_mode')->fetch_row()[0];
sm_check('the server\'s own setting is put back as it was', $smBack === $smGlobalMode, 'now [' . $smBack . '], was [' . $smGlobalMode . ']');
if ($smBack === $smGlobalMode) {
    $smState['putBackMode'] = null;
}

// ---- Part P: what the pages say when all is well -----------------------------------
// An administrator of this run's own database, which is dropped at the end. The
// install script that part B loaded made the table.
echo "\nPart P — what the admin dashboard and Server Information SAY, on a connection that is strict\n";
$smStmt  = $smPlain->prepare('INSERT INTO tblUsers (fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, 1, 1, 1)');
$smWho   = 'Selftest strict-mode administrator';
$smEmail = 'selftest-strict-mode@example.invalid';
$smStmt->bind_param('ss', $smWho, $smEmail);
$smStmt->execute();
$smAdminId = (int) $smStmt->insert_id;
$smStmt->close();

$smPage = sm_page(SM_PAGE_DASHBOARD);
sm_check('the admin dashboard was drawn whole', $smPage['whole'] === true && str_contains($smPage['html'], 'Admin Dashboard') === true, $smPage['why']);
sm_check('and it shows NO alert about strictness', sm_dashboardAlert($smPage['html']) === null && str_contains($smPage['html'], 'NOT strict') === false, json_encode(sm_dashboardAlert($smPage['html'])));
$smPage = sm_page(SM_PAGE_SERVER_INFO);
sm_check('Server Information was drawn whole', $smPage['whole'] === true && str_contains($smPage['html'], 'Database connection') === true, $smPage['why']);
sm_check('it says "Strict, exactly as this portal asks"', str_contains(sm_words($smPage['html']), 'Strict, exactly as this portal asks') === true);
sm_check('its "Strictness setting" row shows the setting the connection is under', sm_sameWords((string) sm_rowValue($smPage['html'], 'Strictness setting (sql_mode)'), $smExpected), 'shows [' . json_encode(sm_rowValue($smPage['html'], 'Strictness setting (sql_mode)')) . ']');
sm_check('and it has no "What this portal asks for" row, which is only for when the two differ', sm_rowValue($smPage['html'], 'What this portal asks for') === null);

/** Plant a change in the scratch copy's DbServer.php, starting from the untouched text. */
function sm_plant(array $replacements): bool
{
    global $smDbServer, $smPristine;
    $text = $smPristine;
    foreach ($replacements as $pattern => $with) {
        $text = preg_replace($pattern, $with, $text, -1, $count);
        if ($text === null || $count !== 1) {
            return false;
        }
    }

    return file_put_contents($smDbServer, $text) !== false;
}

// ---- Part M: a server that cannot be known to be MySQL ------------------------------
// A MariaDB can hide its name: its version text can be replaced, and some
// builds say nothing in their description. To the portal such a server looks
// like one that "is not called MariaDB, and does not answer the question only
// MySQL answers". This part makes this real server look like that, by changing
// the question in the scratch copy to one no server answers.
//
// WHAT THIS DOES NOT SHOW: that a real MariaDB leaves the real question
// unanswered. Only a run against a MariaDB can show that.
echo "\nPart M — a server that cannot be KNOWN to be MySQL (the scratch copy asks a question no server answers)\n";
$smPlanted = sm_plant(['/(private const QUESTION_ONLY_MYSQL_ANSWERS = )[^;]*;/' => "\$1'SELECT @@selftest_setting_no_server_has';"]);
sm_check('the scratch copy was changed to ask a question no server answers', $smPlanted === true);
$smUnsure = ['STRICT_TRANS_TABLES', 'NO_ZERO_IN_DATE', 'NO_ZERO_DATE', 'ERROR_FOR_DIVISION_BY_ZERO', 'NO_ENGINE_SUBSTITUTION'];

$smTable = sm_probeTable($smPlain, 'm');
$smRun   = sm_child('portal', ['SELFTEST_PROBE_TABLE' => $smTable]);
$smFound = $smRun['found'];
sm_check('the portal starts and answers', $smFound !== null, sm_tail($smRun));
sm_check('its connection runs WITHOUT ONLY_FULL_GROUP_BY, and with every other word', sm_sameWords((string) ($smFound['mode'] ?? ''), $smUnsure), 'running under [' . ($smFound['mode'] ?? '?') . ']');
sm_check('so the grouping that word forbids is TAKEN, and a page that groups that way still works', ($smFound['grouping'] ?? -1) === 0, 'the database answered ' . json_encode($smFound['grouping'] ?? null) . ' (0 means it took it)');
sm_check('DbServer judges it "ok": that list IS what the portal asks such a server for', ($smFound['strictness']['state'] ?? '') === 'ok', (string) ($smFound['strictness']['headline'] ?? '?'));
sm_check('the health check says "Strict", in green', ($smFound['probe']['state'] ?? '') === 'ok' && ($smFound['probe']['label'] ?? '') === 'Strict', json_encode($smFound['probe'] ?? null));
sm_checkRefused('portal on a server it cannot name', $smFound['four'] ?? null, $smPlain, $smTable);
// The question goes unanswered on EVERY request to such a server, so it must
// not cost a line in the error log each time.
sm_check(
    'nothing was written to the error log, about the setting or about the question that went unanswered',
    str_contains($smRun['log'], 'strictness the portal asks for') === false && str_contains($smRun['log'], 'selftest_setting_no_server_has') === false,
    $smRun['log']
);

// ---- Part E1: a server that refuses the setting ----------------------------------------
echo "\nPart E1 — a server that REFUSES the setting (the scratch copy asks for a word no server knows)\n";
$smPlanted = sm_plant([
    '/(public const STRICT_MODE_MYSQL = )[^;]*;/'   => "\$1'STRICT_TRANS_TABLES," . SM_UNKNOWN_WORD . "';",
    '/(public const STRICT_MODE_MARIADB = )[^;]*;/' => "\$1'STRICT_TRANS_TABLES," . SM_UNKNOWN_WORD . "';",
]);
sm_check('the scratch copy was changed to ask for a word no server knows', $smPlanted === true);

$smTable = sm_probeTable($smPlain, 'e1');
$smRun   = sm_child('portal', ['SELFTEST_PROBE_TABLE' => $smTable]);
$smFound = $smRun['found'];
sm_check('the portal STILL starts and answers', $smFound !== null, sm_tail($smRun));
// Compared with the SERVER'S OWN setting, read at the start. This used to
// expect an empty setting, which failed on a server started loose but not
// empty (--sql-mode=ANSI_QUOTES, say), where the test's own guard lets it run.
sm_check('its connection is left exactly as the server set it: [' . $smGlobalMode . ']', (string) ($smFound['mode'] ?? '?') === $smGlobalMode, 'running under [' . ($smFound['mode'] ?? '?') . ']');
sm_check('the refusal is written to the error log, with the database\'s reason', str_contains($smRun['log'], 'CRITICAL: the database would not give this connection the strictness the portal asks for') === true && str_contains($smRun['log'], SM_UNKNOWN_WORD) === true, $smRun['log']);
sm_check('the log says the shorter list was refused too', str_contains($smRun['log'], 'refused the shorter list too') === true, $smRun['log']);
sm_check('DbServer judges it "crit" (not strict at all)', ($smFound['strictness']['state'] ?? '') === 'crit', (string) ($smFound['strictness']['headline'] ?? '?'));
sm_check('the health check says "NOT strict", in red', ($smFound['probe']['state'] ?? '') === 'crit' && ($smFound['probe']['label'] ?? '') === 'NOT strict', json_encode($smFound['probe'] ?? null));
sm_check('the health page as a whole is red', ($smFound['overall'] ?? '') === 'crit', (string) ($smFound['overall'] ?? '?'));
// 📣 What the three places SAY. Here the setting asked for and the setting in
//    force differ, which is the only state where showing the wrong one of the
//    two can be seen. Each of these passed before with the page showing what
//    was asked for, or showing nothing.
$smUnder = $smGlobalMode !== '' ? $smGlobalMode : '(empty, or the database would not say)';
sm_check('the health check shows the setting READ BACK: "running under: ' . $smUnder . '"', str_contains((string) ($smFound['probe']['detail'] ?? ''), 'running under: ' . $smUnder) === true, (string) ($smFound['probe']['detail'] ?? ''));
sm_check('and does not show the setting that was only asked for', ($smFound['probe']['detail'] ?? '') !== '' && str_contains((string) $smFound['probe']['detail'], SM_UNKNOWN_WORD) === false, (string) ($smFound['probe']['detail'] ?? ''));
sm_check('and that verdict is TRUE: the four bad values were stored changed', sm_stored($smPlain, $smTable) === SM_STORED_CHANGED, json_encode(sm_stored($smPlain, $smTable)));

$smPage  = sm_page(SM_PAGE_DASHBOARD);
$smAlert = sm_dashboardAlert($smPage['html']);
sm_check('the admin dashboard was drawn whole', $smPage['whole'] === true && str_contains($smPage['html'], 'Admin Dashboard') === true, $smPage['why']);
sm_check('and it shows a RED alert that starts "NOT strict"', $smAlert !== null && $smAlert['colour'] === 'danger' && str_starts_with($smAlert['headline'], 'NOT strict') === true, json_encode($smAlert));
$smPage = sm_page(SM_PAGE_SERVER_INFO);
$smRow  = sm_rowValue($smPage['html'], 'Strictness setting (sql_mode)');
sm_check('Server Information was drawn whole', $smPage['whole'] === true && str_contains($smPage['html'], 'Database connection') === true, $smPage['why']);
sm_check('it says "NOT strict"', str_contains(sm_words($smPage['html']), 'NOT strict: the database changes values that do not fit, and says nothing') === true);
sm_check(
    'its "Strictness setting" row shows the setting READ BACK, not the one asked for',
    $smRow !== null && str_contains($smRow, SM_UNKNOWN_WORD) === false
        && ($smGlobalMode !== '' ? $smRow === $smGlobalMode : str_contains($smRow, 'empty, or the database would not say') === true),
    'shows ' . json_encode($smRow)
);
sm_check('and a "What this portal asks for" row shows the setting that was refused', sm_rowValue($smPage['html'], 'What this portal asks for') === 'STRICT_TRANS_TABLES,' . SM_UNKNOWN_WORD, 'shows ' . json_encode(sm_rowValue($smPage['html'], 'What this portal asks for')));

$smTable = sm_probeTable($smPlain, 'e1i');
$smRun   = sm_child('installer-connection', ['SELFTEST_PROBE_TABLE' => $smTable]);
// "No connection" counts only from an installer that was really reached: the
// refusal of a file that is not in a web folder also hands over nothing.
sm_check('the installer, reached through the entry page, hands over NO connection', is_array($smRun['found']) === true && ($smRun['found']['reached'] ?? false) === true && ($smRun['found']['connection'] ?? null) === false, sm_tail($smRun));
sm_check('so the installer stored nothing', sm_stored($smPlain, $smTable) === []);
// A new visitor, let in afresh, so that nothing is in the session from part S.
$smLetIn = sm_installerLetIn('part E1');
$smRun   = sm_child('installer-step2', $smLetIn + ['SELFTEST_STEP2_DB' => $smStep2Name]);
$smFound = $smRun['found'];
// Still let in, so the refusal below is step 2's own answer about the
// database, and not the gate turning away a visitor it did not know.
sm_check('the installer\'s step 2 stops and says the database is not strict', ($smFound['letIn'] ?? false) === true && str_starts_with((string) ($smFound['error'] ?? ''), 'NOT strict') === true, (string) ($smFound['error'] ?? sm_tail($smRun)));
sm_check('step 2 kept no connection details for a later step to use', ($smFound['keptDetails'] ?? true) === false);
sm_check('step 2 did not go on to create the database', (int) $smPlain->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '" . $smStep2Name . "'")->fetch_row()[0] === 0);

// ---- Part E2: a server that has retired a name --------------------------------------------
echo "\nPart E2 — a server that has RETIRED a name (the unknown word is on the list of names being retired)\n";
$smPlanted = sm_plant([
    '/(public const STRICT_MODE_MYSQL = )[^;]*;/'              => "\$1'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES," . SM_UNKNOWN_WORD . ",NO_ENGINE_SUBSTITUTION';",
    '/(public const STRICT_MODE_MARIADB = )[^;]*;/'            => "\$1'STRICT_TRANS_TABLES," . SM_UNKNOWN_WORD . ",NO_ENGINE_SUBSTITUTION';",
    '/(private const MODE_NAMES_BEING_RETIRED = )\[[^\]]*\];/' => "\$1['" . SM_UNKNOWN_WORD . "'];",
]);
sm_check('the scratch copy was changed to treat the unknown word as a retired name', $smPlanted === true);
$smShorter = $smIsMaria === true ? ['STRICT_TRANS_TABLES', 'NO_ENGINE_SUBSTITUTION'] : ['ONLY_FULL_GROUP_BY', 'STRICT_TRANS_TABLES', 'NO_ENGINE_SUBSTITUTION'];

$smTable = sm_probeTable($smPlain, 'e2');
$smRun   = sm_child('portal', ['SELFTEST_PROBE_TABLE' => $smTable]);
$smFound = $smRun['found'];
sm_check('the portal starts and answers', $smFound !== null, sm_tail($smRun));
sm_check('its connection took the shorter list', sm_sameWords((string) ($smFound['mode'] ?? ''), $smShorter), 'running under [' . ($smFound['mode'] ?? '?') . ']');
sm_check('the error log says the full list was refused and the shorter one taken', str_contains($smRun['log'], 'It did take the same list without the names MySQL is retiring') === true, $smRun['log']);
sm_check('DbServer judges it "warn" (strict, but not the exact setting)', ($smFound['strictness']['state'] ?? '') === 'warn', (string) ($smFound['strictness']['headline'] ?? '?'));
sm_check('the health check is amber', ($smFound['probe']['state'] ?? '') === 'warn', json_encode($smFound['probe'] ?? null));
// 📣 Part E1 reads what the pages say when the setting in force is EMPTY. This
//    is the other case that matters: a setting in force that is NOT empty and
//    is not the one asked for. A page that printed the asked-for list in place
//    of a non-empty one read back passed part E1 (its empty wording was right)
//    and parts C and P (where the two lists are the same); only here does it
//    show.
sm_check(
    'the health check shows the setting READ BACK (the shorter list), not the one asked for',
    ($smFound['mode'] ?? '') !== '' && str_contains((string) ($smFound['probe']['detail'] ?? ''), 'running under: ' . $smFound['mode']) === true
        && str_contains((string) $smFound['probe']['detail'], SM_UNKNOWN_WORD) === false,
    (string) ($smFound['probe']['detail'] ?? '')
);
sm_checkRefused('portal on the shorter list', $smFound['four'] ?? null, $smPlain, $smTable);
$smPage = sm_page(SM_PAGE_SERVER_INFO);
$smRow  = sm_rowValue($smPage['html'], 'Strictness setting (sql_mode)');
sm_check('Server Information was drawn whole', $smPage['whole'] === true && str_contains($smPage['html'], 'Database connection') === true, $smPage['why']);
sm_check('its "Strictness setting" row shows the shorter list the connection really took, not the one asked for', $smRow !== null && sm_sameWords($smRow, $smShorter) === true, 'shows ' . json_encode($smRow));

$smTable = sm_probeTable($smPlain, 'e2i');
$smRun   = sm_child('installer-connection', ['SELFTEST_PROBE_TABLE' => $smTable]);
$smFound = $smRun['found'];
sm_check('the installer still hands over a connection (a warning never blocks)', ($smFound['reached'] ?? false) === true && ($smFound['connection'] ?? false) === true, (string) ($smFound['stopped'] ?? sm_tail($smRun)));
sm_checkRefused('installer on the shorter list', $smFound['four'] ?? null, $smPlain, $smTable);

// ---- The end ---------------------------------------------------------------------------------
echo "\n";
if ($smState['checks'] !== SM_EXPECTED_CHECKS) {
    $smState['failures']++;
    echo 'FAIL — ' . $smState['checks'] . ' checks ran, where ' . SM_EXPECTED_CHECKS . " are expected: a part was skipped or ran twice.\n";
}
echo $smState['checks'] . ' checks, ' . $smState['failures'] . " failed.\n";
if ($smState['failures'] > 0) {
    echo "FAILED.\n";
    exit(1);
}
echo "Reached the end. Every connection the portal opens refuses a value that does not fit, on a server that would not.\n";
exit(0);
