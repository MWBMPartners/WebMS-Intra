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
const EXPECTED_CHECKS = 105; // 81 before the #709 fix round added section 8d; round 2
                            // added four more after three plants went uncaught, giving 93.
                            // #492 part 3 added one: a child's event registration is
                            // deleted on the column saying whose record it is and
                            // nowhere else, and one on the handler that fills that
                            // column, and #492 part 4 one on the wording a person reads
                            // about their expense claims. 93 + 3 = 96.
                            // #703 (D-016) added section 23's five: who made a
                            // visit or recorded a DBS check is kept, the name is
                            // never emptied, every writer fills it, the columns
                            // exist, and the page shows it. 96 + 5 = 101.
                            // #703 round 2 (D-110, D-111, D-113) widened the five to
                            // the five kept columns and added one: the sentences a
                            // member reads say what keeping the number costs. 102.
                            // #703 round 3 added one: the reasons a member is really
                            // SHOWN (GdprEraser's own, not the written list's). 103.
                            // Round 4 added one: the list of records that keep the
                            // number, every item, in all four places. 104.
                            // #940 (D-117, D-118) added one: what a member is shown
                            // about the activity log, and the written list's own
                            // reason for it, are said WHOLE - kept for ever by
                            // choice, the organisation's own period. 105.

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

/**
 * 🔍 A PATTERN SEARCH THAT CANNOT BE MISTAKEN FOR "NOT THERE" (#829).
 *
 * `preg_match()` and `preg_match_all()` answer `false` when the search could
 * not RUN at all — PCRE's JIT stack ran out, or the backtracking limit was
 * reached — and every search in this file was read with `=== 1`, `!== 1`,
 * `> 0` or by looking at the matches it filled in. So a search that never ran
 * gave exactly the answer of a search that found nothing, and a check asking
 * for ABSENCE ("the page does NOT show…") passed on a page it never read.
 * That is not hypothetical: `tools/db-strict-mode-selftest.php` hit the JIT
 * limit on the admin dashboard once that page grew past roughly 20-30 KB, and
 * its "shows NO alert" check went on passing whatever the page drew (#792).
 *
 * These two answer EXACTLY what PHP's functions answer whenever the search
 * runs, so no check here asks anything different. When it cannot run they
 * record a RED check naming the pattern, how much text it was searching and
 * PHP's own reason, and then THROW, so no caller is ever handed an answer it
 * could read as "absent" or as "present". The red line comes first on
 * purpose: a bare uncaught exception is a crash with a label, not a check
 * that reports.
 *
 * Deliberately NOT used inside the router this file writes out for its test
 * web server (that code runs in another process, where these functions do
 * not exist, and it searches only a short request path).
 *
 * @param mixed $found Filled in exactly as `preg_match()` fills its third argument.
 */
function gc_find(string $pattern, string $subject, mixed &$found = null, int $flags = 0, int $offset = 0): int
{
    $answer = preg_match($pattern, $subject, $found, $flags, $offset);
    if ($answer === false) {
        gc_searchCouldNotRun($pattern, $subject);
    }

    return $answer;
}

/**
 * The same, for `preg_match_all()`: the number of matches when the search
 * ran, and a red check plus a thrown error when it could not.
 *
 * @param mixed $found Filled in exactly as `preg_match_all()` fills its third argument.
 */
function gc_findAll(string $pattern, string $subject, mixed &$found = null, int $flags = 0, int $offset = 0): int
{
    $answer = preg_match_all($pattern, $subject, $found, $flags, $offset);
    if ($answer === false) {
        gc_searchCouldNotRun($pattern, $subject);
    }

    return $answer;
}

/**
 * The same again for `preg_replace()` and `preg_split()`, because they are
 * the same fault in another form (#829): `preg_replace()` answers `null` when
 * it cannot run and `preg_split()` answers `false`, and this file turned both
 * into "nothing" with `(string)`, `(array)` or `?: []`. Text that has been
 * tidied to an EMPTY string contains nothing, so a check that then asks the
 * tidied text for ABSENCE ("the statement does not select `moderatorID`")
 * passed without ever having read it.
 */
function gc_replace(string|array $pattern, string|array $replacement, string $subject, int $limit = -1): string
{
    $answer = preg_replace($pattern, $replacement, $subject, $limit);
    if ($answer === null) {
        gc_searchCouldNotRun(is_array($pattern) === true ? implode(' ', $pattern) : $pattern, $subject);
    }

    return $answer;
}

/** `preg_split()`, with the same refusal to read "could not run" as "nothing". @return list<string> */
function gc_split(string $pattern, string $subject, int $limit = -1, int $flags = 0): array
{
    $answer = preg_split($pattern, $subject, $limit, $flags);
    if ($answer === false) {
        gc_searchCouldNotRun($pattern, $subject);
    }

    return $answer;
}

/** Record the red check, then stop the caller reading anything into the failure. */
function gc_searchCouldNotRun(string $pattern, string $subject): never
{
    $why = preg_last_error_msg();
    check('a pattern search COULD NOT RUN, so its check was not answered (#829)', false, 'pattern ' . $pattern . ' over ' . strlen($subject) . ' bytes: ' . $why . ' — this is NOT "not found"');
    throw new RuntimeException('A pattern search could not run (' . $why . '), so the check that asked it has no answer: ' . $pattern);
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
    $text = (string) gc_replace('#/\*.*?\*/#s', '', $text);
    $text = (string) gc_replace('/--[^\n]*/', '', $text);

    $tables = [];
    gc_findAll('/CREATE TABLE IF NOT EXISTS\s+`(\w+)`(.*?)\n\)\s*ENGINE/s', $text, $found, PREG_SET_ORDER);
    foreach ($found as $one) {
        gc_findAll('/^\s+`(\w+)`/m', $one[2], $columns);
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

// GdprEraser::DELETE_ON_ACTOR_COLUMN lets a TABLE be deleted from on a column
// that says the person acted on the record. It names the table, not the
// column, so the one column each listed table may be deleted on is written out
// here, exactly.
//
// 🪦 `tblEventRegistrations` CAME OFF BOTH LISTS on 3 November 2026 (#492 part
//    3). It used to be deleted on `submittedByUserID` — whoever typed the form
//    in — so erasing a volunteer who had registered somebody else's child
//    deleted that child's registration. Migration 246 adds
//    `registeredForUserID`, which says whose record it is and is a SUBJECT
//    column, so the delete needs no exception at all. The table being absent
//    from DELETE_ON_ACTOR_COLUMN is what the check above now holds: a delete
//    put back on either `submittedByUserID` or `reviewedByID` fails it. And
//    `$wrongDelete` below starts from `array_diff()` of the two lists, so
//    taking a table off only one of them fails too.
$mayOnlyDeleteOn = [
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
    'where a whole record MAY be deleted because of what somebody did, it is only on the one column decided: a line saying this person opened a pastoral file, and nothing else',
    $wrongDelete === [],
    implode('; ', $wrongDelete)
);

// 📝 #492 PART 3, pinned HERE as well as in the real-database test, because
//    this half judges the LIST before anybody runs an erasure and the other
//    half judges the run — #709's lesson that both are needed.
//
//    A registration must be deleted on exactly one column, the one that says
//    whose record it is, and the other two must merely detach. Written out as
//    an exact map rather than "no delete on the submitter", so that deleting
//    the delete altogether, or moving it, fails here.
$registrationActions = [];
foreach ($instructions as $instruction) {
    if ((string) $instruction['table'] === 'tblEventRegistrations') {
        $registrationActions[(string) $instruction['userCol']] = (string) $instruction['action'];
    }
}
ksort($registrationActions);
check(
    'a child\'s event registration is DELETED only on the column saying whose record it is, and merely detached from whoever typed it in or reviewed it (#492 part 3)',
    $registrationActions === [
        'registeredForUserID' => 'delete',
        'reviewedByID'        => 'anonymise',
        'submittedByUserID'   => 'anonymise',
    ],
    'the instructions for tblEventRegistrations are ' . json_encode($registrationActions)
    . ' -- a registration holds a child\'s name, date of birth, allergies and medical notes, so deleting'
    . ' it on request is right; finding it through `submittedByUserID` was not, because that column'
    . ' records only whoever was signed in when the form was sent, so erasing a volunteer who had'
    . ' registered somebody else\'s child deleted that child\'s registration. The delete belongs on'
    . ' `registeredForUserID` (migration 246) and nowhere else.'
);

// 📝 AND THE WRITER, because the column is only worth anything if something
//    honest fills it (#492 part 3, 3 November 2026).
//
//    `registeredForUserID` is what an erasure DELETES on. If the registration
//    handler simply copied the submitter into it — the obvious tidy-up, and
//    what the one-time backfill in migration 246 does for rows that already
//    existed — then every NEW registration would be back to the old fault:
//    erasing a volunteer would delete the child's registration. So the write
//    is pinned here, and the WHOLE condition is pinned, not the words in it:
//    adding `&& false`, or widening the test to accept anything but the exact
//    answer "me", fails this.
//
//    Nothing in this repository exercises that page over HTTP (there is no
//    registration page test), which is why this static pin is the only guard
//    on the write. It cannot tell you the form renders, or that a browser
//    sends the field — only that the handler still asks the right question.
$registerSave = __DIR__ . '/../web/_apps/calendar/event-register-save.php';
$registerText = is_readable($registerSave) === true ? (string) file_get_contents($registerSave) : '';
$registerTidy = (string) gc_replace('/\s+/', ' ', $registerText);
$theCondition = 'if ($submittedByArg !== null '
    . '&& is_string($_POST[\'registeredFor\'] ?? null) === true '
    . '&& (string) $_POST[\'registeredFor\'] === \'me\' '
    . ') { $registeredForArg = $submittedByArg; }';
check(
    'the registration handler records "who it is for" ONLY when the person said the registration is their own, and the INSERT names that column (#492 part 3)',
    $registerText !== ''
    && str_contains($registerTidy, $theCondition) === true
    // Assigned in exactly two places: the empty default, and inside that block.
    && gc_findAll('/\$registeredForArg\s*=(?!=)/', $registerText) === 2
    // Named in the INSERT's column list, AND really bound: the column name on
    // its own would pass on a statement that lists it and binds nothing.
    && str_contains($registerTidy, '`submittedByUserID`, `registeredForUserID`,') === true
    && str_contains($registerTidy, '$submittedByArg, $registeredForArg') === true,
    $registerText === ''
        ? 'web/_apps/calendar/event-register-save.php could not be read, so this check proves nothing'
        : 'the whole condition must be present exactly, `$registeredForArg` must be assigned in exactly two places'
        . ' (the empty default and inside that block), and the INSERT must both NAME `registeredForUserID` and'
        . ' BIND $registeredForArg. Found '
        . gc_findAll('/\$registeredForArg\s*=(?!=)/', $registerText) . ' assignment(s); condition present: '
        . (str_contains($registerTidy, $theCondition) === true ? 'yes' : 'NO')
        . '; column in the INSERT: ' . (str_contains($registerTidy, '`submittedByUserID`, `registeredForUserID`,') === true ? 'yes' : 'NO')
        . '; value bound: ' . (str_contains($registerTidy, '$submittedByArg, $registeredForArg') === true ? 'yes' : 'NO')
);

// 🧾 #492 PART 4 — THE ONE SENTENCE A PERSON READS ABOUT THEIR EXPENSE CLAIMS.
//
//    `tblExpenseClaims`.`userID` is `INT NOT NULL`, so the claimant stays on
//    the claim whatever anybody intends. Until 3 November 2026 both the
//    written list and the in-app help said the claimant's name "is removed" /
//    "comes off the claim", which is UNTRUE and untrue in the REASSURING
//    direction: the erasure tries, the database refuses, and the report of the
//    erasure records that it could do nothing. The owner has now decided the
//    claimant is kept deliberately, for HMRC, so both places say that.
//
//    THE POSITIVE CLAUSE COMES FIRST on purpose. A check made only of
//    ABSENCES passes the day the sentence disappears altogether, which is
//    #694's section-P lesson. And the forbidden wording is not invented: it is
//    quoted from what was there, which `git show HEAD:web/_apps/help/admin.php`
//    produces until this change lands.
$claimReason  = (string) ($catalogue['tblExpenseClaims']['reason'] ?? '');
$helpAdmin    = __DIR__ . '/../web/_apps/help/admin.php';
$helpText     = is_readable($helpAdmin) === true ? (string) file_get_contents($helpAdmin) : '';
$helpTidy     = (string) gc_replace('/\s+/', ' ', $helpText);
$claimSays    = str_contains($claimReason, 'the claimant is kept with them') === true
    && str_contains($claimReason, 'the database refuses it') === true;
$helpSays     = str_contains($helpTidy, 'the claimant is kept with them') === true;
$claimDenies  = str_contains($claimReason, 'the claimant\'s name is removed') === true;
$helpDenies   = str_contains($helpTidy, 'claimant\'s name comes off the claim') === true;
check(
    'the written list and the help page both say an expense claim KEEPS its claimant, deliberately and for HMRC, and neither says the name comes off (#492 part 4)',
    $helpText !== '' && $claimSays === true && $helpSays === true
    && $claimDenies === false && $helpDenies === false,
    $helpText === ''
        ? 'web/_apps/help/admin.php could not be read, so this check proves nothing'
        : 'the written list must say the claimant is kept with the claim AND that the database refuses the attempt'
        . ' (found: ' . ($claimSays === true ? 'yes' : 'NO') . '), the help page must say the claimant is kept with'
        . ' them (found: ' . ($helpSays === true ? 'yes' : 'NO') . '), and neither may say the name is removed'
        . ' (written list says it: ' . ($claimDenies === true ? 'YES' : 'no')
        . ', help page says it: ' . ($helpDenies === true ? 'YES' : 'no') . ').'
        . ' `tblExpenseClaims`.`userID` is INT NOT NULL: the erasure tries, the database refuses, and the report'
        . ' records that it could do nothing. Saying the name comes off is untrue in the reassuring direction.'
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
// 8a-ii. A FREE-TEXT COLUMN EMPTIED ON AN INSTRUCTION THAT MATCHES AN ACTOR
//        (#635, 2 November 2026). This is the general form of the trap #635
//        had to work around by hand, written as a check rather than left as a
//        comment in the erasure routine.
//
//        In processEntry()'s anonymise branch, a column that IS an account
//        number is emptied with `IF(col = ?, NULL, col)` - scoped to this one
//        person. Any OTHER column in `nullCols` is emptied with a plain
//        `col = NULL`, on EVERY row the instruction matched. That is right when
//        the matched column says who the row is ABOUT, and wrong when it says
//        who ACTED on it: match on an actor and the erasure deletes a THIRD
//        PARTY's name, address or telephone from every row that actor ever
//        touched, on a request that was never theirs.
//
//        tblErrors is why this exists. Its written list names two account
//        columns, and its three diagnostic columns belong to the SUBJECT one;
//        on the "who resolved it" instruction they would have blanked an
//        unrelated visitor's address from every fault the erased administrator
//        had ticked off. Nothing would have reported it.
//
//        What this cannot see: whether a column holds somebody else's data at
//        all. It only judges the pairing of a non-account column with an
//        actor-matched instruction, which is the shape that does the harm.
$freeTextOnActor = [];
foreach ($instructions as $instruction) {
    $userCol = (string) ($instruction['userCol'] ?? '');
    if (in_array($userCol, $actorColumns, true) === false) {
        continue;
    }
    foreach ((array) ($instruction['nullCols'] ?? []) as $column) {
        $column = (string) $column;
        if (in_array($column, $linkColumns, true) === true) {
            continue;
        }
        $freeTextOnActor[] = (string) ($instruction['table'] ?? '') . ': `' . $column
            . '` would be emptied on every row matched by `' . $userCol . '`';
    }
}
check(
    'no instruction empties a non-account column on rows it matched by WHO ACTED, which would delete somebody else\'s data (#635)',
    $freeTextOnActor === [],
    implode('; ', $freeTextOnActor)
    . ' -- `' . 'nullCols' . '` on an actor-matched instruction is emptied with a plain `= NULL` on EVERY'
    . ' matched row, so this deletes a THIRD PARTY\'s details from rows that have nothing to do with the'
    . ' person who asked, and still reaches none of their own. Put the column on the instruction that'
    . ' matches the row\'s SUBJECT, or record in the written list that it cannot be reached and why.'
);

// -----------------------------------------------------------------------------
// 8c. MATCHING THROUGH A PARENT TABLE (#709, 2 November 2026).
//
//     One instruction in the list finds its rows by looking the subject up on
//     another table. `tblCareVisit`'s notes describe the person being cared
//     for, and the only account numbers on that row are the WORKER who made
//     the visit and the worker its follow-up was given to - so the subject is
//     one table up, on `tblCareCase.personUserID`.
//
//     This is the most dangerous shape in the whole routine, for a reason
//     worth stating plainly: get the parent column wrong and the erasure
//     empties a THIRD PARTY's safeguarding notes - every visit the erased
//     worker ever made, about people who never asked for anything - while
//     reaching not one note about the person who did ask. Both halves of that
//     are bad, and the second half is why nobody would notice the first.
//
//     The rule lives in `GdprEraser::whyParentMatchUnusable()` and these
//     checks ASK IT rather than keeping a second copy, which is the project's
//     standing rule after a password-reset test was found comparing its own
//     reimplementation of a portal rule.
// -----------------------------------------------------------------------------
$parentMatched = [];
foreach ($instructions as $position => $instruction) {
    if (isset($instruction['subjectVia']) === true) {
        $parentMatched[$position] = $instruction;
    }
}

check(
    'at least one instruction matches through a parent table, so the checks below are about something real',
    $parentMatched !== [],
    'none found -- if the only such instruction has been removed, remove these checks too rather than '
    . 'leaving them passing on an empty list'
);

// Every one of them is acceptable to the portal's own rule.
$viaRefused = [];
foreach ($parentMatched as $instruction) {
    $why = \Portal\Core\GdprEraser::whyParentMatchUnusable($instruction);
    if ($why !== '') {
        $viaRefused[] = (string) $instruction['table'] . ': ' . $why;
    }
}
check(
    'every parent-matched instruction passes the portal\'s own test of whether it may match that way',
    $viaRefused === [],
    implode('; ', $viaRefused) . ' -- processEntry() would refuse it at run time and the step would FAIL'
);

// 🛑 THE ORDERING TRAP, and the one check that catches it.
//
//    The parent's own instruction empties the very column the child matches
//    through: `tblCareCase.personUserID` is set to NULL by the `tblCareCase`
//    step. Put the child's instruction after it and the look-up finds nothing,
//    every note stays where it was, and the trail says "nothing held for this
//    person" - a silent, permanent, complete failure that reads like success.
//
//    In the list as it stood before #709 the parent came FIRST, so an
//    instruction added in the obvious place would have done exactly nothing.
//    That is not a hypothetical: it is the state of the file this check was
//    written against.
//
//    The run refuses too (GdprEraser::whyParentLookUpUnsafe()). Both are
//    needed. This one judges the LIST, which is where the mistake is made and
//    where it can be seen before anybody runs an erasure; that one judges the
//    RUN, and is the only half that survives the steps being called in some
//    other order.
$outOfOrder = [];
foreach ($parentMatched as $position => $instruction) {
    $parent = (string) $instruction['subjectVia']['parent'];
    foreach ($instructions as $otherPosition => $other) {
        if ((string) $other['table'] !== $parent) {
            continue;
        }
        if ($otherPosition < $position) {
            $outOfOrder[] = (string) $instruction['table'] . ' (position ' . (string) $position
                . ') matches through `' . $parent . '`, which is already changed at position '
                . (string) $otherPosition;
        }
    }
}
check(
    'a parent-matched instruction comes BEFORE every instruction for its parent table, or its look-up would find nothing',
    $outOfOrder === [],
    implode('; ', $outOfOrder)
    . ' -- the parent step empties the column this one matches through, so running it later finds no rows,'
    . ' changes nothing, and writes "nothing held for this person" into the sealed audit trail'
);

// The link to the parent must survive. Emptying `childCol` would detach a
// safeguarding record from its case, which is not anonymising anything: it
// destroys the record's meaning and leaves every word of the note in place.
$wouldDetach = [];
foreach ($parentMatched as $instruction) {
    $childCol = (string) $instruction['subjectVia']['childCol'];
    if (in_array($childCol, array_map('strval', (array) ($instruction['nullCols'] ?? [])), true) === true) {
        $wouldDetach[] = (string) $instruction['table'] . '.' . $childCol;
    }
}
check(
    'a parent-matched instruction never empties the link to its own parent',
    $wouldDetach === [],
    implode(', ', $wouldDetach)
    . ' -- that detaches the row from the case it belongs to while leaving every word of the note in place'
);

// 🧪 THE RULE ITSELF, exercised on instructions built here on purpose. Without
//    this the three checks above only prove that today's one instruction is
//    fine; they say nothing about whether the rule would catch a bad one. Each
//    case names the fault it stands for, and each MUST be refused.
$good = [
    'table'      => 'tblCareVisit',
    'userCol'    => '',
    'action'     => 'anonymise',
    'nullCols'   => ['notes'],
    'subjectVia' => [
        'childCol'  => 'caseID',
        'parent'    => 'tblCareCase',
        'parentKey' => 'caseID',
        'personCol' => 'personUserID',
    ],
];
$mustRefuse = [
    'the parent column says who ACTED (the one that would destroy a third party\'s notes)'
        => ['subjectVia' => ['personCol' => 'openedByID'] + $good['subjectVia']],
    'the parent column is not an account column at all'
        => ['subjectVia' => ['personCol' => 'summary'] + $good['subjectVia']],
    'it also names a column on the row itself, so there are two ways to find the rows'
        => ['userCol' => 'visitedByID'],
    'it would DELETE rows because of who the parent is about'
        => ['action' => 'delete'],
    'it names no column to empty, so it changes nothing while reading as coverage'
        => ['nullCols' => []],
    'a name that is not a plain identifier'
        => ['subjectVia' => ['parent' => 'tblCareCase; DROP TABLE x'] + $good['subjectVia']],
    'one of the four names is missing'
        => ['subjectVia' => ['childCol' => 'caseID', 'parent' => 'tblCareCase', 'personCol' => 'personUserID']],
    'subjectVia is not a list at all'
        => ['subjectVia' => 'tblCareCase.personUserID'],
];
$notRefused = [];
foreach ($mustRefuse as $fault => $change) {
    if (\Portal\Core\GdprEraser::whyParentMatchUnusable(array_replace($good, $change)) === '') {
        $notRefused[] = $fault;
    }
}
check(
    'the rule refuses all eight ways a parent match can be wrong, the actor column among them',
    $notRefused === [],
    'accepted when it should have refused: ' . implode('; ', $notRefused)
);

check(
    'the rule accepts the shape that is right, and says nothing about an instruction that does not use this form',
    \Portal\Core\GdprEraser::whyParentMatchUnusable($good) === ''
        && \Portal\Core\GdprEraser::whyParentMatchUnusable(['table' => 'tblUsers', 'userCol' => 'userID', 'action' => 'anonymise']) === '',
    'the rule refuses a correct instruction, or objects to one that does not match through a parent at all'
);

// The clause builder, pinned WHOLE for both shapes. A builder that quietly
// returned something else - the parent's whole table, say, or a clause with no
// WHERE at all - would widen an erasure to every row in the table, and the
// three checks above would not notice.
check(
    'the one clause builder writes exactly the right SQL for both shapes, and refuses to guess when it can write neither',
    \Portal\Core\GdprEraser::whereForEntry('userID', null) === '`userID` = ?'
        && \Portal\Core\GdprEraser::whereForEntry('', $good['subjectVia'])
            === '`caseID` IN (SELECT `caseID` FROM `tblCareCase` WHERE `personUserID` = ?)'
        && \Portal\Core\GdprEraser::whereForEntry('', null) === ''
        && \Portal\Core\GdprEraser::whereForEntry('', ['childCol' => 'caseID']) === '',
    'got: ' . \Portal\Core\GdprEraser::whereForEntry('', $good['subjectVia'])
);

// 📌 THE PIN. The three checks at the top of this section all walk a list built
//    from the instructions themselves, so deleting the instruction makes them
//    stop examining it rather than go red - the shape that let a removed
//    guarantee pass thirty green checks in #697. This names the one fact #709
//    exists to deliver, so that removing it fails.
$visitNotes = null;
foreach ($parentMatched as $instruction) {
    if ((string) $instruction['table'] === 'tblCareVisit') {
        $visitNotes = $instruction;
    }
}
check(
    'a pastoral visit\'s notes are emptied, matched through the case\'s own subject (#709)',
    $visitNotes !== null
        && (string) $visitNotes['action'] === 'anonymise'
        && array_map('strval', (array) $visitNotes['nullCols']) === ['notes']
        && $visitNotes['subjectVia'] === [
            'childCol'  => 'caseID',
            'parent'    => 'tblCareCase',
            'parentKey' => 'caseID',
            'personCol' => 'personUserID',
        ],
    $visitNotes === null
        ? 'there is no parent-matched instruction for tblCareVisit at all'
        : 'it is: ' . json_encode($visitNotes)
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
//     IT IS NOW EMPTY, AND IT MUST STAY EMPTY (#635, 2 November 2026). All 35
//     were decided by the owner on 1 and 2 November 2026, and every reason now
//     lives beside its column in the written list itself, under
//     `notEmptiedHere` - not here. That is the point of the move: a reason in
//     `web/_core/personal-data-catalogue.php` is in the file a data subject's
//     request is answered from, and a reader of that list can see why a column
//     they asked about is still there. A table name in a test fixture told
//     nobody anything.
//
//     Four of the three dozen are worth knowing about, because they are the
//     shape that nearly went in wrong. In tblLiveChatMessages, tblEventRSVPInvites,
//     tblVenues (the caretaker's name and telephone) and tblKidCheckins
//     (who collected a child), the column really is personal - and the only
//     column an instruction could match on belongs to a DIFFERENT person: the
//     sender, the coordinator who sent the invitation, whoever added the venue,
//     the helper who did the checking. Emptying them would have deleted a third
//     party's details from rows that have nothing to do with the person who
//     asked, while reaching none of their own. They are kept, with that written
//     down. An earlier draft of this work called them "the same fault as #626"
//     and would have emptied all four.
//
//     Nothing may be added to this list. If you are tempted, the answer is a
//     `notEmptiedHere` line with a real reason, which the three checks below
//     judge.
//
//     Tables dealt with by a dedicated step or by the last step are left out:
//     their columns are emptied by code of their own, which section 10 checks.
// -----------------------------------------------------------------------------
$leftBehindOnPurpose = [
    // Deliberately empty - see the note above. Do not add a table here.
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
// Faults in the written list's OWN explanations - see the long note below.
$explainedNotNamed      = [];
$explainedButEmptied    = [];
$explainedWithoutReason = [];
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

    // 📋 A column the written list EXPLAINS is not a column left behind (#635,
    //    2 November 2026). `notEmptiedHere` is the entry's own answer to "this
    //    named column is not emptied by this table's instruction, and here is
    //    why that is right". It covers three kinds of reason, all of them real:
    //    the column is deliberately kept; it is emptied somewhere else; or it is
    //    personal and genuinely unreachable, because the only column an
    //    instruction could match on belongs to a DIFFERENT person.
    //
    //    This is what the old failure message always asked for in words -
    //    "change the written list to say the column is deliberately kept and
    //    why" - and it replaces `$leftBehindOnPurpose`, which was a list of
    //    table names in a TEST file with no reason attached to any of them.
    //    A reason that lives beside the column, in the file a data subject's
    //    request is answered from, is worth more than a name in a fixture.
    //
    //    THE THREE CHECKS BELOW ARE WHAT STOP IT BECOMING AN EXCUSE LIST, which
    //    is exactly what the pinned list was forbidden from being: a reason must
    //    actually be a reason, an explanation for a column the entry does not
    //    name is stale, and an explanation for a column that IS now emptied must
    //    go, so the list shrinks as the work is done.
    $explained = (array) ($meta['notEmptiedHere'] ?? []);
    $named     = array_map('strval', (array) ($meta['columns'] ?? []));

    foreach ($explained as $column => $why) {
        $column = (string) $column;
        if (in_array($column, $named, true) === false) {
            $explainedNotNamed[] = $table . '.' . $column;
        }
        if (in_array($column, $dealtWith[$table] ?? [], true) === true) {
            $explainedButEmptied[] = $table . '.' . $column;
        }
        if (strlen(trim((string) $why)) < 40) {
            $explainedWithoutReason[] = $table . '.' . $column;
        }
    }

    $leftHere = [];
    foreach ($named as $column) {
        $column = (string) $column;
        if (in_array($column, $dealtWith[$table] ?? [], true) === true) {
            continue;
        }
        // An account number: the checks above are the ones that judge those.
        if (in_array($column, $linkColumns, true) === true) {
            continue;
        }
        // The entry says why this one is not emptied, in enough words to be a
        // reason. The checks above judge whether that claim is still true.
        if (array_key_exists($column, $explained) === true
            && strlen(trim((string) $explained[$column])) >= 40
        ) {
            continue;
        }
        $leftHere[] = $column;
    }

    sort($leftHere);
    // 🪦 THE PINNED-LIST BRANCH IS GONE (#635, 2 November 2026). It read
    //    `$leftBehindOnPurpose[$table]` and, where a table was pinned, either
    //    recorded it as "pinned but now clean" or subtracted the pinned names
    //    from what was left behind. With the list empty that branch could never
    //    run, so it was dead code that could only ever SUPPRESS a real column if
    //    somebody put a name back. The list being empty is now pinned directly,
    //    below, which is a check that can actually fail.
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
    'every column the written list explains as not-emptied is one it actually names (#635)',
    $explainedNotNamed === [],
    implode(', ', $explainedNotNamed)
    . ' -- `notEmptiedHere` explains a column the entry\'s own `columns` list does not name, so the'
    . ' explanation is stale: the column was renamed or taken off the list and the reason was left'
    . ' behind. Remove it, or put the column back if it is still personal.'
);

check(
    'no column is explained as not-emptied while the erasure routine does empty it (#635)',
    $explainedButEmptied === [],
    implode(', ', $explainedButEmptied)
    . ' -- the written list says this column is not emptied here and gives a reason, but an'
    . ' instruction DOES empty it. One of the two is wrong, and a reader of the list is being told'
    . ' something untrue about their own data. This is the check that makes the list SHRINK as the'
    . ' work is done, exactly as the pinned list it replaced had to.'
);

check(
    'every not-emptied explanation is long enough to be an actual reason (#635)',
    $explainedWithoutReason === [],
    implode(', ', $explainedWithoutReason)
    . ' -- fewer than 40 characters is a label, not a reason. This is the guard that stops'
    . ' `notEmptiedHere` becoming the excuse list $leftBehindOnPurpose was forbidden from being:'
    . ' a column may only be left behind if somebody wrote down why, in words a data subject could'
    . ' read.'
);

check(
    'the old pinned list of tables that leave a column behind is EMPTY and stays empty (#635)',
    $leftBehindOnPurpose === [],
    'it holds ' . count($leftBehindOnPurpose) . ' table(s): ' . implode(', ', array_keys($leftBehindOnPurpose))
    . ' -- every one of the 35 was decided on 1 and 2 November 2026 and its reason now lives beside its'
    . ' column in the written list, under `notEmptiedHere`. Nothing may go back on this list. A table'
    . ' name here explains nothing to the person whose data it is; a reason in'
    . ' web/_core/personal-data-catalogue.php does.'
);

// 📌 WHAT THIS PACKAGE ITSELF EMPTIED, pinned by name so that undoing it cannot
//    be green. The checks above compare the erasure routine against the WRITTEN
//    LIST, so deleting a column from an entry AND taking its `nullCols` away
//    passes both: the list no longer names it, so nothing is "left behind".
//    That is the #706 shape - a guard that reads as complete because it is only
//    ever compared against itself - and the independent check proved it by
//    planting exactly that undo on tblAuditTrail.ipAddress with all 71 green.
//
//    The general cure is #706: compare the list against the SCHEMA, so a
//    personal column that no entry names is found. That is its own sweep. This
//    is the narrow version: the five columns #635 emptied must still be named
//    AND still be emptied.
$shouldBeEmptied = [
    'tblErrors'           => ['visitorIP', 'userAgent', 'requestHeaders'],
    'tblAuditTrail'       => ['ipAddress'],
    'tblEventAttendance'  => ['notes'],
];
$noLongerEmptied = [];
foreach ($shouldBeEmptied as $table => $columns) {
    foreach ($columns as $column) {
        $named   = in_array($column, array_map('strval', (array) ($catalogue[$table]['columns'] ?? [])), true);
        $emptied = in_array($column, $dealtWith[$table] ?? [], true);
        if ($named === false || $emptied === false) {
            $noLongerEmptied[] = $table . '.' . $column
                . ($named === false ? ' (no longer named in the written list)' : '')
                . ($emptied === false ? ' (no longer emptied)' : '');
        }
    }
}

// ⚠️ THE LOOP ABOVE ITERATES THIS VERY ARRAY, so deleting a line from
//    `$shouldBeEmptied` does not fail anything - it just stops testing that
//    column, silently. That is #697's lesson (proof 26's refusal loop could not
//    notice a deleted name) and the independent check found it here on 2
//    November 2026 by deleting tblAuditTrail's line and watching 73 stay green.
//    The shape is pinned inside the SAME check, so the count does not move.
$pinnedShape = [];
foreach ($shouldBeEmptied as $table => $columns) {
    $pinnedShape[] = $table . ':' . implode('+', $columns);
}
sort($pinnedShape);

check(
    'the five columns #635 emptied are still named in the written list AND still emptied',
    $noLongerEmptied === []
    && $pinnedShape === [
        'tblAuditTrail:ipAddress',
        'tblErrors:visitorIP+userAgent+requestHeaders',
        'tblEventAttendance:notes',
    ],
    implode('; ', $noLongerEmptied)
    . ' -- #635 gave each of these an instruction after the written list had named it personal and'
    . ' nothing had emptied it. Taking the column off the list as well as out of `nullCols` makes every'
    . ' other check here pass, because they only ever compare the routine against the list. If one of'
    . ' these is genuinely not personal, that is a decision to record in the entry with a reason, not a'
    . ' deletion. The list itself is pinned: it must be exactly'
    . ' tblAuditTrail:ipAddress, tblErrors:visitorIP+userAgent+requestHeaders, tblEventAttendance:notes'
    . ' and it is ' . implode(', ', $pinnedShape) . ' -- if those differ, somebody shortened the list'
    . ' instead of fixing the code, which would stop a column being tested without failing anything.'
);

check(
    'the activity log empties every detail the written list names for it (#626)',
    ($leftBehindOnPurpose['tblActivityLogs'] ?? null) === null
    // The `$pinnedAndClean` clause that used to sit here went with the pinned
    // branch (#635): that list is empty now, so the clause could never fail.
    // The five columns below are what this check is really about.
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
if (gc_find("/'activityLogs'\s*=>.*?'(SELECT[^']*tblActivityLogs[^']*)'/s", $exportText, $m) === 1) {
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
$prayerSelect  = (gc_find('/\'prayerRequests\'\s*=>.*?FROM\s+tblPrayerRequests/s', $exportHere, $m) === 1) ? $m[0] : '';
$bookingSelect = (gc_find('/\'venueBookingsEdited\'\s*=>.*?FROM\s+tblVenueBookings/s', $exportHere, $m) === 1) ? $m[0] : '';
// Judge the CODE, not the comments beside it. Every check below reads these
// stripped copies: the first draft tested the raw text and failed on the
// page's own explanation, which names the columns it is explaining the
// absence of and quotes "SELECT *" as the thing not to put back.
$prayerCode    = (string) gc_replace('!//[^\n]*!', '', $prayerSelect);
$bookingCode   = (string) gc_replace('!//[^\n]*!', '', $bookingSelect);

check(
    'the download does NOT hand over the account numbers of whoever moderated a prayer request or whoever it was given to: they are other people',
    $prayerSelect !== ''
        && str_contains($prayerCode, 'SELECT *') === false
        && gc_find('/\bmoderatorID\b/', $prayerCode) !== 1
        && gc_find('/\bassignedToUserID\b/', $prayerCode) !== 1,
    $prayerSelect === ''
        ? 'the prayer-request query could not be found in data-export.php at all, so this check proves nothing: find it and fix this check'
        : 'it selects: ' . gc_replace('/\s+/', ' ', $prayerSelect)
);
check(
    'and it still hands over the request that is the person\'s own: the subject, the body, how it was answered and when',
    $prayerSelect !== ''
        && str_contains($prayerCode, 'subject') === true
        && str_contains($prayerCode, 'body') === true
        && str_contains($prayerCode, 'submitterID') === true,
    'it selects: ' . gc_replace('/\s+/', ' ', $prayerSelect)
);
check(
    'the download does NOT hand over the free-text notes of a booking somebody merely edited: anybody may have written them, about anybody',
    $bookingSelect !== ''
        && gc_find('/\bnotes\b/', $bookingCode) !== 1,
    $bookingSelect === ''
        ? 'the venue-booking query could not be found in data-export.php at all, so this check proves nothing: find it and fix this check'
        : 'it selects: ' . gc_replace('/\s+/', ' ', $bookingSelect)
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
gc_findAll(
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
    if (gc_find('/\b(FROM|JOIN)\s+' . preg_quote($table, '/') . '\b/', $export) !== 1) {
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
    gc_findAll('/claimedAt\s*(?:=|<|>|<=|>=)\s*([A-Za-z_]+(?:\([A-Za-z_]*)?)/', $source, $uses);
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
        && gc_find('/INSERT\s+INTO\s+`?tblErasureRequest`?/i', $codeOnly((string) file_get_contents($file->getPathname()))) === 1
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

// Which page may file a request as ALREADY CONFIRMED. There are two, and each
// earns it a different way:
//
//   "Delete my account"  — the person has typed the confirming phrase
//                          themselves, so there is nobody left to e-mail.
//   Offboarding (#569)   — an ADMINISTRATOR is acting on somebody who is
//                          leaving. There is no link for anybody to click,
//                          and the request lands as `pending_review`; it is
//                          the same thing the "Confirm on the person's
//                          behalf" button does, with the administrator's own
//                          gates in front of it.
//
// "Request data erasure" has no phrase and no administrator, so it must go
// through the emailed link.
//
// WHY THE LIST IS PINNED WHOLE. A further caller, or the flag appearing on a
// page that has not earned it, would let a request reach an administrator's
// queue that nobody confirmed — and in `after_undo_window` mode the daily
// sweep would then carry it out with no human looking at it. That is why the
// offboarding entry is NOT simply added to the list: what makes it safe is
// its gates, so those are pinned here too. An offboarding handler that loses
// its administrator test, its form-token test or its AccountGuard check turns
// this check red even though the list of callers has not changed.
//
// This check went red on 31 October 2026 for exactly the right reason: the
// #569 offboarding work added the third caller, and the list said two. The
// answer was to read the new caller's gates and pin them, not to widen the
// list and move on.
//
// AND THEN THE FIRST ATTEMPT AT PINNING THEM PASSED ON BROKEN CODE, THREE
// WAYS. An independent check found all three by planting, the same day:
//
//   1. It matched each gate's `if (...)` LINE and nothing else, so deleting
//      the REFUSAL inside the braces — leaving `if (App::isAdmin() === false)
//      { }` — passed every check. Any signed-in member could then offboard
//      anybody. This repository has learnt that exact lesson once already
//      (#668's section D passed on four disabled gates): pin the WHOLE
//      condition, and require the block to refuse.
//   2. The same for `AccountGuard`, which is #518 reintroduced: an
//      administrator of ANY organisation reaching ANY account, now also
//      filing a CONFIRMED erasure request.
//   3. Callers were named by `basename()`, so moving the call into a new,
//      completely ungated `zzplant/do.php` was indistinguishable from the
//      gated `offboarding/do.php`. `do.php` is a generic name. The two
//      basenames in the version before this one happened to be distinctive,
//      so keying on the name was never safe — it merely looked it.
//
// So: callers are named by their path under `web/`, each gate must REFUSE,
// and the three gates must appear IN ORDER, which is what the sentence below
// claims and what nothing checked before.
//
// WHY THE SOURCE IS MASKED RATHER THAN JUST STRIPPED OF COMMENTS. The walk
// below asks "does an `exit` come before the closing brace". A `}` inside a
// message, or the word `exit` inside one, would answer that question falsely
// in either direction, and `$codeOnly()` leaves string literals alone. So
// every literal becomes a run of 'x' of the same length: the shape of the
// code is kept, and nothing inside a message can be mistaken for code.

/**
 * One file's PHP with comments removed, every string literal replaced by the
 * same number of 'x' characters, and runs of whitespace collapsed to a single
 * space, so that matching does not depend on how it happens to be laid out.
 */
$maskedCode = static function (string $path): string {
    $code = '';
    foreach (token_get_all((string) @file_get_contents($path)) as $token) {
        if (is_array($token) === false) {
            $code .= $token;
            continue;
        }
        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) === true) {
            continue;
        }
        $code .= in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) === true
            ? str_repeat('x', strlen($token[1]))
            : $token[1];
    }

    return (string) gc_replace('/\s+/', ' ', $code);
};

/**
 * Where one gate is, and -1 when it is not there or does not refuse.
 *
 * Each anchor is found after the one before it, so a gate is identified by a
 * sequence rather than by one line. From the end of the last anchor the walk
 * requires an `exit` BEFORE the next `}`: that is what makes an emptied
 * block — the condition kept, the refusal deleted — fail.
 *
 * @param list<string> $anchors
 */
$gateAt = static function (string $masked, array $anchors): int {
    $at = 0;
    foreach ($anchors as $anchor) {
        $found = strpos($masked, $anchor, $at);
        if ($found === false) {
            return -1;
        }
        $at = $found + strlen($anchor);
    }
    $after = substr($masked, $at);
    $close = strpos($after, '}');
    $stop  = strpos($after, 'exit');
    if ($close === false || $stop === false || $stop > $close) {
        return -1;
    }

    return $at;
};
$offboardMasked = $maskedCode(__DIR__ . '/../web/_apps/offboarding/do.php');
$adminGate = $gateAt($offboardMasked, ['if (App::isAdmin() === false) {']);
$tokenGate = $gateAt($offboardMasked, ['Auth::verifyCsrf(', ') === false) {']);
$guardGate = $gateAt($offboardMasked, [
    'AccountGuard::check($userId, AccountGuard::REACH_ACCOUNT',
    'if ($verdict !== AccountGuard::ALLOW) {',
]);
$filingCalls = [];
// The folder the walk starts from, kept in a variable so the path each call is
// named by is cut at exactly the same place the walk began. Taking the length
// of realpath() instead cut the wrong number of characters off, because the
// iterator hands back the path as it was given — `tools/../web/…` — and
// realpath() is shorter.
$webBase = __DIR__ . '/../web';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($webBase, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    gc_findAll('/GdprEraser::fileRequest\(([^;]*)\);/', $codeOnly((string) file_get_contents($file->getPathname())), $calls);
    foreach ($calls[1] as $arguments) {
        // The PATH under `web/`, never the file's own name: `do.php` is a
        // generic name, and a new ungated file called that read as the gated
        // one (found by planting, 31 October 2026).
        $where = str_replace(DIRECTORY_SEPARATOR, '/', substr((string) $file->getPathname(), strlen($webBase) + 1));
        $filingCalls[] = $where . ': ' . gc_replace('/\s+/', ' ', trim($arguments));
    }
}
sort($filingCalls);
check(
    'only the typed-phrase page and the offboarding handler file a request as already confirmed, and the offboarding handler still refuses at all three of its gates, in order',
    $filingCalls === [
        '_apps/account/erasure-request.php: $siteId, $userId',
        '_apps/auth/account/delete-confirm.php: Site::id(), $userId, true',
        '_apps/offboarding/do.php: Site::id(), $userId, true',
    ]
        && str_contains($deleteCode, "!== 'DELETE MY ACCOUNT'") === true
        // Each gate must be PRESENT and must REFUSE, and they must come in
        // this order: not an administrator, then not a valid form token, then
        // AccountGuard. Order matters because AccountGuard moved below the
        // work it protects would leave the sentence above untrue.
        && $adminGate > 0 && $tokenGate > $adminGate && $guardGate > $tokenGate,
    implode(' | ', $filingCalls)
        . '; gates at admin ' . $adminGate . ', form token ' . $tokenGate . ', AccountGuard ' . $guardGate
        . ' (-1 means missing, or present and refusing nothing)'
);

// An administrator can confirm a waiting request on the person's behalf. The
// button must carry the form token and a prompt that makes the administrator
// say they have checked who is asking; the handler must go through the one
// function that writes the audit line.
$listPage    = (string) @file_get_contents(__DIR__ . '/../web/_apps/admin/erasure/index.php');
$confirmForm = gc_find('/<form[^>]*>(?:(?!<\/form>).)*name="action" value="confirm"(?:(?!<\/form>).)*<\/form>/s', $listPage, $form) === 1 ? $form[0] : '';
check(
    'the "Confirm on the person\'s behalf" button carries the form token and a prompt about checking identity, and its handler writes the audit line',
    $confirmForm !== '' && str_contains($confirmForm, 'name="csrf_token"') === true
        && gc_find('/data-confirm="[^"]*checked, yourself, that the request really comes from them[^"]*"/', $confirmForm) === 1
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
    $definition = gc_find('/CREATE TABLE IF NOT EXISTS\s+`' . $table . '`(.*?)\n\)\s*ENGINE/s', $schemaText, $found) === 1
        && gc_find('/^\s+`' . $column . '`\s+([^\n]*)$/m', $found[1], $line) === 1 ? $line[1] : '';
    if ($definition === '' || stripos($definition, 'NOT NULL') !== false || stripos($definition, 'DEFAULT NULL') === false) {
        $stillRequired[] = $table . '.' . $column . ' in the fresh-install script (' . trim(substr($definition, 0, 40)) . ')';
    }
    if (gc_find('/ALTER TABLE `' . $table . '` MODIFY COLUMN `' . $column . '` INT DEFAULT NULL/', $migration) !== 1) {
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
    $joinedUp = (string) gc_replace("/'\s*\.\s*'/", '', $codeOnly((string) @file_get_contents(__DIR__ . '/../web/' . $page)));
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
    $next = gc_find('/\n\s*(?:(?:public|private|protected)\s+)?(?:static\s+)?function\s+\w+\s*\(/', $code, $m, PREG_OFFSET_CAPTURE, $start + 10) === 1
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
    && gc_find('/if \(\$state\[\'accountEmptied\'\] === false && \$state\[\'state\'\] !== \'unreadable\'\) \{\s*\$state\[\'state\'\] = \'not-erased\';/', $rowState) === 1
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
    // #703 round 8: the rule moved into sortingScope(), which givingOutlook()
    // (what My data shows) asks too; so the keep step's CALL is pinned here and
    // the helper's whole condition below, rather than the inline text it held.
    && str_contains($atSite, "\$scope = self::sortingScope((string) \$rule['scope'], \$existing, \$emptiedAlready);")
    && str_contains($method($eraserCode, 'sortingScope'), "if (\$keptRow !== null && (\$keptRow['clearedAt'] ?? null) === null && \$accountEmptied === true\n"
        . "            && (string) (\$keptRow['scope'] ?? '') === GivingRetention::SCOPE_ALL) {\n            return GivingRetention::SCOPE_ALL;\n        }\n\n        return \$todayScope;")
    && str_contains($atSite, '$emptiedAlready = self::accountEmptiedForGiving($account);')
    // #703 round 9: the helper's WHOLE body, because its address-only branch
    // (the made-up e-mail address alone counts as emptied) was pinned by
    // nothing - deleting it left every check green (plant M7 of round 8's
    // check). A SOURCE pin: no driven test builds an account emptied by
    // address alone beside an earlier run's kept row.
    && str_contains($method($eraserCode, 'accountEmptiedForGiving'), "return \$account === null\n"
        . "            || (string) (\$account['fullName'] ?? '') === self::TOMBSTONE_NAME\n"
        . "            || self::isTombstoneAddress((string) (\$account['emailAddress'] ?? '')) === true;")
    && str_contains($atSite, 'self::$everyGiftSites[] = $siteId;')
    && str_contains($method($eraserCode, 'unnameCountEnvelopes'), "' OR cs.siteID IN (' . implode(', ', array_map('intval', self::\$everyGiftSites)) . ')'")
    // #703 round 9: the "nothing to keep" test moved into keepsNothing(),
    // which givingOutlook() asks too; the CALL is pinned here and the helper's
    // whole body below.
    && str_contains($atSite, 'if (self::keepsNothing($facts, $keptAlready) === true) {')
    && str_contains($method($eraserCode, 'keepsNothing'), "return \$keptAlready === false && (int) (\$facts['gifts']['kept'] ?? 0) === 0 && (int) (\$facts['declarations']['n'] ?? 0) === 0\n"
        . "            && (int) (\$facts['statements']['n'] ?? 0) === 0 && (int) (\$facts['envelopes']['n'] ?? 0) === 0;")
    && str_contains($atSite, '$facts        = self::givingFactsAt($db, $siteId, $userId, $scope);')
    && str_contains($atSite, "['date' => \$lastGift, 'from' => \$from] = self::keptCountedFrom(\$facts, \$earlierRun === true ? \$existing : null);")
    && strpos($atSite, '$keptAlready = $earlierRun === true') < strpos($atSite, 'GivingRetention::keptGiftSql($scope')
    && str_contains($atSite, "'UPDATE tblGivingKeptDonors SET erasureRequestID = ?, fullName = ?, address = ?, postcode = ?, scope = ?, '")
    && strpos($atSite, 'if ($emptiedAlready === true) {') !== false
    && strpos($atSite, 'if ($emptiedAlready === true) {') < strpos($atSite, "'UPDATE tblGivingKeptDonors SET lastGiftDate = ?")
    // #703 round 9: "never move the date earlier" now lives in
    // keptCountedFrom(), which the keep step calls with the earlier run's row.
    && str_contains($method($eraserCode, 'keptCountedFrom'), "if (\$earlierRow !== null && (string) \$earlierRow['lastGiftDate'] > \$lastGift) {")
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
// 🚫 THE REFUSAL MUST LIE BETWEEN THE QUESTION AND THE WRITE, which is the
//    property actually being asserted — not "an exit() within 1,100
//    characters of the question", which is what this used to ask.
//
//    Measured on 4 November 2026: the question sits at byte 1,500 and the
//    `UPDATE … status = "cancelled"` at 2,391, a gap of 891, so the 1,100
//    window reached **209 characters PAST the write**. Exactly one `exit();`
//    lies between them — the gate's own. So the old form was sound only
//    because no other refusal happened to sit in that slack, and any new one
//    added just after the cancel write would have restored the fault, on the
//    guard that stops an erasure request being cancelled after it has already
//    changed somebody's records. This is #742's shape, found in this file by
//    an independent check rather than by the #742 sweep.
//
//    Asking for the position needs no brace walk and no window: a refusal
//    after the write cannot satisfy it, because it is not before the write.
$refuseAt    = $askAt === false ? false : strpos($processCode, 'exit();', $askAt);
$hasRun      = $method($eraserCode, 'givingStepHasRun');
$hasChanged  = $method($eraserCode, 'runHasChangedAnything');
$trailLine   = $method($eraserCode, 'trailHasLine');
check(
    'a request whose run has changed ANYTHING cannot be cancelled: the page asks before it writes "cancelled", leaves with a message, and the question covers a deleted row, an emptied column and a kept name, and answers "yes" when it cannot tell',
    $askAt !== false && $cancelAt !== false && $askAt < $cancelAt
    && $refuseAt !== false && $refuseAt < $cancelAt
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
if (gc_find("/'totalDeleted'\s*=>\s*([^,\]]+)/", $sweepCode, $m) === 1) {
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
    && gc_find('/UPDATE tblCountEnvelopes ce.*?SET ce\.giverID = NULL, ce\.giverName = NULL/s', $clear) === 1
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
    if (gc_find('/org\.address|[\'"](GB|UK|US|IE)[\'"]|HMRC|United Kingdom/', $code) === 1) {
        $countryLiterals[] = $where;
    }
}
check(
    'no country is built in: the giving-records code never reads the installation\'s own address, and names no country, no tax office and no figure of its own',
    $retentionCode !== '' && $countryLiterals === [] && str_contains($retentionCode, "'giving.retention.countryCode'")
    && gc_find("/giving\.retention\.countryCode',\s*'',\s*''/", $migration209) === 1
    && gc_find("/giving\.retention\.countryCode',\s*'',\s*''/", $schemaText) === 1
    && gc_find("/giving\.retention\.confirmed',\s*'false',\s*'false'/", $schemaText) === 1,
    'a country or tax office is named in: ' . implode(', ', $countryLiterals)
);

// The words on the settings page.
$pageText = (string) gc_replace('/\s+/', ' ', strip_tags((string) gc_replace('/<\?php.*?\?>/s', ' ', $settingsPage)));
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
    && gc_find('/HMRC|financialRetentionYears/', $privacyCode) !== 1
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

// -----------------------------------------------------------------------------
// 🧷 SECTION 8d — the parent-matched code that NOTHING pinned (#709, fix round)
// -----------------------------------------------------------------------------
// The independent check planted five faults that no check anywhere caught.
// Four of them are closed here; the fifth is dead code and is written up in
// the eraser test instead. They are all the same shape: a guard whose
// BEHAVIOUR is tested somewhere, while nothing says the portal still CALLS it.
//
//   - Replacing the whole run-time guard's call with `if (false)` left the
//     coverage test, the eraser test and all 46 audit scripts green.
//   - Reverting `inventory()` to its old "skip an instruction with no column
//     of its own" left both tests green, although the change is listed as
//     delivered - so "what we hold about you" would have stopped counting
//     these rows and nothing would have said so.
//   - Swapping `parentKey` for `childCol` in the clause builder left
//     everything green, because the one real instruction uses `caseID` for
//     BOTH, so the builder pin could not tell the two names apart.
//   - Deleting a case from the rule's own `$mustRefuse` list STOPPED TESTING
//     it rather than failing, while the label went on claiming eight ways.
//
// The source checks below read the class with comments removed and every
// string literal masked, because a `}` or the words `whereForEntry` inside a
// message would answer them falsely in either direction.

$eraserMasked = $maskedCode($eraserFile);

/**
 * The body of one method, found by walking braces over MASKED source.
 *
 * Masked, so a brace inside a log message cannot end the body early - the
 * fault that once let a check pass on a removed gate.
 */
$methodBody = static function (string $masked, string $signature): string {
    $at = strpos($masked, $signature);
    if ($at === false) {
        return '';
    }
    $open = strpos($masked, '{', $at);
    if ($open === false) {
        return '';
    }
    $depth = 0;
    for ($i = $open, $len = strlen($masked); $i < $len; $i++) {
        if ($masked[$i] === '{') {
            $depth++;
        } elseif ($masked[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($masked, $open, $i - $open + 1);
            }
        }
    }

    return '';
};

$processEntryBody = $methodBody($eraserMasked, 'function processEntry(');
$inventoryBody    = $methodBody($eraserMasked, 'function inventory(');

// 📌 THE OUTER GATE FIRST, because pinning only the inner calls was not enough
//    and a second check round proved it: `if (false && isset(...))` on the one
//    line above switched BOTH guards off - the rule and the run-time guard -
//    with all 336 checks across the two files still green. The inner pins were
//    satisfied by text that never ran. So the whole `if` that decides whether
//    a parent-matched instruction is examined at all is pinned here, with
//    nothing before `isset` and nothing after `=== true`.
check(
    'a parent-matched instruction is still EXAMINED at all: the whole outer condition is pinned, so `if (false && isset(...))` - which switches off both guards in one word - fails here',
    $processEntryBody !== ''
        && str_contains($processEntryBody, 'if (isset($entry[xxxxxxxxxxxx]) === true) { $whyNotViaParent = self::whyParentMatchUnusable($entry);') === true,
    'the gate that examines a parent-matched instruction has changed, or something was added to its condition'
);

// 📌 And the RULE's own call, which nothing pinned until round 2 - only the
//    run-time guard's was pinned, so deleting the rule's call was invisible.
check(
    'the rule that refuses an unusable parent match is still ASKED by the step, and still fails it',
    $processEntryBody !== ''
        && str_contains($processEntryBody, '$whyNotViaParent = self::whyParentMatchUnusable($entry);') === true
        && gc_find('/if \(\$whyNotViaParent !== xx\) \{[^}]*?return self::STEP_FAILED;/', $processEntryBody) === 1,
    'processEntry() no longer asks whyParentMatchUnusable() and fails the step on its answer'
);

// 📌 The WHOLE condition, not the mere mention of the method. `&& false` on it,
//    or `if (false)`, must fail this - which is exactly what was planted.
check(
    'the step that empties a child table still ASKS the run-time guard and refuses on its answer: the whole condition is pinned, so `if (false)` or `&& false` fails here',
    $processEntryBody !== ''
        && str_contains($processEntryBody, '$whyLookUpUnsafe = self::whyParentLookUpUnsafe( $db, $requestId, $table, $via );') === true
        && str_contains($processEntryBody, 'if ($whyLookUpUnsafe !== xx) {') === true,
    'processEntry() no longer asks whyParentLookUpUnsafe() and acts on its whole answer'
);

// 📌 NEITHER refusal may be truncated, and the check that claimed to guard this
//    COULD NOT. It measured the guard's own returned sentence - a literal in
//    the same file - so putting `mb_substr(..., 0, 250)` back around the audit
//    line, which is where the fault was, left everything green. Measured, the
//    two refusals are 261 and 317 characters as stored, so they really would be
//    cut. `details` is a TEXT column; the 250-character cuts in that method are
//    for FAULT text whose length nobody controls.
check(
    'neither parent-matched refusal is written into the audit trail through a 250-character cut: the fault #709 records is an identifier sliced in half, and the reasons here are 261 and 317 characters long',
    $processEntryBody !== ''
        && str_contains($processEntryBody, 'null, xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx . $whyNotViaParent );') === true
        && str_contains($processEntryBody, 'null, xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx . $whyLookUpUnsafe . xxx );') === true,
    'one of the two parent-matched refusals is no longer logged whole - look for mb_substr() around it'
);

// 📌 The list of actions that count as "this step already answered". Widening it
//    puts the whole fault back and nothing noticed: a PROBE failure logs
//    `failed` with details beginning "could not look for this person (matched
//    through ...)", so it carries the discriminator and sits before the
//    parent's change. Section 19 proves the behaviour; this pins the list, so
//    the two cannot drift.
//    This one cannot use the masked source, because masking replaces the whole
//    SQL literal with 'x' and the list is INSIDE it. So comments are stripped
//    and the strings kept - which means a COMMENTED-OUT copy cannot satisfy it,
//    the trap this project has been caught by, while the SQL itself is still
//    readable. Section 19 proves the behaviour; this pins the text, so the two
//    cannot drift apart.
$eraserCodeOnly = static function (string $path): string {
    $code = '';
    foreach (token_get_all((string) @file_get_contents($path)) as $token) {
        if (is_array($token) === false) {
            $code .= $token;
            continue;
        }
        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) === true) {
            continue;
        }
        $code .= $token[1];
    }

    return (string) gc_replace('/\s+/', ' ', $code);
};

check(
    'only an action meaning "this step reached its rows" counts as it having answered: the list is exactly anonymise, partial and none - adding `failed` restores the fault the guard exists to prevent, because a probe failure writes the same "matched through" sentence',
    str_contains(
        $eraserCodeOnly($eraserFile),
        "AND action IN ('anonymise', 'partial', 'none') AND auditID < ? "
    ) === true,
    'the trustworthy-action list has changed; `failed` and `skip` must stay out of it'
);

// 📌 And the refusal must still REFUSE. Matching the condition alone passed
//    once with the body emptied (#668's section-D lesson, and #569's).
check(
    'and that refusal still fails the step rather than carrying on: the guard cannot be reduced to a condition with nothing behind it',
    $processEntryBody !== ''
        && gc_find(
            '/if \(\$whyLookUpUnsafe !== xx\) \{[^}]*?return self::STEP_FAILED;/',
            $processEntryBody
        ) === 1,
    'the run-time guard\'s refusal no longer returns STEP_FAILED'
);

// 📌 "What we hold about you" counts these rows. Reverting this was invisible.
check(
    'the "what we hold about you" count asks the ONE clause builder too, so a parent-matched instruction is counted rather than skipped',
    $inventoryBody !== '' && str_contains($inventoryBody, 'self::whereForEntry(') === true,
    'inventory() no longer builds its clause with whereForEntry(), so parent-matched rows are left out of what the person is told'
);

// 📌 Four DIFFERENT names, so swapping any two of them fails. With the real
//    instruction alone this check cannot tell `childCol` from `parentKey`,
//    because that instruction uses `caseID` for both.
check(
    'the clause builder puts each of the four names in its own place: a made-up instruction whose four names all differ pins the shape, which the real one cannot because its child column and parent key are both `caseID`',
    \Portal\Core\GdprEraser::whereForEntry('', [
        'childCol'  => 'zzChildKey',
        'parent'    => 'tblZzParent',
        'parentKey' => 'zzParentKey',
        'personCol' => 'zzPersonID',
    ]) === '`zzChildKey` IN (SELECT `zzParentKey` FROM `tblZzParent` WHERE `zzPersonID` = ?)',
    'got: ' . \Portal\Core\GdprEraser::whereForEntry('', [
        'childCol'  => 'zzChildKey',
        'parent'    => 'tblZzParent',
        'parentKey' => 'zzParentKey',
        'personCol' => 'zzPersonID',
    ])
);

// 📌 The exhaustiveness pin. `$mustRefuse` is ITERATED, so deleting a case
//    stops testing it instead of failing, while the label above still says
//    "all eight ways". #697 had the same fault and the same remedy.
check(
    'the list of ways a parent match can be wrong is pinned BY NAME, so deleting one stops the run rather than quietly shrinking what is tested',
    array_keys($mustRefuse) === [
        'the parent column says who ACTED (the one that would destroy a third party\'s notes)',
        'the parent column is not an account column at all',
        'it also names a column on the row itself, so there are two ways to find the rows',
        'it would DELETE rows because of who the parent is about',
        'it names no column to empty, so it changes nothing while reading as coverage',
        'a name that is not a plain identifier',
        'one of the four names is missing',
        'subjectVia is not a list at all',
    ],
    'the eight ways are no longer the eight named here: ' . implode(' | ', array_keys($mustRefuse))
);

// -----------------------------------------------------------------------------
// 21. EVERY MEMBERSHIP TABLE THAT IS AUDITED IS ALSO REACHED BY THE STEP THAT
//     EMPTIES THE CHANGE HISTORY ABOUT A PERSON (#717).
//
//     `tblAuditTrail.userID` is the ACTOR. What a row is ABOUT is the pair
//     (tableName, recordID), and no column says who that is — so
//     `GdprEraser::eraseAuditTrailAboutPerson()` has to name each table it
//     reaches and look the person's rows up in it.
//
//     THE POINT OF THIS CHECK IS THE DAY SOMEBODY ADDS A FIFTH. Until #717 the
//     step named two tables while four were audited, and there was nothing
//     anywhere comparing the two sets — a guard only ever compared against
//     itself, which is the #706 shape. The payloads carry account numbers and
//     no names today, so there was no identifying residue; the moment one
//     carries a name, #707's fault is back in a table nothing covers and
//     nothing would notice.
//
//     Both sides are read from the code rather than listed here, so neither can
//     drift from the other without this going red.
// -----------------------------------------------------------------------------
$auditedMembership = [];
$webRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web';
$walker  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($webRoot));
foreach ($walker as $file) {
    if ($file->isFile() === false || $file->getExtension() !== 'php') {
        continue;
    }
    $text = (string) file_get_contents($file->getPathname());
    // Every `Logger::audit(` call, on one line or spread over several, and the
    // first quoted table name after it.
    if (gc_findAll('/Logger::audit\(\s*\n?\s*\x27(tblUser\w+)\x27/', $text, $found) === 1
        || isset($found[1]) === true
    ) {
        foreach ($found[1] as $table) {
            $auditedMembership[$table] = true;
        }
    }
}
$auditedMembership = array_keys($auditedMembership);
sort($auditedMembership);

$eraserSource = (string) file_get_contents(
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core'
    . DIRECTORY_SEPARATOR . 'GdprEraser.php'
);
$stepStart = strpos($eraserSource, 'function eraseAuditTrailAboutPerson');
$stepBody  = $stepStart === false
    ? ''
    : substr($eraserSource, $stepStart, 4000);
gc_findAll('/tableName = \x27(tblUser\w+)\x27/', $stepBody, $reached);
$reachedMembership = array_values(array_unique($reached[1] ?? []));
sort($reachedMembership);

check(
    'every membership table anything audits is also reached by the step that empties the change history about a person (#717)',
    $auditedMembership !== [] && $auditedMembership === $reachedMembership,
    'audited: ' . implode(', ', $auditedMembership)
    . ' -- reached by the step: ' . implode(', ', $reachedMembership)
    . ' -- add the missing one to GdprEraser::eraseAuditTrailAboutPerson(), with its own key column, '
    . 'and bind one more userID. An audited membership table the step does not reach is #707\'s fault '
    . 'waiting for somebody to put a name in that payload.'
);

// -----------------------------------------------------------------------------
// 22. ONLY THE PERMANENT REFUSAL MAY BE RECORDED AS "work for a human" (#710).
//
//     `whyParentLookUpUnsafe()` gives THREE refusals and only ONE of them is
//     permanent:
//       - the parent was changed EARLIER IN THIS RUN  -> an ordering mistake,
//         recoverable: fix the list and run the request again;
//       - the trail could not be read                 -> recoverable: try again;
//       - the parent was changed by an EARLIER RUN    -> PERMANENT.
//
//     `finishByHand()` closes a request when every failure of its run is the
//     third kind. So if either recoverable answer ever recorded itself in
//     `$onlyHandWorkLeft`, a request whose only failure is FIXABLE would be
//     closed by hand and the failure buried — under an audit line saying it
//     "cannot be completed by any number of further runs", which is false of an
//     ordering mistake by that branch's own words.
//
//     THIS IS PINNED BECAUSE IT WAS PINNED BY NOTHING. An independent check
//     added that one line to the ordering-mistake branch and all 262 checks of
//     the eraser self-test stayed green. The invariant was asserted in three
//     comments and guarded nowhere, which is the #706 shape: a guard measured
//     only against itself.
//
//     Read from the source, because no behavioural test can reach the ordering
//     branch — producing it needs an instruction list the coverage test itself
//     refuses (section 8c pins the order).
// -----------------------------------------------------------------------------
$eraserText = (string) file_get_contents(
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core'
    . DIRECTORY_SEPARATOR . 'GdprEraser.php'
);
// 🧹 Comments out with PHP'S OWN TOKENISER, not a regular expression.
//
//    Every hand-rolled comment stripper in this repository has been wrong at
//    least once: a `//` inside a string literal (a URL is the usual one) eats
//    the rest of the line, and a `/*` inside one swallows everything to the
//    next `*/` (#716, #675). `token_get_all()` cannot make either mistake
//    because it is the parser PHP itself uses. Comments are replaced by a
//    single space so nothing on either side is joined together.
$eraserCode = '';
foreach (token_get_all($eraserText) as $token) {
    if (is_array($token) === true && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) === true) {
        $eraserCode .= ' ';
        continue;
    }
    $eraserCode .= is_array($token) === true ? $token[1] : $token;
}

// 🧷 COUNT EVERY MENTION, NOT EVERY MENTION OF ONE SPELLING. This check used to
//    count `/\$onlyHandWorkLeft\s*\[/`, which is one way of writing the write
//    and not the only one. An independent check added the same fault as
//    `self::$onlyHandWorkLeft = self::$onlyHandWorkLeft + [$child => …]` in the
//    ordering-mistake branch, and again in the could-not-read branch: that form
//    has no `[` after the name, so the count stayed at one, the original write
//    was still in the permanent branch for the position check below, and BOTH
//    checks passed on code that would close a request whose only failure was
//    fixable. The could-not-read plant needed no second mistake at all — one
//    failed read of `tblErasureAudit` would have closed the request and written
//    into the sealed, never-erased trail that no further run could help.
//
//    So the whole surface is pinned instead: the static may be mentioned in
//    EXACTLY FOUR places, each named below, and the one write must be written
//    in exactly one form. Any edit to those four lines fails this check ON
//    PURPOSE — that is the repository's own remedy for a guard that a reworded
//    fault walks past (#697 pins two helper bodies whole for the same reason).
$expectedMentions = [
    'read'        => "'handWorkOnly' => self::\$onlyHandWorkLeft,",
    'write'       => 'self::$onlyHandWorkLeft[$child] = self::HAND_WORK_SENTENCE;',
    'reset'       => 'self::$onlyHandWorkLeft = [];',
    'declaration' => 'private static array $onlyHandWorkLeft = [];',
];
$mentionCount = gc_findAll('/onlyHandWorkLeft/', $eraserCode);
$mentionsFound = [];
foreach ($expectedMentions as $what => $text) {
    $mentionsFound[$what] = substr_count($eraserCode, $text);
}

check(
    'the "only a human can finish this" list is mentioned in exactly FOUR places and written to in exactly ONE, whatever form the write is spelt in (#710)',
    $mentionCount === 4
    && $mentionsFound === ['read' => 1, 'write' => 1, 'reset' => 1, 'declaration' => 1],
    $mentionCount . ' mention(s) of $onlyHandWorkLeft in the code (comments removed by token_get_all), '
    . 'and the four expected lines were found ' . json_encode($mentionsFound) . ' time(s) each. '
    . 'There must be exactly four mentions — the read in execute(), the one write, the reset at the '
    . 'start of a run, and the declaration — each appearing once. A FIFTH mention is a second way of '
    . 'recording a step as work only a human can finish, and a write in the ordering-mistake branch or '
    . 'the could-not-read branch would let finishByHand() close a request whose only failure is '
    . 'FIXABLE, burying it under a line claiming no further run can help.'
);

// And that the one write really is in the permanent branch: the sentence it
// records is the one that branch returns, and no other branch mentions it.
$permanentBranch = strpos((string) $eraserCode, 'was changed by an EARLIER run of this request');
$theWrite        = strpos((string) $eraserCode, '$onlyHandWorkLeft[');
$orderingBranch  = strpos((string) $eraserCode, 'was changed earlier in this same run');

check(
    'and that one write sits in the PERMANENT branch, after the ordering branch has already returned (#710)',
    $permanentBranch !== false && $theWrite !== false && $orderingBranch !== false
    && $theWrite > $orderingBranch && $theWrite < $permanentBranch,
    'ordering branch at ' . var_export($orderingBranch, true)
    . ', the write at ' . var_export($theWrite, true)
    . ', permanent branch at ' . var_export($permanentBranch, true)
    . ' -- the write must come after the ordering branch and immediately before the permanent '
    . 'refusal it belongs to.'
);

// -----------------------------------------------------------------------------
// 23. THE WORKER ON A PASTORAL, SAFEGUARDING OR CHECK-IN RECORD IS KEPT
//     (#703; the owner's decisions D-016, D-110, D-111, D-112, D-113).
//
//     D-016 "rules out unlinking the worker": who made a pastoral visit or
//     recorded a DBS check. D-110 adds who OPENED a care case, D-111 who
//     checked a child IN and OUT. Until #703 the code detached every one of
//     them. These checks fail if any comes back, and they are pinned BY NAME
//     rather than read from the written list, for the reason `$ownerSaysKeep`
//     gives above: a list and a routine changed back together would still
//     agree with each other.
//
//     KEEPING THE NUMBER IS NOT KEEPING THE NAME. The worker's own account is
//     emptied by the erasure, so the name has to be on the record itself
//     (migration 272), written when the record is made. So the checks also
//     cover: nothing empties a name column; every writer under `web/` fills
//     it; the database has it; the migration's one-off copy never writes the
//     stand-in name of an emptied account; and the pastoral page shows the
//     kept name once the account is emptied.
//
//     D-113 keeps the NUMBER beside the name and requires the portal to say
//     what that costs. The sentences that used to say the opposite are pinned
//     at the end of this section, both ways: the reassuring wording must be
//     gone, and the plain wording must be there.
//
//     What these cannot see: whether the writers RUN. Section F of
//     `tools/erasure-pages-selftest.php` drives every one of them over HTTP,
//     and sections 3b and 19 of `tools/gdpr-eraser-selftest.php` run a real
//     erasure.
// -----------------------------------------------------------------------------
// table => [account-number column => [name column, decision]]
$d016 = [
    'tblCareVisit'   => ['visitedByID'    => ['visitedByName',    'D-016']],
    'tblDbsChecks'   => ['recordedByID'   => ['recordedByName',   'D-016']],
    'tblCareCase'    => ['openedByID'     => ['openedByName',     'D-110']],
    'tblKidCheckins' => ['checkedInByID'  => ['checkedInByName',  'D-111'],
                         'checkedOutByID' => ['checkedOutByName', 'D-111']],
];

$notKeptAsDecided = [];
foreach ($d016 as $table => $columns) {
    foreach ($columns as $number => [$name, $decision]) {
        $onNumber = array_values(array_filter(
            $byTable[$table] ?? [],
            static fn (array $e): bool => (string) $e['userCol'] === $number
        ));
        $actions = array_map(static fn (array $e): string => (string) $e['action'], $onNumber);
        if ($actions !== ['retain']) {
            $notKeptAsDecided[] = $table . '.' . $number . ' (' . $decision . ') has '
                . ($actions === [] ? 'no instruction' : implode('+', $actions));
        }
    }
}
check(
    'the worker who made a pastoral visit, opened a care case, recorded a DBS check or checked a child in or out is KEPT on an erasure: exactly one "retain" instruction for each of the five, and nothing that empties or deletes on them (#703; D-016, D-110, D-111)',
    $notKeptAsDecided === [],
    implode('; ', $notKeptAsDecided) . ' -- the owner ruled out unlinking the worker. If this is meant to change, that is a '
    . 'NEW decision for the owner, recorded in docs/decisions/ and naming the one it supersedes, not an edit here'
);

$nameTouched  = [];
$nameUnlisted = [];
foreach ($instructions as $instruction) {
    $table = (string) $instruction['table'];
    if (isset($d016[$table]) === false) {
        continue;
    }
    $touches = array_merge(
        [(string) $instruction['userCol']],
        array_map('strval', (array) ($instruction['nullCols'] ?? [])),
        array_map('strval', array_keys((array) ($instruction['overrides'] ?? []))),
        array_map('strval', array_keys((array) ($instruction['sqlSets'] ?? [])))
    );
    foreach ($d016[$table] as [$name, $decision]) {
        if (in_array($name, $touches, true) === true) {
            $nameTouched[] = $table . '.' . $name . ' (' . (string) $instruction['action'] . ')';
        }
    }
}
foreach ($d016 as $table => $columns) {
    foreach ($columns as $number => [$name, $decision]) {
        if (in_array($name, array_map('strval', (array) ($catalogue[$table]['columns'] ?? [])), true) === false) {
            $nameUnlisted[] = $table . '.' . $name . ' is not named in the written list';
        }
        $why = (string) ($catalogue[$table]['notEmptiedHere'][$name] ?? '');
        // An 'unlink' entry must say why the name is not emptied (section 8b
        // asks only that it be long enough; this asks that it give THE reason).
        if ((string) ($catalogue[$table]['decision'] ?? '') === 'unlink'
            && (strlen(trim($why)) < 40 || str_contains($why, $decision) === false)
        ) {
            $nameUnlisted[] = $table . '.' . $name . ' has no reason under notEmptiedHere naming ' . $decision;
        }
    }
}
check(
    'the worker\'s NAME on those records is never emptied, and the written list names it and says why (#703)',
    $nameTouched === [] && $nameUnlisted === [],
    implode('; ', array_merge($nameTouched, $nameUnlisted))
    . ' -- the account is emptied by the erasure, so the name column is the only place the name survives'
);

// Every writer of these tables, wherever it is, must fill the name in the SAME
// statement that writes the number. Searched for rather than listed, so a
// SECOND writer added later is caught, and the statement's pieces are joined
// up first so a column list split over several lines of PHP is still read
// whole. An INSERT must name every name column whose number it names; an
// UPDATE that sets a number must set its name.
$writersFound   = [];
$writerProblems = [];
$webTree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../web', FilesystemIterator::SKIP_DOTS));
foreach ($webTree as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $code  = (string) gc_replace("/'\s*\.\s*'/", '', $codeOnly((string) file_get_contents($file->getPathname())));
    $where = substr($file->getPathname(), strlen(__DIR__ . '/../web/'));
    foreach ($d016 as $table => $columns) {
        gc_findAll('/INSERT\s+INTO\s+`?' . $table . '`?\s*\(([^)]*)\)/i', $code, $inserts, PREG_SET_ORDER);
        foreach ($inserts as $insert) {
            $writersFound[] = $where . ' (INSERT ' . $table . ')';
            foreach ($columns as $number => [$name, $decision]) {
                if (str_contains($insert[1], $number) === true && str_contains($insert[1], $name) === false) {
                    $writerProblems[] = $where . ' inserts ' . $table . '.' . $number . ' without ' . $name;
                }
            }
        }
        gc_findAll('/UPDATE\s+`?' . $table . '`?\s+SET\s+([^;]*)/i', $code, $updates, PREG_SET_ORDER);
        foreach ($updates as $update) {
            foreach ($columns as $number => [$name, $decision]) {
                if (gc_find('/`?' . $number . '`?\s*=/', $update[1]) === 1) {
                    $writersFound[] = $where . ' (UPDATE ' . $table . '.' . $number . ')';
                    if (gc_find('/`' . $name . '`\s*=\s*\(SELECT `u`\.`fullName` FROM `tblUsers` `u` WHERE `u`\.`userID` = \?\)/', $update[1]) !== 1) {
                        $writerProblems[] = $where . ' sets ' . $table . '.' . $number . ' without reading ' . $name . ' from the account';
                    }
                }
            }
        }
        unset($inserts, $updates);
    }
}
sort($writersFound);
// Each writer reads the name from the account in the same statement - pinned
// as the exact sub-query, so a NULL or a session value in its place fails.
$fromAccount = [
    '_apps/care/visit-save.php'                 => "'SELECT ?, ?, (SELECT `u`.`fullName` FROM `tblUsers` `u` WHERE `u`.`userID` = ?), ?, ?, ?'",
    '_apps/care/case-save.php'                  => "'SELECT ?, ?, ?, ?, ?, (SELECT `u`.`fullName` FROM `tblUsers` `u` WHERE `u`.`userID` = ?)'",
    '_apps/admin/safeguarding/dbs-save.php'     => "(SELECT `r`.`fullName` FROM `tblUsers` `r` WHERE `r`.`userID` = ?)'",
    '_apps/kids/checkin-do.php'                 => "'SELECT ?, ?, ?, ?, (SELECT `u`.`fullName` FROM `tblUsers` `u` WHERE `u`.`userID` = ?)'",
];
$bindsTwice = [
    '_apps/care/visit-save.php'             => "bind_param('iiisss', \$caseId, \$userId, \$userId,",
    '_apps/care/case-save.php'              => "bind_param('isssii', \$siteId, \$person, \$category, \$summary, \$userId, \$userId);",
    '_apps/admin/safeguarding/dbs-save.php' => "\$status, \$recorder, \$recorder);",
    '_apps/kids/checkin-do.php'             => "bind_param('iisii', \$childId, \$eventIdArg, \$badge, \$staffId, \$staffId);",
    '_apps/kids/checkout.php'               => "bind_param('iisi', \$staffId, \$staffId, \$pickupName, \$checkinId);",
];
foreach ($fromAccount as $page => $subQuery) {
    if (str_contains($codeOnly((string) @file_get_contents(__DIR__ . '/../web/' . $page)), $subQuery) === false) {
        $writerProblems[] = $page . ' no longer reads the name from the account in the same statement';
    }
}
foreach ($bindsTwice as $page => $bind) {
    if (str_contains($codeOnly((string) @file_get_contents(__DIR__ . '/../web/' . $page)), $bind) === false) {
        $writerProblems[] = $page . ' no longer binds the signed-in account for the name as well as the number';
    }
}
check(
    'every writer of these records fills in the worker\'s name, read from their own account in the same statement (#703; D-016, D-110, D-111)',
    $writerProblems === []
        && $writersFound === [
            '_apps/admin/safeguarding/dbs-save.php (INSERT tblDbsChecks)',
            '_apps/care/case-save.php (INSERT tblCareCase)',
            '_apps/care/visit-save.php (INSERT tblCareVisit)',
            '_apps/kids/checkin-do.php (INSERT tblKidCheckins)',
            '_apps/kids/checkout.php (UPDATE tblKidCheckins.checkedOutByID)',
        ],
    'writers found: ' . implode(', ', $writersFound) . ' | ' . implode('; ', $writerProblems)
    . ' -- a record written without the name loses who made it the moment that person is erased'
);

// The columns, and migration 272's one-off copy. The copy's guard is pinned
// WHOLE and compared with the ONE test of "emptied by an erasure",
// GdprEraser::emptiedAccountSql(): round 1 of the independent check made the
// e-mail half never match and the old pin, which read only the first half,
// stayed green.
$migration272 = (string) @file_get_contents(__DIR__ . '/../web/_sql/272_worker_name_kept_on_care_records.sql');
$migrationFlat = trim((string) gc_replace('/\s+/', ' ', str_replace('`', '', $migration272)));
$emptiedTest   = trim((string) gc_replace('/\s+/', ' ', \Portal\Core\GdprEraser::emptiedAccountSql('u')));
$schemaNow     = (string) file_get_contents($schemaFile);
$shapeProblems = [];
foreach ($d016 as $table => $columns) {
    foreach ($columns as $number => [$name, $decision]) {
        $definition = gc_find('/CREATE TABLE IF NOT EXISTS\s+`' . $table . '`(.*?)\n\)\s*ENGINE/s', $schemaNow, $found) === 1
            && gc_find('/^\s+`' . $name . '`\s+([^\n]*)$/m', $found[1], $line) === 1 ? $line[1] : '';
        if (str_starts_with($definition, 'VARCHAR(255) DEFAULT NULL') === false) {
            $shapeProblems[] = $table . '.' . $name . ' in the fresh-install script ("' . substr($definition, 0, 30) . '")';
        }
        if (gc_find('/ALTER TABLE `' . $table . '` ADD COLUMN `' . $name . '` VARCHAR\(255\) DEFAULT NULL COMMENT .*? AFTER `' . $number . '`/', $migration272) !== 1) {
            $shapeProblems[] = $table . '.' . $name . ' in migration 272';
        }
        $copy = gc_find('/UPDATE ' . $table . ' (\w+) JOIN tblUsers u ON u\.userID = \1\.' . $number
            . ' SET \1\.' . $name . ' = u\.fullName WHERE \1\.' . $name . ' IS NULL AND NOT (\(.*?\));/', $migrationFlat, $copyParts) === 1
            ? $copyParts[2] : '';
        if ($copy !== $emptiedTest) {
            $shapeProblems[] = 'migration 272 copies names into ' . $table . '.' . $name . ' guarded by "' . $copy
                . '", which is not GdprEraser::emptiedAccountSql(\'u\') ("' . $emptiedTest . '")';
        }
    }
}
check(
    'all five name columns exist and may be empty, in the fresh-install script and in migration 272, and its one-off copy refuses an emptied account by EXACTLY the one test the portal uses (#703)',
    $shapeProblems === [] && \Portal\Core\GdprEraser::TOMBSTONE_NAME === '[Deleted User]',
    implode('; ', $shapeProblems)
);

// Joined up, then every run of spaces made one, because the SQL's own spacing
// for readability is not what is being pinned.
$casePage = (string) gc_replace('/\s+/', ' ', gc_replace("/'\s*\.\s*'/", '', $codeOnly((string) @file_get_contents(__DIR__ . '/../web/_apps/care/case.php'))));
check(
    'the pastoral page shows the kept name once the visitor\'s account has been emptied, and the live name before (#703)',
    str_contains($casePage, "CASE WHEN u.userID IS NULL OR ' . GdprEraser::emptiedAccountSql('u') . ' THEN v.visitedByName ELSE u.fullName END AS visitorName")
        && str_contains($casePage, 'use Portal\Core\GdprEraser;'),
    'web/_apps/care/case.php no longer chooses the kept name for an emptied account, so the page would show "[Deleted User]"'
);

// 🗣️ D-113: SAY SO. Every sentence that told a member an emptied account stops
//    a record naming anybody is gone, and the plain sentence is there instead,
//    in the places a member or an administrator reads it. Both directions,
//    because a check made only of absences passes on a page that says nothing.
//    (The statements about the ACCOUNT in the two reasons and the policy, and
//    the policy's worker row, are pinned WHOLE further down. The PAGES - the
//    privacy notice and help/admin - are pinned as they are RENDERED, over
//    HTTP, by tools/erasure-pages-selftest.php (#703 rounds 6-7): reading their
//    source missed text PHP prints. That test compares each region's markup and
//    the tag and attributes of every wrapper around it; it cannot say whether
//    text is VISIBLE, which only a browser can. Their source-side guards were
//    taken out of this file, because two guards over one fact drift apart.)
//
//    WHAT THIS CANNOT DO, said plainly (#703 round 7): it cannot tell whether
//    any OTHER sentence is true. Round 6 tried, with a list of reassuring
//    phrases scanned across every reason and every string the eraser writes;
//    round 7's check found it let five rephrasings through (round 5's exact
//    "the link to the person is cut" among them) and cried wolf on a true
//    sentence ("...can still be traced back to you through your account
//    number"). A phrase cannot judge truth - a question of the wrong KIND for a
//    check (D-065; #763 and #775 paid for the same lesson). It was deleted.
//    Whether a sentence is true is checked by a person, at review.
$reasonOf = static fn (string $table): string => (string) ($catalogue[$table]['reason'] ?? '');
$policyDoc = (string) gc_replace('/\s+/', ' ', (string) @file_get_contents(__DIR__ . '/../docs/gdpr-erasure-policy.md'));
$saysTheOpposite = [];
foreach ([
    'tblExpenseClaims reason' => [$reasonOf('tblExpenseClaims'), 'What stops the claim naming anybody'],
    'tblApiRateLimits reason' => [$reasonOf('tblApiRateLimits'), 'no longer identifies anybody'],
] as $where => [$text, $old]) {
    if (str_contains($text, $old) === true) {
        $saysTheOpposite[] = $where . ' still says "' . $old . '"';
    }
}
$saysItPlainly = [
    'tblExpenseClaims reason' => str_contains($reasonOf('tblExpenseClaims'), 'can match the claim back to them (D-113)'),
    'tblApiRateLimits reason' => str_contains($reasonOf('tblApiRateLimits'), 'can still be matched back to them by anyone who can read the database or a backup (D-113)'),
    'tblCareVisit reason'     => str_contains($reasonOf('tblCareVisit'), '(D-113)'),
    'tblCareCase reason'      => str_contains($reasonOf('tblCareCase'), '(D-113)'),
    'tblDbsChecks reason'     => str_contains($reasonOf('tblDbsChecks'), '(D-113)'),
    'tblKidCheckins reason'   => str_contains($reasonOf('tblKidCheckins'), '(D-113)'),
];
check(
    'D-113: the expense claim, rate-limit and four worker-record reasons each say plainly that the person can be matched back to their kept records, and the two old sentences there that said the opposite have not come back (#703). Whether any other sentence is true is for review, not this check',
    $saysTheOpposite === [] && array_filter($saysItPlainly, static fn (bool $ok): bool => $ok === false) === [],
    implode('; ', $saysTheOpposite) . ' | missing the plain sentence: '
    . implode(', ', array_keys(array_filter($saysItPlainly, static fn (bool $ok): bool => $ok === false)))
);

// 🗣️ D-113, WHAT A MEMBER IS ACTUALLY SHOWN (#703 round 3). My data and
//    Request erasure print `GdprEraser::inventory()`, which carries the reason
//    of each INSTRUCTION - and for a table written out by hand in
//    GdprEraser::catalogue() that is GdprEraser's own reason, never the
//    written list's (fromPersonalDataCatalogue() skips such a table). So the
//    check above, which read the written list, guarded text no member reads:
//    the independent check made the check-in reason reassuring (plant PF) and
//    every suite stayed green. These are the sentences on the page, taken from
//    the real instructions, and pinned both ways. The erasure REPORT's own
//    lines - the refused-column line every expense claim gets, and the giving
//    clearing line - are pinned beside them, from the code, because a sealed
//    trail cannot be corrected afterwards.
$shown = [];
foreach ($instructions as $instruction) {
    $shown[(string) $instruction['table'] . '.' . (string) $instruction['userCol']] = (string) ($instruction['reason'] ?? '');
}
// 📌 PINNED WHOLE (#703 round 4). Round 3 pinned each reason's opening, its
//    decision number and its ending, and the independent check put "In
//    practice nobody can trace them to you once your account is emptied." in
//    the MIDDLE of one with every suite green: a reassurance added anywhere
//    the pin did not look. So each sentence a worker reads is compared WHOLE,
//    with only runs of spaces made one. The cost is stated: rewording one of
//    these sentences means changing it here too, on purpose - which is the
//    point, because these are the sentences D-113 is about.
$wholeKept = static fn (string $s): string => trim((string) gc_replace('/\s+/', ' ', $s));
$keptLines = [
    'tblCareVisit.visitedByID'      => 'pastoral visits you made keep your name and your account number as the person who made them, even after your account is erased (decision D-016): a pastoral or safeguarding record must still say who carried it out. Nothing else about you is on them unless the record is also about you. Through that number, anyone who can read the database or a backup can match your other kept records - an expense claim, for instance - back to you (D-113)',
    'tblDbsChecks.recordedByID'     => 'DBS checks you recorded keep your name and your account number as the person who recorded them, even after your account is erased (decision D-016): a safeguarding check must still say who carried it out. Nothing else about you is on them unless the record is also about you. Through that number, anyone who can read the database or a backup can match your other kept records - an expense claim, for instance - back to you (D-113)',
    'tblCareCase.openedByID'        => 'pastoral care cases you opened keep your name and your account number as the person who opened them, even after your account is erased (decision D-110): a pastoral record must still say who opened it. Nothing else about you is on them unless the record is also about you. Through that number, anyone who can read the database or a backup can match your other kept records - an expense claim, for instance - back to you (D-113)',
    'tblKidCheckins.checkedInByID'  => 'children you checked in keep your name and your account number as the helper who checked them in, even after your account is erased (decision D-111): who handed a child over must stay answerable. Nothing else about you is on them unless the record is also about you. Through that number, anyone who can read the database or a backup can match your other kept records - an expense claim, for instance - back to you (D-113)',
    'tblKidCheckins.checkedOutByID' => 'children you checked out keep your name and your account number as the helper who checked them out, even after your account is erased (decision D-111): who handed a child over, and to whom, must stay answerable. Nothing else about you is on them unless the record is also about you. Through that number, anyone who can read the database or a backup can match your other kept records - an expense claim, for instance - back to you (D-113)',
];
$shownWrong = [];
foreach ($keptLines as $key => $expected) {
    $line = $wholeKept($shown[$key] ?? '');
    if ($line !== $wholeKept($expected)) {
        $shownWrong[] = $key . ': "' . mb_substr($line, 0, 160) . '…"';
    }
}
if (str_contains($shown['tblExpenseClaims.userID'] ?? '', 'the number on the claim can still be matched back to you through any record that keeps your name beside it') === false) {
    $shownWrong[] = 'tblExpenseClaims.userID';
}
$eraserCodeNow = (string) gc_replace("/'\s*\.\s*'/", '', $codeOnly((string) file_get_contents($eraserFile)));
if (str_contains($eraserCodeNow, "to be empty, so the account number stays on the row. The account itself has been emptied, but the number can still be matched back to the person through any record that keeps their name beside it (D-113). See migration 192.") === false
    || str_contains($eraserCodeNow, 'no longer names anybody') === true
) {
    $shownWrong[] = 'the erasure report\'s refused-column line (every expense claim)';
}
if (str_contains($eraserCodeNow, 'points at names nobody') === true
    || str_contains($eraserCodeNow, 'points at has no name on it, though the number can still be matched back to the person through any record that keeps their name beside it (D-113).') === false
) {
    $shownWrong[] = 'the erasure report\'s giving clearing line';
}
// 🧾 WHAT AN ERASED PERSON'S ACCOUNT NUMBER STAYS ON, said WHOLE in four places
//    (#703 rounds 4 and 5). Round 4 pinned each ITEM of the list as a word and
//    promised that "harmless rewording stays green"; the independent check then
//    reversed its meaning five ways with every item still present ("not your
//    expense claims (those lose it)", "(all of which are anonymous once your
//    account is emptied)", "None of these can be traced back to you.") and
//    raised false alarms the other way. So there is ONE rule now: each place's
//    WHOLE statement is compared, with only runs of spaces made one - the way
//    the five worker reasons above are. Changing a sentence means changing its
//    pin, on purpose. The item check is DELETED rather than kept beside this,
//    because two guards over one fact drift apart.
//
//    The statement is TWO lists and says neither is complete: what is kept ON
//    PURPOSE (measured in round 4), and the records kept for SECURITY and as a
//    HISTORY OF CHANGES, with each one's real clearing rule read from the code
//    (`log_sweep_tables()` sweeps tblActivityLogs and tblErrors - and nothing
//    else; nothing deletes from
//    tblAuditTrail or tblAssetAudit; `RateLimiter::recordHit()` tidies about
//    one call in 64, only when a later request of the same kind arrives).
//
//    Also pinned whole: the policy's whole Examples section, which holds the
//    worker-records row with its D-112 qualifier ("unless the record is also
//    about them") - so neither that qualifier nor a new row can change unseen.
//    The help page's paragraph, and the privacy notice, are pinned as RENDERED
//    by tools/erasure-pages-selftest.php.
//
//    HOW EACH IS READ: the two reasons from the real instructions and the
//    written list; the policy's section from its heading to the next.
// (The two PAGE pins that stood here - the privacy notice and help/admin, read
// from source with PHP blocks and tags taken out - were REPLACED in #703 round 6
// by pins of the RENDERED pages in tools/erasure-pages-selftest.php. Read from
// source they missed what PHP prints (an echoed reassurance passed) and anything
// just outside the cut.)
// The policy's whole "Examples" section, from its heading to the next one
// (#703 round 6): pinning two of its rows let a NEW row - a new reassurance -
// go in beside them with everything green (plant M3).
$pinPolicySection = static function (string $from, string $to) use ($wholeKept): string {
    $doc = (string) @file_get_contents(__DIR__ . '/../docs/gdpr-erasure-policy.md');
    $a   = strpos($doc, $from);
    $b   = $a === false ? false : strpos($doc, $to, $a);

    return $a === false || $b === false ? '' : $wholeKept(substr($doc, $a, $b - $a));
};
$catalogueNow = require $catalogueFile;
$statementsNow = [
    'what a member is shown about their account (GdprEraser, tblUsers)' => $wholeKept($shown['tblUsers.userID'] ?? ''),
    'the written list (personal-data-catalogue.php, tblUsers)'         => $wholeKept((string) ($catalogueNow['tblUsers']['reason'] ?? '')),
    'the policy, its whole Examples section (docs/gdpr-erasure-policy.md)' => $pinPolicySection('## Examples', '## Workflow'),
];
$statementsPinned = [
    'what a member is shown about their account (GdprEraser, tblUsers)' => 'your account is kept as an empty shell so that the records pointing at it do not break: your name becomes "[Deleted User]", and your e-mail address, telephone, address, biography, map position, language, sign-in secrets, calendar link and notification choices are emptied. Most records lose your account number. Some keep it on purpose: your expense claims, your own DBS checks, your tax declarations, an approval step given to you by name and the record of each giving statement issued to you. Some keep your name as well: gifts kept for tax (or every gift, where an organisation keeps them all), offering envelopes that will become such gifts, your tax declarations and a giving statement\'s file at an organisation keeping your name, where every gift on it is kept or its period cannot be told from it - with any home address held for you, until the organisation\'s keep-until date if it has confirmed one, or longer while a hold is on (what is shown for your gifts says which of yours, and until when) - and any pastoral visits, care cases, DBS checks or children\'s check-ins you made (D-016, D-110, D-111). Separately, records kept for security and as a history of changes can mention your account number, in what they describe or in a stored copy of a record that was changed: the activity log, deleted by the nightly clear-out after the period set for the organisation it was recorded under (a year unless changed), if that scheduled job has been set up - and where that period is for ever, which is so only where the installation keeps the log for ever and the organisation has not chosen a number of days of its own, nothing deletes it; the change history of records, which nothing clears; and rate-limit records, tidied away only when a later request of the same kind happens to trigger it, so one can stay for a long time. Neither list is every place your number may be written: anything typed about you can mention it too. Through that number and the names kept beside it, anyone who can read the database or a backup can match these records back to you (D-113)',
    'the written list (personal-data-catalogue.php, tblUsers)'         => 'The account itself. It is emptied rather than deleted: hundreds of records point at it, and deleting the row would drag them all down with it. Name, email, telephone, address, coordinates, biography and photo are all cleared, a photo file held in the portal\'s uploads folder is deleted, and the account is left as a tombstone with no name on it. Most records that point at it lose the account NUMBER. SOME keep it on purpose: the person\'s expense claims, their own DBS checks, their tax declarations, an approval step given to them by name and the record of each giving statement issued to them. Some keep their name as well: gifts kept for tax (or every gift, where an organisation keeps them all), offering envelopes that will become such gifts, their tax declarations and a giving statement\'s file at an organisation keeping their name, where every gift on it is kept or its period cannot be told from it - with any home address held for them, in tblGivingKeptDonors, until the organisation\'s keep-until date if it has confirmed one, or longer while a hold is on - and the pastoral visits, care cases, DBS checks or children\'s check-ins they made, which keep their name beside the number (the owner\'s decisions D-016, D-110, D-111). Separately, records kept for security and as a history of changes can mention the number, in what they describe or in a stored copy of a changed record: the activity log (tblActivityLogs), deleted by the nightly clear-out after audit.retentionDays for the organisation it was recorded under (365 days unless changed) if that scheduled job has been set up - and where that period is for ever, which is so only where the installation keeps the log for ever and the organisation has not chosen a number of days of its own, nothing deletes it; the change history of records (tblAuditTrail, tblAssetAudit), which nothing clears; and rate-limit records (tblApiRateLimits), tidied away only when a later request of the same kind happens to trigger it, so one can stay for a long time. Neither list is every place the number may be written: free text typed about the person can mention it too. Through that number, anyone who can read the database or a backup can match their other kept records back to them (D-113).',
    'the policy, its whole Examples section (docs/gdpr-erasure-policy.md)' => '## Examples These are examples, not the list. Where this table and the file disagree, the file is right. `tools/audit-checks/check_docs_table_refs.py` checks that every table named in this document exists; it cannot check what is said about it. | Category | Tables | Treatment | Reason | | --- | --- | --- | --- | | **Ways of signing in** | `tblLocalAccounts`, `tblLinkedAccounts`, `tblWebAuthnCredentials`, `tblTotpBackupCodes`, `tblTrustedDevices`, `tblPasswordResets` | **Delete** | About the person and nobody else. (This row used to name two tables that do not exist. Signed-in sessions are not in the database at all: PHP keeps them as files on the server.) | | **The person\'s own connections and choices** | `tblZoomAccount`, `tblPaymentMethod`, `tblPushSubscriptions`, `tblUserSmsPreference`, `tblNewsletterSubscription`, `tblUserTranslationPref` | **Delete** | Theirs alone; nothing for the organisation to keep | | **Membership** | `tblUserSites`, `tblUserRoles` | **Delete** | Which organisation somebody belongs to, and in what role, is about them | | **Tax declarations** (Gift Aid in the United Kingdom) | `tblGiftAidDeclaration` | **Kept, unchanged**, with the donor\'s name kept beside it in `tblGivingKeptDonors`, until the organisation\'s keep-until date - once it has confirmed how many years (with no end date until then), and never while a hold is on, the organisation\'s or the installation\'s. Its address, postcode, the network address it was made from, its signature and its notes are then emptied, and the name beside it is removed; the declaration, its dates and the account number stay. Where the date has already passed and no hold is on when the erasure runs, this happens as the erasure finishes | The organisation\'s evidence for tax relief claimed on the donor\'s gifts. This row said "Delete" until 11 October 2026 | | **Gifts that keep the donor\'s name** | `tblGivingEntry`, `tblGiftAidDeclaration`, `tblGivingStatementLog`, the kept statement files, and the name and home address in `tblGivingKeptDonors` | **Kept with the donor\'s name** until the organisation\'s keep-until date - once it has confirmed how many years (with no end date until then), and never while a hold is on, the organisation\'s or the installation\'s. Then the name, address and link are removed, the declarations\' address, postcode, network address, signature and notes are emptied and the kept statement files are deleted; the gift, declaration and statement records themselves stay. Where the date has already passed and no hold is on when the erasure runs, this happens as the erasure finishes. A statement file is kept only where every gift on it is kept, or where its name does not give its period; every other statement file is deleted when the erasure runs | Tax and accounting rules in most countries. By default only gifts with a tax declaration, receipt or statement; an organisation may choose every gift. See "Giving records" below | | **Every other gift, and counted envelopes** | `tblGivingEntry`, `tblCountEnvelopes` | **Anonymise** at once (the donor\'s number and any typed name are removed). One exception: an envelope in a count that is not yet closed keeps its giver when the gift it will become is one that keeps the donor\'s name; it loses it with the rest when the keep-until date passes | The amount, date and method are the organisation\'s accounts. The exception exists because the gift made from such an envelope would otherwise have no donor and drop out of the tax claim | | **Records the person only worked on** | Visitors, salvation cards, tasks and rota slots given to them; follow-up notes, payments, files, room bookings and loans they made | **Kept**; only their account number is removed | The record is somebody else\'s, or the organisation\'s. Until 11 October 2026 the first four were deleted. Pastoral and safeguarding records and children\'s check-ins are the exception, in the next row | | **Pastoral and safeguarding records the person made, and children they checked in or out** | `tblCareVisit` (`visitedByID`, `visitedByName`), `tblCareCase` (`openedByID`, `openedByName`), `tblDbsChecks` (`recordedByID`, `recordedByName`), `tblKidCheckins` (`checkedInByID`, `checkedInByName`, `checkedOutByID`, `checkedOutByName`) | **Kept, with their name AND account number** as the person who made the visit, opened the case, recorded the check or checked the child in or out - including a visit they made on a case about themselves. Their account itself is still emptied, so the name is the one written on the record when it was made. Their name and number are the only things about them left on these records, unless the record is also about them (a visit on a case about them, or their own DBS check) | The owner\'s decisions D-016 (3 November 2026), D-110, D-111 and D-112 (10 November 2026), #703: a safeguarding record that cannot say who carried it out is worse for the people it protects. **A person asking to be erased cannot expect their name to come off these records.** **And because the number stays beside the name (D-113), anyone who can read the database or a backup can use these records to re-identify the erased person on every other record that kept their number** - an expense claim, a rate-limit record. The owner chose to keep both and say so. Until #703 the account number was removed and no name was kept; a worker erased before #703 cannot be given their name back. What a visit says about the person **cared for** is still emptied when that person asks (#709) | | **An invitation the person accepted** | `tblInvitation` | **Delete** | It holds their own email address. One they only sent is kept | | **Other financial records** | `tblExpenseClaims`, `tblPayment` | **Kept.** A payment loses the link to the person. An expense claim KEEPS the claimant\'s account number (the owner, 3 November 2026: a claim must say who was paid; the database does not allow the column to be empty either) | The organisation\'s accounts. The claimant\'s account is emptied, but the number on the claim is not anonymous if they also made pastoral visits, care cases, DBS checks or children\'s check-ins, which keep their name beside it (D-113). (This row used to say the link to the person is removed for both, which was untrue for claims; and it used to give one country\'s rule, "UK HMRC: 6-year retention required", as the reason for every customer.) | | **Things the person wrote or made** | `tblAnnouncements`, `tblEvents`, `tblRecording`, `tblPrayerRequests` | **Kept**; the link to the person is removed. A prayer request they made is kept only as the record that one was made and answered: its subject, its body, any prayer partner\'s note about it, and the name, email address and network address they typed are all emptied (#715) | An announcement, event or recording is the organisation\'s; who made it is removed from the record itself. The change history keeps a copy of each change for ever (D-116), and that copy can still hold the account number; what the content says can still mention the person (#703) | | **Attendance** | `tblEventAttendance`, `tblSmallGroupMeetingAttendance` | **Kept**; the link to the person is removed | Deleting the row would leave the register wrong for everybody else who was there | | **The log of what people did** | `tblActivityLogs` | **Kept until the nightly clear-out deletes it** (after `audit.retentionDays` for the organisation the record was made under, 365 days unless changed, if that scheduled job has been set up; a record that belongs to no organisation follows the installation\'s figure). An organisation\'s own administrator sets its figure, within the installation\'s (D-118). **Where the installation has chosen to keep the activity log for ever, that is the longest any organisation may keep it, not a rule for all: nothing deletes the records of an organisation that has chosen for ever or has not chosen a number of days of its own, nor records that belong to no organisation, but an organisation that has chosen a number of days is still cleared after it** (D-117). On the person\'s own records the network address, browser description, request headers, session identifier, stored copy of the session and account link are cleared. **What each record says is kept as written, for as long as the record is**, so it can still name the person, give their e-mail address or give their account number, and a record of linking or unlinking a sign-in provider gives that provider\'s e-mail address (#933). Records other people made can mention them too | The organisation needs the record of what happened to run and repair the portal; who did it is not kept. **This row was missing until 27 October 2026**, which mattered because the activity log is the one erasure behaviour #626 changed: a record now has to NAME its person in `userID`, and that single column is what an erasure matches. A record made at a shared kiosk deliberately names nobody, so it is not reached — a kiosk session outlives the person at it. Records written before #626 are brought into line separately, in pieces, from the Upgrade page | | **The account itself** | `tblUsers` | **Emptied, not deleted.** The name becomes `[Deleted User]`; email address, telephone, address, biography, map position, language, two-factor secret, calendar address and notification choices are cleared; the account is switched off and loses any administrator rights | Hundreds of records point at the account. Deleting the row would break or delete them. Most of those records lose the account NUMBER, but SOME keep it on purpose - expense claims, the person\'s own DBS checks, tax declarations, an approval step given to the person by name and the record of each giving statement issued. Some keep the NAME as well: gifts kept for tax (or every gift, where an organisation keeps them all), offering envelopes that will become such gifts, tax declarations and, at an organisation keeping the name, a giving statement\'s file where every gift on it is kept or its period cannot be told from it, with any home address held (`tblGivingKeptDonors`), until the keep-until date once one is confirmed, or longer while a hold is on; and the pastoral visits, care cases, DBS checks or children\'s check-ins the person made (`tblCareVisit`, `tblCareCase`, `tblDbsChecks`, `tblKidCheckins`). Separately, records kept for security and as a history of changes can mention the number, in what they describe or in a stored copy of a changed record: the activity log (`tblActivityLogs`), deleted by the nightly clear-out after `audit.retentionDays` for the organisation the record was made under (365 days unless changed) if that scheduled job has been set up - and where that period is for ever, which is so only where the installation keeps the log for ever and the organisation has not chosen a number of days of its own, nothing deletes it; the change history of records (`tblAuditTrail`, `tblAssetAudit`), which nothing clears; and rate-limit records (`tblApiRateLimits`), tidied away only when a later request of the same kind happens to trigger it, so one can stay for a long time. Neither list is every place the number may be written: free text typed about the person can mention it too. So an emptied account does not by itself make these records anonymous: the pastoral visits, care cases, DBS checks and children\'s check-ins keep the name beside the same number (D-113), and a kept giving record keeps it until the keep-until date |',
];
$statementsWrong = [];
foreach ($statementsPinned as $place => $expected) {
    $now = $statementsNow[$place] ?? '';
    if ($now !== $wholeKept($expected)) {
        // Say WHERE they part, so a deliberate rewording is quick to re-pin and
        // an accidental one is easy to see.
        $at = 0;
        while ($at < strlen($now) && $at < strlen($expected) && $now[$at] === $expected[$at]) {
            $at++;
        }
        $statementsWrong[] = $place . ($now === '' ? ': not found at all' : ': differs from the pinned text at "…' . substr($now, max(0, $at - 30), 90) . '…"');
    }
}
// A direction is a line number with the digits filed off (round 4): the policy
// names the records it means.
if (str_contains($policyDoc, 'in the row above') === true) {
    $statementsWrong[] = 'the policy points at "the row above" again';
}
check(
    'D-113: what an erased person\'s account number stays on - kept on purpose, and kept for security and as a history of changes, neither list claimed complete - is said WHOLE and unchanged in all four places, and the worker-records paragraphs keep the D-112 qualifier (#703 round 5)',
    $statementsWrong === [],
    implode(' | ', $statementsWrong)
);

// 📜 #940 (D-117, D-118, D-119): WHAT A MEMBER IS SHOWN ABOUT THE ACTIVITY LOG.
//    Since #932 the log may be kept for ever (by the installation, or by an
//    organisation where the installation allows it), an organisation's own
//    administrator sets its period, and a record belonging to no organisation
//    follows the installation's. GdprEraser's own reason for tblActivityLogs is
//    what My data and Request erasure print; the written list's reason is the
//    same fact for whoever reads the catalogue. Both are compared WHOLE, with
//    only runs of spaces made one (round 5's rule above): an item check passes
//    a reversed sentence. Changing either means changing its pin, on purpose.
//    The privacy pages print THIS organisation's real figure and are pinned as
//    rendered, per state, by section L of tools/erasure-pages-selftest.php.
$activityNow = [
    'what a member is shown about the activity log (GdprEraser, tblActivityLogs)' => $wholeKept($shown['tblActivityLogs.userID'] ?? ''),
    'the written list (personal-data-catalogue.php, tblActivityLogs)'           => $wholeKept((string) ($catalogueNow['tblActivityLogs']['reason'] ?? '')),
];
$activityPinned = [
    'what a member is shown about the activity log (GdprEraser, tblActivityLogs)' => 'the record of what happened is kept for running and repairing the portal, until the activity log is cleared: the nightly clear-out deletes it after the period set for the organisation it was recorded under (a year unless changed; that organisation\'s own administrator sets it, within the limit for the whole installation), or after the installation\'s period for a record that belongs to no organisation, if that scheduled job has been set up. Where the installation has chosen to keep the activity log for ever, that is the longest any organisation may keep it, not a rule for all: nothing deletes the records of an organisation that has chosen for ever or has not chosen a number of days of its own, nor records that belong to no organisation, but an organisation that has chosen a number of days is still cleared after it. Removed from your own records: your account number, the address the request came from, the browser\'s name, the request\'s headers, the session\'s fingerprint and the facts about the session. What each record says is kept as it was written for as long as the record is, so it can still name you, give your e-mail address or give your account number - a record of linking or unlinking a sign-in provider, for instance, gives that provider\'s e-mail address. Records other people made can also mention you',
    'the written list (personal-data-catalogue.php, tblActivityLogs)'           => 'Kept for running and repairing the portal, until the nightly clear-out deletes it after audit.retentionDays for the organisation it was recorded under (365 days unless changed; that organisation\'s own administrator sets it, within the limit for the whole installation), or the installation\'s figure for a record that belongs to no organisation, if that scheduled job has been set up. Where the installation has chosen to keep the activity log for ever, that is the longest any organisation may keep it, not a rule for all: nothing deletes the records of an organisation that has chosen for ever or has not chosen a number of days of its own, nor records that belong to no organisation, but an organisation that has chosen a number of days is still cleared after it. On the person\'s own records the account number, the address, the browser, the headers and the session details are removed, but what each record SAYS is kept as written for as long as the record is: it can still name them, give their e-mail address or give their account number, and a record of linking or unlinking a sign-in provider gives that provider\'s e-mail address. Records other people made can mention them too',
];
$activityWrong = [];
foreach ($activityPinned as $place => $expected) {
    if (($activityNow[$place] ?? '') !== $wholeKept($expected)) {
        $activityWrong[] = $place . ': "' . mb_substr($activityNow[$place] ?? '', 0, 200) . '…"';
    }
}
check(
    'D-117/D-118: what a member is shown about the activity log, and the written list\'s reason for it, say WHOLE that it is cleared after the period set for the organisation it was made under (set by that organisation\'s administrator within the installation\'s), unless kept for ever by choice, and that what a record says stays as long as the record does (#940)',
    $activityWrong === [],
    implode(' | ', $activityWrong)
);

check(
    'D-113, WHAT A MEMBER IS SHOWN: the reasons My data and Request erasure print for an erased worker\'s kept records, their account and their expense claims - and the erasure report\'s own lines - say plainly that the name and number stay and can be matched back to them (#703)',
    $shownWrong === [],
    implode(' | ', $shownWrong)
);

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
