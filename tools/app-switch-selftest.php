<?php
// Path: tools/app-switch-selftest.php
/**
 * -----------------------------------------------------------------------------
 * An app's off switch — self-test 🚪 (#478 and #570)
 * -----------------------------------------------------------------------------
 * Switching an app off in Admin → Apps used to hide its pages and leave its
 * data endpoints answering (#478), and the "app is disabled" page named the
 * wrong app (#570). This file checks the pieces that put both right:
 *
 *   1. every `api` folder on disk has a line in
 *      `AppRegistry::API_FOLDER_APPS`, and every line has a folder;
 *   2. every app named in that list, or in `ROUTES_OUTSIDE_PREFIX`, is a
 *      registered app; a folder that has a registered app of its own name
 *      names THAT app; and the whole list is exactly the one written out by
 *      hand here;
 *   3. the exceptions are exactly the documented ones: the `push`, `tours`
 *      and `users` folders, and the `unsubscribe` page;
 *   4. `appForRoute()` gives the right app for every address in
 *      `ROUTES_OUTSIDE_PREFIX`, none for `unsubscribe`, and the answers it
 *      gave before are unchanged;
 *   5. no seeded address whose page lives in a switchable app's folder is
 *      left untied to that app, apart from the two written down here;
 *   6. with an app's switch off, its folder's app reads as off;
 *   7. `ApiRouter` asks the question in BOTH ways in, after "does the handler
 *      exist" and before the handler is loaded, as a plain statement with
 *      nothing wrapped round it; and the gate itself, and
 *      `AppRegistry::isEnabledForSite()`, are EXACTLY the code written out
 *      here (read from the files, with comments and spacing removed);
 *   8. inside `Router::renderAppDisabled()`, no variable that is set before
 *      the header template is loaded and read after it has a name the
 *      templates themselves assign (#570), with the names worked out from
 *      the template files rather than typed in here;
 *   9. (only when a throwaway database is given) the per-organisation
 *      question an API key is answered by, `isEnabledForSite()`.
 *
 * No database is needed for 1 to 8, and no start-up code is loaded: only
 * `App.php` and `AppRegistry.php`, with the settings put in place through
 * reflection.
 *
 * WHY CHECK 7 COMPARES TWO SMALL FUNCTIONS WORD FOR WORD
 * -----------------------------------------------------
 * Check 7 first looked only for the pieces of the gate (it reads the list,
 * it asks about the key's organisation, it answers 403). Four planted
 * faults passed it: the gate skipped whenever a request carried a key; a
 * request with a valid key never refused; a folder with no line let
 * through; and "no row for the switch" counting as ON for a key (found by
 * the independent check of 11 October 2026). Each kept every piece and
 * changed what happens around them. So the two functions are now compared
 * whole. They are short, and they are the whole of the rule: anybody
 * changing either must change the copy in this file too, on purpose.
 * `tools/access-pages-selftest.php` proves the same things by asking the
 * running portal.
 *
 * ITS OWN CONTROLS, so a check here cannot quietly stop checking anything:
 * check 8 runs the same detector over a small made-up function and template
 * that DO collide and must be reported; and the run ends by comparing the
 * number of checks that ran with the number written at the top, so a block
 * that was skipped (or a crash part way) can never read as a pass.
 *
 * WHAT THIS CANNOT PROVE
 * ----------------------
 * - That a real request is refused: that needs the portal running.
 *   `tools/access-pages-selftest.php` does that (a session, and an API key
 *   sent to another organisation's address).
 * - Check 7 reads `ApiRouter.php` and `AppRegistry.php` as text. It
 *   requires the exact code of the gate and of its two call statements, but
 *   it cannot see something done elsewhere that makes them harmless (the
 *   list emptied at run time, say). `tools/access-pages-selftest.php` asks
 *   the running portal instead.
 * - Check 8 covers the one function. Other pages that set a variable, load
 *   the header and read the variable afterwards are not checked.
 * - Check 9 does not run without `SELFTEST_DB_NAME`, and says so. Without
 *   it, nothing here exercises `isEnabledForSite()`.
 * - Scheduled jobs, the personal pages under `account/` and the router's
 *   short addresses (`/e/…`, `/f/…`, `/a/…`, `/os/…`) of a switched-off app
 *   are outside #478 and are not checked.
 *
 * Usage:  php tools/app-switch-selftest.php
 *         SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… php tools/app-switch-selftest.php
 *         (the database must start with `selftest_` and hold the tables of
 *         `web/_sql/full_schema.sql`; host, user as the other self-tests)
 * Exit:   0 only when every check that should run did run and passed.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/478
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/570
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$repo = dirname(__DIR__);
$web  = $repo . DIRECTORY_SEPARATOR . 'web';
if (defined('PORTAL_CORE') === false) {
    define('PORTAL_CORE', $web . DIRECTORY_SEPARATOR . '_core');
}
if (defined('PORTAL_APPS') === false) {
    define('PORTAL_APPS', $web . DIRECTORY_SEPARATOR . '_apps');
}

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'App.php';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'AppRegistry.php';

use Portal\Core\App;
use Portal\Core\AppRegistry;

/** How many checks a run without a database makes, and how many more with one. */
const EXPECTED_WITHOUT_DB = 76;
const EXPECTED_DB_ONLY    = 10;

$failures = 0;
$passes   = 0;

/**
 * ✅ Report one check.
 *
 * @param string $what   What is being checked, in plain words.
 * @param bool   $isOk   Whether it passed.
 * @param string $detail Shown only on failure.
 */
function check(string $what, bool $isOk, string $detail = ''): void
{
    global $failures, $passes;
    if ($isOk === true) {
        $passes++;
        echo '  PASS  ' . $what . "\n";

        return;
    }
    $failures++;
    echo '  FAIL  ' . $what . "\n";
    if ($detail !== '') {
        echo '          ' . $detail . "\n";
    }
}

/**
 * A constant of AppRegistry, or an empty list when it does not exist. On the
 * code before #478 neither list exists; asking for one outright would stop
 * this file with an error instead of a FAIL line for each thing missing.
 *
 * @return array<string, mixed>
 */
function registryList(string $name): array
{
    $full = AppRegistry::class . '::' . $name;

    return defined($full) === true ? (array) constant($full) : [];
}

/** Put a made-up settings array where App::settings() reads it. */
function useSettings(array $settings): void
{
    $property = new ReflectionProperty(App::class, 'settings');
    $property->setValue(null, $settings);
}

/**
 * A settings array with one dot-named key set, e.g. 'chat.enabled' => 'true'
 * becomes ['chat' => ['enabled' => 'true']].
 *
 * @return array<string, mixed>
 */
function settingsWith(string $dotKey, string $value): array
{
    $out  = [];
    $node =& $out;
    foreach (explode('.', $dotKey) as $part) {
        $node[$part] = [];
        $node        =& $node[$part];
    }
    $node = $value;

    return $out;
}

/** PHP code with its comments removed, so a commented-out call is never counted. */
function codeOnly(string $source): string
{
    $out = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) === true) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $out .= $token[1];
        } else {
            $out .= $token;
        }
    }

    return $out;
}

/**
 * PHP code with its comments AND all its spacing removed, so two pieces of
 * code compare equal exactly when they are the same statements. Text inside
 * quotes is kept as written.
 */
function squeezed(string $code): string
{
    $out = '';
    foreach (token_get_all('<?php ' . $code) as $token) {
        if (is_array($token) === true) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_OPEN_TAG], true) === true) {
                continue;
            }
            $out .= $token[1];
        } else {
            $out .= $token;
        }
    }

    return $out;
}

/**
 * The body of one method, from its opening brace to the matching closing
 * one, taken from code that already has its comments removed. '' when the
 * method is not there.
 */
function methodBody(string $code, string $method): string
{
    if (preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $code, $m, PREG_OFFSET_CAPTURE) !== 1) {
        return '';
    }
    $open = strpos($code, '{', $m[0][1]);
    if ($open === false) {
        return '';
    }
    $depth  = 0;
    $length = strlen($code);
    for ($i = $open; $i < $length; $i++) {
        if ($code[$i] === '{') {
            $depth++;
        } elseif ($code[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($code, $open, $i - $open + 1);
            }
        }
    }

    return '';
}

/**
 * Every variable name a piece of PHP ASSIGNS: `$x =`, `$x .=` and the other
 * combined forms, `$x++` / `--$x`, the variables after `as` in a foreach,
 * and the variables inside `[…] =` or `list(…) =`.
 *
 * It errs on the side of MORE names (a variable assigned only inside a
 * closure is counted although it never leaves the closure). For check 8
 * that can only make the check stricter, never let a collision through.
 *
 * @return list<string> Names without the dollar sign, sorted.
 */
function assignedNames(string $source): array
{
    $tokens = array_values(array_filter(
        token_get_all($source),
        static fn ($t): bool => is_array($t) === false
            || in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === false
    ));
    $assignOps = [T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_MOD_EQUAL, T_AND_EQUAL,
        T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_COALESCE_EQUAL, T_POW_EQUAL, T_INC, T_DEC];
    $names = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_array($token) === true && $token[0] === T_VARIABLE) {
            $next = $tokens[$i + 1] ?? null;
            $prev = $tokens[$i - 1] ?? null;
            if ($next === '='
                || (is_array($next) === true && in_array($next[0], $assignOps, true) === true)
                || (is_array($prev) === true && in_array($prev[0], [T_INC, T_DEC], true) === true)) {
                $names[] = substr($token[1], 1);
            }
            continue;
        }
        // foreach (… as $key => $value): every variable up to the closing bracket.
        if (is_array($token) === true && $token[0] === T_AS) {
            $depth = 0;
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j] === '(' || $tokens[$j] === '[') {
                    $depth++;
                } elseif ($tokens[$j] === ')' || $tokens[$j] === ']') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif (is_array($tokens[$j]) === true && $tokens[$j][0] === T_VARIABLE) {
                    $names[] = substr($tokens[$j][1], 1);
                }
            }
            continue;
        }
        // [$a, $b] = …  and  list($a, $b) = …
        if ($token === ']' || $token === ')') {
            if (($tokens[$i + 1] ?? null) !== '=') {
                continue;
            }
            $open  = $token === ']' ? '[' : '(';
            $depth = 0;
            for ($j = $i; $j >= 0; $j--) {
                if ($tokens[$j] === $token) {
                    $depth++;
                } elseif ($tokens[$j] === $open) {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                } elseif (is_array($tokens[$j]) === true && $tokens[$j][0] === T_VARIABLE) {
                    $names[] = substr($tokens[$j][1], 1);
                }
            }
        }
    }
    $names = array_values(array_unique($names));
    sort($names);

    return $names;
}

/**
 * Every name the header template assigns, following each template or core
 * file it loads with `require` or `include` (which is how the menu,
 * `nav.php`, comes in).
 *
 * @param string       $file    The template to start from.
 * @param list<string> $scanned Filled with the files that were read.
 *
 * @return list<string>
 */
function templateAssignedNames(string $file, array &$scanned): array
{
    $real = realpath($file);
    if ($real === false || in_array($real, $scanned, true) === true) {
        return [];
    }
    $scanned[] = $real;
    $source    = (string) file_get_contents($real);
    $names     = assignedNames($source);
    // Each loaded file is named by a quoted string ending in .php somewhere
    // after `require` or `include` on the same statement.
    preg_match_all('/\b(?:require|include)(?:_once)?\b[^;]*?\'([A-Za-z0-9_\-]+\.php)\'[^;]*;/', codeOnly($source), $loads);
    foreach ($loads[1] as $loaded) {
        foreach ([dirname($real), PORTAL_CORE] as $folder) {
            $candidate = $folder . DIRECTORY_SEPARATOR . $loaded;
            if (is_file($candidate) === true) {
                $names = array_merge($names, templateAssignedNames($candidate, $scanned));
                break;
            }
        }
    }
    $names = array_values(array_unique($names));
    sort($names);

    return $names;
}

/**
 * The variables of one function body that are SET before the header
 * template is loaded and READ after it, and whose names the templates
 * assign. Each of those would print whatever the template left in it.
 *
 * @param string       $body          The function's code, comments removed.
 * @param list<string> $templateNames Names the templates assign.
 *
 * @return array{found: bool, carried: list<string>, clash: list<string>}
 *         `found` is false when the body never loads header.php at all;
 *         `carried` is every variable set before and read after.
 */
function headerClashes(string $body, array $templateNames): array
{
    if (preg_match('/\brequire\b[^;]*header\.php[^;]*;/', $body, $m, PREG_OFFSET_CAPTURE) !== 1) {
        return ['found' => false, 'carried' => [], 'clash' => []];
    }
    $before = substr($body, 0, $m[0][1]);
    $after  = substr($body, $m[0][1] + strlen($m[0][0]));
    $set    = assignedNames('<?php ' . $before);
    preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)/', $after, $read);
    $carried = array_values(array_intersect($set, array_unique($read[1])));
    sort($carried);
    $clash = array_values(array_intersect($carried, $templateNames));

    return ['found' => true, 'carried' => $carried, 'clash' => $clash];
}

echo "\n" . str_repeat('=', 78) . "\n";
echo "An app's off switch — self-test (#478 and #570)\n";
echo str_repeat('=', 78) . "\n";

$folderApps  = registryList('API_FOLDER_APPS');
$outsideApps = registryList('ROUTES_OUTSIDE_PREFIX');
$registered  = AppRegistry::all();
$schema      = (string) file_get_contents($web . DIRECTORY_SEPARATOR . '_sql' . DIRECTORY_SEPARATOR . 'full_schema.sql');

// Every address the fresh-install script seeds, with the page file behind it.
preg_match_all(
    "/\(\s*'([a-z0-9][a-z0-9\-\/\._]*)'\s*,\s*'([A-Za-z0-9_\-\/\.]+\.php)'\s*,\s*[01]\s*\)/",
    $schema,
    $routeRows,
    PREG_SET_ORDER
);
$seededRoutes = [];
foreach ($routeRows as $row) {
    $seededRoutes[$row[1]] = $row[2];
}

// -----------------------------------------------------------------------------
// 1. Every api folder has a line, and every line has a folder
// -----------------------------------------------------------------------------
echo "\n1. Every api folder on disk has a line in API_FOLDER_APPS\n";

$onDisk = [];
foreach ((array) glob(PORTAL_APPS . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'api', GLOB_ONLYDIR) as $dir) {
    $onDisk[] = basename(dirname((string) $dir));
}
sort($onDisk);
$listed = array_keys($folderApps);
sort($listed);

check('the list exists and is not empty', $folderApps !== [], 'AppRegistry::API_FOLDER_APPS is missing or empty.');
check(
    'api folders were found on disk (so the comparison below is not of two empty lists)',
    count($onDisk) >= 19,
    'Found ' . count($onDisk) . ' folders under web/_apps/*/api.'
);
check(
    'every api folder on disk has a line',
    array_diff($onDisk, $listed) === [],
    'No line for: ' . implode(', ', array_diff($onDisk, $listed)) . '. Add each to AppRegistry::API_FOLDER_APPS with its app (or null).'
);
check(
    'every line names a folder that exists',
    $folderApps !== [] && array_diff($listed, $onDisk) === [],
    'No such folder: ' . implode(', ', array_diff($listed, $onDisk))
);

// -----------------------------------------------------------------------------
// 2. Every app named in either list is registered
// -----------------------------------------------------------------------------
echo "\n2. Every app named in either list is a registered app\n";

$unknownFolderApps = [];
foreach ($folderApps as $folder => $slug) {
    if ($slug !== null && isset($registered[$slug]) === false) {
        $unknownFolderApps[] = $folder . ' → ' . $slug;
    }
}
check(
    'API_FOLDER_APPS names only registered apps',
    $folderApps !== [] && $unknownFolderApps === [],
    'Not registered: ' . implode(', ', $unknownFolderApps)
);
$unknownOutside = [];
foreach ($outsideApps as $address => $slug) {
    if (isset($registered[$slug]) === false) {
        $unknownOutside[] = $address . ' → ' . $slug;
    }
}
check(
    'ROUTES_OUTSIDE_PREFIX names only registered apps',
    $outsideApps !== [] && $unknownOutside === [],
    $outsideApps === [] ? 'AppRegistry::ROUTES_OUTSIDE_PREFIX is missing or empty.' : 'Not registered: ' . implode(', ', $unknownOutside)
);
// A folder tied to a WRONG app that happens to be registered (`'venues' =>
// 'worship'`) passed every check above: the app exists, so nothing looked
// odd. Two checks close that. The first needs no list of its own; the
// second is the whole list, written out by hand.
$wrongOwnName = [];
foreach ($folderApps as $folder => $slug) {
    if (isset($registered[$folder]) === true && (($registered[$folder]['isCore'] ?? false) === false) && $slug !== $folder) {
        $wrongOwnName[] = $folder . ' → ' . var_export($slug, true);
    }
}
check(
    'a folder that has a registered app of its own name names THAT app',
    $folderApps !== [] && $wrongOwnName === [],
    'Tied to something else: ' . implode(', ', $wrongOwnName)
);
// Written out by hand, NOT read from the class. null = belongs to the portal itself.
$expectedFolderApps = [
    'announcements'   => 'announcements',
    'assets'          => 'assets',
    'attendance'      => 'attendance',
    'calendar'        => 'calendar',
    'documents'       => 'documents',
    'events'          => 'calendar',        // Events is the Calendar app
    'expenses'        => 'expenses',
    'leadership'      => 'leadership',
    'livechat'        => 'cop-live-chat',   // its switch is chat.enabled
    'livestream'      => 'livestream',
    'noticeboard'     => 'noticeboard',
    'prayer-requests' => 'prayer-requests',
    'push'            => null,
    'service-plans'   => 'service-plans',
    'tasks'           => 'tasks',
    'tours'           => null,
    'users'           => null,
    'venues'          => 'venues',
    'worship'         => 'worship',
];
$actualFolderApps = $folderApps;
ksort($actualFolderApps);
ksort($expectedFolderApps);
$differing = [];
foreach ($expectedFolderApps as $folder => $slug) {
    if (array_key_exists($folder, $actualFolderApps) === false || $actualFolderApps[$folder] !== $slug) {
        $differing[] = $folder;
    }
}
check(
    'the whole list is exactly the one written out in this file, folder by folder',
    $actualFolderApps === $expectedFolderApps,
    'Differs for: ' . implode(', ', array_merge($differing, array_keys(array_diff_key($actualFolderApps, $expectedFolderApps))))
);

$coreNamed = [];
foreach (array_merge(array_values($folderApps), array_values($outsideApps)) as $slug) {
    if ($slug !== null && (($registered[$slug]['isCore'] ?? false) === true)) {
        $coreNamed[] = $slug;
    }
}
check(
    'no line names an app that cannot be switched off (such a line would do nothing)',
    $coreNamed === [],
    'Core apps named: ' . implode(', ', array_unique($coreNamed))
);

// -----------------------------------------------------------------------------
// 3. The exceptions are exactly the documented ones
// -----------------------------------------------------------------------------
echo "\n3. The exceptions are exactly the documented ones\n";

$alwaysOn = array_keys(array_filter($folderApps, static fn ($slug): bool => $slug === null));
sort($alwaysOn);
check(
    'the folders no switch applies to are exactly push, tours and users',
    $alwaysOn === ['push', 'tours', 'users'],
    'Found: ' . ($alwaysOn === [] ? '(none)' : implode(', ', $alwaysOn))
);
check(
    'the unsubscribe page is a real seeded address, in the Newsletter app\'s folder',
    ($seededRoutes['unsubscribe'] ?? '') === 'newsletter/unsubscribe.php',
    'full_schema.sql seeds it as: ' . ($seededRoutes['unsubscribe'] ?? '(not seeded)')
);
check(
    'unsubscribe is NOT in ROUTES_OUTSIDE_PREFIX: people must be able to stop mail whatever the Newsletter switch says',
    $outsideApps !== [] && array_key_exists('unsubscribe', $outsideApps) === false
);
check(
    'appForRoute() ties unsubscribe to no app at all',
    AppRegistry::appForRoute('unsubscribe') === null
);

// -----------------------------------------------------------------------------
// 4. appForRoute() gives the right app for every address outside its app's own
// -----------------------------------------------------------------------------
echo "\n4. Pages that live outside their app's own address are tied to it\n";

// Written out by hand, NOT read from the class: reading the expected answers
// from the list being checked would agree with it even when it was wrong.
$expectedOutside = [
    'attend'                => 'calendar',
    'attend/save'           => 'calendar',
    'calendar.ics'          => 'calendar',
    'account/calendar-feed' => 'calendar',
    'recordings.rss'        => 'recordings',
    'visit'                 => 'visitors',
];
foreach ($expectedOutside as $address => $slug) {
    $owner = AppRegistry::appForRoute($address);
    check(
        sprintf('/%s belongs to the %s app', $address, $slug),
        ($owner['slug'] ?? null) === $slug,
        'appForRoute() answered: ' . var_export($owner['slug'] ?? null, true)
    );
    check(
        sprintf('/%s is a real seeded address whose page is in that app\'s folder', $address),
        str_starts_with((string) ($seededRoutes[$address] ?? ''), $slug . '/') === true,
        'full_schema.sql seeds it as: ' . ($seededRoutes[$address] ?? '(not seeded)')
    );
}
$listedOutside = array_keys($outsideApps);
sort($listedOutside);
$expectedKeys = array_keys($expectedOutside);
sort($expectedKeys);
check(
    'ROUTES_OUTSIDE_PREFIX holds exactly those six addresses',
    $listedOutside === $expectedKeys,
    'It holds: ' . implode(', ', $listedOutside)
);

// What appForRoute() answered before #478 must be unchanged.
$unchanged = [
    'calendar'           => 'calendar',
    'calendar/manage'    => 'calendar',
    'attendance'         => 'attendance',       // not Calendar, although it starts with "attend"
    'attendance/report'  => 'attendance',
    'admin/reports/x'    => 'reports',          // the longest matching start wins over "admin"
    'admin/apps'         => 'admin',
    'dashboard'          => 'dashboard',
    'prayer-requests'    => 'prayer-requests',
];
foreach ($unchanged as $address => $slug) {
    $owner = AppRegistry::appForRoute($address);
    check(
        sprintf('/%s still belongs to %s', $address, $slug),
        ($owner['slug'] ?? null) === $slug,
        'appForRoute() answered: ' . var_export($owner['slug'] ?? null, true)
    );
}
foreach (['no-such-page', 'attend/other', 'calendar.icsx', 'visitx', 'login'] as $address) {
    check(
        sprintf('/%s belongs to no app (an address is matched whole, never by its start)', $address),
        AppRegistry::appForRoute($address) === null
    );
}

// -----------------------------------------------------------------------------
// 5. No other seeded page of a switchable app is left untied to it
// -----------------------------------------------------------------------------
echo "\n5. No other seeded page of a switchable app is left untied to it\n";

// The two addresses this check is told about, and why. Anything else that
// turns up is a page added under an address of its own with no line in
// ROUTES_OUTSIDE_PREFIX: the #478 fault again.
$knownUntied = [
    // Deliberate: see check 3.
    'unsubscribe' => 'newsletter',
    // NOT decided: the page that accepts an invitation lives in the Invites
    // app's folder under /auth/invite, so its address ties it to the Auth
    // app, which is always on. Whether the Invites switch should close it is
    // a "Found" item of the roll-out plan (F5), not part of #478.
    'auth/invite' => 'invites',
];
$untied = [];
foreach ($seededRoutes as $address => $target) {
    $folder = explode('/', $target)[0];
    if (isset($registered[$folder]) === false || ($registered[$folder]['isCore'] ?? false) === true) {
        continue; // the page is not in a switchable app's folder
    }
    $owner = AppRegistry::appForRoute($address);
    if (($owner['slug'] ?? null) !== $folder) {
        $untied[$address] = $folder;
    }
}
ksort($untied);
ksort($knownUntied);
check(
    'the fresh-install script\'s addresses were read (so this is not a comparison of nothing)',
    count($seededRoutes) > 400,
    'Read ' . count($seededRoutes) . ' addresses from full_schema.sql.'
);
check(
    'the only pages of a switchable app not tied to it are unsubscribe (deliberate) and auth/invite (a known open question)',
    $untied === $knownUntied,
    'Untied: ' . json_encode($untied) . '. Add a new one to AppRegistry::ROUTES_OUTSIDE_PREFIX, or explain it here.'
);

// -----------------------------------------------------------------------------
// 6. With the switch off, the folder's app reads as off
// -----------------------------------------------------------------------------
echo "\n6. With an app's switch off, its api folder's app reads as off\n";

$switchable = array_filter($folderApps, static fn ($slug): bool => $slug !== null);
check('there are sixteen folders that follow an app\'s switch', count($switchable) === 16, 'Found ' . count($switchable) . '.');
foreach ($switchable as $folder => $slug) {
    $key = (string) ($registered[$slug]['settingKey'] ?? '');
    useSettings(settingsWith($key, 'true'));
    $whenOn = AppRegistry::isEnabled((string) $slug);
    useSettings(settingsWith($key, 'false'));
    $whenOff = AppRegistry::isEnabled((string) $slug);
    useSettings([]);
    $whenMissing = AppRegistry::isEnabled((string) $slug);
    check(
        sprintf('api/%s follows %s: on when it is on, off when it is off, off when it was never set', $folder, $key),
        $key !== '' && $whenOn === true && $whenOff === false && $whenMissing === false,
        sprintf('on=%s off=%s never set=%s', var_export($whenOn, true), var_export($whenOff, true), var_export($whenMissing, true))
    );
}
useSettings([]);

// -----------------------------------------------------------------------------
// 7. ApiRouter asks, in both ways in, before the handler is loaded
// -----------------------------------------------------------------------------
echo "\n7. ApiRouter asks the question in both ways in, before the handler is loaded\n";

$apiRouter = codeOnly((string) file_get_contents(PORTAL_CORE . DIRECTORY_SEPARATOR . 'ApiRouter.php'));
foreach (['dispatch' => '$appName', 'dispatchV1' => '$app'] as $method => $folderVariable) {
    $body    = methodBody($apiRouter, $method);
    $call    = 'self::requireAppSwitchedOn(' . $folderVariable . ')';
    $gate    = strpos($body, $call);
    $exists  = strpos($body, 'is_readable($apiFile)');
    $perFlag = strpos($body, 'self::resolveEnabledFlag(');
    $load    = strpos($body, 'require $apiFile');
    check(
        sprintf('%s() calls the gate, once, with the folder it is about to load from', $method),
        $body !== '' && substr_count($body, 'requireAppSwitchedOn(') === 1 && $gate !== false,
        $body === '' ? 'The method was not found.' : 'Expected exactly one call written as ' . $call . '.'
    );
    check(
        sprintf('%s() calls it after "does the handler exist", before the per-endpoint switch and before the handler is loaded', $method),
        $gate !== false && $exists !== false && $perFlag !== false && $load !== false
            && $exists < $gate && $gate < $perFlag && $gate < $load,
        sprintf('positions — exists:%s gate:%s per-endpoint:%s load:%s', var_export($exists, true), var_export($gate, true), var_export($perFlag, true), var_export($load, true))
    );
}
// The call is a PLAIN statement, straight after the "not found" answer and
// straight before the per-endpoint switch is read: nothing wrapped round it.
// (A call inside `if (no key was sent) { … }` no longer matches.)
$apiRouterRaw = (string) file_get_contents(PORTAL_CORE . DIRECTORY_SEPARATOR . 'ApiRouter.php');
foreach (['dispatch' => '$appName', 'dispatchV1' => '$app'] as $method => $folderVariable) {
    $exact = "if(is_readable(\$apiFile)===false){ApiResponse::error('API endpoint not found',404);}"
        . 'self::requireAppSwitchedOn(' . $folderVariable . ');'
        . "\$enabled=self::resolveEnabledFlag('api.'." . $folderVariable . ".'.'.\$action.'.enabled');";
    $body  = squeezed(methodBody(codeOnly($apiRouterRaw), $method));
    $at    = strpos($body, $exact);
    check(
        sprintf('%s(): the call is a plain statement between "not found" and the per-endpoint switch, with nothing wrapped round it', $method),
        $at !== false && substr_count(substr($body, 0, (int) $at), '{') - substr_count(substr($body, 0, (int) $at), '}') === 1,
        'Expected, with spacing removed: ' . $exact
    );
}

// 🔒 THE GATE ITSELF, WORD FOR WORD. See "WHY CHECK 7 COMPARES TWO SMALL
//    FUNCTIONS WORD FOR WORD" at the top of this file. If you change
//    ApiRouter::requireAppSwitchedOn() on purpose, change this copy too, and
//    run tools/access-pages-selftest.php.
$expectedGate = <<<'GATE'
{
    if (array_key_exists($folder, AppRegistry::API_FOLDER_APPS) === false) {
        error_log(
            'ApiRouter: the api folder "' . $folder . '" has no line in AppRegistry::API_FOLDER_APPS, '
            . 'so its endpoints are refused. Add a line saying which app it belongs to (or null for none).'
        );
        ApiResponse::error('This API endpoint is disabled', 403);
    }

    $slug = AppRegistry::API_FOLDER_APPS[$folder];
    if ($slug === null) {
        return;
    }

    $keyRow     = ApiAuth::bearerKeyRow();
    $switchedOn = $keyRow !== null
        ? AppRegistry::isEnabledForSite($slug, (int) ($keyRow['siteID'] ?? 0))
        : AppRegistry::isEnabled($slug);

    if ($switchedOn === false) {
        $appName = (string) (AppRegistry::all()[$slug]['name'] ?? $slug);
        ApiResponse::error('The ' . $appName . ' app is switched off for this organisation.', 403);
    }
}
GATE;
check(
    'the gate, ApiRouter::requireAppSwitchedOn(), is exactly the code written out in this file',
    squeezed(methodBody(codeOnly($apiRouterRaw), 'requireAppSwitchedOn')) === squeezed($expectedGate),
    'It differs. A folder with no line must be refused; a null line returns; a key is judged by its own organisation; off means 403, key or no key.'
);
// 🔒 …and the question a key is answered by, word for word: no row at all
//    must read as OFF (`null` is neither '1' nor 'true').
$expectedForSite = <<<'FORSITE'
{
    $all = self::all();
    if (isset($all[$slug]) === false) {
        return false;
    }
    if (($all[$slug]['isCore'] ?? false) === true) {
        return true;
    }
    $value = App::settingForSite((string) $all[$slug]['settingKey'], $siteId);

    return $value === '1' || $value === 'true';
}
FORSITE;
check(
    'AppRegistry::isEnabledForSite() is exactly the code written out in this file',
    squeezed(methodBody(codeOnly((string) file_get_contents(PORTAL_CORE . DIRECTORY_SEPARATOR . 'AppRegistry.php')), 'isEnabledForSite')) === squeezed($expectedForSite),
    'It differs. An app that is not registered is off; a core app is on; otherwise only the stored values 1 and true mean on.'
);

$gateBody = methodBody($apiRouter, 'requireAppSwitchedOn');
check(
    'the gate reads the hand-written list, and refuses a folder that has no line',
    str_contains($gateBody, 'array_key_exists($folder, AppRegistry::API_FOLDER_APPS) === false'),
    $gateBody === '' ? 'ApiRouter::requireAppSwitchedOn() was not found.' : ''
);
check(
    'for an API key the gate asks about the KEY\'s organisation, otherwise about the address\'s',
    str_contains($gateBody, 'ApiAuth::bearerKeyRow()')
        && str_contains($gateBody, "AppRegistry::isEnabledForSite(\$slug, (int) (\$keyRow['siteID'] ?? 0))")
        && str_contains($gateBody, 'AppRegistry::isEnabled($slug)')
);
check(
    'a switched-off app is answered 403 with the sentence that names it',
    preg_match("/ApiResponse::error\('The ' \. \\\$appName \. ' app is switched off for this organisation\.', 403\)/", $gateBody) === 1
);

// -----------------------------------------------------------------------------
// 8. The "app is disabled" page keeps its own values through the header (#570)
// -----------------------------------------------------------------------------
echo "\n8. The \"app is disabled\" page names the right app (#570)\n";

// The detector's own control first: a made-up function and template that DO
// collide. If this ever stops being reported, the real check below proves
// nothing.
$controlTemplateNames = assignedNames('<?php foreach ($apps as $key => $leaked) { $other = 1; } [$first, $second] = $pair; $count++;');
check(
    'control: the detector reads a template\'s assignments (plain, foreach, list and ++)',
    $controlTemplateNames === ['count', 'first', 'key', 'leaked', 'other', 'second'],
    'It read: ' . implode(', ', $controlTemplateNames)
);
$control = headerClashes(
    '{ $leaked = "mine"; $safe = "also mine"; require X . \'header.php\'; echo $leaked . $safe; }',
    $controlTemplateNames
);
check(
    'control: a variable set before the header, read after it and assigned by the template IS reported',
    $control['found'] === true && $control['clash'] === ['leaked'] && $control['carried'] === ['leaked', 'safe'],
    json_encode($control)
);

$scanned       = [];
$templateNames = templateAssignedNames(PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php', $scanned);
$scannedNames  = array_map('basename', $scanned);
check(
    'the header template and the menu it loads were both read',
    in_array('header.php', $scannedNames, true) === true && in_array('nav.php', $scannedNames, true) === true,
    'Read: ' . implode(', ', $scannedNames)
);
check(
    'the templates assign a good number of names (so the list below is not empty by accident)',
    count($templateNames) >= 20,
    'Found ' . count($templateNames) . ' names.'
);
$router   = codeOnly((string) file_get_contents(PORTAL_CORE . DIRECTORY_SEPARATOR . 'Router.php'));
$disabled = methodBody($router, 'renderAppDisabled');
$real     = headerClashes($disabled, $templateNames);
check(
    'renderAppDisabled() loads the header, and carries the app\'s name and description across it',
    $real['found'] === true && count($real['carried']) >= 2,
    'Set before the header and read after it: ' . ($real['carried'] === [] ? '(nothing)' : implode(', ', $real['carried']))
);
check(
    'none of the values it carries across the header has a name the templates assign',
    $real['found'] === true && $real['clash'] === [],
    'The templates overwrite: $' . implode(', $', $real['clash']) . ' — the page would print whatever the menu left there.'
);
check(
    'its two links are built by Router::url(), not written as bare addresses',
    str_contains($disabled, "self::url('admin/apps')") === true && str_contains($disabled, "self::url('')") === true
        && str_contains($disabled, 'href="/') === false,
    'A bare address leaves the organisation\'s own address when organisations are told apart by the first part of it.'
);

// -----------------------------------------------------------------------------
// 9. (needs a database) the per-organisation question an API key is answered by
// -----------------------------------------------------------------------------
echo "\n9. isEnabledForSite(): the question an API key is answered by\n";

$dbName       = (string) getenv('SELFTEST_DB_NAME');
$expectChecks = EXPECTED_WITHOUT_DB;
if ($dbName === '') {
    echo "  NOT RUN — no SELFTEST_DB_NAME was given, so nothing here exercised isEnabledForSite().\n";
    echo "            Give a throwaway database (see the top of this file) to run these " . EXPECTED_DB_ONLY . " checks.\n";
} elseif (str_starts_with($dbName, 'selftest_') === false) {
    $expectChecks += EXPECTED_DB_ONLY;
    check('the database given is a throwaway one', false, "SELFTEST_DB_NAME must start with selftest_ (got '{$dbName}'). Nothing was written.");
} elseif (method_exists(AppRegistry::class, 'isEnabledForSite') === false) {
    $expectChecks += EXPECTED_DB_ONLY;
    check('AppRegistry::isEnabledForSite() exists', false);
} else {
    $expectChecks += EXPECTED_DB_ONLY;
    $mysqli = new mysqli(
        getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
        getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
        getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
        $dbName,
        getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
    );
    $mysqli->set_charset('utf8mb4');
    $dbProperty = new ReflectionProperty(App::class, 'db');
    $dbProperty->setValue(null, $mysqli);

    // Two made-up organisations, numbered where no other self-test's are
    // (the calendar tests use 910001 and 910002). Everything is inside one
    // transaction that is rolled back, so nothing is left behind even if a
    // check throws.
    $orgOn  = 915001;
    $orgOff = 915002;
    $mysqli->begin_transaction();
    try {
        $mysqli->query(
            "INSERT INTO tblSites (siteID, siteKey, siteName) VALUES ({$orgOn}, 'selftest-switch-on', 'Switch self-test, on'), "
            . "({$orgOff}, 'selftest-switch-off', 'Switch self-test, off')"
        );
        // Calendar: the portal-wide value says OFF; organisation "on" has its
        // own row saying on; organisation "off" has none, so it falls back.
        $mysqli->query("DELETE FROM tblSettings WHERE settingKey IN ('calendar.enabled', 'assets.enabled', 'selftest-none.enabled')");
        $mysqli->query(
            "INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES "
            . "(NULL, 'calendar.enabled', 'false', 'false', 0), ({$orgOn}, 'calendar.enabled', 'true', 'false', 0), "
            // Asset Tracker: the portal-wide value says ON; organisation "off"
            // has its own row saying off.
            . "(NULL, 'assets.enabled', 'true', 'true', 0), ({$orgOff}, 'assets.enabled', '0', 'true', 0)"
        );
        // The address's own settings say the OPPOSITE of each organisation's
        // answer below, so a version that read them instead would fail.
        useSettings(['calendar' => ['enabled' => 'false'], 'assets' => ['enabled' => 'true']]);
        check('Calendar is ON for the organisation whose own row says so, although the portal-wide value and the address say off', AppRegistry::isEnabledForSite('calendar', $orgOn) === true);
        check('Calendar is OFF for the organisation with no row of its own (the portal-wide value decides)', AppRegistry::isEnabledForSite('calendar', $orgOff) === false);
        check('Asset Tracker is OFF for the organisation whose own row says so, although the portal-wide value and the address say on', AppRegistry::isEnabledForSite('assets', $orgOff) === false);
        check('Asset Tracker is ON for the organisation with no row of its own', AppRegistry::isEnabledForSite('assets', $orgOn) === true);
        // No row ANYWHERE for the switch (neither the organisation's nor the
        // portal-wide one): must read as OFF. A version that treated "no
        // row" as on passed every check here until this one was added.
        $mysqli->query("DELETE FROM tblSettings WHERE settingKey = 'venues.enabled'");
        check('Venue Bookings has NO row at all for its switch: it reads as OFF for an organisation', AppRegistry::isEnabledForSite('venues', $orgOn) === false);
        $valuesRead = [];
        foreach (['true' => true, '1' => true, 'false' => false, '0' => false, '' => false, 'yes' => false, 'TRUE' => false] as $stored => $expectedOn) {
            $mysqli->query("DELETE FROM tblSettings WHERE settingKey = 'venues.enabled'");
            $write = $mysqli->prepare("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, 'venues.enabled', ?, '0', 0)");
            $storedText = (string) $stored;
            $write->bind_param('is', $orgOn, $storedText);
            $write->execute();
            $write->close();
            if (AppRegistry::isEnabledForSite('venues', $orgOn) !== $expectedOn) {
                $valuesRead[] = var_export($storedText, true);
            }
        }
        check('only the stored values "true" and "1" mean on; "false", "0", an empty value and anything else mean off', $valuesRead === [], 'Read wrongly: ' . implode(', ', $valuesRead));
        $mysqli->query("DELETE FROM tblSettings WHERE settingKey = 'venues.enabled'");
        $mysqli->query("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES ({$orgOff}, 'venues.enabled', 'true', '0', 0)");
        check('a row belonging to ANOTHER organisation does not switch the app on for this one', AppRegistry::isEnabledForSite('venues', $orgOn) === false && AppRegistry::isEnabledForSite('venues', $orgOff) === true);
        check('an app that is not registered reads as off', AppRegistry::isEnabledForSite('selftest-none', $orgOn) === false);
        check('an app that cannot be switched off reads as on, with no setting at all', AppRegistry::isEnabledForSite('dashboard', $orgOff) === true);
        check('the address\'s own answer is the opposite, so the checks above did not pass by reading it', AppRegistry::isEnabled('calendar') === false && AppRegistry::isEnabled('assets') === true);
    } finally {
        $mysqli->rollback();
        useSettings([]);
    }
}

// -----------------------------------------------------------------------------
// The end: every check that should have run, did
// -----------------------------------------------------------------------------
echo "\n" . str_repeat('=', 78) . "\n";
printf("%d passed, %d failed, %d ran of %d expected\n", $passes, $failures, $passes + $failures, $expectChecks);

if (($passes + $failures) !== $expectChecks) {
    $failures++;
    echo "\nFAIL — the number of checks that ran is not the number this file expects. A block was\n";
    echo "skipped or ran twice, or a check was added without updating the number at the top.\n";
}
if ($failures > 0) {
    echo "\nSomething about an app's off switch is wrong. Read the FAIL lines above before releasing.\n";
    exit(1);
}

echo "\nAll good. A switched-off app's data endpoints and stray pages are tied to its switch.\n";
exit(0);
