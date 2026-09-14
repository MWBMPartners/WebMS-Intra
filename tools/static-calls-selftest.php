<?php
// Path: tools/static-calls-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Static-call check — self-test 🧵 (#494 follow-up)
 * -----------------------------------------------------------------------------
 * Standalone and dependency-free — no database, no start-up code, no network.
 * It runs the REAL checking logic (tools/audit-checks/check_static_calls.php,
 * the same file the pull-request workflow calls) against a small, committed
 * set of fixtures (tools/audit-checks/fixtures/static_calls/) and asserts
 * every one of the 17 documented cases — PLUS four extra ones found after
 * those 17 were first built (three by an independent verifier re-checking
 * this work, one by the build itself) — comes out the way it is supposed to.
 *
 * WHY THIS IS WORTH HAVING
 * ------------------------
 * The check this file tests replaced a 1,486-line hand-written PHP lexer with
 * one built on PHP's own tokenizer, because an independent review reproduced
 * ten separate faults against the old version — six real, working PHP
 * patterns it wrongly accused of being broken, and four genuinely broken
 * patterns it let straight through. Fixing bugs like that without a
 * regression test just means the same shape of bug comes back the next time
 * somebody "simplifies" something — which is exactly what happened to the
 * ORIGINAL version of this file's own token-depth counting the first time it
 * ran against the real codebase (see the long comment in check_static_calls
 * .php's own sc_curly_delta() for that story). This file exists so every one
 * of the 17 cases stays proven, every time, not just the day it was fixed —
 * and a SECOND, independent pass over this same work found four more real
 * gaps the 17 never exercised (a trait-use vs. import distinction the call
 * scanner skipped even though the core-map builder already had it; an
 * `insteadof` adaptation block that could close a bracketed namespace one
 * brace early; and two internal bugs the build itself had already found and
 * fixed, but only ever proven by a code comment, not a committed fixture) —
 * see extra01-extra04 below for each one.
 *
 * The fixtures live in a small fake "core" directory and a small fake "apps"
 * directory under tools/audit-checks/fixtures/static_calls/ — NEVER the real
 * web/_core or web/_apps, and NEVER scanned in a normal run (tools/ is not
 * one of the four real scan roots the check uses by default). Every fixture
 * file is real, valid PHP — `php -l` clean, checked directly by this file's
 * own first check below — because faults 7, 8 and 9 are RUNTIME failures
 * (wrong method, wrong argument count, a real call PHP would really throw
 * on), not syntax errors; a fixture proving them has to be syntactically
 * perfect PHP that is nonetheless functionally wrong.
 *
 * A SECOND review by Codex, of the tokenizer version itself, reproduced eight
 * more faults: six in the PHP check and two in the Python wrapper. The six in
 * the PHP check each have their own small fixture set under
 * fixtures/static_calls/review2/ (one core/ and one apps/ folder per fault),
 * run separately by section 9 below. They are kept apart from the fixtures
 * above because two of them need two classes with the same short name in
 * the core folder, which would change every count the original cases rely
 * on. Every expected count in section 9 was decided from what real PHP does
 * with the fixture, not copied from the check's own output.
 *
 * A THIRD review reproduced three more: two in the PHP check (a class name
 * held in a constant or property read as a literal class name; a `...` or
 * `=` inside a parameter's attribute hiding that the parameter is required),
 * and one in the wrapper (failure lines the pull-request workflow's filter
 * silently dropped). The first two have fixture sets under
 * fixtures/static_calls/review3/, run by section 10. The third is tested by
 * section 11, which runs the real wrapper with a stand-in `php` and the
 * workflow's own filter lines. All three sections were run against the
 * PREVIOUS check and wrapper and failed there, which proves they detect the
 * faults rather than merely passing.
 *
 * Needs PHP 8.5: one review3 fixture uses callables in attribute arguments.
 * Section 11 also needs python3 and bash on PATH.
 *
 * Usage:  php tools/static-calls-selftest.php
 * Exit:   0 if every check passes, 1 if any fails.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/494
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$fixturesDir = __DIR__ . '/audit-checks/fixtures/static_calls';
$coreDir     = $fixturesDir . '/core';
$appsDir     = $fixturesDir . '/apps';
$checkScript = __DIR__ . '/audit-checks/check_static_calls.php';

$failures = 0;
$passes   = 0;

/**
 * ✅ Report one check.
 *
 * @param string $what   What is being checked, in plain words.
 * @param bool   $isOk   Whether it passed.
 * @param string $detail Shown only on failure.
 *
 * @return void
 */
function check(string $what, bool $isOk, string $detail = ''): void
{
    global $failures, $passes;
    if ($isOk === true) {
        $passes++;
        printf("  PASS  %s\n", $what);
        return;
    }
    $failures++;
    printf("  FAIL  %s\n", $what);
    if ($detail !== '') {
        printf("          %s\n", $detail);
    }
}

echo "Static-call check — self-test\n";
echo str_repeat('=', 78) . "\n\n";

// -----------------------------------------------------------------------------
// 0. Every fixture file has to be valid PHP on its own terms. Faults 7-9 are
//    RUNTIME failures, not syntax errors, so a fixture proving them can — and
//    must — still be syntactically perfect PHP.
// -----------------------------------------------------------------------------
check('the checking logic file exists', is_readable($checkScript));
check('the fixtures core directory exists', is_dir($coreDir));
check('the fixtures apps directory exists', is_dir($appsDir));
if ($failures > 0) {
    echo "\nWithout these, nothing else here can run.\n";
    exit(1);
}

// Every fixture file at any depth, so the second review's sets under
// review2/ are linted too. Globbing only core/*.php and apps/*.php, as this
// file first did, would silently skip them.
$fixtureFiles = [];
$fixtureIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($fixturesDir, FilesystemIterator::SKIP_DOTS)
);
foreach ($fixtureIterator as $fixtureFile) {
    if ($fixtureFile->isFile() && str_ends_with($fixtureFile->getFilename(), '.php')) {
        $fixtureFiles[] = $fixtureFile->getPathname();
    }
}
sort($fixtureFiles);
check('fixture files were found (56: the 22 original files + 24 in review2/ + 10 in review3/)', count($fixtureFiles) === 56, 'found ' . count($fixtureFiles));

$lintFailures = [];
foreach ($fixtureFiles as $file) {
    $out = [];
    $exit = 0;
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $exit);
    if ($exit !== 0) {
        $lintFailures[] = basename($file) . ': ' . implode(' / ', $out);
    }
}
check(
    'every fixture file passes php -l',
    $lintFailures === [],
    implode('; ', array_slice($lintFailures, 0, 5))
);
if ($lintFailures !== []) {
    echo "\nA fixture that isn't valid PHP proves nothing — fix it before trusting any\n";
    echo "other result below.\n";
    exit(1);
}

// -----------------------------------------------------------------------------
// 1. Fixtures are never scanned in a normal run. `tools/` is not one of the
//    four real scan roots (web/_core, web/_apps, web/public_html,
//    web/_install), so a plain, argument-free run of the real check must
//    never mention this directory at all.
// -----------------------------------------------------------------------------
$plainOut = [];
$plainExit = 0;
exec('php ' . escapeshellarg($checkScript) . ' 2>&1', $plainOut, $plainExit);
$plainOutStr = implode("\n", $plainOut);
check(
    'fixtures are not scanned in a normal (argument-free) run',
    !str_contains($plainOutStr, 'fixtures/static_calls'),
    'the plain run\'s own output mentions the fixtures directory — tools/ must never be a scan root'
);

// -----------------------------------------------------------------------------
// 2. Run the REAL checking logic against ONLY the fixtures, exactly the way
//    this self-test's own header comment says it does — --core and --root
//    make that possible without touching the real codebase at all.
// -----------------------------------------------------------------------------
/**
 * Run the real check against one fixture set (a folder holding core/ and
 * apps/), with core/ as the class map and both folders as scan roots.
 *
 * @return array{0: string, 1: int} [combined output, exit code]
 */
function runCheck(string $checkScript, string $setDir): array
{
    $cmd = sprintf(
        'php %s --core=%s --root=%s --root=%s 2>&1',
        escapeshellarg($checkScript),
        escapeshellarg($setDir . '/core'),
        escapeshellarg($setDir . '/core'),
        escapeshellarg($setDir . '/apps')
    );
    $lines = [];
    $exit = 0;
    exec($cmd, $lines, $exit);
    return [implode("\n", $lines), $exit];
}

[$output, $exitCode] = runCheck($checkScript, $fixturesDir);

check('the check runs against the fixtures at all', $output !== '', 'no output at all from the check');

// -----------------------------------------------------------------------------
// 3. Coverage figures — proves the fixture core directory was actually read,
//    not just that the run didn't crash. Every number here is exact, on
//    purpose: this file's own fixtures are the only thing under test, so
//    there is no reason any of them should ever be approximate.
// -----------------------------------------------------------------------------
/**
 * Pull the integer following a label like "Classes mapped (...): 7" out of
 * the check's own report text.
 */
function extractCount(string $output, string $label): ?int
{
    // The label must start its line (after any indent). Then `[^\n]*?:\s*`
    // skips, lazily, to the first colon that is followed by a number — which
    // tolerates a parenthetical aside ("Entities left alone (defines
    // __callStatic itself, ...): 2") AND an aside that itself contains
    // colons ("Static calls found (ClassName::method(), ...): 6485"). The
    // first version used `[^:\n]*:`, which stopped at the `::` in that
    // second label and could never read it.
    if (preg_match('/^\s*' . preg_quote($label, '/') . '[^\n]*?:\s*(\d+)/m', $output, $m) === 1) {
        return (int) $m[1];
    }
    return null;
}

check('classes mapped: 7 (Site, Auth, Greeter, OrphanChild, Base, Weird, Conflicted)', extractCount($output, 'Classes mapped (web/_core/*.php)') === 7);
check('traits mapped: 3 (Greetable, TraitA, TraitB)', extractCount($output, 'Traits mapped (web/_core/*.php)') === 3);
check('enums mapped: 1 (Status)', extractCount($output, 'Enums mapped (web/_core/*.php)') === 1);
check('interfaces mapped: 1 (Thing)', extractCount($output, 'Interfaces mapped (web/_core/*.php)') === 1);
check('fixture files scanned: 22 (21 in apps/ + 1 in core/, since core/ is ALSO passed as a scan root)', extractCount($output, 'PHP files scanned for static calls') === 22);

check(
    'entities left alone: exactly OrphanChild and Weird (unresolvable parents)',
    str_contains($output, 'OrphanChild') && str_contains($output, 'Weird')
    && extractCount($output, 'Entities left alone') === 2,
    'expected exactly 2 (OrphanChild: unqualified unknown parent; Weird: leading-backslash unknown GLOBAL parent)'
);

// -----------------------------------------------------------------------------
// 4. The finding-count summary — the three headline numbers the pull-request
//    comment is built from. 6 missing-method (5 from the 17 documented cases
//    + 1 from extra03's nested-call regression), 0 zero-argument, 1
//    unimported; see the per-case assertions below for exactly which lines
//    make up each number.
// -----------------------------------------------------------------------------
check('calls to a method that does not exist: 6', extractCount($output, 'Calls to a method that does not exist') === 6);
check('calls made with zero arguments to a method needing at least one: 0 (the insteadof fix)', extractCount($output, 'Calls made with zero arguments to a method needing at least one') === 0);
check('calls to a core class used with no import: 1', extractCount($output, 'Calls to a core class used with no import and no namespace of its own') === 1);

// -----------------------------------------------------------------------------
// 5. The exit code has to match: findings exist here on purpose (the MUST-
//    be-reported cases), so this run must fail, not pass.
// -----------------------------------------------------------------------------
check('exit code is 1 (the fixtures deliberately include real findings)', $exitCode === 1);

/**
 * One line, in the check's own "  • path:line — message" shape, naming a
 * fixture file (by its basename — the check prints a path relative to
 * WHATEVER repository root it resolved, which differs from this file's own
 * idea of that path, so basename is the one thing guaranteed to match either
 * way).
 */
function mentionsFixture(string $output, string $basename): bool
{
    foreach (explode("\n", $output) as $line) {
        if (str_contains($line, '•') && str_contains($line, $basename)) {
            return true;
        }
    }
    return false;
}

// -----------------------------------------------------------------------------
// 6. The ten NEW cases from the tokenizer rewrite (#494's Codex review).
// -----------------------------------------------------------------------------
echo "\n-- The ten cases the old hand-written lexer got wrong --------------------\n";

check('case 1 — grouped import `use Portal\\Core\\{Site, Auth};` is NOT accused', !mentionsFixture($output, 'case01_grouped_import.php'));
check('case 2 — a non-core class sharing a short name is NOT accused', !mentionsFixture($output, 'case02_shadow_import.php'));
check('case 3 — bracketed `namespace Portal\\Core { ... }` is NOT accused', !mentionsFixture($output, 'case03_bracketed_namespace.php'));
check('case 4 — trait conflict resolution (`insteadof`) is NOT accused', !mentionsFixture($output, 'case04_trait_conflict.php'));
check('case 5 — a backtick string containing a fake call is NOT accused', !mentionsFixture($output, 'case05_backtick.php'));
check('case 6 — a heredoc genuinely NESTED inside another one, whose inner body contains the outer marker word, is NOT accused', !mentionsFixture($output, 'case06_nested_heredoc.php'));
check(
    'case 7 — an aliased import (`use ... as S;` then `S::missing()`) IS reported',
    str_contains($output, 'case07_aliased_import.php:12 — S::missing()')
);
check(
    'case 8 — a nonexistent method on an ENUM IS reported (enums are now mapped)',
    str_contains($output, 'case08_enum.php:20 — Status::missing()')
);
check('case 8b — a hand-written enum static method is NOT accused', !mentionsFixture($output, 'case08_enum.php:17'));
check('case 8c — the built-in enum cases() is NOT accused', !mentionsFixture($output, 'case08_enum.php:18'));
check(
    'case 9 — a call inside string interpolation `{$a[Site::missing()]}` IS reported',
    str_contains($output, 'case09_interpolation_call.php:14 — Site::missing()')
);
check('case 10 — `extends \\Base` (leading-backslash, single segment) is SKIPPED, not mis-mapped to the sibling core class', !mentionsFixture($output, 'case10_unknown_global_parent.php'));

// -----------------------------------------------------------------------------
// 7. The seven cases the old script already handled correctly — proving the
//    rewrite did not regress any of them.
// -----------------------------------------------------------------------------
echo "\n-- The seven cases the old script already got right -----------------------\n";

check('carried 1 — a plain trait method is NOT accused', !mentionsFixture($output, 'carried01_trait_method.php'));
check('carried 2 — a class with an unknown (unqualified) parent is SKIPPED', !mentionsFixture($output, 'carried02_unknown_parent.php'));
check('carried 3 — \\Vendor\\Site::x() is NOT checked as the core Site', !mentionsFixture($output, 'carried03_fqcn_other_vendor.php'));
check('carried 4 — a call-shaped STRING nested inside interpolation is NOT accused', !mentionsFixture($output, 'carried04_nested_string_in_interpolation.php'));
check(
    'carried 5 — Site::name() IS reported (the real historical bug, #494)',
    str_contains($output, 'carried05_site_name.php:9 — Site::name()')
);
check(
    'carried 6 — a core class used with no import IS reported (the real Auth::csrfToken() outage)',
    str_contains($output, 'carried06_core_class_no_import.php:10 — Auth::csrfToken()')
);
check(
    'carried 7 — a call written right after a heredoc ending "TXT);" IS reported',
    str_contains($output, 'carried07_call_after_heredoc.php:25 — Site::name()')
);

// -----------------------------------------------------------------------------
// 8. Four EXTRA regression cases — none of the 17 documented ones; each
//    covers a real gap or a real fix found only AFTER the initial 17 were
//    built (three by an independent verifier, one by the build itself, but
//    never previously proven by a committed, permanent fixture — see each
//    file's own header comment for the full story). Kept separate from "the
//    17" everywhere in this file's own naming and counts, on purpose: the
//    17 are what the build brief asked for by name, and conflating "22
//    total" with "17 documented" would make a future case go missing
//    unnoticed inside a bigger number.
// -----------------------------------------------------------------------------
echo "\n-- Four extra cases found after the original 17 were built ----------------\n";

check(
    'extra 1 — a trait declared+used in ONE app file, sharing a short name with a core class, is NOT accused (build item 1: trait-use vs import)',
    !mentionsFixture($output, 'extra01_class_trait_use.php')
);
check(
    'extra 2 — an `insteadof` adaptation block inside a BRACKETED namespace does not close that namespace early (cases 3+4 combined)',
    !mentionsFixture($output, 'extra02_ns_trait_adapt.php')
);
check(
    'extra 3 — a static call nested inside another call\'s own arguments IS reported',
    str_contains($output, 'extra03_nested_call_in_args.php:24 — Site::missingNested()')
);
check(
    'extra 4 — a core method declared right after one containing string interpolation is NOT accused (still correctly mapped)',
    !mentionsFixture($output, 'extra04_core_interpolation_method.php')
);

// -----------------------------------------------------------------------------
// 9. The second review's six PHP-check faults, one fixture set each. For
//    every set: the exit code, the exact coverage and finding counts, every
//    line that MUST be reported, and every file that must NOT be accused.
//    "left alone" is where a call the check cannot be sure about must land:
//    neither accused nor counted as verified.
// -----------------------------------------------------------------------------
echo "\n-- The second review's six faults in the PHP check (review2/) --------------\n";

/** The six counts every set checks, in the order the report prints them. */
function review2Counts(int $calls, int $resolved, int $leftAlone, int $missing, int $zeroArg, int $unimported): array
{
    return [
        'Static calls found' => $calls,
        'resolved to one of the mapped entities' => $resolved,
        'of those, left alone' => $leftAlone,
        'Calls to a method that does not exist' => $missing,
        'Calls made with zero arguments to a method needing at least one' => $zeroArg,
        'Calls to a core class used with no import and no namespace of its own' => $unimported,
    ];
}

$review2Sets = [
    'r2_01_namespace_imports' => [
        'exit' => 1,
        // 10_: Site::vendorOnly() is Vendor\Site (not mapped); Site::ok(1) is core.
        // 20_: Site::missing() is core and missing; Site::vendorOnly() is Vendor\Site.
        'counts' => review2Counts(4, 2, 0, 1, 0, 0),
        'reported' => ['20_core_in_one_vendor_in_two.php:16 — Site::missing()'],
        'silent' => ['10_vendor_in_one_core_in_two.php'],
    ],
    'r2_02_import_lists' => [
        'exit' => 1,
        // 10_: both imported by the comma list. 20_: function/const items import
        // no class, so both are unimported. 30_: `use Auth;` is \Auth, not mapped.
        'counts' => review2Counts(5, 2, 0, 0, 0, 2),
        'reported' => [
            '20_group_function_const.php:15 — Site::free()',
            '20_group_function_const.php:16 — Auth::ok()',
        ],
        'silent' => ['10_comma_import.php', '30_single_segment_import.php'],
    ],
    'r2_03_core_namespaces' => [
        'exit' => 1,
        // Site::coreOnly() verified; Child's parent is Vendor\Base (not mapped),
        // so Child::fromVendorBase() is left alone; Site::vendorOnly() is missing.
        'counts' => review2Counts(3, 3, 1, 1, 0, 0) + ['Classes mapped' => 4, 'Entities left alone' => 1],
        'reported' => ['20_missing_core_method.php:12 — Site::vendorOnly()'],
        'silent' => ['10_real_methods.php'],
    ],
    'r2_04_conditional_declarations' => [
        'exit' => 0,
        // Both classes are declared inside if/else, so both calls are left alone.
        'counts' => review2Counts(2, 2, 2, 0, 0, 0) + ['Classes mapped' => 2, 'Entities left alone' => 2],
        'reported' => [],
        'silent' => ['10_calls.php'],
    ],
    'r2_05_by_reference_return' => [
        'exit' => 1,
        'counts' => review2Counts(2, 2, 0, 0, 1, 0),
        'reported' => ['20_need.php:12 — Site::need() called with no arguments'],
        'silent' => ['10_ok.php'],
    ],
    'r2_06_trait_selection' => [
        'exit' => 1,
        'counts' => review2Counts(5, 5, 0, 0, 1, 0),
        'reported' => ['20_still_reported.php:12 — AliasUser::ok() called with no arguments'],
        'silent' => ['10_calls_that_run.php'],
    ],
];

/**
 * Run the real check against each fixture set in `$sets` (folders under
 * `$baseDir`) and assert its exit code, counts, reported lines and silent
 * files. Shared by the second and third reviews' sets.
 */
function checkFixtureSets(string $checkScript, string $baseDir, array $sets): void
{
    foreach ($sets as $set => $expect) {
        [$setOutput, $setExit] = runCheck($checkScript, $baseDir . '/' . $set);
        check("{$set}: exit code is {$expect['exit']}", $setExit === $expect['exit'], "got {$setExit}");
        foreach ($expect['counts'] as $label => $want) {
            $got = extractCount($setOutput, $label);
            check("{$set}: \"{$label}\" is {$want}", $got === $want, 'got ' . var_export($got, true));
        }
        foreach ($expect['reported'] as $needle) {
            check("{$set}: IS reported — {$needle}", str_contains($setOutput, $needle));
        }
        foreach ($expect['silent'] as $basename) {
            check("{$set}: {$basename} is NOT accused", !mentionsFixture($setOutput, $basename));
        }
    }
}

checkFixtureSets($checkScript, $fixturesDir . '/review2', $review2Sets);

// -----------------------------------------------------------------------------
// 10. The third review's two PHP-check faults, one fixture set each, run the
//     same way as section 9. Every "reported" line really throws in PHP, and
//     every "NOT accused" file really runs without error. That was checked by
//     running each apps/ file, not assumed.
// -----------------------------------------------------------------------------
echo "\n-- The third review's two faults in the PHP check (review3/) ---------------\n";

$review3Sets = [
    'r3_01_dynamic_class_expressions' => [
        'exit' => 1,
        // 10_ and 20_: six calls through a constant or property holding a
        // class name, all left alone and none resolved. 30_: two literal
        // Site calls, still judged; one is missing.
        'counts' => review2Counts(8, 2, 0, 1, 0, 0) + ['left alone because the class is held in a constant or property' => 6],
        'reported' => ['30_still_judged.php:13 — Site::notThere()'],
        'silent' => ['10_no_import.php', '20_with_site_import.php'],
    ],
    'r3_02_parameter_attributes' => [
        'exit' => 1,
        // Three Site::ok(...) references inside core attributes, four calls in
        // 10_, one each in 20_ and 30_. The `...` and `=` inside attributes
        // no longer make need() and needClosure() optional.
        'counts' => review2Counts(9, 9, 0, 0, 2, 0) + ['left alone because the class is held in a constant or property' => 0],
        'reported' => [
            '20_need.php:10 — Site::need() called with no arguments',
            '30_need_closure.php:11 — Site::needClosure() called with no arguments',
        ],
        'silent' => ['10_runs.php'],
    ],
];

checkFixtureSets($checkScript, $fixturesDir . '/review3', $review3Sets);

// -----------------------------------------------------------------------------
// 11. The third review's wrapper fault. The pull-request workflow (step 20 of
//     .github/workflows/pr-security.yml) keeps a line only if its bullet comes
//     first on that line. A failure line written straight after a crash
//     message with no line ending, or a findings exit whose only bullet is
//     mid-line, reached the comment as nothing at all.
//
//     This runs the real wrapper with a stand-in `php` (fixtures/static_calls/
//     review3/r3_03_wrapper/fake-php.sh) and passes the output through the
//     workflow's own shell lines. The lines are copied exactly, and checked
//     below to still be in the workflow file, so a change there fails here
//     rather than leaving this test checking an old filter.
// -----------------------------------------------------------------------------
echo "\n-- The third review's wrapper fault: failure lines step 20 would drop ------\n";

$repoRoot      = dirname(__DIR__);
$wrapperScript = __DIR__ . '/audit-checks/check_static_calls.py';
$workflowText  = (string) @file_get_contents($repoRoot . '/.github/workflows/pr-security.yml');
$step20Lines   = [
    'STATIC_CALLS=$(python3 tools/audit-checks/check_static_calls.py 2>&1 || true)',
    'if echo "$STATIC_CALLS" | grep -a -q \'•\'; then',
    'echo "$STATIC_CALLS" | grep -a -E \'^[[:space:]]*•|^###\'',
];
foreach ($step20Lines as $step20Line) {
    check("step 20 of pr-security.yml still contains: {$step20Line}", str_contains($workflowText, $step20Line));
}
// The same three lines as a script. Run from the repository root, so the
// wrapper's relative path in the first line is the workflow's own.
$step20Script = $step20Lines[0] . "\n" . $step20Lines[1] . "\n  " . $step20Lines[2] . "\nfi\n";

/**
 * Run a command with its own environment and folder, and return
 * [standard output, standard error, exit code]. Reading one pipe to the end
 * before the other could stall on output larger than a pipe holds, but the
 * stand-in prints a line or two.
 */
function runProcess(array $command, array $env, string $cwd): array
{
    $pipes = [];
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
    if (!is_resource($process)) {
        return ['', 'proc_open failed for ' . implode(' ', $command), -1];
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [$stdout, $stderr, proc_close($process)];
}

// Copied rather than run in place, so the test never depends on the fixture's
// executable bit surviving a checkout.
$fakeDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sc-selftest-fake-php-' . bin2hex(random_bytes(6));
$fakeReady = @mkdir($fakeDir, 0700)
    && @copy($fixturesDir . '/review3/r3_03_wrapper/fake-php.sh', $fakeDir . '/php')
    && @chmod($fakeDir . '/php', 0755);
register_shutdown_function(static function () use ($fakeDir): void {
    @unlink($fakeDir . '/php');
    @rmdir($fakeDir);
});
check('the stand-in php is ready in a temporary folder', $fakeReady);

$crashLine = '  • check_static_calls: the checking logic exited with code 7';
$noFindingLine = '  • check_static_calls: the checking logic exited with code 1 (findings) but printed no finding line';
$realFinding = '  • web/x.php:3 - Site::name() - no such method';
$wrapperCases = [
    // stdout: exact expected bytes, or ['startsWith' => ...]. report: a line
    // that must START a line of step 20's output, or '' for "must be empty".
    'crash_stdout_no_newline' => ['exit' => 7, 'stdout' => ['startsWith' => "Fatal error\n{$crashLine}"], 'stderr' => '', 'report' => $crashLine],
    'crash_stderr_no_newline' => ['exit' => 7, 'stdout' => ['startsWith' => "\n{$crashLine}"], 'stderr' => 'Fatal error', 'report' => $crashLine],
    'exit1_mid_line_bullet' => ['exit' => 1, 'stdout' => ['startsWith' => "error mentions • in middle\n{$noFindingLine}"], 'stderr' => '', 'report' => $noFindingLine],
    'exit1_bullet_split_across_streams' => ['exit' => 1, 'stdout' => ['startsWith' => "partial\n{$noFindingLine}"], 'stderr' => "{$realFinding}\n", 'report' => $noFindingLine],
    'exit1_real_finding' => ['exit' => 1, 'stdout' => "### Calls to a method that does not exist\n\n{$realFinding}\n", 'stderr' => '', 'report' => $realFinding],
    'clean' => ['exit' => 0, 'stdout' => "check_static_calls: OK - nothing to report\n", 'stderr' => '', 'report' => ''],
];

foreach ($wrapperCases as $mode => $expect) {
    $env = getenv();
    $env['PATH'] = $fakeDir . PATH_SEPARATOR . ($env['PATH'] ?? '/usr/bin:/bin');
    $env['SC_FAKE_PHP_MODE'] = $mode;

    [$stdout, $stderr, $exit] = runProcess(['python3', $wrapperScript], $env, $repoRoot);
    check("wrapper, {$mode}: exit code is {$expect['exit']}", $exit === $expect['exit'], "got {$exit}; stderr: {$stderr}");
    if (is_array($expect['stdout'])) {
        check(
            "wrapper, {$mode}: the stand-in's output is unchanged and the failure line starts a line of its own",
            str_starts_with($stdout, $expect['stdout']['startsWith']),
            'stdout was: ' . json_encode($stdout, JSON_UNESCAPED_UNICODE)
        );
    } else {
        check("wrapper, {$mode}: output passed through byte for byte, nothing added", $stdout === $expect['stdout'], 'stdout was: ' . json_encode($stdout, JSON_UNESCAPED_UNICODE));
    }
    check("wrapper, {$mode}: standard error passed through unchanged", $stderr === $expect['stderr'], 'stderr was: ' . json_encode($stderr, JSON_UNESCAPED_UNICODE));

    [$report] = runProcess(['bash', '-c', $step20Script], $env, $repoRoot);
    if ($expect['report'] === '') {
        check("wrapper, {$mode}: step 20's filters keep nothing", $report === '', 'kept: ' . json_encode($report, JSON_UNESCAPED_UNICODE));
        continue;
    }
    $kept = array_filter(explode("\n", $report), static fn (string $line): bool => str_starts_with($line, $expect['report']));
    check("wrapper, {$mode}: step 20's filters keep the line \"{$expect['report']}\"", $kept !== [], 'kept: ' . json_encode($report, JSON_UNESCAPED_UNICODE));
}

// -----------------------------------------------------------------------------
// 12. The success message states plainly what is, and is not, verified — so
//     a clean run can never be mistaken for a stronger guarantee than it is.
// -----------------------------------------------------------------------------
check(
    'a clean run\'s own success message says what is NOT verified',
    str_contains($plainOutStr, 'NOT verified')
    && str_contains($plainOutStr, 'argument TYPES')
    && str_contains($plainOutStr, 'visibility')
);

echo "\n" . str_repeat('-', 78) . "\n";
printf("%d passed, %d failed\n", $passes, $failures);

if ($failures > 0) {
    echo "\nOne or more of the 17 documented cases, one of the 4 extra cases found since,\n";
    echo "one of the second review's six fixture sets, or one of the third review's\n";
    echo "two fixture sets or wrapper cases came out wrong. Check\n";
    echo "tools/audit-checks/check_static_calls.php (or check_static_calls.py for a\n";
    echo "\"wrapper\" line) against the specific fixture(s) named above before trusting\n";
    echo "a real-codebase run.\n";
    exit(1);
}

echo "\nAll good. Every one of the 17 documented cases — the ten the old\n";
echo "hand-written lexer got wrong, and the seven it already got right — plus the\n";
echo "4 extra cases found after those 17 were first built, the second review's\n";
echo "six fixture sets, and the third review's two fixture sets and wrapper cases,\n";
echo "still comes out the way it is supposed to.\n";
exit(0);
