<?php
// Path: tools/restore-order-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Restore ordering self-test 🔗 (#472)
 * -----------------------------------------------------------------------------
 * Standalone and dependency-free — no database, no start-up code, no network.
 * It runs the REAL ordering method inside Portal\Core\DbBackup against made-up
 * table layouts, and checks the order it produces is actually usable.
 *
 * WHY THIS IS WORTH HAVING
 * ------------------------
 * Putting a backup back is the one operation nobody gets to practise. It runs
 * when something has already gone wrong, usually in a hurry, usually by someone
 * who is worried. If it is subtly broken, that is the worst possible moment to
 * find out.
 *
 * Getting the ORDER wrong is the easiest way to break it, and it fails in a way
 * that looks fine in testing. Tables point at each other: an attendance record
 * points at an event, an event points at a site. A row cannot be written before
 * the row it points at exists. So the tables have to be filled parents-first and
 * emptied children-first, and if that order is even slightly wrong the restore
 * stops part way through — during a recovery.
 *
 * The real database has no loops today. This checks the code still behaves
 * sensibly if one ever appears, rather than quietly producing an order that
 * cannot work.
 *
 * WHAT THIS CANNOT TELL YOU
 * -------------------------
 * Only the ordering. It does not and cannot prove that a real restore against a
 * real database works — that needs a real database, and the machine this was
 * written on had no working Docker at the time. Say so plainly rather than
 * letting a green tick here be mistaken for more than it is.
 *
 * Usage:  php tools/restore-order-selftest.php
 * Exit:   0 if every check passes, 1 if any check fails.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/472
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require __DIR__ . '/../web/_core/DbBackup.php';

use Portal\Core\DbBackup;

/**
 * 🔓 Reach the private ordering method without needing a database connection.
 *
 * The method is private on purpose — nothing outside the class should be
 * ordering tables. But testing it through a real restore would need a real
 * database, and then this test could not run at all. So it is called directly,
 * which also means this exercises the SHIPPED code rather than a copy of it.
 *
 * @param array<int, string>                $tables The tables to order.
 * @param array<string, array<int, string>> $graph  Which tables point at which.
 *
 * @return array<int, string>|null The order, or null if they cannot be ordered.
 */
function orderTables(array $tables, array $graph): ?array
{
    $method = new ReflectionMethod(DbBackup::class, 'restoreOrder');
    // A DbBackup instance needs a database connection it will never use here,
    // so the method is invoked without one.
    $instance = (new ReflectionClass(DbBackup::class))->newInstanceWithoutConstructor();
    return $method->invoke($instance, $tables, $graph);
}

$failures = 0;
$passes   = 0;

/**
 * ✅ Check one case and report it.
 *
 * @param string $what     What is being checked, in plain words.
 * @param bool   $isOk     Whether it passed.
 * @param string $detail   What went wrong, shown only on failure.
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

echo "Restore ordering — self-test\n";
echo str_repeat('=', 78) . "\n\n";

// -----------------------------------------------------------------------------
// 1. A straight chain, which is the ordinary case.
//    An attendance row points at an event; an event points at a site.
// -----------------------------------------------------------------------------
$graph = [
    'tblAttendance' => ['tblEvents'],
    'tblEvents'     => ['tblSites'],
    'tblSites'      => [],
];
$order = orderTables(['tblAttendance', 'tblEvents', 'tblSites'], $graph);

check(
    'a straight chain is ordered parents-first',
    $order !== null
        && array_search('tblSites', $order, true) < array_search('tblEvents', $order, true)
        && array_search('tblEvents', $order, true) < array_search('tblAttendance', $order, true),
    'got: ' . ($order === null ? 'null' : implode(' -> ', $order))
);

// -----------------------------------------------------------------------------
// 2. THE CHECK THAT MATTERS MOST. Every table must come after everything it
//    points at. If this is wrong the restore stops half way through, during a
//    recovery, which is the worst possible time.
// -----------------------------------------------------------------------------
$graph = [
    'tblChildA' => ['tblParent'],
    'tblChildB' => ['tblParent', 'tblOther'],
    'tblParent' => ['tblRoot'],
    'tblOther'  => ['tblRoot'],
    'tblRoot'   => [],
];
$order = orderTables(array_keys($graph), $graph);

$everyParentFirst = true;
if ($order === null) {
    $everyParentFirst = false;
} else {
    $position = array_flip($order);
    foreach ($graph as $table => $parents) {
        foreach ($parents as $parent) {
            if ($position[$parent] > $position[$table]) {
                $everyParentFirst = false;
            }
        }
    }
}
check(
    'every table comes after everything it points at',
    $everyParentFirst,
    'got: ' . ($order === null ? 'null' : implode(' -> ', $order))
);

// -----------------------------------------------------------------------------
// 3. A table pointing at ITSELF must not stop the ordering. Nine real foreign
//    keys in this database do exactly that — a category whose parent is another
//    category, for example. It constrains rows within one table, not the order
//    tables are handled in.
// -----------------------------------------------------------------------------
$graph = ['tblCategories' => ['tblCategories'], 'tblSites' => []];
$order = orderTables(['tblCategories', 'tblSites'], $graph);

check(
    'a table pointing at itself does not block the ordering',
    $order !== null && count($order) === 2,
    'got: ' . ($order === null ? 'null — this would refuse a perfectly good restore' : implode(' -> ', $order))
);

// -----------------------------------------------------------------------------
// 4. A CIRCLE must be refused, not guessed at. Two tables pointing at each other
//    cannot be filled in any order that works. Returning some order anyway would
//    mean a restore that half-works and reports success — the exact failure this
//    whole piece of work exists to remove.
// -----------------------------------------------------------------------------
$graph = ['tblAlpha' => ['tblBeta'], 'tblBeta' => ['tblAlpha']];
$order = orderTables(['tblAlpha', 'tblBeta'], $graph);

check(
    'tables pointing at each other in a circle are refused, not guessed at',
    $order === null,
    'got an order back: ' . ($order === null ? '' : implode(' -> ', $order)) . ' — it should have refused'
);

// -----------------------------------------------------------------------------
// 5. Nothing must be lost or duplicated. A table missing from the order would
//    silently never be restored.
// -----------------------------------------------------------------------------
$graph = [
    'tblOne' => ['tblTwo'], 'tblTwo' => ['tblThree'], 'tblThree' => [],
    'tblFour' => [], 'tblFive' => ['tblOne'],
];
$order = orderTables(array_keys($graph), $graph);

check(
    'every table appears exactly once, none lost or repeated',
    $order !== null
        && count($order) === 5
        && count(array_unique($order)) === 5
        && array_diff(array_keys($graph), $order) === [],
    'got: ' . ($order === null ? 'null' : implode(' -> ', $order))
);

// -----------------------------------------------------------------------------
// 6. A table pointing at something not being restored must still be placed.
//    Restoring one table out of a snapshot is a real thing people do.
// -----------------------------------------------------------------------------
$graph = ['tblLonely' => ['tblNotInThisRestore']];
$order = orderTables(['tblLonely'], $graph);

check(
    'a table pointing at something outside the restore is still placed',
    $order !== null && $order === ['tblLonely'],
    'got: ' . ($order === null ? 'null' : implode(' -> ', $order))
);

// -----------------------------------------------------------------------------
// 7. Nothing to do is not a failure.
// -----------------------------------------------------------------------------
check('an empty list is handled without complaint', orderTables([], []) === []);

echo "\n" . str_repeat('=', 78) . "\n";
printf("%d passed, %d failed\n", $passes, $failures);

if ($failures > 0) {
    echo "\nThe order tables are put back in has changed, and it is now wrong.\n";
    echo "A restore in this state could stop half way through. Check\n";
    echo "web/_core/DbBackup.php before releasing anything.\n";
    exit(1);
}

echo "\nAll good. Tables will be put back in an order that works.\n";
echo "\nNote what this does NOT prove: it checks the ORDERING only. Whether a real\n";
echo "restore against a real database works still needs a real database.\n";
exit(0);
