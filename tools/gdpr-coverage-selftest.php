<?php
// Path: tools/gdpr-coverage-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Erasure coverage self-test 🗄️ (#479)
 * -----------------------------------------------------------------------------
 * Standalone and dependency-free — no database, no start-up code, no network.
 * It checks that the instructions the erasure routine will follow actually match
 * the written list of where personal information lives.
 *
 * WHY THIS IS WORTH HAVING
 * ------------------------
 * Somebody has two rights over their own information: a copy of it, and its
 * deletion. Both depend on one thing — an accurate list of where that
 * information is.
 *
 * That list used to be remembered rather than written down, and it drifted. When
 * the difference was finally measured, 77 of the 126 tables holding personal
 * information were in neither the erasure list nor the download. Two were found
 * by accident, while looking at something else. The gap included a child's
 * allergies and medical notes.
 *
 * A written list fixes that only for as long as the code actually follows it.
 * This checks that it does.
 *
 * THE ONE THAT WOULD BE WORST TO GET WRONG
 * ----------------------------------------
 * A record the law says to KEEP being deleted anyway. Gift Aid declarations,
 * financial records, safeguarding records — deleting those does not merely lose
 * data, it puts the organisation in breach in the opposite direction, and it
 * cannot be undone.
 *
 * Until today the erasure routine would have done exactly that. Anything that
 * was not the word "delete" fell into a plain "else" and was treated as
 * "anonymise" — so a "keep this" instruction would have emptied the very columns
 * that had to be preserved, and reported success. The check below exists because
 * that was a real possibility, not a theoretical one.
 *
 * Usage:  php tools/gdpr-coverage-selftest.php
 * Exit:   0 if every check passes, 1 if any fails.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/479
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$catalogueFile = __DIR__ . '/../web/_core/personal-data-catalogue.php';
$eraserFile    = __DIR__ . '/../web/_core/GdprEraser.php';

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

echo "Erasure coverage — self-test\n";
echo str_repeat('=', 78) . "\n\n";

// -----------------------------------------------------------------------------
// 1. The written list has to exist and be readable.
// -----------------------------------------------------------------------------
check('the written list of personal data exists', is_readable($catalogueFile));
if (is_readable($catalogueFile) === false) {
    echo "\nWithout it nothing else can be checked.\n";
    exit(1);
}

$catalogue = require $catalogueFile;
check('it is a list', is_array($catalogue) && count($catalogue) > 0);

// -----------------------------------------------------------------------------
// 2. Every entry has a decision, and it is one of the four allowed.
// -----------------------------------------------------------------------------
$allowed = ['erase', 'unlink', 'retain', 'not-personal'];
$bad     = [];
foreach ($catalogue as $table => $meta) {
    $decision = (string) ($meta['decision'] ?? '');
    if (in_array($decision, $allowed, true) === false) {
        $bad[] = $table . ' => "' . $decision . '"';
    }
}
check(
    'every table has one of the four allowed decisions',
    $bad === [],
    implode(', ', array_slice($bad, 0, 5))
);

// -----------------------------------------------------------------------------
// 3. Every entry has a reason. An erasure that cannot be explained is not one
//    the organisation could defend if challenged.
// -----------------------------------------------------------------------------
$noReason = [];
foreach ($catalogue as $table => $meta) {
    if (trim((string) ($meta['reason'] ?? '')) === '') {
        $noReason[] = (string) $table;
    }
}
check(
    'every decision has a reason written against it',
    $noReason === [],
    implode(', ', array_slice($noReason, 0, 5))
);

// -----------------------------------------------------------------------------
// 4. THE IMPORTANT ONE. Anything kept for legal reasons must say how long.
//    "We keep this" without "for how long, and why" is not a position anybody
//    could defend.
// -----------------------------------------------------------------------------
$noPeriod = [];
foreach ($catalogue as $table => $meta) {
    if ((string) ($meta['decision'] ?? '') !== 'retain') {
        continue;
    }
    if (trim((string) ($meta['period'] ?? '')) === '') {
        $noPeriod[] = (string) $table;
    }
}
check(
    'everything kept for legal reasons says for how long',
    $noPeriod === [],
    implode(', ', array_slice($noPeriod, 0, 5))
);

// -----------------------------------------------------------------------------
// 5. THE ONE THAT WOULD BE WORST TO GET WRONG. The erasure routine must handle
//    "keep this" as its own instruction, and must REFUSE anything it does not
//    recognise rather than guessing.
// -----------------------------------------------------------------------------
$eraser = (string) file_get_contents($eraserFile);

check(
    '"keep this" is handled as its own instruction, not as a fallback',
    strpos($eraser, "\$action === 'retain'") !== false,
    'without this, a record the law says to keep would be emptied instead'
);

check(
    'an unrecognised instruction refuses instead of guessing',
    strpos($eraser, 'unrecognised instruction') !== false,
    'a plain "else" here treats anything unknown as "empty the columns", which '
    . 'would destroy exactly what had to be preserved'
);

check(
    'emptying a record is only ever done when explicitly asked for',
    strpos($eraser, "elseif (\$action === 'anonymise')") !== false,
    '"anonymise" must be its own branch, never the catch-all'
);

// -----------------------------------------------------------------------------
// 6. The erasure routine has to actually read the written list, or the list is
//    just a document.
// -----------------------------------------------------------------------------
check(
    'the erasure routine reads the written list',
    strpos($eraser, 'personal-data-catalogue.php') !== false
);

// -----------------------------------------------------------------------------
// 7. Tables with no link to an account at all. These cannot be found by
//    matching on a person, so they need handling another way. Reported rather
//    than failed — the point is that somebody knows about them.
// -----------------------------------------------------------------------------
$linkColumns = [
    'userID', 'memberID', 'donorID', 'submitterID', 'recipientUserID',
    'assignedToID', 'targetUserID', 'convertedUserID', 'uploadedByUserID',
    'leaderID', 'approverID', 'reviewedByID', 'startedByID',
    'submittedByUserID', 'createdByID', 'updatedByID',
];
$unreachable = [];
foreach ($catalogue as $table => $meta) {
    if ((string) ($meta['decision'] ?? '') !== 'erase') {
        continue;
    }
    $columns = (array) ($meta['columns'] ?? []);
    if (array_intersect($linkColumns, $columns) === []) {
        $unreachable[] = (string) $table;
    }
}

echo "\n" . str_repeat('-', 78) . "\n";
if ($unreachable !== []) {
    echo "Tables holding personal information with NO link to an account:\n\n";
    foreach ($unreachable as $table) {
        echo '  • ' . $table . "\n";
    }
    echo "\nThese cannot be found by matching on a person, so an erasure request\n";
    echo "cannot reach them on its own. Each needs either a link adding, or a rule\n";
    echo "that removes it after a set time. This is not a failure — it is the list\n";
    echo "of things that need a decision.\n";
} else {
    echo "Every table holding personal information can be traced to a person.\n";
}

echo "\n" . str_repeat('=', 78) . "\n";
printf("%d passed, %d failed\n", $passes, $failures);

if ($failures > 0) {
    echo "\nSomething about how personal information is erased has changed, and it is\n";
    echo "now wrong. Check web/_core/GdprEraser.php and the written list before\n";
    echo "releasing anything.\n";
    exit(1);
}

echo "\nAll good. The erasure routine matches the written list.\n";
exit(0);
