<?php
// Path: tools/report-builder-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Reports Builder injection-safety self-test 🛡️📊 (#156)
 * -----------------------------------------------------------------------------
 * Standalone, dependency-free (no DB, no bootstrap, no network) regression
 * guard for `Portal\Core\ReportRegistry` / `Portal\Core\ReportBuilder` — the
 * whitelist registry and the ONE place report SQL is assembled.
 *
 * Exercises the REAL classes (not a reimplementation):
 *   PART A — ReportRegistry::assertSelfConsistent() passes on the live
 *            registry (naming conventions + forbidden-table blocklist).
 *   PART B — a benign definition compiles cleanly, with the tenant-scope
 *            predicate provably first and outside the user-filter parens.
 *   PART C — a red-team pass: every hostile/malformed definition throws
 *            \InvalidArgumentException BEFORE any SQL string exists —
 *            unknown source/column/operator, injection-shaped strings in
 *            col/op/dir/transform/conjunction fields, oversized IN lists,
 *            arity mismatches, a gated column probed with no session (no
 *            role), and a stale/future format version.
 *   PART D — bind arity: for every definition compiled in B/C that
 *            succeeds, strlen(types) === count(params) AND the '?' count
 *            in the compiled SQL equals count(params) exactly.
 *
 * A stub `App::init()` is called with an UNCONNECTED mysqli handle
 * (`mysqli_init()` never calls real_connect()) purely so
 * `AppRegistry::isEnabled()` can resolve the six sources' app-enabled
 * flags from an in-memory settings array — no network, no DB I/O occurs
 * anywhere in this script. `App::user()` returns null in a CLI process
 * (no active session), so every gated-column check below runs as an
 * anonymous/no-role caller — exactly the "non-treasurer probes a gated
 * column" scenario the security self-proof calls for.
 *
 * Usage:  php tools/report-builder-selftest.php
 * Exit:   0 on success (all assertions pass), 1 on any failure.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/156
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

if (defined('PORTAL_CORE') === false) {
    define('PORTAL_CORE', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core');
}

require PORTAL_CORE . '/App.php';
require PORTAL_CORE . '/AppRegistry.php';
require PORTAL_CORE . '/ReportRegistry.php';
require PORTAL_CORE . '/ReportBuilder.php';

use Portal\Core\App;
use Portal\Core\ReportBuilder;
use Portal\Core\ReportRegistry;

$failures = 0;
function assertTrue(string $label, bool $cond): void
{
    global $failures;
    echo ($cond === true ? 'PASS' : 'FAIL') . ' — ' . $label . "\n";
    if ($cond === false) {
        $failures++;
    }
}

function expectThrows(string $label, callable $fn): void
{
    global $failures;
    try {
        $fn();
        echo 'FAIL — ' . $label . ' (no exception thrown)' . "\n";
        $failures++;
    } catch (\InvalidArgumentException $e) {
        echo 'PASS — ' . $label . ' -> InvalidArgumentException: ' . $e->getMessage() . "\n";
    } catch (\Throwable $e) {
        echo 'FAIL — ' . $label . ' (wrong exception type: ' . get_class($e) . ': ' . $e->getMessage() . ')' . "\n";
        $failures++;
    }
}

// 🔧 Stub App::init() — unconnected mysqli handle (mysqli_init() never
// calls real_connect()), settings array flips every source's owning app
// (+ 'reports' itself) enabled so compile()'s AppRegistry::isEnabled()
// gate can resolve without touching a real database.
$stubDb = mysqli_init();
App::init($stubDb, [
    'directory'   => ['enabled' => 'true'],
    'calendar'    => ['enabled' => 'true'],
    'attendance'  => ['enabled' => 'true'],
    'expenses'    => ['enabled' => 'true'],
    'giving'      => ['enabled' => 'true'],
    'tasks'       => ['enabled' => 'true'],
    'reports'     => ['enabled' => 'true'],
]);

echo "=== PART A — ReportRegistry::assertSelfConsistent() ===\n";
try {
    ReportRegistry::assertSelfConsistent();
    assertTrue('registry is self-consistent', true);
} catch (\Throwable $e) {
    assertTrue('registry is self-consistent (' . $e->getMessage() . ')', false);
}

echo "\n=== PART B — benign definition compiles + tenant scope is first ===\n";

$benign = [
    'v'       => 1,
    'source'  => 'users',
    'columns' => ['fullName', 'isActive', 'locale'],
    'filters' => [
        'conjunction' => 'AND',
        'rows' => [
            ['col' => 'isActive', 'op' => 'eq', 'vals' => [1]],
        ],
    ],
    'sort' => ['col' => 'fullName', 'dir' => 'asc'],
];
$compiledBenign = ReportBuilder::compile($benign, 42);
assertTrue('benign definition compiles', is_string($compiledBenign['sql']));
assertTrue(
    'tenant scope is the FIRST predicate in WHERE (outside any filter parens)',
    (bool) preg_match('/WHERE us\.siteID = \? AND us\.isActive = 1 AND \(/', $compiledBenign['sql'])
);
assertTrue(
    'siteID param (42) is bound first',
    $compiledBenign['params'][0] === 42
);

echo "\n=== PART C — red-team definitions (each MUST throw before any SQL exists) ===\n";

$base = static fn (array $overrides): array => array_merge([
    'v' => 1, 'source' => 'expenses', 'columns' => ['claimTitle'],
], $overrides);

expectThrows('unknown source (SQL-injection-shaped string)', static function () use ($base): void {
    ReportBuilder::compile($base(['source' => 'tblUsers; DROP TABLE tblUsers']), 1);
});

expectThrows('injection attempt in a filter column key', static function () use ($base): void {
    ReportBuilder::compile($base([
        'filters' => ['conjunction' => 'AND', 'rows' => [
            ['col' => '1=1 OR claimDate', 'op' => 'eq', 'vals' => ['x']],
        ]],
    ]), 1);
});

expectThrows('injection attempt in a filter operator key', static function () use ($base): void {
    ReportBuilder::compile($base([
        'filters' => ['conjunction' => 'AND', 'rows' => [
            ['col' => 'claimTitle', 'op' => "LIKE ' OR '1'='1", 'vals' => ['x']],
        ]],
    ]), 1);
});

expectThrows('injection attempt in sort.dir', static function () use ($base): void {
    ReportBuilder::compile($base(['sort' => ['col' => 'claimTitle', 'dir' => 'desc; DELETE']]), 1);
});

expectThrows('injection attempt in group.transform', static function (): void {
    ReportBuilder::compile([
        'v' => 1, 'source' => 'attendance',
        'group' => ['col' => 'sessionDate', 'transform' => '0x'],
        'aggregates' => [['col' => null, 'fn' => 'count']],
    ], 1);
});

expectThrows('injection attempt in filters.conjunction', static function () use ($base): void {
    ReportBuilder::compile($base([
        'filters' => ['conjunction' => 'OR 1=1', 'rows' => [
            ['col' => 'claimTitle', 'op' => 'eq', 'vals' => ['x']],
        ]],
    ]), 1);
});

expectThrows('oversized IN value list (21 > max 20)', static function () use ($base): void {
    ReportBuilder::compile($base([
        'filters' => ['conjunction' => 'AND', 'rows' => [
            ['col' => 'status', 'op' => 'in', 'vals' => array_fill(0, 21, 'Pending')],
        ]],
    ]), 1);
});

expectThrows('arity mismatch (between with 1 value instead of 2)', static function () use ($base): void {
    ReportBuilder::compile($base([
        'filters' => ['conjunction' => 'AND', 'rows' => [
            ['col' => 'claimDate', 'op' => 'between', 'vals' => ['2026-01-01']],
        ]],
    ]), 1);
});

expectThrows('gated column selected by a caller with no role/session (SELECT position)', static function () use ($base): void {
    ReportBuilder::compile($base(['columns' => ['claimTitle', 'totalAmount']]), 1);
});

expectThrows('gated column filtered by a caller with no role/session (WHERE position — blocks the filter-as-oracle leak)', static function () use ($base): void {
    ReportBuilder::compile($base([
        'filters' => ['conjunction' => 'AND', 'rows' => [
            ['col' => 'totalAmount', 'op' => 'gt', 'vals' => [100000]],
        ]],
    ]), 1);
});

expectThrows('strictly-treasurer donor-identity column, probed by a non-treasurer (A8)', static function (): void {
    ReportBuilder::compile([
        'v' => 1, 'source' => 'giving', 'columns' => ['donorName'],
    ], 1);
});

expectThrows('stale/future format version', static function () use ($base): void {
    ReportBuilder::compile(array_merge($base([]), ['v' => 999]), 1);
});

expectThrows('unknown top-level key', static function () use ($base): void {
    ReportBuilder::compile($base(['sql' => 'SELECT * FROM tblUsers']), 1);
});

expectThrows('unknown column key', static function () use ($base): void {
    ReportBuilder::compile($base(['columns' => ['claimTitle', 'notARealColumn']]), 1);
});

expectThrows('unknown aggregation function', static function (): void {
    ReportBuilder::compile([
        'v' => 1, 'source' => 'expenses',
        'group' => ['col' => 'status', 'transform' => null],
        'aggregates' => [['col' => 'totalAmount', 'fn' => 'sumAndDropTable']],
    ], 1);
});

echo "\n=== PART D — bind-arity recount on every successful compile above ===\n";

$reCheck = static function (string $label, array $definition, int $siteId) use (&$failures): void {
    $compiled = ReportBuilder::compile($definition, $siteId);
    $qMarks = substr_count($compiled['sql'], '?');
    $ok = strlen($compiled['types']) === count($compiled['params'])
        && $qMarks === count($compiled['params']);
    echo ($ok === true ? 'PASS' : 'FAIL') . " — {$label}: types=" . strlen($compiled['types'])
        . ' params=' . count($compiled['params']) . " '?'count={$qMarks}\n";
    if ($ok === false) {
        $failures++;
    }
};

$reCheck('benign users definition', $benign, 42);
// Grouped mode + no-gate columns only (no session = no role = every
// gated column would refuse — this second recount deliberately stays on
// tasks.* so it exercises grouping/IN/BETWEEN rather than re-proving the
// gate check part C already covers).
$reCheck('grouped tasks definition with IN + BETWEEN filters', [
    'v' => 1, 'source' => 'tasks',
    'group' => ['col' => 'status', 'transform' => null],
    'aggregates' => [['col' => 'dueDate', 'fn' => 'count'], ['col' => null, 'fn' => 'count']],
    'filters' => ['conjunction' => 'OR', 'rows' => [
        ['col' => 'priority', 'op' => 'in', 'vals' => ['high', 'urgent']],
        ['col' => 'dueDate', 'op' => 'between', 'vals' => ['2026-01-01', '2026-12-31']],
    ]],
    'sort' => ['col' => 'count_dueDate', 'dir' => 'desc'],
], 7);

echo "\n" . ($failures === 0 ? "ALL PASS ({$failures} failures)\n" : "{$failures} FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
