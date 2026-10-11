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
 * It loads the REAL class (`GdprEraser::catalogue()` needs no database) and
 * checks the instructions it really produces, using the class's own lists of
 * column names. It used to keep copies of those lists; the copies drifted
 * (#535), which is how eight tables came to be promised and never touched.
 *
 * WHAT IT CANNOT DO. It proves the instructions are the right ones. It cannot
 * prove they WORK on a database: that a statement runs, that only the right
 * rows change, or that a request survives its own erasure (#510). That is
 * `tools/gdpr-eraser-selftest.php`, which runs the real erasure on a throwaway
 * database.
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
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/479
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/510
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/535
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$catalogueFile = __DIR__ . '/../web/_core/personal-data-catalogue.php';
$eraserFile    = __DIR__ . '/../web/_core/GdprEraser.php';
$schemaFile    = __DIR__ . '/../web/_sql/full_schema.sql';

// 🔢 How many checks this file makes — EXACTLY. See the end of the file.
const EXPECTED_CHECKS = 26;

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
// 7. ONE list of the columns that tie a record to a person (#535).
//
//    This file used to carry its own copy of that list, and a second copy of
//    the "acted on it" names, each "kept in step" with GdprEraser by hand.
//    GdprEraser carried a third, private copy. They drifted: two names were in
//    one and not another, and the tables that used them were passed over in
//    silence. Everything below now reads the real class's constants, and the
//    REAL instructions it produces, so this test cannot agree with itself
//    while disagreeing with the code.
// -----------------------------------------------------------------------------
require_once $eraserFile;

$linkColumns       = \Portal\Core\GdprEraser::LINK_COLUMNS;
$actorColumns      = \Portal\Core\GdprEraser::ACTOR_COLUMNS;
// Tables that DO delete the whole record even though the only link says the
// person acted on it. Each was looked at deliberately and carries its reason.
$deliberateDeletes = \Portal\Core\GdprEraser::DELETE_ON_ACTOR_COLUMN;
// Tables a dedicated step erases, because no single column can be matched
// against a person. Each maps to the name of that step (check 10).
$dedicatedSteps    = \Portal\Core\GdprEraser::DEDICATED_STEP_TABLES;
// Tables execute() deals with itself, last, and the table walk never touches.
$finalStepTables   = \Portal\Core\GdprEraser::FINAL_STEP_TABLES;
// What the erasure routine will really do: needs no database.
$instructions      = \Portal\Core\GdprEraser::catalogue();

check(
    'the erasure routine keeps no second, private list of person columns',
    strpos($eraser, '$linkColumns') === false,
    'GdprEraser.php has a $linkColumns of its own again; use self::LINK_COLUMNS'
);
check(
    'every "acted on it" column name is also on the one list of person columns',
    array_diff($actorColumns, $linkColumns) === [],
    implode(', ', array_diff($actorColumns, $linkColumns))
);

// Instructions grouped by table, for the checks below.
$byTable = [];
foreach ($instructions as $instruction) {
    $byTable[(string) $instruction['table']][] = $instruction;
}

// -----------------------------------------------------------------------------
// 7a. Every instruction is one the erasure routine will carry out. A 'refuse'
//     instruction is how GdprEraser says "the written list asks for something
//     I will not do"; at run time it is a failed step. Here it fails the build.
// -----------------------------------------------------------------------------
$refusals = [];
foreach ($instructions as $instruction) {
    if (in_array((string) $instruction['action'], ['delete', 'anonymise', 'retain'], true) === false) {
        $refusals[] = (string) $instruction['table'] . ' ("' . (string) $instruction['action'] . '": '
            . (string) ($instruction['reason'] ?? '') . ')';
    }
}
check(
    'every instruction is one the erasure routine will carry out',
    $refusals === [],
    implode('; ', array_slice($refusals, 0, 4))
);

// -----------------------------------------------------------------------------
// 7b. THE #535 SHAPE. A table the written list says to erase or unlink must get
//     at least one instruction that really deletes or empties something, or be
//     handled by a dedicated or final step. Eight tables were promised and got
//     nothing at all, with no line in any audit trail to show it.
// -----------------------------------------------------------------------------
$promisedAndSkipped = [];
foreach ($catalogue as $table => $meta) {
    $table    = (string) $table;
    $decision = (string) ($meta['decision'] ?? '');
    if ($decision !== 'erase' && $decision !== 'unlink') {
        continue;
    }
    if (isset($dedicatedSteps[$table]) === true || in_array($table, $finalStepTables, true) === true) {
        continue;
    }
    $acts = false;
    foreach ($byTable[$table] ?? [] as $instruction) {
        if (in_array((string) $instruction['action'], ['delete', 'anonymise'], true) === true) {
            $acts = true;
        }
    }
    if ($acts === false) {
        $promisedAndSkipped[] = $table;
    }
}
check(
    'every table the list says to erase or unlink really gets an instruction',
    $promisedAndSkipped === [],
    implode(', ', $promisedAndSkipped) . ' -- the list promises something the erasure routine cannot do: '
    . 'list a real person column, or mark the table "retain" with the true reason'
);

// -----------------------------------------------------------------------------
// 7c. Names are checked against the thing that uses them: the database. Every
//     table and column an instruction names, and every column a catalogue entry
//     lists, must exist in the fresh-install script. A wrong name does not
//     crash anything - it is a table quietly never reached, or (for a kept
//     table) an audit line saying "table missing" about a table that exists.
// -----------------------------------------------------------------------------
/**
 * 🗺️ Every table in the fresh-install script, with its columns.
 *
 * Read as text, the same way tools/audit-checks/check_personal_data_coverage.py
 * reads it, so the two agree about what exists. What it cannot do: it sees only
 * CREATE TABLE statements, so a column added by an ALTER TABLE would be missed.
 * (The script has none that adds an ordinary column; the count check below
 * fails loudly if the reading ever stops working.)
 *
 * @return array<string, string[]> Table name => column names.
 */
function schemaColumns(string $file): array
{
    $text = (string) file_get_contents($file);
    $text = (string) preg_replace('#/\*.*?\*/#s', '', $text);
    $text = (string) preg_replace('/--[^\n]*/', '', $text);

    $tables = [];
    preg_match_all('/CREATE TABLE IF NOT EXISTS\s+`(\w+)`(.*?)\n\)\s*ENGINE/s', $text, $found, PREG_SET_ORDER);
    foreach ($found as $one) {
        preg_match_all('/^\s+`(\w+)`/m', $one[2], $columns);
        $tables[$one[1]] = array_values(array_unique(array_merge($tables[$one[1]] ?? [], $columns[1])));
    }

    return $tables;
}

$schema = schemaColumns($schemaFile);
check(
    'the fresh-install script could be read (more than 200 tables found)',
    count($schema) > 200,
    'found ' . count($schema) . ' -- the checks below would prove nothing'
);

$wrongInInstructions = [];
foreach ($instructions as $instruction) {
    $table = (string) $instruction['table'];
    if (isset($schema[$table]) === false) {
        $wrongInInstructions[] = $table . ' (no such table)';
        continue;
    }
    $named = array_merge(
        (string) $instruction['userCol'] === '' ? [] : [(string) $instruction['userCol']],
        array_map('strval', (array) ($instruction['nullCols'] ?? [])),
        array_map('strval', array_keys((array) ($instruction['overrides'] ?? [])))
    );
    foreach ($named as $column) {
        if (in_array($column, $schema[$table], true) === false) {
            $wrongInInstructions[] = $table . '.' . $column;
        }
    }
}
check(
    'every table and column an instruction names exists in the database',
    $wrongInInstructions === [],
    implode(', ', array_slice($wrongInInstructions, 0, 8))
);

$wrongInList = [];
foreach ($catalogue as $table => $meta) {
    $table = (string) $table;
    if (isset($schema[$table]) === false) {
        $wrongInList[] = $table . ' (no such table)';
        continue;
    }
    foreach ((array) ($meta['columns'] ?? []) as $column) {
        if (in_array((string) $column, $schema[$table], true) === false) {
            $wrongInList[] = $table . '.' . (string) $column;
        }
    }
}
check(
    'every column the written list names exists in the database',
    $wrongInList === [],
    implode(', ', array_slice($wrongInList, 0, 8))
);

// -----------------------------------------------------------------------------
// 7d. THE #510 SHAPE. No instruction may touch the erasure request or its audit
//     trail. One did: it deleted the request being carried out, the database
//     deleted every audit line with it, and the run crashed half way. The
//     request is closed by execute() as its last step, and nowhere else.
// -----------------------------------------------------------------------------
$touchesTheRecord = [];
foreach ($instructions as $instruction) {
    $table = (string) $instruction['table'];
    if ($table === 'tblErasureAudit' || in_array($table, $finalStepTables, true) === true) {
        $touchesTheRecord[] = $table . ' (' . (string) $instruction['action'] . ')';
    }
}
check(
    'no instruction touches the erasure request or its audit trail',
    $touchesTheRecord === [],
    implode(', ', $touchesTheRecord)
);
check(
    'the erasure request is listed as a final step, and the list says "unlink"',
    in_array('tblErasureRequest', $finalStepTables, true) === true
        && (string) ($catalogue['tblErasureRequest']['decision'] ?? '') === 'unlink',
    'it says "' . (string) ($catalogue['tblErasureRequest']['decision'] ?? '(missing)') . '"'
);

// -----------------------------------------------------------------------------
// 7e. A record the list says to KEEP must have nothing but "keep" instructions.
// -----------------------------------------------------------------------------
$keptButChanged = [];
foreach ($catalogue as $table => $meta) {
    if ((string) ($meta['decision'] ?? '') !== 'retain') {
        continue;
    }
    $kept = $byTable[(string) $table] ?? [];
    if ($kept === []) {
        $keptButChanged[] = (string) $table . ' (no instruction, so the audit trail would not say it was kept)';
    }
    foreach ($kept as $instruction) {
        if ((string) $instruction['action'] !== 'retain') {
            $keptButChanged[] = (string) $table . ' (' . (string) $instruction['action'] . ')';
        }
    }
}
check(
    'everything the list says to keep is kept, and the audit trail will say so',
    $keptButChanged === [],
    implode(', ', $keptButChanged)
);

// Tables the owner has decided, by name, must be KEPT. The check above only
// proves the list and the code agree; if both were changed back together they
// would still agree. This one fails whichever of them is changed.
$ownerSaysKeep = [
    // Roll-out decision 8: kept for tax reasons (HMRC may ask for six years
    // after the last gift claimed; that period is not confirmed with an
    // accountant). It was deleted on erasure until then.
    'tblGiftAidDeclaration' => 'Salem874, 11 October 2026',
];
$notKept = [];
foreach ($ownerSaysKeep as $table => $decidedBy) {
    $actions = array_map(static fn (array $e): string => (string) $e['action'], $byTable[$table] ?? []);
    if ((string) ($catalogue[$table]['decision'] ?? '') !== 'retain' || $actions !== ['retain']) {
        $notKept[] = $table . ' (list: "' . (string) ($catalogue[$table]['decision'] ?? 'missing') . '", code: '
            . ($actions === [] ? 'no instruction' : implode('+', $actions)) . '; kept by decision of ' . $decidedBy . ')';
    }
}
check(
    'the records the owner decided must be kept (Gift Aid declarations) are kept',
    $notKept === [],
    implode('; ', $notKept)
);

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
//
//    Checked twice, on purpose: once against what the written list SAYS, and
//    once (below) against the instructions the code really PRODUCES.
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
    . ' -- keep the record and drop the name, or add it to '
    . 'GdprEraser::DELETE_ON_ACTOR_COLUMN with a reason'
);

// The same rule, on the real instructions - hand-written and derived alike.
$actorDeletes = [];
foreach ($instructions as $instruction) {
    if ((string) $instruction['action'] === 'delete'
        && in_array((string) $instruction['userCol'], $actorColumns, true) === true
        && isset($deliberateDeletes[(string) $instruction['table']]) === false
    ) {
        $actorDeletes[] = (string) $instruction['table'] . '.' . (string) $instruction['userCol'];
    }
}
check(
    'the code never deletes a record just because somebody acted on it',
    $actorDeletes === [],
    implode(', ', $actorDeletes)
);

// -----------------------------------------------------------------------------
// 9. THE ONE THAT MAKES THIS WHOLE FILE WORTH HAVING. There are TWO lists, and
//    until 11 September 2026 they disagreed about 17 tables without anybody
//    noticing.
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
// SUBJECT - the one not keyed on an actor column. An actor-keyed instruction
// is covered by the check just above, which is the one that would do harm.
$disagreements = [];
$notListed     = [];
foreach ($handMatches as $entry) {
    $table   = (string) $entry[1];
    $userCol = (string) $entry[2];
    $action  = (string) $entry[3];

    if (isset($catalogue[$table]) === false) {
        $notListed[] = $table;
        continue;
    }

    if (in_array($userCol, $actorColumns, true) === true) {
        continue;
    }

    $decision = (string) ($catalogue[$table]['decision'] ?? '');
    $expected = $sameThing[$decision] ?? '';
    if ($expected !== $action) {
        $disagreements[] = $table . '.' . $userCol . ': the list says "'
            . $decision . '" but the code does "' . $action . '"';
    }
}

check(
    'the hand-written instructions could be read (more than 50 found)',
    count($handMatches) > 50,
    'found ' . count($handMatches) . ' -- the two checks below would prove nothing'
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
//     matched against a person (GdprEraser::DEDICATED_STEP_TABLES). The generic
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
if ($dedicatedSteps === []) {
    $dedicatedFaults[] = 'GdprEraser::DEDICATED_STEP_TABLES is empty, so this check would prove nothing';
}
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

// -----------------------------------------------------------------------------
// Reported, not failed: what an erasure request CANNOT reach. Each of these is
// kept, and the audit trail of every erasure says so. The point of printing
// them is that somebody knows, and can decide on a time limit for each.
// -----------------------------------------------------------------------------
$unreachable = [];
foreach ($instructions as $instruction) {
    if ((string) $instruction['action'] === 'retain' && (string) $instruction['userCol'] === '') {
        $unreachable[] = (string) $instruction['table'];
    }
}

echo "\n" . str_repeat('-', 78) . "\n";
if ($unreachable !== []) {
    echo "Tables holding personal information with NO link to an account:\n\n";
    foreach ($unreachable as $table) {
        echo '  • ' . $table . "\n";
    }
    echo "\nThese cannot be found by matching on a person, so an erasure request\n";
    echo "cannot reach them. Each is kept, and every erasure's audit trail says so.\n";
    echo "Each needs either a link adding, or a rule that removes it after a set\n";
    echo "time. This is not a failure — it is the list of things that need a\n";
    echo "decision.\n";
} else {
    echo "Every table holding personal information can be traced to a person.\n";
}

echo "\n" . str_repeat('=', 78) . "\n";
printf("%d passed, %d failed\n", $passes, $failures);

// 🔢 The run must have reached this line having made every check. A check that
//    was never made cannot fail, so "0 failed" alone proves nothing. Change the
//    number only together with adding or removing a check.
if (($passes + $failures) !== EXPECTED_CHECKS) {
    printf(
        "\nFAIL: %d checks were made and exactly %d were expected. A check was skipped,\n"
        . "added or removed without updating EXPECTED_CHECKS at the top of this file.\n",
        $passes + $failures,
        EXPECTED_CHECKS
    );
    exit(1);
}

if ($failures > 0) {
    echo "\nSomething about how personal information is erased has changed, and it is\n";
    echo "now wrong. Check web/_core/GdprEraser.php and the written list before\n";
    echo "releasing anything.\n";
    exit(1);
}

echo "\nAll good. The erasure routine matches the written list.\n";
exit(0);
