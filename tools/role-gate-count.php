<?php
// Path: tools/role-gate-count.php
/**
 * -----------------------------------------------------------------------------
 * How many hard-coded role gates are left, counted properly 🔢 (#727)
 * -----------------------------------------------------------------------------
 * Counts calls to `App::hasRole()` and `App::can()` under `web/`, using PHP's
 * own tokeniser with comments and docblocks dropped, and splits the
 * `App::hasRole()` calls into those passing a literal key (which a capability
 * could replace) and those working the key out at run time (which one cannot).
 *
 * WHY THIS EXISTS, AND IT IS THE WHOLE POINT
 * -------------------------------------------------------------------------
 * Four separate documents in this repository quoted a `grep` count of these
 * gates as a measured fact, and every one of them was wrong, because
 *     grep -rhoE "App::hasRole\(" web --include='*.php' | wc -l
 * counts every mention in a comment or a docblock as well as every call. On
 * commit `b12ff44` that command answered 87 where there were 65 calls. Worse,
 * CONVERTING a gate makes the grep count go DOWN by less than one, because
 * each converted file keeps a comment naming the gate it replaced — on the
 * working tree of 3 November 2026, `grep -rn "App::hasRole('asset_manager')"`
 * returned 19 hits of which ZERO were calls.
 *
 * So the rule the owner's instructions already state — "Count the call sites,
 * never quote a number" — needs something to count them WITH. This is it.
 * Cite this script, not a figure.
 *
 * WHAT IT CANNOT DO, said plainly
 * -------------------------------------------------------------------------
 *   - It cannot tell whether a gate SHOULD become a capability. A literal key
 *     is merely a candidate; whether the door it opens is a thing a customer
 *     recognises is a judgement, and `Portal\Core\Capabilities` is where that
 *     judgement is written down.
 *   - It counts a call written `\Portal\Core\App::hasRole()` or through an
 *     alias or a variable class name as nothing at all, because it matches
 *     the three tokens `App` `::` `hasRole`. Every call in this repository is
 *     written that way today (checked), but a future one need not be.
 *   - It says nothing about reachability. A gate inside a function nothing
 *     calls is counted like any other.
 *   - It is NOT an audit gate and exits 0 whatever it finds. Nothing should
 *     fail a push on a count; the count is for a person deciding scope.
 *
 * USAGE
 * -------------------------------------------------------------------------
 *     php tools/role-gate-count.php                 # the whole picture
 *     php tools/role-gate-count.php asset_manager   # one role key as well
 *
 * A role key given as the first argument is counted separately, as literal
 * `App::hasRole('<key>')` calls only — that is the figure "37 of 61" in
 * `Capabilities`' class header came from.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/727
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$repoRoot = dirname(__DIR__);
$webRoot  = $repoRoot . DIRECTORY_SEPARATOR . 'web';

if (is_dir($webRoot) === false) {
    fwrite(STDERR, "Cannot find the web folder at " . $webRoot . "\n");
    exit(1);
}

/** The role key to report on separately, if one was asked for. */
$oneKey = isset($argv[1]) === true ? (string) $argv[1] : '';

/**
 * Every call to `App::<method>(` in one file, with comments and docblocks
 * dropped, as a list of ['line' => int, 'firstArg' => array|string|null].
 *
 * The tokeniser is what makes this honest: a `//` or `/** *​/` mention is a
 * single T_COMMENT / T_DOC_COMMENT token and is thrown away before anything
 * is matched, so a comment can never be counted as a call.
 *
 * @param string $source The PHP source.
 * @param string $method The static method name to look for.
 *
 * @return list<array{line:int, firstArg:mixed}>
 */
function rgc_callsIn(string $source, string $method): array
{
    $kept = [];
    foreach (token_get_all($source) as $token) {
        if (is_array($token) === true
            && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT || $token[0] === T_WHITESPACE)) {
            continue;
        }
        $kept[] = $token;
    }

    $found = [];
    $count = count($kept);
    for ($i = 0; $i < $count - 3; $i++) {
        $classToken  = $kept[$i];
        $colonToken  = $kept[$i + 1];
        $methodToken = $kept[$i + 2];
        $parenToken  = $kept[$i + 3];

        if (is_array($classToken) === false || $classToken[0] !== T_STRING || $classToken[1] !== 'App') {
            continue;
        }
        if (is_array($colonToken) === false || $colonToken[0] !== T_DOUBLE_COLON) {
            continue;
        }
        if (is_array($methodToken) === false || $methodToken[0] !== T_STRING || $methodToken[1] !== $method) {
            continue;
        }
        if ($parenToken !== '(') {
            continue;
        }

        $found[] = [
            'line'     => (int) $classToken[2],
            'firstArg' => $kept[$i + 4] ?? null,
        ];
    }

    return $found;
}

/**
 * Every `.php` file under a folder, as absolute paths, sorted.
 *
 * @param string $root
 *
 * @return list<string>
 */
function rgc_phpFiles(string $root): array
{
    $paths    = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $entry) {
        if ($entry->isFile() === true && strtolower($entry->getExtension()) === 'php') {
            $paths[] = $entry->getPathname();
        }
    }
    sort($paths);

    return $paths;
}

$files = rgc_phpFiles($webRoot);

$hasRoleSites    = 0;
$hasRoleFiles    = [];
$hasRoleLiteral  = 0;
$hasRoleRunTime  = [];
$canSites        = 0;
$canFiles        = [];
$oneKeySites     = 0;
$oneKeyFiles     = [];
$perKey          = [];

foreach ($files as $path) {
    $source   = (string) file_get_contents($path);
    $relative = substr($path, strlen($repoRoot) + 1);

    foreach (rgc_callsIn($source, 'hasRole') as $call) {
        $hasRoleSites++;
        $hasRoleFiles[$relative] = true;
        $arg = $call['firstArg'];

        if (is_array($arg) === true && $arg[0] === T_CONSTANT_ENCAPSED_STRING) {
            $hasRoleLiteral++;
            // Strip the surrounding quotes; the keys in this repository hold
            // no escapes, so this needs nothing cleverer.
            $key = trim((string) $arg[1], "'\"");
            $perKey[$key] = ($perKey[$key] ?? 0) + 1;
            if ($oneKey !== '' && $key === $oneKey) {
                $oneKeySites++;
                $oneKeyFiles[$relative] = true;
            }
            continue;
        }

        // 🧐 NOT literal. A class constant (`self::EXPORT_ROLE`) is listed
        //    here too, because the tokeniser cannot resolve it — but it is a
        //    FIXED key, so the line below says which kind each one is rather
        //    than lumping them together as "variable".
        $shown = is_array($arg) === true ? (string) $arg[1] : (string) $arg;
        $kind  = (is_array($arg) === true && $arg[0] === T_VARIABLE) ? 'variable' : 'fixed, not a literal';
        $hasRoleRunTime[] = $relative . ':' . $call['line'] . '  ' . $shown . '   (' . $kind . ')';
    }

    foreach (rgc_callsIn($source, 'can') as $call) {
        $canSites++;
        $canFiles[$relative] = true;
    }
}

echo "Counted with PHP's tokeniser, comments and docblocks dropped.\n";
echo "Folder: web/\n\n";

echo "App::hasRole()  — a hard-coded role gate\n";
echo "  call sites:                 " . $hasRoleSites . "\n";
echo "  files:                      " . count($hasRoleFiles) . "\n";
echo "  literal key:                " . $hasRoleLiteral . "\n";
echo "  key not a literal:          " . count($hasRoleRunTime) . "\n";
foreach ($hasRoleRunTime as $line) {
    echo "      " . $line . "\n";
}

echo "\nApp::can()      — a capability gate (#727)\n";
echo "  call sites:                 " . $canSites . "\n";
echo "  files:                      " . count($canFiles) . "\n";

if ($perKey !== []) {
    arsort($perKey);
    echo "\nLiteral role keys, commonest first\n";
    foreach ($perKey as $key => $n) {
        printf("  %-28s %d\n", $key, $n);
    }
}

if ($oneKey !== '') {
    echo "\nAsked for: App::hasRole('" . $oneKey . "')\n";
    echo "  literal call sites:         " . $oneKeySites . "\n";
    echo "  files:                      " . count($oneKeyFiles) . "\n";
    foreach (array_keys($oneKeyFiles) as $relative) {
        echo "      " . $relative . "\n";
    }
}

echo "\nFor comparison, the command NOT to trust:\n";
$grepCount = 0;
foreach ($files as $path) {
    $grepCount += (int) preg_match_all('/App::hasRole\(/', (string) file_get_contents($path));
}
echo "  grep -rhoE \"App::hasRole\\(\" web --include='*.php' | wc -l  ->  " . $grepCount . "\n";
echo "  (comments and docblocks included, so it is larger than the call count)\n";

// 🚪 Never a gate: this is a count for a person, not a check.
exit(0);
