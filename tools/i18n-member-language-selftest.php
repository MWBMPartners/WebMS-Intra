<?php
// Path: tools/i18n-member-language-selftest.php
/**
 * -----------------------------------------------------------------------------
 * A member's saved language really is applied now 🌍
 * -----------------------------------------------------------------------------
 * #763. Until 5 November 2026 a member could choose their language, the choice
 * was stored, and **nothing applied it** — every page came out in whatever the
 * browser's `Accept-Language` header asked for, or the installation's default.
 *
 * WHY IT HAPPENED, because the shape is worth recognising
 *   `I18n::init()` is called from `bootstrap.php`. The session does not start
 *   until `index.php` calls `Auth::ensureSession()`, which is AFTER start-up
 *   has finished. Two of `resolveLocale()`'s four sources are behind
 *   `session_status() === PHP_SESSION_ACTIVE`, so both were skipped every
 *   time, and the block in bootstrap that read `tblUsers.locale` was behind
 *   the same test and could not run either. **Nothing was broken; the
 *   question was simply asked too early.**
 *
 * WHAT IT COVERS
 *   A-D  the ordering itself, with no database: start-up resolves without a
 *        session, `init()` latches so asking again changes nothing, and
 *        `reconsiderNowSessionExists()` gives the member's answer.
 *   E    the database read, against a real MySQL: a member whose
 *        `tblUsers.locale` is `cy` is served `cy`.
 *   G    forgetting a choice — the account page's "follow my browser" option.
 *   F    the other half — `switchLocale()` really storing a choice. It is the
 *        ONLY thing that has ever written that column, and until #763 its one
 *        caller sat where no session existed, so it wrote nothing at all.
 *
 * WHAT IT DOES NOT COVER, said plainly
 *   It drives the CLASS, not a page. Nothing here proves `index.php` calls the
 *   method — that is what `check_member_language_applied.py` is for, and a
 *   static check cannot prove a page RENDERS in Welsh either. Driving that
 *   needs HTTP and a seeded installation.
 *
 *   It also says nothing about whether the Welsh translations exist. A member
 *   asking for a language with no translation file gets English words under a
 *   Welsh locale, which is the correct behaviour and is not tested here.
 *
 * Section E needs a MySQL server (`SELFTEST_DB_*`, the same variables every
 * other database self-test here reads). It **makes its own scratch database**
 * and drops it afterwards, so it can never disturb a real schema — and it
 * REFUSES rather than skipping when no server answers, because a skipped
 * section reads exactly like a passing one.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/763
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// ⚠️ EVERYTHING IS BUFFERED, AND THAT IS LOAD-BEARING. `session_start()`
//    refuses once a single byte has been printed, so without this the session
//    never becomes active, source 1 is skipped for the WRONG reason, and the
//    test reports a false failure on correct code.
ob_start();

$sep  = DIRECTORY_SEPARATOR;
$root = dirname(__DIR__);
define('PORTAL_ROOT', $root . $sep . 'web');
define('PORTAL_CORE', PORTAL_ROOT . $sep . '_core');

// A stand-in for what `bootstrap.php` builds. `App::settings()` falls back to
// the global `$SETTINGS`, so this is the real read path rather than a stub.
// ⚠️ `defaultLocale` is the ONLY key here that anything reads. A draft also
//    set `enabledLocales`, which NOTHING in this portal consults — the real
//    `I18n::enabledLocales()` lists readable `web/_lang/*.php` files — and its
//    presence made E5's label read as though an installation could choose its
//    languages. A fixture key nothing reads is a claim nothing supports.
$SETTINGS = ['i18n' => ['defaultLocale' => 'en']];

require PORTAL_CORE . $sep . 'App.php';
require PORTAL_CORE . $sep . 'I18n.php';

use Portal\Core\I18n;

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

echo "A. Start-up really does run before any session exists\n";
check('A1 no session when init() is called, exactly as in bootstrap.php',
    session_status(), PHP_SESSION_NONE);
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr';
I18n::init();
check('A2 so init() answers from Accept-Language, as it always did',
    I18n::locale(), 'fr');

echo "B. The session starts afterwards — and init() will not look again\n";
session_start();
check('B1 the session really is active now', session_status(), PHP_SESSION_ACTIVE);
$_SESSION['user_id']     = 7;
$_SESSION['user_locale'] = 'cy';
I18n::init();
// ⚠️ THIS IS THE FAULT, NOT AN ODDITY OF THE TEST. `init()` latches
//    `$initialised` and returns, so the old code could never revisit the
//    answer once a session existed.
check('B2 THE FAULT: calling init() again changes nothing, Welsh ignored',
    I18n::locale(), 'fr');

echo "C. Asking again, now there is a session, gives the member's own language\n";
I18n::reconsiderNowSessionExists(null);
check('C1 THE FIX: the member\'s choice is applied', I18n::locale(), 'cy');

// The language switcher's own session key was unreachable for the same reason.
// ⚠️ It must move the answer AWAY from Welsh, or the check cannot fail.
unset($_SESSION['user_locale'], $_SESSION['user_id']);
$_SESSION['portal_locale'] = 'fr';
I18n::reconsiderNowSessionExists(null);
check('C2 the switcher\'s session key is reachable too, and CHANGED the answer',
    I18n::locale(), 'fr');

echo "D. Harmless where there is no member — a scheduled job, or a visitor\n";
session_write_close();
$_SESSION = [];
I18n::reconsiderNowSessionExists(null);
check('D1 with no member it falls back to Accept-Language rather than throwing',
    I18n::locale(), 'fr');

echo "E. The database read, against a real MySQL\n";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
$user = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
$pass = getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '';
$port = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;

// 🗄️ Its OWN database, never the one the other tests use. A real schema's
//    `tblUsers` has columns this test does not fill, so inserting into it
//    would fail — and a test that quietly works around a real schema is a
//    test of the work-around.
$scratch = 'i18n763_scratch';

try {
    $db = new mysqli($host, $user, $pass, '', $port);
} catch (\Throwable $e) {
    ob_end_flush();
    echo "\nREFUSED: section E needs a MySQL server and none answered on "
        . "{$host}:{$port}.\n";
    echo "         It is not skipped, because a skipped section reads exactly "
        . "like a passing one.\n";
    echo "         Set SELFTEST_DB_HOST / _USER / _PASS / _PORT and run again.\n";
    exit(2);
}

$db->query('DROP DATABASE IF EXISTS `' . $scratch . '`');
$db->query('CREATE DATABASE `' . $scratch . '`');
$db->select_db($scratch);
$db->query(
    'CREATE TABLE `tblUsers` ('
    . '`userID` INT NOT NULL PRIMARY KEY, '
    . '`locale` VARCHAR(10) NULL'
    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
$db->query(
    "INSERT INTO `tblUsers` (`userID`, `locale`) VALUES "
    . "(7, 'cy'), (8, NULL), (9, ''), (10, 'kl')"
);

$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';
session_id('i18n763sectione');
session_start();
$_SESSION = ['user_id' => 7];
// Start from English, which is what a real signed-in request begins with.
I18n::reconsiderNowSessionExists(null);
check('E0 we begin on English, as a real request does', I18n::locale(), 'en');

I18n::reconsiderNowSessionExists($db);
check('E1 THE WHOLE POINT: a member whose tblUsers.locale is cy is served cy',
    I18n::locale(), 'cy');
check('E2 ...and it is remembered, so the column is read once per session',
    $_SESSION['user_locale'] ?? null, 'cy');

// ⚠️ THE BROWSER NOW ASKS FOR FRENCH FOR THE NEXT THREE CASES, AND WITHOUT
//    THAT NONE OF THEM COULD FAIL. They are all of the form "this stored value
//    must NOT be applied, so the browser decides" — and while the browser was
//    asking for English and the default was English, the right answer and the
//    wrong answer were the same string `'en'`. Measured: removing the very
//    guard E3 and E4 are named after left both GREEN. The whole point of each
//    is that the answer comes from somewhere else, so somewhere else has to be
//    saying something different.
//
//    ⚠️ BE EXACT ABOUT WHAT MAKES THEM RED, because the obvious plant does
//       NOT. Deleting the `!== null && !== ''` guard from
//       `reconsiderNowSessionExists()` leaves E3 and E4 GREEN — measured —
//       because `resolveLocale()` tests the very same thing on the session
//       value, so an empty value is skipped there instead and the browser
//       still decides. Only E6 goes red, on the session write. What these two
//       catch is the mistake somebody would really make: treating an empty
//       column as a choice of English (`$stored !== '' ? $stored : 'en'`).
//       Planted, that turns E3, E4 and E6 red together.
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr';

$_SESSION = ['user_id' => 8];
I18n::reconsiderNowSessionExists($db);
check('E3 a member who chose NOTHING follows the browser, not the empty column',
    I18n::locale(), 'fr');

$_SESSION = ['user_id' => 9];
I18n::reconsiderNowSessionExists($db);
check('E4 an EMPTY saved language is not applied; the browser decides',
    I18n::locale(), 'fr');

$_SESSION = ['user_id' => 10];
I18n::reconsiderNowSessionExists($db);
// E5's label used to say "a language this installation does not offer", and
// nothing in this portal lets an installation offer or withhold a language:
// `isSupported()` is a hard-coded list of thirteen and `enabledLocales()` is
// "which `_lang/*.php` files are readable". The real question is narrower.
check('E5 a language the portal does not KNOW (kl) is not applied',
    I18n::locale(), 'fr');
check('E6 ...and is not written into the session, so it is not sticky',
    isset($_SESSION['user_locale']), false);

$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';

// A fault must never take a page down: somebody is trying to read it.
// ⚠️ The browser is back on English above, so "the language already chosen"
//    and "what the browser asks for" are the same here — which would make this
//    unable to fail if it were about the stored value. It is not: it is about
//    the method RETURNING AT ALL rather than throwing, and the `check()` below
//    only runs if it did.
$_SESSION = ['user_id' => 7];
$db->query('DROP TABLE `tblUsers`');
I18n::reconsiderNowSessionExists($db);
check('E7 a missing table falls back rather than throwing',
    I18n::locale(), 'en');

echo "F. Choosing a language from an address really saves it now\n";
// ⚠️ `switchLocale()` is the ONLY thing that has ever written
//    `tblUsers.locale`, and until #763 its one caller was in `bootstrap.php`
//    where no session exists — so it stored nothing, wrote nothing, and the
//    choice was gone by the time its own redirect landed. That is why every
//    account row still held the column's own default (D-060).
$db->query(
    'CREATE TABLE `tblUsers` ('
    . '`userID` INT NOT NULL PRIMARY KEY, '
    . '`locale` VARCHAR(10) NULL'
    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
$db->query('INSERT INTO `tblUsers` (`userID`, `locale`) VALUES (11, NULL)');

// `switchLocale()` reaches the database through `App::db()`, which falls back
// to the global `$mysqli`. Pointing that at the scratch connection is how the
// real call path is exercised rather than a stand-in for it.
$GLOBALS['mysqli'] = $db;

$_SESSION = ['user_id' => 11];
I18n::reconsiderNowSessionExists($db);
check('F0 the member starts with no saved language, so English',
    I18n::locale(), 'en');

$switched = I18n::switchLocale('cy');
check('F1 the switch is accepted', $switched, true);
check('F2 ...and applied at once', I18n::locale(), 'cy');
check('F3 ...and kept for the rest of the visit',
    $_SESSION['portal_locale'] ?? null, 'cy');

$row = $db->query('SELECT `locale` FROM `tblUsers` WHERE `userID` = 11')->fetch_assoc();
check('F4 THE OTHER HALF OF #763: it is written to tblUsers.locale',
    $row['locale'] ?? null, 'cy');

// And a language this installation does not offer is refused outright, rather
// than stored and then quietly ignored on every later visit.
check('F5 an unknown language is refused', I18n::switchLocale('kl'), false);
$row = $db->query('SELECT `locale` FROM `tblUsers` WHERE `userID` = 11')->fetch_assoc();
check('F6 ...and nothing is written when it is refused',
    $row['locale'] ?? null, 'cy');

echo "G. Forgetting a choice, which is what the account page's empty option does\n";
// ⚠️ CLEARING HAS TO BE EXPRESSIBLE OR D-060's DESIGN TRAPS PEOPLE. An empty
//    stored language IS "not chosen" on this portal, so a member who once
//    picked one could otherwise never go back to being asked.
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr';
$_SESSION = ['user_id' => 11];
check('G0 the member is still on their stored choice', I18n::switchLocale('cy'), true);
check('G0b ...which is Welsh, not the browser\'s French', I18n::locale(), 'cy');

check('G1 clearing is accepted', I18n::chooseLanguage(null), true);
check('G2 ...and the answer goes back to the BROWSER, not the default',
    I18n::locale(), 'fr');
$row = $db->query('SELECT `locale` FROM `tblUsers` WHERE `userID` = 11')->fetch_assoc();
check('G3 ...and the column is emptied, which is what "not chosen" IS',
    $row['locale'], null);
check('G4 ...and neither session key is left behind as an empty string',
    isset($_SESSION['user_locale']) || isset($_SESSION['portal_locale']), false);

// An empty string must behave exactly as null does: the account page's select
// submits '' for "follow my browser", never a PHP null.
I18n::switchLocale('cy');
check('G5 an EMPTY STRING clears too, because that is what a form sends',
    I18n::chooseLanguage(''), true);
check('G6 ...with the same answer', I18n::locale(), 'fr');

// And `storedChoice()` is the one reader both the resolver and the page use.
I18n::switchLocale('cy');
check('G7 storedChoice reads back what was stored',
    I18n::storedChoice(11, $db), 'cy');
I18n::chooseLanguage(null);
check('G8 ...and answers null for "not chosen", never an empty string',
    I18n::storedChoice(11, $db), null);
check('G9 ...and answers null for an account that does not exist',
    I18n::storedChoice(99999, $db), null);
check('G10 ...and null for a nonsense account number, without asking the database',
    I18n::storedChoice(0, $db), null);

$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';

$db->query('DROP DATABASE IF EXISTS `' . $scratch . '`');
$db->close();

ob_end_flush();
echo "\n";
echo ($passed + $failed) . " checks: {$passed} passed, {$failed} failed.\n";
echo "It drives the CLASS. That index.php calls it at all is guarded by\n";
echo "tools/audit-checks/check_member_language_applied.py, and neither proves\n";
echo "a page RENDERS in Welsh — that needs HTTP and a seeded installation.\n";
exit($failed === 0 ? 0 : 1);
