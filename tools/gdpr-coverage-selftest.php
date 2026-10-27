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
 * @version   1.2.0
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
const EXPECTED_CHECKS = 68;

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
// 7e. A record the list says to KEEP must be kept: it has a "keep" instruction,
//     and nothing may delete it or empty anything on it.
//
//     NARROWED on 11 October 2026 (roll-out package A2a, #492), and exactly
//     this far. This used to demand NOTHING BUT "keep" instructions. A kept
//     table can also name who HANDLED the record - who typed in a DBS check,
//     who ran a giving statement - and that person has the same right to be
//     forgotten as anybody. So one other kind of instruction is allowed on a
//     kept table: one that empties a single "acted on it" column and nothing
//     else (no other column, no fixed value, no extra SQL). It cannot touch
//     the record of the person it is kept about: it only ever matches a row
//     where THAT column names the erased person, and only empties that
//     column. Anything wider still fails here.
// -----------------------------------------------------------------------------
$keptButChanged = [];
foreach ($catalogue as $table => $meta) {
    if ((string) ($meta['decision'] ?? '') !== 'retain') {
        continue;
    }
    $kept  = $byTable[(string) $table] ?? [];
    $keeps = 0;
    foreach ($kept as $instruction) {
        if ((string) $instruction['action'] === 'retain') {
            $keeps++;
            continue;
        }
        $onlyDetachesWhoHandledIt = (string) $instruction['action'] === 'anonymise'
            && in_array((string) $instruction['userCol'], $actorColumns, true) === true
            && (array) ($instruction['nullCols'] ?? []) === []
            && (array) ($instruction['overrides'] ?? []) === []
            && (array) ($instruction['sqlSets'] ?? []) === [];
        if ($onlyDetachesWhoHandledIt === false) {
            $keptButChanged[] = (string) $table . '.' . (string) $instruction['userCol'] . ' (' . (string) $instruction['action'] . ')';
        }
    }
    if ($keeps === 0) {
        $keptButChanged[] = (string) $table . ' (no "keep" instruction, so the audit trail would not say it was kept)';
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

// GdprEraser::DELETE_ON_ACTOR_COLUMN lets two TABLES be deleted from on a column
// that says the person acted on the record. It names the table, not the
// column, and one of those tables now has a second such column: who REVIEWED
// a child's event registration. A delete on that would destroy a child's
// record, medical details and all, because of who looked it over. So the one
// column each of those tables may be deleted on is written out here, exactly.
$mayOnlyDeleteOn = [
    'tblEventRegistrations' => 'submittedByUserID', // whoever signed the child up
    'tblCareAccessLog'      => 'viewerID',          // a line saying this person opened a file
];
$wrongDelete = array_diff(array_keys($deliberateDeletes), array_keys($mayOnlyDeleteOn));
foreach ($instructions as $instruction) {
    $table = (string) $instruction['table'];
    if ((string) $instruction['action'] === 'delete' && isset($deliberateDeletes[$table]) === true
        && (string) $instruction['userCol'] !== ($mayOnlyDeleteOn[$table] ?? '')
    ) {
        $wrongDelete[] = $table . ' is deleted on `' . (string) $instruction['userCol'] . '`';
    }
}
check(
    'where a whole record MAY be deleted because of what somebody did, it is only on the one column decided: a child\'s registration on who submitted it, never on who reviewed it',
    $wrongDelete === [],
    implode('; ', $wrongDelete)
);

// -----------------------------------------------------------------------------
// 8b. THE #492 SHAPE: A SECOND PERSON COLUMN THAT NOTHING MATCHES ON.
//
//     An instruction matches rows on ONE column. For years a table with two
//     person columns got one instruction, matched on the first, which emptied
//     the second "only on those rows". A gift somebody else gave and the
//     erased person RECORDED therefore kept their number; so did 36 other
//     columns, counted on a real database on 11 October 2026.
//
//     Two rules stop it coming back. The real-database test proves the same
//     thing on rows (its section 4c); these fail without a database.
// -----------------------------------------------------------------------------
$noInstructionOfItsOwn = [];
foreach ($catalogue as $table => $meta) {
    $table = (string) $table;
    if ((string) ($meta['decision'] ?? '') === 'not-personal'
        || isset($dedicatedSteps[$table]) === true || in_array($table, $finalStepTables, true) === true
    ) {
        continue;
    }
    $matchedOn = array_map(static fn (array $e): string => (string) $e['userCol'], $byTable[$table] ?? []);
    foreach (array_intersect($linkColumns, (array) ($meta['columns'] ?? [])) as $column) {
        if (in_array($column, $matchedOn, true) === false) {
            $noInstructionOfItsOwn[] = $table . '.' . $column;
        }
    }
}
check(
    'every person column the written list names has an instruction that matches on THAT column',
    $noInstructionOfItsOwn === [],
    implode(', ', $noInstructionOfItsOwn) . ' -- named in the list, and only reached when the same row also matches another column'
);

$emptiedInPassing = [];
foreach ($instructions as $instruction) {
    foreach ((array) ($instruction['nullCols'] ?? []) as $column) {
        if (in_array((string) $column, $linkColumns, true) === true && (string) $column !== (string) $instruction['userCol']) {
            $emptiedInPassing[] = (string) $instruction['table'] . ': `' . (string) $column . '` is emptied only on rows matched by `'
                . (string) $instruction['userCol'] . '`';
        }
    }
}
check(
    'no instruction empties a second person column "in passing": each such column has to be matched on in its own right',
    $emptiedInPassing === [],
    implode('; ', $emptiedInPassing) . ' -- give that column an instruction of its own'
);

// -----------------------------------------------------------------------------
// 8b. A COLUMN THE WRITTEN LIST NAMES AND NOTHING EMPTIES (#626, 27 October
//     2026).
//
//     The check above asks about columns that hold an ACCOUNT NUMBER. This one
//     asks about the rest: a column the written list names as what makes a
//     table personal, which is not an account number, on a table the list
//     promises to "unlink". Such a column has to be emptied by some
//     instruction, or the table keeps pointing at the person after the
//     erasure.
//
//     THIS IS WHAT #626 WAS. Six columns were named for the activity log and
//     only one, `userID`, was ever emptied. The address the request came from,
//     the browser's name, the headers, the session's fingerprint and the facts
//     about the session all stayed — and the facts held the account number
//     that had just been emptied beside them. Nothing noticed for months,
//     because every check here was about account numbers.
//
//     THE PINNED LIST BELOW IS NOT AN EXCUSE LIST. It is the 35 tables that
//     were in that state on the day this check was written. They are pinned by
//     name so that:
//       - a table NOT on the list must be clean, so this check fails the
//         moment somebody adds a column to the written list and empties
//         nothing, or takes the emptying away again from the activity log;
//       - the 35 cannot be forgotten. They are written up as their own issue,
//         and the owner decides each: empty it, or say in the written list
//         that it is deliberately kept and why.
//     Nothing may be ADDED to this list. Taking a table off it is the work.
//
//     Some of the 35 are explained and some look like the same fault as #626.
//     `tblUsers`' two image columns are emptied by eraseProfileImages() once
//     the file itself is dealt with, which is deliberate (emptying them here
//     would lose the only record of where the file is). `tblErrors` holding an
//     address, a browser name and headers is #626 over again on another table.
//     A `notes` column is a judgement: free text somebody typed about a person
//     may be the record the organisation must keep. None of that is settled
//     here, and this check does not pretend to settle it.
//
//     Tables dealt with by a dedicated step or by the last step are left out:
//     their columns are emptied by code of their own, which section 10 checks.
// -----------------------------------------------------------------------------
$leftBehindOnPurpose = [
    'tblAssetEventAssignments'    => ['notes'],
    'tblAssetIdentifiers'         => ['notes'],
    'tblAssetLicenseAssignments'  => ['notes'],
    'tblAssetLoans'               => ['notes'],
    'tblAssetStocktakeItems'      => ['notes'],
    'tblAssetStocktakes'          => ['notes'],
    'tblAttendanceSessions'       => ['sessionID', 'notes'],
    'tblAuditTrail'               => ['ipAddress'],
    'tblCareVisit'                => ['notes'],
    'tblCountSessions'            => ['notes'],
    'tblDecisionMoments'          => ['notes'],
    'tblErrors'                   => ['visitorIP', 'userAgent', 'requestHeaders'],
    'tblEventAttendance'          => ['notes'],
    'tblEventOccurrenceOverrides' => ['notes'],
    'tblEventRSVPInvites'         => ['email', 'displayName'],
    'tblKidCheckins'              => ['pickupName'],
    'tblKidProfiles'              => ['fullName', 'dateOfBirth', 'allergies', 'medicalNotes'],
    'tblLiveChatMessages'         => ['displayName', 'senderIP'],
    'tblNoticeboardUploads'       => ['storedName'],
    'tblResourceBooking'          => ['notes'],
    'tblRotaSlot'                 => ['notes'],
    'tblSalvationCards'           => ['fullName', 'email', 'phone', 'address', 'notes'],
    'tblServicePlanItem'          => ['notes'],
    'tblServicePlans'             => ['notes'],
    'tblSmallGroupMeetings'       => ['notes'],
    'tblSmallGroups'              => ['postcode', 'latitude', 'longitude', 'what3words'],
    'tblUsers'                    => ['avatarPath', 'displayPhoto'],
    'tblVenueAgreements'          => ['notes'],
    'tblVenueBookings'            => ['notes'],
    'tblVenueInvoicePayments'     => ['notes'],
    'tblVenueInvoices'            => ['notes'],
    'tblVenueUsageTypeWindows'    => ['note'],
    'tblVenues'                   => ['postcode', 'latitude', 'longitude', 'what3words', 'caretakerName', 'caretakerPhone', 'notes'],
    'tblVisitorContact'           => ['summary'],
    'tblWorkflowActions'          => ['comment'],
];

// What each instruction deals with, per table: the column it matches on (which
// processEntry() always empties for a table other than tblUsers), every column
// in `nullCols`, and every column `overrides` gives a new value.
$dealtWith = [];
foreach ($instructions as $instruction) {
    $table = (string) ($instruction['table'] ?? '');
    $dealtWith[$table][] = (string) ($instruction['userCol'] ?? '');
    foreach ((array) ($instruction['nullCols'] ?? []) as $column) {
        $dealtWith[$table][] = (string) $column;
    }
    foreach (array_keys((array) ($instruction['overrides'] ?? [])) as $column) {
        $dealtWith[$table][] = (string) $column;
    }
}

$leftBehindNow  = [];
$pinnedAndClean = [];
foreach ($catalogue as $table => $meta) {
    $table = (string) $table;
    if ((string) ($meta['decision'] ?? '') !== 'unlink') {
        continue;
    }
    if (in_array($table, \Portal\Core\GdprEraser::FINAL_STEP_TABLES, true) === true
        || isset(\Portal\Core\GdprEraser::DEDICATED_STEP_TABLES[$table]) === true
    ) {
        continue;
    }

    $leftHere = [];
    foreach ((array) ($meta['columns'] ?? []) as $column) {
        $column = (string) $column;
        if (in_array($column, $dealtWith[$table] ?? [], true) === true) {
            continue;
        }
        // An account number: the checks above are the ones that judge those.
        if (in_array($column, $linkColumns, true) === true) {
            continue;
        }
        $leftHere[] = $column;
    }

    sort($leftHere);
    $pinned = $leftBehindOnPurpose[$table] ?? null;
    if ($pinned !== null) {
        sort($pinned);
        if ($leftHere === []) {
            // Good news, and it still has to be said out loud: the pinned list
            // is now wrong and must shrink.
            $pinnedAndClean[] = $table;
            continue;
        }
        $extra = array_diff($leftHere, $pinned);
        if ($extra !== []) {
            $leftBehindNow[] = $table . ': ' . implode(', ', $extra) . ' (beyond what is pinned for it)';
        }
        continue;
    }
    if ($leftHere !== []) {
        $leftBehindNow[] = $table . ': ' . implode(', ', $leftHere);
    }
}

check(
    'no table gains a named column that nothing empties (the activity log is the one #626 fixed)',
    $leftBehindNow === [],
    implode('; ', array_slice($leftBehindNow, 0, 6))
    . ' -- the written list says "unlink" and names this column as what makes the table personal, '
    . 'so an erasure must empty it. Give its instruction a `nullCols` entry, or change the written '
    . 'list to say the column is deliberately kept and why. Do NOT add it to $leftBehindOnPurpose.'
);

check(
    'the pinned list of tables that leave a column holds no table that is now clean',
    $pinnedAndClean === [],
    implode(', ', $pinnedAndClean)
    . ' -- nothing is left behind on this table any more, so take it off $leftBehindOnPurpose. '
    . 'A pinned list that is out of date stops this check seeing a fault come back.'
);

check(
    'the activity log empties every detail the written list names for it (#626)',
    ($leftBehindOnPurpose['tblActivityLogs'] ?? null) === null
    && in_array('tblActivityLogs', $pinnedAndClean, true) === false
    && count(array_intersect(
        ['requestHeaders', 'sessionID', 'visitorIP', 'userAgent', 'sessionDataSnapshot'],
        $dealtWith['tblActivityLogs'] ?? []
    )) === 5,
    'the address, browser name, headers, session fingerprint and session facts must all be emptied '
    . 'on an erased person\'s activity records, and tblActivityLogs must never be pinned as a table '
    . 'that leaves one. Found: ' . implode(', ', $dealtWith['tblActivityLogs'] ?? ['nothing at all'])
);

// -----------------------------------------------------------------------------
// 8c. THE PERSON'S OWN DOWNLOAD MUST NOT HAND THEM SOMEBODY ELSE'S DETAILS
//     (#626, the owner's decision, 27 October 2026).
//
//     The download of "what we hold about you" is open to any signed-in
//     member. It reads the activity log by `userID` — and #626 gave every
//     record the account that caused it, so records that used to be invisible
//     here (because they carried no account number at all) are now included.
//
//     About two dozen of the ~550 places that write an activity record put
//     ANOTHER PERSON into the free-text description: an invited guest's
//     e-mail address, an outside crew member's name. Handing those to the
//     member who caused the record would be giving them somebody else's
//     personal details on request.
//
//     So the description is left out of the download. The kind of activity,
//     the address it came from and the time all stay. Nothing checks what
//     goes into that column, so it cannot be made safe by reading it once —
//     which is also why stripping addresses and names out of the text was
//     rejected: it would miss cases while looking as though it had not.
//
//     This check exists because the obvious "improvement" is to put the
//     description back.
// -----------------------------------------------------------------------------
// Read here rather than using $export, which section 10 loads further down
// this file: this block runs before that line. (The first draft used $export
// and died with "preg_match(): Argument #2 must be of type string, null".)
$exportPage     = __DIR__ . '/../web/_apps/auth/account/data-export.php';
$exportText     = is_readable($exportPage) === true ? (string) file_get_contents($exportPage) : '';
$activitySelect = '';
if (preg_match("/'activityLogs'\s*=>.*?'(SELECT[^']*tblActivityLogs[^']*)'/s", $exportText, $m) === 1) {
    $activitySelect = $m[1];
}
check(
    'the person\'s own data download does NOT hand over the activity log\'s free-text description, which can name somebody else',
    $activitySelect !== '' && str_contains($activitySelect, 'activityDescription') === false,
    $activitySelect === ''
        ? 'the activity-log query could not be found in data-export.php at all, so this check proves nothing: find it and fix this check'
        : 'it selects: ' . $activitySelect
);
check(
    'and it still hands over what the record says about the person themselves: the kind of activity, the address and the time',
    $activitySelect !== ''
        && str_contains($activitySelect, 'activityType') === true
        && str_contains($activitySelect, 'visitorIP') === true
        && str_contains($activitySelect, 'timestamp') === true,
    'it selects: ' . $activitySelect
);

// -----------------------------------------------------------------------------
// 8d. THE DOWNLOAD DOES NOT HAND OVER OTHER PEOPLE'S DETAILS.
//     The same rule as the activity description above, applied where the Codex
//     review of 27 October 2026 found it broken: the prayer-request rows were
//     fetched with `SELECT *`, so a person's own download carried the account
//     numbers of whoever MODERATED their request and whoever it was ASSIGNED
//     to — two people the written list itself marks as separate. And the venue
//     bookings were included merely because this person was the last to EDIT
//     them, notes and all: free text that anybody may have written and that may
//     hold somebody else's telephone number.
//
//     The owner chose (27 October 2026) to drop the third-party columns and
//     keep everything that is the person's own. These checks are here because
//     the obvious "improvement" is to put a `SELECT *` back.
// -----------------------------------------------------------------------------
// $export itself is not read until section 9 below, so read the page here
// rather than reaching forward for a variable that does not exist yet. The
// same mistake crashed the activity-description checks when they were added.
$exportHere    = (string) @file_get_contents(__DIR__ . '/../web/_apps/auth/account/data-export.php');
$prayerSelect  = (preg_match('/\'prayerRequests\'\s*=>.*?FROM\s+tblPrayerRequests/s', $exportHere, $m) === 1) ? $m[0] : '';
$bookingSelect = (preg_match('/\'venueBookingsEdited\'\s*=>.*?FROM\s+tblVenueBookings/s', $exportHere, $m) === 1) ? $m[0] : '';
// Judge the CODE, not the comments beside it. Every check below reads these
// stripped copies: the first draft tested the raw text and failed on the
// page's own explanation, which names the columns it is explaining the
// absence of and quotes "SELECT *" as the thing not to put back.
$prayerCode    = (string) preg_replace('!//[^\n]*!', '', $prayerSelect);
$bookingCode   = (string) preg_replace('!//[^\n]*!', '', $bookingSelect);

check(
    'the download does NOT hand over the account numbers of whoever moderated a prayer request or whoever it was given to: they are other people',
    $prayerSelect !== ''
        && str_contains($prayerCode, 'SELECT *') === false
        && preg_match('/\bmoderatorID\b/', $prayerCode) !== 1
        && preg_match('/\bassignedToUserID\b/', $prayerCode) !== 1,
    $prayerSelect === ''
        ? 'the prayer-request query could not be found in data-export.php at all, so this check proves nothing: find it and fix this check'
        : 'it selects: ' . preg_replace('/\s+/', ' ', $prayerSelect)
);
check(
    'and it still hands over the request that is the person\'s own: the subject, the body, how it was answered and when',
    $prayerSelect !== ''
        && str_contains($prayerCode, 'subject') === true
        && str_contains($prayerCode, 'body') === true
        && str_contains($prayerCode, 'submitterID') === true,
    'it selects: ' . preg_replace('/\s+/', ' ', $prayerSelect)
);
check(
    'the download does NOT hand over the free-text notes of a booking somebody merely edited: anybody may have written them, about anybody',
    $bookingSelect !== ''
        && preg_match('/\bnotes\b/', $bookingCode) !== 1,
    $bookingSelect === ''
        ? 'the venue-booking query could not be found in data-export.php at all, so this check proves nothing: find it and fix this check'
        : 'it selects: ' . preg_replace('/\s+/', ' ', $bookingSelect)
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
//     so. Each one must: be marked "erase" or "unlink" in the written list;
//     have its step
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
    // "erase" or "unlink". Until mail package M3 (#592) only "erase" was
    // accepted here, because the one dedicated step there was deleted rows.
    // The send log's step (tblEmailLog) keeps the row and takes the person's
    // address out of it, which the written list calls "unlink"; calling it
    // "erase" to satisfy this line would have made the list say something
    // untrue. Everything else asked of a dedicated step is unchanged, and a
    // table marked "retain" or "not-personal" still fails here: a step that
    // removes something has no business on a table the list says to keep.
    if (in_array((string) ($catalogue[$table]['decision'] ?? ''), ['erase', 'unlink'], true) === false) {
        $dedicatedFaults[] = $table . ' is not marked "erase" or "unlink" in the written list';
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
// 11. OTHER PEOPLE'S RECORDS ARE NEVER DELETED (roll-out package A2a,
//     Salem874's decision of 11 October 2026).
//
//     Until then, erasing a follow-up worker deleted every visitor, salvation
//     card, task and rota slot that had been GIVEN to them, and every swap
//     request aimed at them. The cause was two column names classed as "this
//     record is about the person". Check 8 could not see it, because check 8
//     trusts that classing.
//
//     Checked twice, like check 8: the classing itself, and then each table
//     by name, so that putting either back fails here.
// -----------------------------------------------------------------------------
$givenTo = ['assignedToID', 'targetUserID'];
check(
    'being given a record, or being who a request is aimed at, is classed as "acted on it", so nothing is deleted on it',
    array_diff($givenTo, $actorColumns) === [],
    implode(', ', array_diff($givenTo, $actorColumns)) . ' is back among the "about the person" names in GdprEraser::LINK_COLUMNS'
);

// Table => the only column a whole row may be deleted on ('' = none at all).
$keepOthersRecords = [
    'tblVisitor'         => 'convertedUserID', // the erased person's OWN visitor record
    'tblSalvationCards'  => '',
    'tblTasks'           => '',
    'tblRotaSlot'        => '',
    'tblRotaSwapRequest' => '',
];
$deletesOthers = [];
foreach ($keepOthersRecords as $table => $onlyDeleteOn) {
    $found = false;
    foreach ($byTable[$table] ?? [] as $instruction) {
        $found = true;
        if ((string) $instruction['action'] === 'delete' && (string) $instruction['userCol'] !== $onlyDeleteOn) {
            $deletesOthers[] = $table . ' is deleted on `' . (string) $instruction['userCol'] . '`';
        }
        if (in_array((string) $instruction['action'], ['delete', 'anonymise'], true) === false) {
            $deletesOthers[] = $table . '.' . (string) $instruction['userCol'] . ' is "' . (string) $instruction['action'] . '"';
        }
    }
    if ($found === false) {
        $deletesOthers[] = $table . ' has no instruction at all';
    }
}
check(
    'a visitor, salvation card, task, rota slot or swap request is never deleted because of who it was given to',
    $deletesOthers === [],
    implode('; ', $deletesOthers)
);

// -----------------------------------------------------------------------------
// 12. An instruction may carry extra changes as fixed SQL text (`sqlSets`).
//     That text goes into the statement as it stands, so the list of them is
//     EXACT: one, the swap-request cancel. Adding another has to be done here
//     too, on purpose, by somebody who has read it.
// -----------------------------------------------------------------------------
$extraChanges = [];
foreach ($instructions as $instruction) {
    foreach ((array) ($instruction['sqlSets'] ?? []) as $piece) {
        $extraChanges[] = (string) $instruction['table'] . '.' . (string) $instruction['userCol'] . ' ('
            . (string) $instruction['action'] . '): ' . (string) $piece;
    }
}
// Two since 12 October 2026: the same fixed piece, on the same table, once
// for the person a request was aimed at and once for the person who asked.
check(
    'the only extra change any instruction makes is cancelling a swap request that was still waiting',
    $extraChanges === [
        "tblRotaSwapRequest.targetUserID (anonymise): `status` = IF(`status` = 'pending', 'cancelled', `status`)",
        "tblRotaSwapRequest.requestedByID (anonymise): `status` = IF(`status` = 'pending', 'cancelled', `status`)",
    ],
    implode(' | ', $extraChanges)
);

// -----------------------------------------------------------------------------
// 13. TIME. `tblErasureRequest.claimedAt` says when a run was started, and is
//     what lets a request whose run was killed be put back. It is a moment in
//     UTC. Written or compared with the server's own clock (NOW()) it would be
//     an hour wrong on the two nights a year the clocks change, and on the
//     spring night that would let a request be put back while still running.
// -----------------------------------------------------------------------------
$processFile = __DIR__ . '/../web/_apps/admin/erasure/process.php';
$process     = is_readable($processFile) === true ? (string) file_get_contents($processFile) : '';
$clockFaults = [];
$utcUses     = 0;
foreach (['GdprEraser.php' => $eraser, 'process.php' => $process] as $name => $source) {
    preg_match_all('/claimedAt\s*(?:=|<|>|<=|>=)\s*([A-Za-z_]+(?:\([A-Za-z_]*)?)/', $source, $uses);
    foreach ($uses[1] as $rightHandSide) {
        if (in_array($rightHandSide, ['UTC_TIMESTAMP(', 'DATE_SUB(UTC_TIMESTAMP', 'NULL'], true) === true) {
            $utcUses++;
        } else {
            $clockFaults[] = $name . ': claimedAt is set or compared with ' . $rightHandSide;
        }
    }
}
check(
    'the moment a run was started is only ever written and compared in UTC, never on the server\'s own clock',
    $clockFaults === [] && $utcUses >= 4,
    $clockFaults === [] ? 'only ' . $utcUses . ' uses were found; four are expected, so the pattern has stopped matching' : implode('; ', $clockFaults)
);

// -----------------------------------------------------------------------------
// 14. ONE ROUTE ONLY (Salem874, 11 October 2026). "Delete my account" used to
//     empty the account itself, on the spot, with its own short list of
//     statements: no review, no audit trail, nothing kept that has to be
//     kept. It now files an ordinary erasure request, and so does "Request
//     data erasure", through one function.
// -----------------------------------------------------------------------------
$deletePage  = (string) @file_get_contents(__DIR__ . '/../web/_apps/auth/account/delete-confirm.php');
$requestPage = (string) @file_get_contents(__DIR__ . '/../web/_apps/account/erasure-request.php');
// The code of a page without its comments, so that a comment describing what
// the page USED to do cannot fail (or satisfy) a check on what it does.
$codeOnly = static function (string $source): string {
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) === true && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) === true) {
            continue;
        }
        $code .= is_array($token) === true ? $token[1] : $token;
    }

    return $code;
};
$deleteCode = $codeOnly($deletePage);

$filers = [];
$webDir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../web', FilesystemIterator::SKIP_DOTS));
foreach ($webDir as $file) {
    if ($file->getExtension() === 'php'
        && preg_match('/INSERT\s+INTO\s+`?tblErasureRequest`?/i', $codeOnly((string) file_get_contents($file->getPathname()))) === 1
    ) {
        $filers[] = basename($file->getPathname());
    }
}
check(
    'there is one way to file an erasure request, and both pages that offer it use it',
    $filers === ['GdprEraser.php']
        && str_contains($deleteCode, 'GdprEraser::fileRequest(') === true
        && str_contains($codeOnly($requestPage), 'GdprEraser::fileRequest(') === true,
    'files that insert a request: ' . implode(', ', $filers)
);

$touches = [];
foreach (['UPDATE ', 'DELETE FROM', 'beginTransaction', 'session_destroy', '$mysqli', 'App::db('] as $sign) {
    if (stripos($deleteCode, $sign) !== false) {
        $touches[] = $sign;
    }
}
check(
    '"Delete my account" files a request and changes nothing itself: no statement of its own, and nobody is signed out',
    $deleteCode !== '' && $touches === [],
    $deleteCode === '' ? 'the page could not be read' : 'it contains: ' . implode(', ', $touches)
);

// What the person is told straight after filing, whichever page they used and
// whether or not the confirmation email could be sent. Both messages have to
// say that nothing has been erased: somebody used to the old "Delete my
// account" page, which emptied the account on the spot and signed them out,
// must not think that has just happened.
$told = static fn (bool $mailed, bool $confirmed): array => \Portal\Core\GdprEraser::whatHappensNext(
    ['requestID' => 7, 'email' => 'a@selftest.invalid', 'mailed' => $mailed, 'confirmed' => $confirmed]
);
$toldWhenMailed    = $told(true, false);
$toldWhenNotMailed = $told(false, false);
$toldWhenConfirmed = $told(false, true);
check(
    'after filing, the person is told what happens next and that nothing has been erased, on all three routes',
    str_contains($toldWhenMailed['message'], 'nothing has been erased yet') === true
        && str_contains($toldWhenMailed['message'], 'click the link within 24 hours') === true
        && str_contains($toldWhenMailed['message'], 'An administrator then reviews it') === true
        && str_contains($toldWhenNotMailed['message'], 'Nothing has been erased') === true
        && str_contains($toldWhenNotMailed['message'], 'could not be sent') === true
        && str_contains($toldWhenNotMailed['message'], 'ask an administrator to confirm it for you') === true
        && str_contains($toldWhenNotMailed['message'], 'erasure request #7') === true
        // "Delete my account": confirmed by the typed words, so the message
        // must NOT send the person looking for an email that was never sent.
        && str_contains($toldWhenConfirmed['message'], 'nothing has been erased yet') === true
        && str_contains($toldWhenConfirmed['message'], 'there is no email to wait for') === true
        && str_contains($toldWhenConfirmed['message'], 'click the link') === false
        && str_contains($toldWhenConfirmed['message'], 'a@selftest.invalid') === false
        && $toldWhenMailed['type'] === 'info' && $toldWhenNotMailed['type'] === 'warning' && $toldWhenConfirmed['type'] === 'info',
    json_encode([$toldWhenMailed, $toldWhenNotMailed, $toldWhenConfirmed])
);

// Which page may file a request as ALREADY CONFIRMED. Only "Delete my account",
// where the person has typed the confirming phrase; "Request data erasure" has
// no phrase and must go through the emailed link. A third caller, or the flag
// on the wrong page, would let a request reach an administrator's queue that
// nobody confirmed.
$filingCalls = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../web', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    preg_match_all('/GdprEraser::fileRequest\(([^;]*)\);/', $codeOnly((string) file_get_contents($file->getPathname())), $calls);
    foreach ($calls[1] as $arguments) {
        $filingCalls[] = basename($file->getPathname()) . ': ' . preg_replace('/\s+/', ' ', trim($arguments));
    }
}
sort($filingCalls);
check(
    'only "Delete my account", which checks a typed phrase, files a request as already confirmed; "Request data erasure" does not',
    $filingCalls === ['delete-confirm.php: Site::id(), $userId, true', 'erasure-request.php: $siteId, $userId']
        && str_contains($deleteCode, "!== 'DELETE MY ACCOUNT'") === true,
    implode(' | ', $filingCalls)
);

// An administrator can confirm a waiting request on the person's behalf. The
// button must carry the form token and a prompt that makes the administrator
// say they have checked who is asking; the handler must go through the one
// function that writes the audit line.
$listPage    = (string) @file_get_contents(__DIR__ . '/../web/_apps/admin/erasure/index.php');
$confirmForm = preg_match('/<form[^>]*>(?:(?!<\/form>).)*name="action" value="confirm"(?:(?!<\/form>).)*<\/form>/s', $listPage, $form) === 1 ? $form[0] : '';
check(
    'the "Confirm on the person\'s behalf" button carries the form token and a prompt about checking identity, and its handler writes the audit line',
    $confirmForm !== '' && str_contains($confirmForm, 'name="csrf_token"') === true
        && preg_match('/data-confirm="[^"]*checked, yourself, that the request really comes from them[^"]*"/', $confirmForm) === 1
        && str_contains($codeOnly($process), 'GdprEraser::confirmOnBehalf($requestId, $siteId, $adminId)') === true
        && str_contains($codeOnly($process), "Auth::verifyCsrf(\$_POST['csrf_token'] ?? '') === false") === true,
    $confirmForm === '' ? 'no such form on the list page' : 'the form, the prompt or the handler is not as expected'
);

// -----------------------------------------------------------------------------
// 15. Migration 208. Four "who did it" columns have to be emptiable, or the
//     instruction that empties them does nothing and says "left in place". And
//     the two pages that list those records must not drop a row whose number
//     has been emptied.
// -----------------------------------------------------------------------------
$schemaText  = (string) file_get_contents($schemaFile);
$migration   = (string) @file_get_contents(__DIR__ . '/../web/_sql/208_erasure_keeps_others_records.sql');
// Nine since 12 October 2026: the last five were the columns an erasure could
// not clear only because the database forbade it (Salem874's decision).
$mustBeEmptiable = [
    'tblVisitorContact' => 'contactedByID', 'tblAssetResources' => 'uploadedByID',
    'tblVenueAgreementFiles' => 'uploadedByID', 'tblCareVisit' => 'visitedByID',
    'tblAssetLicenseAssignments' => 'linkedByID', 'tblAssetLoans' => 'requestedByID',
    'tblOffboarding' => 'offboardedByID', 'tblResourceBooking' => 'bookedByID',
    'tblRotaSwapRequest' => 'requestedByID',
];
$stillRequired = [];
foreach ($mustBeEmptiable as $table => $column) {
    $definition = preg_match('/CREATE TABLE IF NOT EXISTS\s+`' . $table . '`(.*?)\n\)\s*ENGINE/s', $schemaText, $found) === 1
        && preg_match('/^\s+`' . $column . '`\s+([^\n]*)$/m', $found[1], $line) === 1 ? $line[1] : '';
    if ($definition === '' || stripos($definition, 'NOT NULL') !== false || stripos($definition, 'DEFAULT NULL') === false) {
        $stillRequired[] = $table . '.' . $column . ' in the fresh-install script (' . trim(substr($definition, 0, 40)) . ')';
    }
    if (preg_match('/ALTER TABLE `' . $table . '` MODIFY COLUMN `' . $column . '` INT DEFAULT NULL/', $migration) !== 1) {
        $stillRequired[] = $table . '.' . $column . ' in migration 208';
    }
}
check(
    'the nine "who did it" columns migration 208 frees may be empty, in the fresh-install script and in the migration',
    $stillRequired === [],
    implode('; ', $stillRequired)
);

$hidesKeptRecords = [];
foreach ([
    '_apps/visitors/profile.php'    => 'LEFT JOIN tblUsers u ON u.userID = c.contactedByID',
    '_apps/care/case.php'           => 'LEFT JOIN tblUsers u ON u.userID = v.visitedByID',
    // Added 12 October 2026: a room booking whose booker was forgotten, and
    // an approval on an expense claim whose approver was (page and PDF).
    '_apps/resources/resource.php'  => 'LEFT JOIN tblUsers u ON u.userID = b.bookedByID',
    '_apps/resources/approvals.php' => 'LEFT JOIN tblUsers u ON u.userID = b.bookedByID',
    '_apps/expenses/view/index.php' => 'LEFT JOIN tblUsers U ON U.userID = A.userID',
    '_core/ExpensePdf.php'          => 'LEFT JOIN tblUsers U ON U.userID = A.userID',
] as $page => $join) {
    // The pieces of a statement are joined up first, so that a join split
    // over two lines of PHP, or with a comment between them, is still read.
    $joinedUp = (string) preg_replace("/'\s*\.\s*'/", '', $codeOnly((string) @file_get_contents(__DIR__ . '/../web/' . $page)));
    if (str_contains($joinedUp, $join) === false) {
        $hidesKeptRecords[] = $page;
    }
}
check(
    'the pages that list follow-up notes, pastoral visits, room bookings and claim approvals still show one whose author has been forgotten',
    $hidesKeptRecords === [],
    implode(', ', $hidesKeptRecords) . ' joins the account table without LEFT, so a kept record with an emptied number drops off the page'
);

// -----------------------------------------------------------------------------
// 16. Two things the pages got wrong in use (independent check, 12 October 2026).
// -----------------------------------------------------------------------------
// A double-click on "Ask for my account to be deleted" sends the form twice.
// The first press files the request and uses up the form token, so the second
// fails the token test - and was answered "Nothing was changed. Please try
// again." a moment after the request had been filed. The page must look for
// a request already on its way BEFORE it tests the token.
$alreadyAt = strpos($deleteCode, 'GdprEraser::openRequestFor(');
$tokenAt   = strpos($deleteCode, "Auth::verifyCsrf(\$_POST['csrf_token'] ?? '')");
$filingAt  = strpos($deleteCode, 'GdprEraser::fileRequest(');
check(
    '"Delete my account" looks for a request already on its way before it tests the form token, so a second press is told the truth and files nothing',
    $alreadyAt !== false && $tokenAt !== false && $filingAt !== false && $alreadyAt < $tokenAt && $tokenAt < $filingAt,
    'order found: already-asked at ' . json_encode($alreadyAt) . ', token test at ' . json_encode($tokenAt) . ', filing at ' . json_encode($filingAt)
);

// A loan of a kit copies who asked for it onto each item's own loan. That
// number is emptied when the asker is forgotten (migration 208). Turned into
// a number, an empty value became account "0", which does not exist, and the
// database refused the whole loan. Read as text only: nothing here runs it.
$assetRegister = (string) @file_get_contents(__DIR__ . '/../web/_core/AssetRegister.php');
check(
    'copying a loan onto a kit\'s items passes an empty "who asked" on as empty, not as account 0',
    str_contains($codeOnly($assetRegister), "'requestedByID'        => [\$parentLoan['requestedByID'] !== null ? (int) \$parentLoan['requestedByID'] : null, 'i'],") === true
        && str_contains($codeOnly($assetRegister), "'requestedByID'        => [(int) \$parentLoan['requestedByID'], 'i'],") === false,
    'web/_core/AssetRegister.php turns an empty requestedByID into the number 0 again'
);

// Words that promised or stated more than is true.
$deleteForm  = (string) @file_get_contents(__DIR__ . '/../web/_apps/auth/account/delete.php');
$confirmPage = (string) @file_get_contents(__DIR__ . '/../web/_apps/account/erasure-confirm.php');
$untrueWords = [];
if (stripos($codeOnly($confirmPage), 'email you once') !== false) {
    $untrueWords[] = 'erasure-confirm.php promises an email when the erasure is done; none is sent';
}
if (stripos($codeOnly($deleteForm), 'because the law requires it') !== false) {
    $untrueWords[] = 'delete.php states "because the law requires it" as fact, for every country';
}
if (stripos($deleteForm, 'you stay signed in') === false) {
    $untrueWords[] = 'delete.php no longer says the person stays signed in until the request is carried out';
}
check(
    'the pages do not promise an email that is never sent, do not state one country\'s law as fact, and say the person stays signed in',
    $deleteForm !== '' && $confirmPage !== '' && $untrueWords === [],
    implode('; ', $untrueWords)
);

// -----------------------------------------------------------------------------
// Roll-out package A2b: giving records that keep a donor's name for a set time.
//
// These read the SOURCE. They cannot show the feature works - the real-database
// test (tools/gdpr-eraser-selftest.php, sections 11 to 13) and
// tools/giving-retention-selftest.php do that. What they hold is the SHAPE that
// makes it safe, so that a later tidy-up cannot quietly take it apart.
// -----------------------------------------------------------------------------
$core            = __DIR__ . '/../web/_core/';
$eraserCode      = $codeOnly($eraser);
$retentionSource = (string) @file_get_contents($core . 'GivingRetention.php');
$retentionCode   = $codeOnly($retentionSource);
$givingCode      = $codeOnly((string) @file_get_contents($core . 'Giving.php'));
$sweepCode       = $codeOnly((string) @file_get_contents(__DIR__ . '/../web/_apps/cron/_retention-sweep.php'));
$settingsPage    = (string) @file_get_contents(__DIR__ . '/../web/_apps/admin/settings/giving-records/index.php');
$settingsSave    = $codeOnly((string) @file_get_contents(__DIR__ . '/../web/_apps/admin/settings/giving-records/save.php'));
$migration209    = (string) @file_get_contents(__DIR__ . '/../web/_sql/209_giving_kept_donors.sql');
$method          = static function (string $code, string $name): string {
    // The text of one method, from its name to the next NAMED method or
    // function. (A closure inside it, `static function () {`, has no name
    // and must not end it: the first version of this cut there.)
    $start = strpos($code, 'function ' . $name . '(');
    if ($start === false) {
        return '';
    }
    $next = preg_match('/\n\s*(?:(?:public|private|protected)\s+)?(?:static\s+)?function\s+\w+\s*\(/', $code, $m, PREG_OFFSET_CAPTURE, $start + 10) === 1
        ? $m[0][1]
        : false;

    return $next === false ? substr($code, $start) : substr($code, $start, $next - $start);
};

// The step that copies the name out has to run BEFORE the walk that empties
// the account, and outside the try that lets a failed step carry on.
$run     = $method($eraserCode, 'execute');
$keepAt  = strpos($run, 'self::keepGivingRecords(');
$walkAt  = strpos($run, 'foreach (self::catalogue()');
$tryAt   = strpos($run, "\$steps  = 2;");
check(
    'the giving-records step runs before the table walk empties the account, and outside the try that lets a failed step carry on',
    $keepAt !== false && $walkAt !== false && $tryAt !== false && $keepAt < $tryAt && $tryAt < $walkAt
    && str_contains($method($eraserCode, 'keepGivingRecords'), 'self::putBackToPendingReview(')
    && str_contains($method($eraserCode, 'keepGivingRecords'), 'throw new \RuntimeException('),
    'keepGivingRecords() must be called before `$steps = 2;` and the walk, and must put the request back and throw on any failure'
);

// THE GUARD IS INSIDE THE CLEARING ROUTINE. Nothing may be deleted or changed
// in it before it has asked "is this due?" and left on any other answer.
$clear     = $method($eraserCode, 'clearKeptGivingIdentity');
$guardAt   = strpos($clear, "if (\$state['state'] !== 'due')");
$firstHarm = min(array_filter([strpos($clear, 'unlink('), strpos($clear, 'UPDATE '), strpos($clear, 'DELETE ')], static fn ($at): bool => $at !== false) ?: [PHP_INT_MAX]);
check(
    'the routine that clears a kept name asks the one deciding function first, and leaves on any answer but "due", before it deletes a file or changes a row',
    $guardAt !== false && $guardAt < $firstHarm && str_contains($clear, 'GivingRetention::stateOfKeptRow(')
    && str_contains(substr($clear, $guardAt, 120), 'return $state[\'state\'];') && str_contains($clear, 'DELETE FROM') === false,
    'clearKeptGivingIdentity() must test stateOfKeptRow() === "due" before its first unlink() or UPDATE, and must never delete a row'
);

// THE FOUR RULES OF THE FIX ROUND (independent check, 12 October 2026). Each
// is proved on a real database in tools/gdpr-eraser-selftest.php section 14;
// these checks are the quick ones that need no database.
//
// Rules 1 and 2: the question the clearing asks looks at the ACCOUNT and at
// the gifts still linked, not only at the date stored on the kept row.
$rowState   = $method($retentionCode, 'stateOfKeptRow');
$emptiedSql = $method($eraserCode, 'emptiedAccountSql');
check(
    'a kept record is "due" only when the account has really been emptied, and its date is counted from the later of the stored date, the newest linked gift and the newest envelope; "emptied" is defined once and means inactive AND the made-up name AND the made-up address',
    $rowState !== '' && str_contains($rowState, "GdprEraser::emptiedAccountSql('u')")
    && str_contains($rowState, 'max($storedLastGift, (string) ($found[\'newestGift\'] ?? \'\'), (string) ($found[\'newestEnvelope\'] ?? \'\'))')
    && str_contains($rowState, 'self::stateFor($rule, $from, $today)')
    && preg_match('/if \(\$state\[\'accountEmptied\'\] === false && \$state\[\'state\'\] !== \'unreadable\'\) \{\s*\$state\[\'state\'\] = \'not-erased\';/', $rowState) === 1
    && str_contains($emptiedSql, '.isActive = 0 AND ') && str_contains($emptiedSql, 'self::TOMBSTONE_NAME') && str_contains($emptiedSql, 'self::TOMBSTONE_EMAIL')
    && substr_count($emptiedSql, ' AND ') === 2 && str_contains($emptiedSql, ' OR ') === false
    && str_contains($method($retentionCode, 'keptDonorsForSite'), 'self::stateOfKeptRow('),
    'GivingRetention::stateOfKeptRow() must ask GdprEraser::emptiedAccountSql() and count from the latest of the three dates'
);
// Rule 3: a later run works the row out again, and the statement that could
// write a made-up name over a real one does not exist.
$atSite = $method($eraserCode, 'keepGivingRecordsAtSite');
// (The second fix round, after the narrow check of 12 October 2026: beside an
// emptied account the stored rule sorts the gifts, is never overwritten, and
// the row is never emptied here. Proved in section 15 of the database test.)
check(
    'a later run of an erasure works the kept record out again; when the account is already emptied the name, address, postcode AND the stored rule are not in the statement at all, the wider of the stored rule and today\'s sorts the gifts and the envelopes, and the "nothing to keep" branch is closed',
    $atSite !== '' && str_contains($atSite, "'UPDATE tblGivingKeptDonors SET lastGiftDate = ?, keptGiftCount = ? WHERE keptDonorID = ?'")
    && str_contains($atSite, '$keptAlready = $earlierRun === true && $emptiedAlready === true;')
    // Beside an emptied account the WIDER of the stored rule and today's
    // sorts (third fix round): the stored "every gift" wins over a narrower
    // setting, and today's "every gift" wins over a narrower stored rule.
    && str_contains($atSite, "if (\$keptAlready === true && (string) \$existing['scope'] === GivingRetention::SCOPE_ALL) {")
    && str_contains($atSite, "\$scope = (string) \$rule['scope'];")
    && str_contains($atSite, 'self::$everyGiftSites[] = $siteId;')
    && str_contains($method($eraserCode, 'unnameCountEnvelopes'), "' OR cs.siteID IN (' . implode(', ', array_map('intval', self::\$everyGiftSites)) . ')'")
    && str_contains($atSite, 'if ($keptAlready === false && $keptGifts === 0 && $declarationCount === 0 && $statementCount === 0 && $envelopeCount === 0) {')
    && strpos($atSite, '$keptAlready = $earlierRun === true') < strpos($atSite, 'GivingRetention::keptGiftSql($scope')
    && str_contains($atSite, "'UPDATE tblGivingKeptDonors SET erasureRequestID = ?, fullName = ?, address = ?, postcode = ?, scope = ?, '")
    && strpos($atSite, 'if ($emptiedAlready === true) {') !== false
    && strpos($atSite, 'if ($emptiedAlready === true) {') < strpos($atSite, "'UPDATE tblGivingKeptDonors SET lastGiftDate = ?")
    && str_contains($atSite, "if (\$earlierRun === true && (string) \$existing['lastGiftDate'] > \$lastGift) {")
    && str_contains($atSite, 'left exactly as it is') === false,
    'keepGivingRecordsAtSite() must update an earlier run\'s row, with no name columns when the account is already emptied, and never move the date earlier'
);
// A record already past its date is cleared at the END of the run, after the
// account is emptied (the clearing would refuse before then).
$dueAt     = strpos($run, 'self::clearGivingRecordDueAtErasure(');
$imagesAt  = strpos($run, 'self::eraseProfileImages(');
$closeAt   = strpos($run, 'self::closeRequest(');
check(
    'a record already past its date on the day of the erasure is cleared at the END of the run, after the walk has emptied the account and before the request is closed, by the guarded routine and nothing else',
    $dueAt !== false && $imagesAt !== false && $closeAt !== false && $walkAt < $dueAt && $imagesAt < $dueAt && $dueAt < $closeAt
    && str_contains($method($eraserCode, 'keepGivingRecords'), 'clearKeptGivingIdentity(') === false
    && str_contains($method($eraserCode, 'clearGivingRecordDueAtErasure'), 'self::clearKeptGivingIdentity($keptDonorId)'),
    'execute() must call clearGivingRecordDueAtErasure() after eraseProfileImages() and before closeRequest()'
);
// Rule 4: the cancel is refused before anything is written.
$processCode = $codeOnly((string) @file_get_contents(__DIR__ . '/../web/_apps/admin/erasure/process.php'));
$askAt       = strpos($processCode, '$stillWaiting === true && GdprEraser::runHasChangedAnything($requestId) === true');
$cancelAt    = strpos($processCode, 'UPDATE tblErasureRequest SET status = "cancelled"');
$hasRun      = $method($eraserCode, 'givingStepHasRun');
$hasChanged  = $method($eraserCode, 'runHasChangedAnything');
$trailLine   = $method($eraserCode, 'trailHasLine');
check(
    'a request whose run has changed ANYTHING cannot be cancelled: the page asks before it writes "cancelled", leaves with a message, and the question covers a deleted row, an emptied column and a kept name, and answers "yes" when it cannot tell',
    $askAt !== false && $cancelAt !== false && $askAt < $cancelAt && str_contains(substr($processCode, $askAt, 1100), 'exit();')
    && str_contains($processCode, 'This request cannot be cancelled') && str_contains($processCode, 'Run the request again to finish')
    && str_contains($processCode, 'GdprEraser::givingStepHasRun(') === false
    && str_contains($hasChanged, 'self::givingStepHasRun($requestId) === true')
    && str_contains($hasChanged, "action IN ('delete', 'anonymise', 'partial')")
    && str_contains($hasRun, "tableName IN ('GivingRecords', 'CountEnvelopes') AND action IN ('retain', 'anonymise')")
    && substr_count($trailLine, 'return true;') === 2,
    'process.php must call GdprEraser::runHasChangedAnything() and leave before the UPDATE that cancels'
);
// The clearing decides INSIDE its transaction, with the kept row and the
// donor's gifts locked, and deletes nothing before that. (Proved with a
// second connection in section 15 of the database test.)
$lockRowAt   = strpos($clear, 'FROM tblGivingKeptDonors WHERE keptDonorID = ? FOR UPDATE');
$lockGiftsAt = strpos($clear, "'SELECT entryID FROM tblGivingEntry WHERE siteID = ? AND donorID = ? FOR UPDATE'");
$decideAt    = strrpos($clear, 'GivingRetention::stateOfKeptRow(');
$stepAt      = strpos($clear, 'self::asOneStep(');
check(
    'the clearing locks the kept row and the donor\'s gifts, reads the rule and the state again, and only then deletes a file or changes a row',
    $stepAt !== false && $lockRowAt !== false && $lockGiftsAt !== false && $decideAt !== false
    && $stepAt < $lockRowAt && $lockRowAt < $lockGiftsAt && $lockGiftsAt < $decideAt && $decideAt < $firstHarm
    && substr_count($clear, 'GivingRetention::stateOfKeptRow(') === 2 && substr_count($clear, "if (\$state['state'] !== 'due') {") === 2
    && strpos($clear, 'GivingRetention::ruleForSite($siteId)', $lockGiftsAt) !== false,
    'clearKeptGivingIdentity() must decide again after its two FOR UPDATE reads and before its first unlink() or UPDATE'
);
// The general settings editor: refused in the save (edit and add) and in the
// delete, before anything is written.
$generalSave  = $codeOnly((string) @file_get_contents(__DIR__ . '/../web/_apps/settings/save.php'));
$generalIndex = $codeOnly((string) @file_get_contents(__DIR__ . '/../web/_apps/settings/index.php'));
$lockFn       = $method($retentionCode, 'lockedInGeneralEditor');
$editLockAt   = strpos($generalSave, 'GivingRetention::lockedInGeneralEditor($storedKey, $isPortalWideRow, $isGlobalAdmin) === true');
$addLockAt    = strpos($generalSave, 'GivingRetention::lockedInGeneralEditor($settingKey, false, $isGlobalAdmin) === true');
$firstWrite   = min(array_filter([strpos($generalSave, 'UPDATE tblSettings'), strpos($generalSave, 'INSERT INTO tblSettings')], static fn ($at): bool => $at !== false) ?: [0]);
$deleteAt     = strpos($generalIndex, 'DELETE FROM tblSettings');
$deleteLockAt = strpos($generalIndex, 'GivingRetention::LOCKED_MESSAGE');
check(
    'the general settings editor cannot change, add or delete a giving-records setting: each is refused before the first write, and the only row left open is the installation-wide hold for a global administrator',
    $editLockAt !== false && $addLockAt !== false && $firstWrite > 0 && $editLockAt < $firstWrite && $deleteAt !== false && $deleteLockAt !== false && $deleteLockAt < $deleteAt
    && substr_count($generalSave, 'GivingRetention::LOCKED_MESSAGE') === 2
    && str_contains($lockFn, "strncasecmp(\$key, 'giving.retention.', 17) !== 0")
    && str_contains($lockFn, 'self::KEY_HOLD') && str_contains($lockFn, '$portalWideRow === true') && str_contains($lockFn, '$globalAdmin === true'),
    'web/_apps/settings/save.php and index.php must ask GivingRetention::lockedInGeneralEditor() before any write'
);
// The kept-names table is asked for before anything else, for everybody.
$keepStep = $method($eraserCode, 'keepGivingRecords');
$probeAt  = strpos($keepStep, "\$db->query('SELECT 1 FROM tblGivingKeptDonors LIMIT 0')");
$sitesAt  = strpos($keepStep, "'SELECT siteID FROM tblGivingEntry WHERE donorID = ? '");
check(
    'the giving step asks for the kept-names table before anything else, donor or not, and a missing table is answered with "run the database upgrade"',
    $probeAt !== false && $sitesAt !== false && $probeAt < $sitesAt && str_contains($keepStep, '(int) $e->getCode() === 1146')
    && str_contains($keepStep, 'run the database upgrade, then run this request again')
    // The only way out with "done" is after the envelope step, so a person
    // with no giving records still has their envelopes looked at.
    && substr_count($keepStep, 'return $changed;') === 1
    && strpos($keepStep, 'return $changed;') > strpos($keepStep, 'self::unnameCountEnvelopes('),
    'keepGivingRecords() must probe tblGivingKeptDonors first and must not return early for a person with no giving records'
);
$decide = $method($retentionCode, 'stateFor');
check(
    'the deciding function tests the hold first, then "confirmed", then the date, and only then says "due"',
    ($holdAt = strpos($decide, "\$rule['hold'] !== false")) !== false
    && ($confirmedAt = strpos($decide, "\$rule['confirmed'] !== true")) !== false
    && ($dateAt = strpos($decide, "\$today <= \$date['keepUntil']")) !== false
    && ($dueAt = strpos($decide, "\$state = 'due';")) !== false
    && $holdAt < $confirmedAt && $confirmedAt < $dateAt && $dateAt < $dueAt && substr_count($decide, "'due'") === 1,
    'stateFor() must be: hold, then not confirmed, then today <= keepUntil, else due'
);

// The job hands every record to that routine and decides nothing itself; and
// a cleared name is never counted as a deletion.
$sweepFn = $method($sweepCode, 'run_giving_identity_sweep');
$total   = '';
if (preg_match("/'totalDeleted'\s*=>\s*([^,\]]+)/", $sweepCode, $m) === 1) {
    $total = $m[1];
}
check(
    'the scheduled job decides nothing itself (it calls the guarded routine for every kept record), and a cleared name is not added to "deleted N rows"',
    str_contains($sweepFn, 'GdprEraser::clearKeptGivingIdentity(') && str_contains($sweepFn, 'stateFor') === false
    && str_contains($sweepFn, "'due'") === false && $total !== '' && str_contains($total, 'giving') === false
    && str_contains($total, '$activityDeleted + $errorsDeleted + $registrationsDeleted'),
    'totalDeleted is: ' . $total
);

// Kept gifts are not emptied again by the walk. Nor is an envelope: the
// giving step decides which envelopes keep their giver (those in a count that
// is not yet closed, whose gift will be a kept gift) and takes it off the rest
// itself. (Until 12 October 2026 the walk always took it off, and the gift
// made from such an envelope when the count was closed had no donor.)
$giftLine     = '';
$envelopeLine = '';
foreach ($instructions as $instruction) {
    if ($instruction['table'] === 'tblGivingEntry' && $instruction['userCol'] === 'donorID') {
        $giftLine = (string) $instruction['action'];
    }
    if ($instruction['table'] === 'tblCountEnvelopes' && $instruction['userCol'] === 'giverID') {
        $envelopeLine = (string) $instruction['action'] . ':' . implode(',', (array) ($instruction['nullCols'] ?? []));
    }
}
$envelopeStep = $method($eraserCode, 'unnameCountEnvelopes');
check(
    'the table walk does not take the donor off a KEPT gift or a kept envelope again (both instructions are "keep"); the giving step takes the giver and any typed name off every envelope except one in a count not yet closed with an uncleared kept record; and the clearing takes it off those',
    $giftLine === 'retain' && $envelopeLine === 'retain:'
    && str_contains($envelopeStep, "cs.status <> 'closed' AND k.keptDonorID IS NOT NULL")
    && str_contains($envelopeStep, 'k.clearedAt IS NULL')
    && str_contains($envelopeStep, "SET ce.giverID = NULL, ce.giverName = NULL WHERE ce.giverID = ? AND NOT ' . \$keptEnvelope")
    && strpos($keepStep, 'self::unnameCountEnvelopes(') > strpos($keepStep, 'self::keepGivingRecordsAtSite(')
    && preg_match('/UPDATE tblCountEnvelopes ce.*?SET ce\.giverID = NULL, ce\.giverName = NULL/s', $clear) === 1
    && in_array('giverID', \Portal\Core\GdprEraser::LINK_COLUMNS, true) === true
    && in_array('giverID', \Portal\Core\GdprEraser::ACTOR_COLUMNS, true) === false,
    'gift: ' . $giftLine . '; envelope: ' . $envelopeLine
);

// The claim file reads the kept name; an erased donor is never rendered,
// listed, or regenerated over.
check(
    'the tax claim file reads the KEPT name when there is one, and an erased donor is never rendered for, listed for, or regenerated over',
    str_contains($method($givingCode, 'buildHmrcCsv'), 'COALESCE(k.fullName, u.fullName)')
    && str_contains($method($givingCode, 'buildHmrcCsv'), 'k.clearedAt IS NULL')
    // The kept name is read only beside an EMPTIED account, and "an erased
    // donor" is one whose account is emptied: a kept row beside a live
    // member (an erasure that stopped) is neither.
    && str_contains($method($givingCode, 'buildHmrcCsv'), "GdprEraser::emptiedAccountSql('u')")
    && str_contains($method($givingCode, 'erasedDonorSql'), "GdprEraser::emptiedAccountSql('ku')")
    && str_contains($method($givingCode, 'isErasedDonor'), "self::erasedDonorSql('?', '?')")
    && str_contains($method($givingCode, 'renderStatementPdf'), 'self::isErasedDonor($siteId, $donorId) === true')
    && str_contains($method($givingCode, 'statementsPreview'), "NOT ' . self::erasedDonorSql(")
    && substr_count($method($givingCode, 'renderStatementBatch'), "NOT ' . self::erasedDonorSql(") === 3
    && substr_count($givingCode, 'FROM tblGivingKeptDonors') === 1,
    'Giving.php must join tblGivingKeptDonors in buildHmrcCsv() beside an emptied account, and leave erased donors out of the three statement routines through erasedDonorSql()'
);

// NO COUNTRY IS BUILT IN. The installation's one address is "GB" unless
// changed; reading it would make every organisation look British.
$countryLiterals = [];
foreach (['GivingRetention.php' => $retentionCode, 'giving-records/index.php' => $codeOnly($settingsPage), 'giving-records/save.php' => $settingsSave] as $where => $code) {
    if (preg_match('/org\.address|[\'"](GB|UK|US|IE)[\'"]|HMRC|United Kingdom/', $code) === 1) {
        $countryLiterals[] = $where;
    }
}
check(
    'no country is built in: the giving-records code never reads the installation\'s own address, and names no country, no tax office and no figure of its own',
    $retentionCode !== '' && $countryLiterals === [] && str_contains($retentionCode, "'giving.retention.countryCode'")
    && preg_match("/giving\.retention\.countryCode',\s*'',\s*''/", $migration209) === 1
    && preg_match("/giving\.retention\.countryCode',\s*'',\s*''/", $schemaText) === 1
    && preg_match("/giving\.retention\.confirmed',\s*'false',\s*'false'/", $schemaText) === 1,
    'a country or tax office is named in: ' . implode(', ', $countryLiterals)
);

// The words on the settings page.
$pageText = (string) preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('/<\?php.*?\?>/s', ' ', $settingsPage)));
$claims   = [];
foreach (['the law requires', 'required by law', 'legally required', 'you must keep', 'must be kept for', 'the legal period'] as $phrase) {
    if (stripos($pageText, $phrase) !== false) {
        $claims[] = $phrase;
    }
}
check(
    'the settings page never reads as if the portal knows the law: it says "suggestion", "placeholder, not a finding" and "not legal advice", asks for an adviser, and has the wording for a weak or unclear exception',
    $claims === [] && str_contains($pageText, 'Our suggestion for') && str_contains($pageText, 'placeholder, not a finding')
    && str_contains($pageText, 'This is our reading of the rules, not legal advice. Please check it with your accountant or adviser.')
    && str_contains($pageText, 'we could not confirm that the law lets an organisation keep these records after somebody has asked to be erased')
    && str_contains($pageText, 'Please take local legal advice before relying on this page')
    && str_contains($pageText, 'We found no general right to be erased')
    && str_contains($pageText, 'disagree, or are weak') && str_contains($pageText, 'nothing is cleared before then')
    && str_contains($settingsPage, 'GivingRetention::STRENGTH_WORDS'),
    'claims found: ' . implode(', ', $claims)
);
$isAdminAt = strpos($settingsSave, 'App::isAdmin() === false');
$tokenAt   = strpos($settingsSave, 'Auth::verifyCsrf(');
$saveAt    = strpos($settingsSave, 'GivingRetention::saveForSite($mysqli, $siteId, $posted)');
check(
    'the save handler checks who is asking and the form token before it saves, saves only for the administrator\'s own organisation, and logs it',
    $isAdminAt !== false && $tokenAt !== false && $saveAt !== false && $isAdminAt < $tokenAt && $tokenAt < $saveAt
    && str_contains($settingsSave, '$siteId = Site::id();') && str_contains($settingsSave, "\$_POST['siteID']") === false
    && str_contains($settingsSave, 'Logger::activity(') && str_contains($settingsSave, '.php\'') === false
    && str_contains($settingsPage, 'App::isAdmin() === false'),
    'save.php must be: administrator, POST, form token, then saveForSite() for Site::id()'
);

// The privacy page every member can read. It used to print one installation-
// wide number of years beside one country's tax office.
$privacyPage = (string) @file_get_contents(__DIR__ . '/../web/_apps/privacy/policy.php');
$privacyCode = $codeOnly($privacyPage);
$yearsAt     = strpos($privacyCode, "\$givingRule['years']");
$guardAt     = strpos($privacyCode, "\$givingRule !== null && \$givingRule['confirmed'] === true");
$elseAt      = strpos($privacyCode, '<?php else: ?>', (int) $guardAt);
check(
    'the privacy page gives a number of years only when the organisation has CONFIRMED one, reads that organisation\'s own rule, and names no country\'s tax office',
    $privacyCode !== '' && $guardAt !== false && $yearsAt !== false && $elseAt !== false && $guardAt < $yearsAt && $yearsAt < $elseAt
    && substr_count($privacyCode, "\$givingRule['years']") === 2
    && str_contains($privacyCode, 'GivingRetention::ruleForSite(Site::id())')
    && preg_match('/HMRC|financialRetentionYears/', $privacyCode) !== 1
    && str_contains($privacyCode, 'has not yet confirmed how many years')
    // Both branches say so when the organisation keeps the name on EVERY
    // gift. The unconfirmed one used to say "gifts covered by a tax
    // declaration..." whatever the organisation had chosen.
    && substr_count($privacyPage, "['scope'] === GivingRetention::SCOPE_ALL ? ' (for this organisation, every gift)' : ''") === 2
    && strpos($privacyPage, "\$givingRule !== null && \$givingRule['scope'] === GivingRetention::SCOPE_ALL") > strpos($privacyPage, '<?php else: ?>', (int) strpos($privacyPage, "\$givingRule['confirmed'] === true")),
    'policy.php must print $givingRule[\'years\'] only inside the "confirmed" branch'
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
