<?php
// Path: tools/time-limit-selftest.php
/**
 * -----------------------------------------------------------------------------
 * TimeLimit self-test ⏱️🧪 (#563)
 * -----------------------------------------------------------------------------
 * Proves that `Portal\Core\TimeLimit::atLeast()` raises PHP's time limit when
 * it is too low, never lowers it, leaves "no limit" alone, does not restart
 * the clock when the limit is already enough, and — the reason it exists —
 * does not crash on a host that has switched `set_time_limit` off.
 *
 * HOW. Each case starts a separate PHP process (a "child") with the settings
 * of a particular kind of host, runs one small piece of code, and reads what it
 * printed. Separate processes are needed because the settings under test
 * (`disable_functions`, the starting time limit) are fixed when PHP starts and
 * cannot be changed from inside a running script. The child is started with
 * its command as a list, so there is no shell between us and it.
 *
 * A CONTROL runs first: a child that makes the OLD bare `@set_time_limit()`
 * call on a switched-off host must die with "Call to undefined function". If
 * it did not, this test could not tell the old fault from a fix.
 *
 * WHAT THIS CANNOT PROVE. That each page calls the helper — the audit check
 * tools/audit-checks/check_time_limit_calls.py does that by reading the code.
 * That a real host behaves like `-d disable_functions=...`: this is the
 * closest copy of it that needs no hosting account.
 *
 * Usage:  php tools/time-limit-selftest.php
 * Exit:   0 when every case passes, 1 otherwise. No database, no network.
 *         Case 7 keeps one processor busy for about two seconds on purpose.
 * Optional: SELFTEST_TIMELIMIT_FILE=<path> runs against another copy of
 * TimeLimit.php (used to show the test FAILS on a planted fault).
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/563
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

set_exception_handler(static function (Throwable $e): void {
    echo 'FAIL — the self-test stopped early: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(1);
});

$file = getenv('SELFTEST_TIMELIMIT_FILE') !== false && getenv('SELFTEST_TIMELIMIT_FILE') !== ''
    ? (string) getenv('SELFTEST_TIMELIMIT_FILE')
    : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'TimeLimit.php';

$failures = 0;

/** Record one check. */
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok === true) {
        echo 'PASS — ' . $label . "\n";
        return;
    }
    $failures++;
    echo 'FAIL — ' . $label . ($detail !== '' ? ' (' . $detail . ')' : '') . "\n";
}

/**
 * Run `$code` in a child PHP process started with `$iniFlags` (for example
 * ['disable_functions=set_time_limit', 'max_execution_time=30']).
 *
 * @param list<string> $iniFlags
 *
 * @return array{exit:int, out:string}
 */
function child(array $iniFlags, string $code): array
{
    $cmd = [PHP_BINARY, '-n'];   // -n: ignore the machine's php.ini so the case is exactly what it says
    foreach ($iniFlags as $flag) {
        $cmd[] = '-d';
        $cmd[] = $flag;
    }
    $cmd[] = '-r';
    $cmd[] = $code;

    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($proc) === false) {
        throw new RuntimeException('could not start a child PHP process');
    }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($proc), 'out' => trim($out)];
}

// The code each child runs: load the helper, call it, print the answer and the
// limit now in force, then print a marker proving the child reached its end.
$prefix = 'require ' . var_export($file, true) . ';';
$run = static fn (int $seconds): string => $prefix
    . 'echo json_encode(["answer" => Portal\\Core\\TimeLimit::atLeast(' . $seconds . '), "limit" => (int) ini_get("max_execution_time")]);'
    . 'echo "\nREACHED-THE-END";';

/** Decode a child's first line of output. */
function answer(array $r): array
{
    $first = strtok($r['out'], "\n");

    return is_string($first) ? (array) json_decode($first, true) : [];
}

// 🧪 Control: the OLD bare call on a switched-off host really does die.
$old = child(['disable_functions=set_time_limit'], '@set_time_limit(10); echo "REACHED-THE-END";');
check('control: the old bare @set_time_limit() dies on a host that switched it off',
    $old['exit'] !== 0 && str_contains($old['out'], 'undefined function') && str_contains($old['out'], 'REACHED-THE-END') === false,
    'exit ' . $old['exit'] . ': ' . $old['out']);

// 1. Switched off: false, the child ends normally, the limit is unchanged.
$r = child(['disable_functions=set_time_limit', 'max_execution_time=30'], $run(60));
$a = answer($r);
check('function switched off: no crash, the child reaches its end', $r['exit'] === 0 && str_contains($r['out'], 'REACHED-THE-END'), 'exit ' . $r['exit'] . ': ' . $r['out']);
check('function switched off: answers false and leaves the limit at 30', ($a['answer'] ?? null) === false && ($a['limit'] ?? null) === 30, json_encode($a));

// 2. Limit 30, need 60: raised.
$r = child(['max_execution_time=30'], $run(60));
$a = answer($r);
check('limit 30, needs 60: raised to 60 and answers true', $r['exit'] === 0 && ($a['answer'] ?? null) === true && ($a['limit'] ?? null) === 60, $r['out']);

// 3. Limit 600, need 300: NOT lowered.
$r = child(['max_execution_time=600'], $run(300));
$a = answer($r);
check('limit 600, needs 300: not lowered, answers true', $r['exit'] === 0 && ($a['answer'] ?? null) === true && ($a['limit'] ?? null) === 600, $r['out']);

// 4. No limit (0): untouched, even though 0 is "less than" 300 as a number.
$r = child(['max_execution_time=0'], $run(300));
$a = answer($r);
check('no limit (0), needs 300: left at 0, answers true', $r['exit'] === 0 && ($a['answer'] ?? null) === true && ($a['limit'] ?? null) === 0, $r['out']);

// 5. Exactly enough: untouched.
$r = child(['max_execution_time=300'], $run(300));
$a = answer($r);
check('limit 300, needs 300: unchanged, answers true', $r['exit'] === 0 && ($a['answer'] ?? null) === true && ($a['limit'] ?? null) === 300, $r['out']);

// 6. Switched off AND no limit: still true (nothing needed), still no crash.
$r = child(['disable_functions=set_time_limit', 'max_execution_time=0'], $run(300));
$a = answer($r);
check('switched off but no limit (a command line): answers true, no crash', $r['exit'] === 0 && ($a['answer'] ?? null) === true && ($a['limit'] ?? null) === 0, $r['out']);

// 7. Asking again does NOT restart the clock.
//
// Every call to PHP's set_time_limit() starts the count again from zero. A job
// that asks once per turn of a loop (the feed importer asks once per calendar)
// would then never be stopped, however long it ran. So when the limit is
// already enough, the helper must leave it alone. The child has a 2-second
// limit, asks for 2 seconds on every turn of a busy loop, and would run for 6
// seconds if nothing stopped it: PHP must stop it, and it must not reach its
// end. (The loop is kept busy because on Linux and macOS the limit counts
// processor time, not time on the clock.)
//
// This case was added after an independent check showed that turning `>=`
// into `>` in the helper, which restarts the clock on every call, passed all
// of the cases above: they read the limit, and the limit reads the same.
$r = child(
    ['max_execution_time=2', 'display_errors=1'],
    $prefix . '$until = microtime(true) + 6.0;'
    . 'while (microtime(true) < $until) { Portal\\Core\\TimeLimit::atLeast(2); }'
    . 'echo "REACHED-THE-END";'
);
check('asking again at the same limit does not restart the clock: PHP stops the child at its 2 seconds',
    $r['exit'] !== 0 && str_contains($r['out'], 'Maximum execution time') === true && str_contains($r['out'], 'REACHED-THE-END') === false,
    'exit ' . $r['exit'] . ': ' . $r['out']);

echo $failures === 0 ? "PASS — time-limit self-test: every case passed and the run reached its end.\n" : "FAIL — {$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
