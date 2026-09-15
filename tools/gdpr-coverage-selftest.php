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
    'assignedToID', 'targetUserID', 'convertedUserID', 'leaderID',
    'uploadedByUserID', 'approverID', 'reviewedByID', 'startedByID',
    'submittedByUserID', 'createdByID', 'updatedByID',
    'parentUserID', 'counterpartyUserID', 'recordedByID',
    'approvedByID', 'assignedByID',
];

// Columns that say the person ACTED on a record, rather than that the record is
// about them. Kept in step with GdprEraser::ACTOR_COLUMNS.
$actorColumns = [
    'uploadedByUserID', 'approverID', 'reviewedByID', 'startedByID',
    'submittedByUserID', 'createdByID', 'updatedByID',
    'parentUserID', 'counterpartyUserID', 'recordedByID',
    'approvedByID', 'assignedByID',
    'addedByID', 'markedByID', 'openedByID', 'closedByID',
    'moderatedByID', 'moderatorID', 'presenterID', 'scannedByID',
    'bookedByID', 'requestedByID', 'acceptedByID', 'revokedByID',
    'counter1ID', 'counter2ID', 'offboardedByID', 'rehiredByID',
    'grantedByID', 'enrolledByID', 'linkedByID', 'releasedByID',
    'processedByID',
];

// Tables that DO delete the whole record even though the only link is one of
// those columns. Every entry here has been looked at deliberately and needs a
// reason. Adding to this list should feel uncomfortable.
$deliberateDeletes = [
    // A parent signs a child up. The record holds the CHILD's name, date of
    // birth, allergies and medical notes - deleting it is the entire point.
    'tblEventRegistrations' => 'A child\'s medical details; deletion is the purpose',
];

// Tables with no single column that can be matched against a person, which a
// dedicated step in GdprEraser erases instead (with a matching block in the
// data export). Each maps to the name of that step. Check 10 below proves each
// step exists and runs, so these are left out of the "no link" report rather
// than listed there as if nothing handled them.
$dedicatedSteps = [
    // #498: a list entry only means "this person" together with its tableName.
    'tblDemoDataRegister' => 'eraseDemoDataRegisterEntries',
];

$unreachable = [];
foreach ($catalogue as $table => $meta) {
    if ((string) ($meta['decision'] ?? '') !== 'erase') {
        continue;
    }
    $columns = (array) ($meta['columns'] ?? []);
    if (array_intersect($linkColumns, $columns) === [] && isset($dedicatedSteps[(string) $table]) === false) {
        $unreachable[] = (string) $table;
    }
}

// -----------------------------------------------------------------------------
// 8. THE ONE THAT WOULD DESTROY SOMEBODY ELSE'S RECORDS. A table set to delete
//    the whole row, where the only thing tying it to a person is that they
//    ACTED on it - who recorded it, who approved it, who their parent was.
//
//    Seven tables were in exactly that state. Deleting on those links would
//    have destroyed a venue invoice payment because of who typed it in, a small
//    group's attendance register because of who took it, and a child's profile
//    because of who their parent was.
//
//    This FAILS rather than warns. Getting it wrong destroys other people's
//    records on one person's request, and it cannot be undone.
// -----------------------------------------------------------------------------
$dangerous = [];
foreach ($catalogue as $table => $meta) {
    if ((string) ($meta['decision'] ?? '') !== 'erase') {
        continue;
    }
    $columns = (array) ($meta['columns'] ?? []);
    $links   = array_values(array_intersect($linkColumns, $columns));
    if ($links === []) {
        continue;
    }
    // Does it have ANY link meaning "this record is about them"?
    if (array_diff($links, $actorColumns) !== []) {
        continue;
    }
    if (isset($deliberateDeletes[(string) $table]) === true) {
        continue;
    }
    $dangerous[] = (string) $table . ' (only link: ' . implode(', ', $links) . ')';
}
check(
    'nothing deletes a whole record just because somebody acted on it',
    $dangerous === [],
    implode('; ', $dangerous)
    . ' -- keep the record and drop the name, or add it to $deliberateDeletes '
    . 'with a reason'
);

// -----------------------------------------------------------------------------
// 9. THE ONE THAT MAKES THIS WHOLE FILE WORTH HAVING. There are TWO lists, and
//    until today they disagreed about 17 tables without anybody noticing.
//
//    GdprEraser holds a hand-written list of instructions. The written
//    catalogue holds the complete inventory. The hand-written one silently
//    WINS: any table it mentions is skipped when the catalogue is read.
//
//    So for those 17 tables the catalogue said one thing and the portal did
//    another. It said DELETE the account row (which would have dragged
//    hundreds of records down with it) while the code emptied it. It said KEEP
//    a pastoral case as the law requires while the code emptied it. And a
//    correction made to the catalogue for a child's profile had no effect
//    whatsoever, because the hand-written entry overrode it.
//
//    Two lists that can disagree are not a list. They are a guess. This makes
//    disagreement impossible: every hand-written instruction must match the
//    catalogue's decision for the same table, and every hand-written table must
//    appear in the catalogue at all.
// -----------------------------------------------------------------------------
$handWrittenPart = substr($eraser, 0, (int) strpos($eraser, 'private static function fromPersonalDataCatalogue'));
preg_match_all(
    "/'table'\s*=>\s*'(\w+)'\s*,\s*'userCol'\s*=>\s*'(\w+)'\s*,\s*'action'\s*=>\s*'(\w+)'/s",
    $handWrittenPart,
    $handMatches,
    PREG_SET_ORDER
);

// The two vocabularies for the same four decisions.
$sameThing = [
    'erase'  => 'delete',
    'unlink' => 'anonymise',
    'retain' => 'retain',
];

// One table may legitimately carry TWO instructions, and this has to allow for
// it. A group membership row is DELETED for the person it is about, while the
// same table's "who added them" column is only unlinked. Both are right.
//
// So the catalogue's decision is compared against the instruction for the
// SUBJECT - the one not keyed on an actor column - and any actor-keyed
// instruction is checked separately for the thing that would actually do harm.
$disagreements = [];
$notListed     = [];
$actorDeletes  = [];
foreach ($handMatches as $entry) {
    $table   = (string) $entry[1];
    $userCol = (string) $entry[2];
    $action  = (string) $entry[3];

    if (isset($catalogue[$table]) === false) {
        $notListed[] = $table;
        continue;
    }

    $decision = (string) ($catalogue[$table]['decision'] ?? '');

    if (in_array($userCol, $actorColumns, true) === true) {
        // Keyed on "they did this". Deleting the whole record here would
        // destroy somebody else's record on this person's request.
        if ($action === 'delete' && isset($deliberateDeletes[$table]) === false) {
            $actorDeletes[] = $table . '.' . $userCol;
        }
        continue;
    }

    $expected = $sameThing[$decision] ?? '';
    if ($expected !== $action) {
        $disagreements[] = $table . '.' . $userCol . ': the list says "'
            . $decision . '" but the code does "' . $action . '"';
    }
}

check(
    'the code never deletes a record just because somebody acted on it',
    $actorDeletes === [],
    implode(', ', $actorDeletes)
);

check(
    'the written list and the code agree about every table',
    $disagreements === [],
    implode('; ', array_slice($disagreements, 0, 6))
);

check(
    'every table the code touches appears in the written list',
    $notListed === [],
    implode(', ', array_slice($notListed, 0, 8))
    . ' -- a table handled by the code but absent from the list is invisible to '
    . 'every count, every review and every report'
);

// -----------------------------------------------------------------------------
// 10. Tables erased by a DEDICATED step, because no single column can be
//     matched against a person (see $dedicatedSteps above). The generic
//     handling skips such a table on purpose, so if the dedicated step were
//     removed, or never called, nothing would erase it and nothing would say
//     so. Each one must: be marked "erase" in the written list; have its step
//     defined in GdprEraser; have that step called from execute() BEFORE the
//     catalogue is walked (the #498 step finds a person's membership entries
//     through rows the catalogue deletes); and be handed over by the data
//     export page.
// -----------------------------------------------------------------------------
$exportFile  = __DIR__ . '/../web/_apps/auth/account/data-export.php';
$export      = is_readable($exportFile) === true ? (string) file_get_contents($exportFile) : '';
$executeFrom = strpos($eraser, 'public static function execute(');
$executeTo   = strpos($eraser, 'public static function inventory(');
$executePart = ($executeFrom !== false && $executeTo !== false && $executeTo > $executeFrom)
    ? substr($eraser, $executeFrom, $executeTo - $executeFrom)
    : '';

$dedicatedFaults = [];
foreach ($dedicatedSteps as $table => $method) {
    if ((string) ($catalogue[$table]['decision'] ?? '') !== 'erase') {
        $dedicatedFaults[] = $table . ' is not marked "erase" in the written list';
    }
    if (strpos($eraser, 'private static function ' . $method . '(') === false) {
        $dedicatedFaults[] = 'GdprEraser has no ' . $method . '()';
    }
    $callAt      = strpos($executePart, 'self::' . $method . '(');
    $catalogueAt = strpos($executePart, 'self::catalogue()');
    if ($callAt === false) {
        $dedicatedFaults[] = 'execute() never calls ' . $method . '()';
    } elseif ($catalogueAt !== false && $callAt > $catalogueAt) {
        $dedicatedFaults[] = 'execute() calls ' . $method . '() after the catalogue is walked';
    }
    if (preg_match('/\b(FROM|JOIN)\s+' . preg_quote($table, '/') . '\b/', $export) !== 1) {
        $dedicatedFaults[] = 'the data export never reads ' . $table;
    }
}
check(
    'every table erased by a dedicated step is erased, in time, and exported',
    $dedicatedFaults === [],
    implode('; ', $dedicatedFaults)
);

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
