<?php
// Path: _core/ErasureReview.php
/**
 * -----------------------------------------------------------------------------
 * The free text a rule cannot decide about, and who decided 🧑‍⚖️
 * -----------------------------------------------------------------------------
 * Some columns hold a person's own words, or somebody's name typed by hand, in
 * a row the automatic erasure rules deliberately leave alone. Whether such a
 * line matters depends on the request in front of you: "Jane's laptop" on a
 * licence seat is a name, and "spare projector" on the next row is not. No rule
 * can tell them apart, so a person decides, one row at a time.
 *
 * This class is:
 *
 *   - the ONE place that says WHICH columns need a human look. The list comes
 *     from `personal-data-catalogue.php` and is never written out again here;
 *   - the ONE place a decision is RECORDED, and the only thing that acts on
 *     one.
 *
 * It opens no connection of its own: every method takes one. It keeps no copy
 * of the catalogue: `markedColumns()` reads the file each time it is asked
 * (once per request, remembered for that request only).
 *
 * -----------------------------------------------------------------------------
 * WHAT A DECISION IS, AND WHAT IT IS NOT
 * -----------------------------------------------------------------------------
 * A decision is a CHOICE FROM TWO: empty this line, or keep it. There is no box
 * to type a reason in, and that is deliberate (#710's reason, in a new place).
 * Asking somebody to describe what they did invites them to quote the words
 * being erased into `tblErasureAudit` — a table deliberately absent from the
 * personal-data catalogue (#510 rule 7d) and never erased. So the trail records
 * WHICH row, WHICH column, WHICH of the two answers and WHO gave it, and never
 * the text itself. The same goes for `tblErasureReviewDecisions`: it holds no
 * text either, only the decision.
 *
 * -----------------------------------------------------------------------------
 * "EMPTY IT" REALLY EMPTIES IT, AND THAT IS CHECKED THREE WAYS
 * -----------------------------------------------------------------------------
 * A NOT NULL column cannot be emptied, and the failure is QUIET: the database
 * refuses, and without care the decision would still be recorded. So:
 *
 *   1. `tools/audit-checks/check_erasure_review_items.py` fails when a
 *      reviewable column is declared NOT NULL in `full_schema.sql`, the same
 *      gate `check_erasure_columns_nullable.py` is for the written
 *      instructions (#715, migration 240).
 *   2. `record()` empties the cell and READS IT BACK inside the transaction
 *      that writes the decision. If it is not empty afterwards, nothing is
 *      saved — no decision row, no audit line — and the caller is told.
 *   3. The decision row and the emptying are ONE transaction, so the record
 *      can never say "emptied" about a line that is still there.
 *
 * -----------------------------------------------------------------------------
 * WHICH ROWS ARE OFFERED, AND WHAT THAT CANNOT REACH
 * -----------------------------------------------------------------------------
 * The rows the table's OWN erasure instructions match for this person — the
 * catalogue entry's `columns` list, narrowed to the account columns
 * `GdprEraser::LINK_COLUMNS` names, in that list's own order — AND THEN
 * NARROWED AGAIN TO ONE ORGANISATION where the table records one. So the review
 * reaches no FURTHER than the erasure does, and on a table carrying a `siteID`
 * it deliberately reaches LESS far.
 *
 * ⚠️ IT IS NOT "EXACTLY WHAT THE ERASURE MATCHES", AND SAYING SO WAS WRONG.
 *    An earlier version of this docblock said the two agreed "by construction
 *    rather than by a second rule that could drift". Two things made that
 *    untrue: `rowsHolding()` shows at most `SHOW_AT_MOST` rows per column,
 *    where the erasure has no cap at all; and the organisation filter below.
 *    Both differences are deliberate and both are stated where they bite.
 *
 * -----------------------------------------------------------------------------
 * ONE ORGANISATION'S ROWS, AND WHAT THAT COSTS (the owner, 3 November 2026)
 * -----------------------------------------------------------------------------
 * An erasure request belongs to ONE organisation (`tblErasureRequest.siteID`),
 * and a person may belong to several. The erasure's own catalogue walk is
 * portal-wide, so without a filter the data controller of organisation A was
 * shown — in full, untruncated, by design — the same person's own words from
 * organisation B, and could empty them. READING somebody's words to decide
 * about them is not the same act as erasing them, and the standing rule of
 * 29 October 2026 is that the two administration levels may be DIFFERENT
 * PARTIES. So every reviewable table that records which organisation a row
 * belongs to is narrowed to the request's own organisation.
 *
 * THE COST, ACCEPTED BY THE OWNER ON 3 NOVEMBER 2026 AND WRITTEN HERE SO
 * NOBODY MEETS IT AS A SURPRISE: a request for somebody who belongs to two
 * organisations now needs EACH organisation's own data controller to decide
 * its own lines before it can close. One person can no longer finish such a
 * request alone, and the refusal says so.
 *
 * ⚠️ AND THE SHARPER CONSEQUENCE, SAID BECAUSE A GAP LOOKS LIKE COVERAGE. If
 *    organisation B never FILES a request of its own, nothing ever offers B's
 *    lines to anybody: organisation A's request does not see them, so it does
 *    not refuse over them, and it closes. The erasure's own walk does not
 *    reach them either — that these columns are not reached by any rule is the
 *    whole reason they need a person. So such a line stays, and no record says
 *    it was left. That is the price of not showing one organisation another's
 *    words, it is the owner's decision rather than an oversight, and the
 *    remedy is a request in each organisation the person belongs to.
 *
 * ⚠️ TWO REVIEWABLE TABLES RECORD NO ORGANISATION AT ALL — there is no column
 *    to filter on, and one is NOT invented. Their rows are still shown, and the
 *    page LABELS them so that whoever is deciding knows the row may belong to
 *    another organisation. `organisationScopeNote()` is the ONE place that
 *    wording lives, so the page, the class and the refusal cannot disagree.
 *    Which tables those are is asked of `information_schema`, never written
 *    down here: a hand list of four would rot the first time a table gained
 *    the column.
 *
 * ⚠️ A ROW REACHED THROUGH AN "ACTED ON IT" COLUMN IS NOT THE PERSON'S OWN
 *    ROW, and the page says so against each one. `GdprEraser::ACTOR_COLUMNS`
 *    is what tells the two apart — the same distinction #709 is built around.
 *    Emptying the note on a booking somebody APPROVED would destroy the
 *    booker's words because the approver asked to be forgotten. No rule may do
 *    that; a person looking at the row may decide it, and is shown which
 *    column matched before they do.
 *
 * ⚠️ WHAT THIS CANNOT REACH, said plainly because the gap looks like coverage.
 *    A row that is ABOUT the person but carries no account number of theirs is
 *    not offered and cannot be. The clearest case is a response card
 *    (`tblSalvationCards`): its subject's name, address and prayer request are
 *    all typed by hand, and the only account column on the row is the
 *    follow-up worker it was given to. So a card about the erased person, given
 *    to somebody else, is invisible here — exactly as the catalogue entry says
 *    it is invisible to the erasure. Searching the text for their name was
 *    refused on 1 November 2026 (it misses a mistyped name and can match a
 *    different person who shares a mailbox), and nothing in this review
 *    changes that decision.
 *
 * -----------------------------------------------------------------------------
 * THE REQUEST CANNOT CLOSE WHILE AN ITEM IS UNDECIDED
 * -----------------------------------------------------------------------------
 * `whyNotReadyToClose()` is the ONE test of that, and `GdprEraser` asks it
 * twice: FIRST, before anything in a run changes (so a refusal costs nothing
 * and nothing has to be put back by hand), and again inside the transaction
 * that closes the request, which is the one place a request is ever closed.
 *
 * ⚠️ COUNTING AND SHOWING ARE TWO DIFFERENT JOBS, AND KEEPING THEM ONE WAS A
 *    REAL FAULT. `whyNotReadyToClose()` asks `countUndecided()`, which COUNTS
 *    in the database with NO LIMIT ANYWHERE. It does not look at the list the
 *    page draws. Until 3 November 2026 it did, and that list stopped at 500
 *    rows per column in silence: measured on a real MySQL 8.0.36 with 501
 *    rows, 500 were offered, no problem was reported, deciding all 500 made
 *    this method answer '' — and the request closed with one line of the
 *    person's own words still in it, with nothing anywhere saying so.
 *    Somebody who books a room every week for ten years has about 520 such
 *    rows, so it was not exotic.
 *
 *    THE RULE THAT FOLLOWS FROM IT: a display cap may never be able to change
 *    the close. `SHOW_AT_MOST` is a page-full and nothing more — the page says
 *    how many lines there are and how many it is showing, the count that
 *    decides is unbounded, `record()` finds the one line it is given by a
 *    direct look-up rather than by searching that list, and the list is
 *    ordered UNDECIDED FIRST so that deciding a page-full brings the next one
 *    into view.
 *
 * THE ORDER OF THE FIRST CALL IS LOAD-BEARING — #709's trap in a new place. The
 * table walk EMPTIES the very account columns these rows are found by, so a
 * check made after the walk would find nothing to review, every time, and the
 * review page would go blank the moment a run had been attempted. It is
 * therefore the first thing `execute()` does, before any step has changed
 * anything.
 *
 * -----------------------------------------------------------------------------
 * AND IT MUST NOT BLOCK AN UPGRADE IT IS AHEAD OF
 * -----------------------------------------------------------------------------
 * `tblErasureReviewDecisions` arrives with migration 242, and files reach a
 * server before anybody presses "Run migrations" (#694's lesson). With the
 * table absent nothing could be recorded, so every item would read as
 * undecided and EVERY erasure would be refused — a blocked recovery, which is
 * #697's lesson about making a safety net real without fixing what it then
 * reaches. So `decisionsTableExists()` is asked first and a missing table means
 * "not ready to judge", not "not ready to close": the run carries on and
 * `GdprEraser` writes an honest line saying the review could not be checked.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/724
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

final class ErasureReview
{
    /** The two answers. There is no third, and no free text beside them. */
    public const DECISION_EMPTY = 'empty';
    public const DECISION_KEEP  = 'keep';

    /** Where a decision is kept. Named once so nothing spells it twice. */
    public const DECISIONS_TABLE = 'tblErasureReviewDecisions';

    /**
     * The address of the review page, so the audit line and the refusal can
     * both send somebody to the same place without either writing it out.
     */
    public const PAGE_ROUTE = 'admin/erasure-requests/review';

    /**
     * 📏 HOW MANY LINES ONE PAGE SHOWS, per table and column. A DISPLAY cap
     *    and nothing else: it can never change whether a request may close,
     *    because `whyNotReadyToClose()` counts in the database instead of
     *    reading this list (see the class docblock for the fault that rule
     *    comes out of, which was measured rather than reasoned).
     *
     * Why there is a cap at all: this portal runs on shared hosting with a few
     * seconds per request, and each item carries the WHOLE text of its cell so
     * that somebody can judge it. Ten thousand of them in one page would be a
     * blank screen rather than a review.
     *
     * Why a capped page is still a working review: `itemsFor()` orders
     * undecided lines FIRST, so deciding a page-full brings the next page-full
     * into view, and `record()` looks its one line up directly — a line past
     * the cap can be decided even while it is not shown. So every line is
     * reachable, and the count that matters never stops at a page.
     */
    public const SHOW_AT_MOST = 500;

    /** The column a table uses to say which organisation a row belongs to. */
    private const SITE_COLUMN = 'siteID';

    /**
     * 🏛️ The one sentence that says how far this review reaches across
     *    organisations. ONE place, so the page, the class and the refusal that
     *    reaches the request's report cannot drift apart.
     *
     * Deliberately written WITHOUT A COUNT of how many tables carry a
     * `siteID`: that number is read from `information_schema` and would be
     * wrong here the first time a table gained the column, which is exactly
     * the staleness #678 is about.
     */
    public static function organisationScopeNote(): string
    {
        return 'This review shows one organisation\'s rows: a table that records which organisation a row '
            . 'belongs to is narrowed to the organisation this request was filed in, and a table that records '
            . 'no organisation is not narrowed at all — each of those lines is labelled, because the row may '
            . 'belong elsewhere. So if this person belongs to more than one organisation, each organisation\'s '
            . 'own Data Controller has to decide its own lines before the request can be completed.';
    }

    /**
     * 🔖 Which columns need a human look — table => [column => why no rule
     *    decides it].
     *
     * Read from `personal-data-catalogue.php`, which is where the decision
     * about every column already lives. A column is marked by its entry's
     * `needsReview` list, and its reason is the entry's own `notEmptiedHere`
     * text for that column, so ONE place describes one column.
     *
     * WHY A MARKER RATHER THAN READING THE REASON. The reason is prose. Judging
     * "is this free text a person should look at?" from the words of a sentence
     * would drift the first time somebody reworded one, and it would drift
     * silently. A marker is a decision somebody made on purpose, and
     * `tools/audit-checks/check_erasure_review_items.py` pins the marked set
     * BOTH ways: a column marked without the owner's decision fails, and one of
     * the seven that loses its marker fails too.
     *
     * A marked column with no `notEmptiedHere` reason is NOT silently given a
     * blank one: it is left out and named in `whyListUnusable()`, because the
     * page's whole job is to show somebody why no rule decides this line.
     *
     * @return array<string, array<string, string>>
     */
    public static function markedColumns(): array
    {
        static $remembered = null;
        if ($remembered !== null) {
            return $remembered;
        }

        $marked = [];
        foreach (self::catalogue() as $table => $meta) {
            $table    = (string) $table;
            $needs    = (array) ($meta['needsReview'] ?? []);
            $reasons  = (array) ($meta['notEmptiedHere'] ?? []);
            $named    = array_map('strval', (array) ($meta['columns'] ?? []));
            foreach ($needs as $column) {
                $column = (string) $column;
                // Three things have to be true, and each is a real guard:
                // the name must be safe to put in a statement; the entry must
                // NAME the column (so a renamed column cannot be reviewed
                // under its old name); and there must be a reason to show.
                if (preg_match('/\A[A-Za-z0-9_]+\z/', $column) !== 1) {
                    continue;
                }
                if (in_array($column, $named, true) === false) {
                    continue;
                }
                $why = trim((string) ($reasons[$column] ?? ''));
                if ($why === '') {
                    continue;
                }
                $marked[$table][$column] = $why;
            }
        }

        $remembered = $marked;

        return $marked;
    }

    /**
     * 🚧 Why the marked list cannot be trusted, or '' when it can.
     *
     * `markedColumns()` leaves out anything it cannot use, which on its own
     * would make a broken marker look like "nothing to review" — the one
     * answer this feature must never give by accident. This says what was
     * left out and why, in words fit for a page and for the audit trail.
     *
     * @return string '' when every marker is usable.
     */
    public static function whyListUnusable(): string
    {
        $problems = [];
        foreach (self::catalogue() as $table => $meta) {
            $table   = (string) $table;
            $needs   = (array) ($meta['needsReview'] ?? []);
            $reasons = (array) ($meta['notEmptiedHere'] ?? []);
            $named   = array_map('strval', (array) ($meta['columns'] ?? []));
            foreach ($needs as $column) {
                $column = (string) $column;
                if (preg_match('/\A[A-Za-z0-9_]+\z/', $column) !== 1) {
                    $problems[] = $table . ': a marked column name is not a plain name';
                    continue;
                }
                if (in_array($column, $named, true) === false) {
                    $problems[] = $table . '.' . $column . ' is marked for review but the entry\'s own '
                        . 'column list does not name it';
                    continue;
                }
                if (trim((string) ($reasons[$column] ?? '')) === '') {
                    $problems[] = $table . '.' . $column . ' is marked for review with no reason beside '
                        . 'it, so nobody could be shown why no rule decides it';
                }
            }
        }

        return $problems === [] ? '' : implode('; ', $problems);
    }

    /**
     * 🗂️ Does the table a decision is kept in exist yet?
     *
     * `SHOW TABLES LIKE`, never a blanket try/catch, and asked before every
     * read or write — the #694 pattern. Migration 242 creates the table, and
     * the files reach a server before anybody presses "Run migrations".
     *
     * ⚠️ THE NAME IS WRITTEN INTO THE QUERY, NOT BOUND, and that is a fix
     *    rather than a shortcut. `SHOW TABLES LIKE ?` prepares perfectly well
     *    and then matches NOTHING: MySQL does not take a parameter for that
     *    pattern. A first version did exactly that, and the result was the
     *    whole review quietly switching itself off — `whyNotReadyToClose()`
     *    answers "nothing in the way" when the table is absent, by design, so
     *    every request ran straight through with its free text undecided and
     *    nothing said so. Found by running this against a real database, where
     *    thirty-one checks went red at once. The name is a constant in this
     *    file, matched against `isPlainName()` before it is used, which is what
     *    makes writing it in safe — the `DbBackup::holdingTableExists()`
     *    pattern, which does the same thing for the same reason.
     */
    public static function decisionsTableExists(mysqli $db): bool
    {
        if (self::isPlainName(self::DECISIONS_TABLE) === false) {
            return false;
        }
        try {
            $result = $db->query("SHOW TABLES LIKE '" . self::DECISIONS_TABLE . "'");
            if ($result === false) {
                return false;
            }
            $found = $result->num_rows > 0;
            $result->free();

            return $found;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 📋 Every line of free text this person's erasure needs a human answer
     *    about, with the decision already given where there is one.
     *
     * One entry per ROW per COLUMN. Each holds:
     *   table      the table
     *   column     the reviewable column
     *   keyColumn  that table's own key column
     *   rowKey     the row's key value
     *   text       what is actually in the cell, so somebody can judge it
     *   reason     why no rule decides it (the catalogue's own words)
     *   matchedBy  which account columns of that row name this person
     *   actedOnly  true when EVERY matching column only says they acted on it
     *   oneOrgOnly true when the table records which organisation a row
     *              belongs to, so this row IS this request's organisation's.
     *              FALSE means the table records no organisation at all and
     *              the row may belong to another one — the page says so.
     *   decision   '', 'empty' or 'keep'
     *   decidedBy  the account number that decided, or 0
     *   decidedAt  when, or ''
     *
     * A cell that is already empty is not an item: there is nothing to decide.
     * A table that is not in this database is skipped, exactly as
     * `GdprEraser::processEntry()` skips one (nothing can be held in a table
     * that is not there).
     *
     * ⚠️ THIS IS THE "SHOWING" HALF AND IT IS CAPPED. At most
     *    `SHOW_AT_MOST` items per table and column, UNDECIDED ONES FIRST. The
     *    `counts` it returns are what the page must print, because a page that
     *    shows 500 of 501 lines and says nothing is the fault this split
     *    exists to stop. NOTHING THAT DECIDES ANYTHING MAY READ THIS LIST:
     *    `whyNotReadyToClose()` uses `countUndecided()` and `record()` uses a
     *    direct look-up.
     *
     * @return array{items: list<array<string, mixed>>, problems: list<string>,
     *   counts: array{total: int, shown: int, undecided: int, capped: bool}}
     *   `problems` names anything that could not be looked at. It is NEVER
     *   folded into "nothing to review": `whyNotReadyToClose()` refuses while
     *   it holds anything, because "we could not tell" must not become "there
     *   is nothing there" (#647's rule about -1 and 0, in a new place).
     *   `counts` is measured with no limit: `total` is every line of free text
     *   held, `shown` is how many of them are in `items`, `undecided` is how
     *   many of the total nobody has answered about, and `capped` says whether
     *   this page is showing fewer than there are.
     */
    public static function itemsFor(mysqli $db, int $requestId, int $userId): array
    {
        $items    = [];
        $problems = [];
        $total    = 0;
        $undecided = 0;

        $listProblem = self::whyListUnusable();
        if ($listProblem !== '') {
            $problems[] = $listProblem;
        }

        $siteId    = self::siteOfRequest($db, $requestId, $problems);
        $decisions = self::decisionsFor($db, $requestId, $problems);
        $canCount  = self::decisionsTableExists($db);

        foreach (self::markedColumns() as $table => $columns) {
            $links = self::linkColumnsOf($table);
            if ($links === []) {
                // A marked column on a table with no account column at all.
                // Nothing could be offered, and saying so is the point: the
                // catalogue's own reason for such a column is that it is
                // unreachable, and a silent absence would read as "clean".
                $problems[] = $table . ': no account column, so no row of it can be matched to this person';
                continue;
            }

            $keyColumn = self::keyColumnOf($db, $table);
            if ($keyColumn === '') {
                $problems[] = $table . ': its own key column could not be read, so a row cannot be named';
                continue;
            }

            // 🏛️ Which column, if any, says what organisation a row belongs
            //    to. Asked of the database, never written down, so a table
            //    that gains the column is narrowed from that day with no edit
            //    here. '' means the table records no organisation and its rows
            //    are shown whatever organisation they are in — labelled.
            $siteColumn = self::siteColumnOf($db, $table);
            if ($siteColumn !== '' && $siteId === 0) {
                // The filter cannot be applied because the request's own
                // organisation could not be read. Refusing to show is the
                // safe direction, and it is a PROBLEM rather than silence.
                $problems[] = $table . ': this request\'s organisation could not be read, so its rows were '
                    . 'not looked at (showing another organisation\'s words is the one thing this must not do)';
                continue;
            }

            foreach ($columns as $column => $reason) {
                $counted = self::countHolding(
                    $db,
                    $table,
                    $keyColumn,
                    (string) $column,
                    $links,
                    $userId,
                    $siteColumn,
                    $siteId,
                    $requestId,
                    $canCount,
                    $problems
                );
                $total     += $counted['total'];
                $undecided += $counted['undecided'];

                $rows = self::rowsHolding(
                    $db,
                    $table,
                    $keyColumn,
                    (string) $column,
                    $links,
                    $userId,
                    $siteColumn,
                    $siteId,
                    $requestId,
                    $canCount,
                    $problems
                );
                foreach ($rows as $row) {
                    $rowKey    = (int) $row['rowKey'];
                    $matchedBy = (array) $row['matchedBy'];
                    $actedOnly = true;
                    foreach ($matchedBy as $matched) {
                        if (in_array($matched, GdprEraser::ACTOR_COLUMNS, true) === false) {
                            $actedOnly = false;
                        }
                    }
                    $already = $decisions[$table . '.' . $column . '.' . $rowKey] ?? null;
                    $items[] = [
                        'table'      => (string) $table,
                        'column'     => (string) $column,
                        'keyColumn'  => $keyColumn,
                        'rowKey'     => $rowKey,
                        'text'       => (string) $row['text'],
                        'reason'     => (string) $reason,
                        'matchedBy'  => $matchedBy,
                        'actedOnly'  => $actedOnly,
                        'oneOrgOnly' => $siteColumn !== '',
                        'decision'   => (string) ($already['decision'] ?? ''),
                        'decidedBy'  => (int) ($already['decidedByID'] ?? 0),
                        'decidedAt'  => (string) ($already['decidedAt'] ?? ''),
                    ];
                }
            }
        }

        return [
            'items'    => $items,
            'problems' => $problems,
            'counts'   => [
                'total'     => $total,
                'shown'     => count($items),
                'undecided' => $undecided,
                'capped'    => count($items) < $total,
            ],
        ];
    }

    /**
     * 🔢 How many lines of free text nobody has answered about yet, COUNTED IN
     *    THE DATABASE WITH NO LIMIT. This is the half that decides.
     *
     * Separate from `itemsFor()` on purpose, and the separation is the whole
     * fix: `itemsFor()` draws a page and a page has a size, while this answers
     * a question about everything there is. One list doing both jobs is how a
     * request came to close over a line nobody had seen.
     *
     * @return array{undecided: array<string, int>, problems: list<string>, total: int}
     *   `undecided` is keyed `table.column` with how many of that column's
     *   lines are unanswered; a column with none is absent. `problems` is
     *   anything that could not be looked at, which refuses on its own.
     */
    public static function countUndecided(mysqli $db, int $requestId, int $userId): array
    {
        $problems  = [];
        $undecided = [];
        $total     = 0;

        $listProblem = self::whyListUnusable();
        if ($listProblem !== '') {
            $problems[] = $listProblem;
        }

        $siteId   = self::siteOfRequest($db, $requestId, $problems);
        $canCount = self::decisionsTableExists($db);

        foreach (self::markedColumns() as $table => $columns) {
            $links = self::linkColumnsOf($table);
            if ($links === []) {
                $problems[] = $table . ': no account column, so no row of it can be matched to this person';
                continue;
            }
            $keyColumn = self::keyColumnOf($db, $table);
            if ($keyColumn === '') {
                $problems[] = $table . ': its own key column could not be read, so a row cannot be named';
                continue;
            }
            $siteColumn = self::siteColumnOf($db, $table);
            if ($siteColumn !== '' && $siteId === 0) {
                $problems[] = $table . ': this request\'s organisation could not be read, so its rows were '
                    . 'not looked at (showing another organisation\'s words is the one thing this must not do)';
                continue;
            }
            foreach ($columns as $column => $reason) {
                $counted = self::countHolding(
                    $db,
                    $table,
                    $keyColumn,
                    (string) $column,
                    $links,
                    $userId,
                    $siteColumn,
                    $siteId,
                    $requestId,
                    $canCount,
                    $problems
                );
                $total += $counted['total'];
                if ($counted['undecided'] > 0) {
                    $undecided[$table . '.' . $column] = $counted['undecided'];
                }
            }
        }

        return ['undecided' => $undecided, 'problems' => $problems, 'total' => $total];
    }

    /**
     * ⛔ Why this request may not be closed yet, or '' when it may.
     *
     * The ONE test, asked by `GdprEraser` before a run changes anything and
     * again inside the transaction that closes the request.
     *
     * It refuses for THREE reasons and they are not the same thing: an item
     * nobody has decided; a marked column that could not be used; and a table
     * that could not be looked at. The third is "we could not tell", and it
     * refuses on purpose — reading it as "nothing there" would let a request
     * close over text a transient database fault hid. Running the request again
     * clears it, so refusing costs a retry and the other answer could cost
     * somebody's words.
     *
     * ⚠️ A MISSING `tblErasureReviewDecisions` IS NOT A REFUSAL. See the class
     *    docblock: the table arrives with migration 242, and refusing until it
     *    is there would block every erasure on a server whose files are ahead
     *    of its database. The caller writes an honest line instead.
     *
     * ⚠️ IT ASKS `countUndecided()`, NEVER `itemsFor()`, AND THAT IS THE FIX
     *    OF 3 NOVEMBER 2026. `itemsFor()` draws a page of at most
     *    `SHOW_AT_MOST` rows per column; reading it here meant that the 501st
     *    line of somebody's own words was never counted, so deciding the 500
     *    on screen made this answer '' — the one answer that lets a request
     *    close. Measured on a real database, not reasoned about. Nothing that
     *    decides whether a request may close may read a list with a size.
     */
    public static function whyNotReadyToClose(mysqli $db, int $requestId, int $userId): string
    {
        if (self::decisionsTableExists($db) === false) {
            return '';
        }

        $counted   = self::countUndecided($db, $requestId, $userId);
        $undecided = $counted['undecided'];
        $problems  = $counted['problems'];

        if ($undecided === [] && $problems === []) {
            return '';
        }

        $parts = [];
        if ($undecided !== []) {
            $named = [];
            foreach ($undecided as $where => $howMany) {
                $named[] = $where . ' (' . (string) $howMany . ')';
            }
            $parts[] = (string) array_sum($undecided) . ' line(s) of free text have not been decided about yet: '
                . implode(', ', $named);
            // 🏛️ The cost of narrowing the review to one organisation, said
            //    at the moment somebody is refused rather than left in a
            //    docblock. ONE wording, in one place.
            $parts[] = self::organisationScopeNote();
        }
        if ($problems !== []) {
            $parts[] = 'and this could not be looked at: ' . implode('; ', $problems);
        }

        return implode('; ', $parts);
    }

    /**
     * ✅ Record one decision, and carry it out.
     *
     * 'empty' empties the cell, reads it back, and writes the decision row and
     * the audit line — all in ONE transaction, so the three cannot disagree.
     * 'keep' writes the decision row and the audit line and changes no data.
     *
     * A decision can be CHANGED from keep to empty (somebody looks again and
     * decides the line must go). It cannot be changed from empty back to keep,
     * because the text is already gone and recording "keep" over it would make
     * the record untrue.
     *
     * @return string '' when it was recorded. Otherwise a plain sentence saying
     *   why not, fit to show an administrator. Nothing is changed in that case.
     */
    public static function record(
        mysqli $db,
        int $requestId,
        int $userId,
        string $table,
        string $column,
        int $rowKey,
        string $decision,
        ?int $decidedByID
    ): string {
        if ($decision !== self::DECISION_EMPTY && $decision !== self::DECISION_KEEP) {
            return 'That is not one of the two answers ("empty it" or "keep it"), so nothing was recorded.';
        }
        if (self::decisionsTableExists($db) === false) {
            return 'This portal\'s database needs its upgrade first (Admin, Upgrade), because the table a '
                . 'decision is kept in is not there yet. Nothing was recorded.';
        }

        // 🔒 THE ITEM MUST STILL BE ONE. Asked of the database rather than
        //    taken from the form: the row may have been emptied, changed or
        //    deleted since the page was drawn, and a decision about a line
        //    that is no longer there would be a record of nothing.
        //
        // ⚠️ ASKED ABOUT THIS ONE LINE, NEVER BY SEARCHING `itemsFor()`'s
        //    LIST. That list is a PAGE of at most `SHOW_AT_MOST` rows per
        //    column, so searching it would make the 501st line of somebody's
        //    own words impossible to decide — the request could then never
        //    close, which is the blocked-recovery shape #697 records. A
        //    direct look-up asks exactly the same question of exactly the
        //    same rule (`oneRowHolding()` and `rowsHolding()` share
        //    `holdingWhere()`), with no page in the way.
        $theItem = self::oneItem($db, $requestId, $userId, $table, $column, $rowKey);
        // ⚠️ THERE IS DELIBERATELY NO SECOND TEST HERE for "already emptied".
        //    A draft had one, and it was a clause that COULD NOT FAIL: an
        //    emptied cell is no longer an item at all, because `rowsHolding()`
        //    only offers a cell with something in it, so this branch would
        //    have caught it first. The real guard is inside the transaction
        //    below, where the row is held and the cell read again — which is
        //    also the only place that can catch two people deciding at once.
        if ($theItem === null) {
            // 📏 FOUR CAUSES, AND IT NAMES NONE OF THEM AS THE ONE. `oneItem()`
            //    answers null for a line that is not a review item AND for a
            //    line it could not READ, and nothing can tell those apart from
            //    here. The old wording hedged across three and quietly
            //    asserted that it is not an item, which is the "a hedge that
            //    covers one condition and asserts the other" fault this
            //    repository has recorded three times. Either way nothing is
            //    recorded and nothing is changed, which is the safe direction.
            return 'That line could not be recorded against this request. Either it is no longer one of its '
                . 'review items — already emptied, or the row changed, removed, or in another organisation — or '
                . 'it could not be read just now. Nothing was recorded and nothing was changed; open the review '
                . 'page again to see where the request stands.';
        }

        $keyColumn = (string) $theItem['keyColumn'];
        // Every name below is from the catalogue or from `information_schema`,
        // never from a request, and each has been matched against
        // /\A[A-Za-z0-9_]+\z/ before it got here. That is what makes them safe
        // to put in a statement; the VALUES are bound, as always.
        if (self::isPlainName($table) === false
            || self::isPlainName($column) === false
            || self::isPlainName($keyColumn) === false
        ) {
            return 'That row cannot be named safely, so nothing was recorded.';
        }

        // 🔐 THE TRANSACTION OPENS BEFORE THE LOCK IS TAKEN. A `FOR UPDATE` on a
        //    SELECT outside a transaction is decoration: nothing in `web/_core`
        //    touches autocommit, so the implicit transaction around it commits
        //    and releases the lock at once. That exact fault shipped in #697
        //    with an eleven-line comment claiming it stopped two
        //    administrators colliding.
        if ($db->begin_transaction() === false) {
            return 'A transaction could not be started, so nothing was recorded.';
        }

        try {
            // 🔒 Read the cell again, this time holding the row, so that two
            //    people deciding the same line cannot both succeed. Without
            //    this, one could empty the line while the other recorded it as
            //    KEPT, leaving a record that says the text is still there over
            //    a cell that is empty.
            $stmt = $db->prepare(
                'SELECT `' . $column . '` FROM `' . $table . '` WHERE `' . $keyColumn . '` = ? LIMIT 1 FOR UPDATE'
            );
            if ($stmt === false) {
                throw new \RuntimeException('the row could not be read (database error ' . (string) $db->errno . ')');
            }
            $stmt->bind_param('i', $rowKey);
            $stmt->execute();
            $before = $stmt->get_result()->fetch_row();
            $stmt->close();
            if ($before === null) {
                throw new \RuntimeException('that row is no longer in the database');
            }
            if ($before[0] === null || (string) $before[0] === '') {
                throw new \RuntimeException(
                    'that line is already empty, so there is nothing left to decide about it'
                );
            }

            if ($decision === self::DECISION_EMPTY) {
                $stmt = $db->prepare(
                    'UPDATE `' . $table . '` SET `' . $column . '` = NULL WHERE `' . $keyColumn . '` = ?'
                );
                if ($stmt === false) {
                    throw new \RuntimeException('the line could not be emptied (database error ' . (string) $db->errno . ')');
                }
                $stmt->bind_param('i', $rowKey);
                $stmt->execute();
                $stmt->close();

                // 📏 READ IT BACK. A NOT NULL column cannot be emptied and the
                //    refusal can be quiet, and "I told it to" is not the same
                //    as "it is empty". Three things guard this column being
                //    nullable; this is the one that watches the real database.
                $stmt = $db->prepare(
                    'SELECT `' . $column . '` FROM `' . $table . '` WHERE `' . $keyColumn . '` = ? LIMIT 1'
                );
                if ($stmt === false) {
                    throw new \RuntimeException('the row could not be read back (database error ' . (string) $db->errno . ')');
                }
                $stmt->bind_param('i', $rowKey);
                $stmt->execute();
                $after = $stmt->get_result()->fetch_row();
                $stmt->close();
                if ($after === null) {
                    throw new \RuntimeException('the row could not be read back after emptying it');
                }
                if ($after[0] !== null) {
                    throw new \RuntimeException(
                        'the database would not empty `' . $table . '`.`' . $column . '` — it is most likely '
                        . 'declared NOT NULL in this database, which this portal\'s install script says it '
                        . 'should not be'
                    );
                }
            }

            $stmt = $db->prepare(
                'INSERT INTO `' . self::DECISIONS_TABLE . '` '
                . '(`requestID`, `tableName`, `columnName`, `rowKey`, `decision`, `decidedByID`, `decidedAtUtc`) '
                . 'VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP()) '
                . 'ON DUPLICATE KEY UPDATE `decision` = VALUES(`decision`), '
                . '`decidedByID` = VALUES(`decidedByID`), `decidedAtUtc` = UTC_TIMESTAMP()'
            );
            if ($stmt === false) {
                throw new \RuntimeException('the decision could not be written (database error ' . (string) $db->errno . ')');
            }
            // 🪪 WHO DECIDED IS LEFT EMPTY WHEN THE DECIDER IS THE PERSON
            //    BEING ERASED — on the ROW as well as in the audit line, and
            //    that is a fix of 3 November 2026. The audit line had always
            //    masked the number; the row stored it, while the column's own
            //    comment, `full_schema.sql` and migration 242's header all
            //    said it was left empty in this case. The window was real if
            //    small: the number sat on the row from the decision until the
            //    run's walk nulled it, and a request CANCELLED rather than
            //    completed left it there for good. A comment promising a
            //    guarantee the code had not implemented, in the reassuring
            //    direction, in a privacy feature, is the shape this
            //    repository keeps being burned by — so the code was changed
            //    to match the promise rather than the promise softened.
            $decidedOnRow = ($decidedByID === null || $decidedByID === $userId) ? null : $decidedByID;
            $stmt->bind_param('issisi', $requestId, $table, $column, $rowKey, $decision, $decidedOnRow);
            $stmt->execute();
            $stmt->close();

            // 🧾 The audit line. 'anonymise' for an emptied line, so that
            //    `GdprEraser::runHasChangedAnything()` counts it: once a line
            //    has been emptied for this request, cancelling the request
            //    would leave the person's words gone and a request saying
            //    nothing happened. 'retain' for a kept line, which changes
            //    nothing — and no table of this review's is one of the two
            //    names `givingStepHasRun()` looks for, so a kept line cannot
            //    be mistaken for the giving step having run.
            //
            //    WHO DECIDED is guarded exactly as `closeRequest()` guards it:
            //    the number is left out when the decider is the person being
            //    erased, because this trail is never erased and writing it
            //    would put the number straight back. The DECISION ROW above
            //    leaves it out for the same reason, under the same test.
            $whoDidIt = $decidedByID === null || $decidedByID === $userId
                ? 'an administrator (not named here: they are the person being erased)'
                : 'administrator #' . (string) $decidedByID;
            GdprEraser::logReviewLine(
                $db,
                $requestId,
                $decision === self::DECISION_EMPTY ? 'anonymise' : 'retain',
                $table,
                (string) $rowKey,
                // ⚠️ NEVER THE TEXT ITSELF. What is recorded is which line, what
                //    was decided and who decided — the #710 rule.
                ($decision === self::DECISION_EMPTY
                    ? 'reviewed by hand and EMPTIED: `' . $column . '` on row ' . (string) $rowKey
                    : 'reviewed by hand and KEPT: `' . $column . '` on row ' . (string) $rowKey)
                . ', by ' . $whoDidIt . '. No rule decides this column, so a person did. '
                . 'The text itself is deliberately not written down here.'
            );

            if ($db->commit() === false) {
                throw new \RuntimeException('the decision could not be saved (database error ' . (string) $db->errno . ')');
            }
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $rollbackProblem) {
                error_log('[WebMS-Intra] ErasureReview: rollback failed for erasure #' . $requestId
                    . ': ' . $rollbackProblem->getMessage());
            }

            // 📏 THE DATABASE'S OWN WORDS ARE NEVER PUT IN THIS SENTENCE, for
            //    the reason `rowsHolding()` gives thirty lines below and this
            //    method did not follow until 3 November 2026: a database
            //    message can QUOTE A VALUE, and this sentence is shown on a
            //    page and goes in the activity log. Everything this class
            //    throws itself is composed from names and counts, so those
            //    words are safe; a `mysqli_sql_exception` is the database
            //    talking, and only its NUMBER comes through.
            //
            //    AND THE ONE NUMBER WORTH TRANSLATING. On a strict connection
            //    (every installation's: #593) the UPDATE itself throws rather
            //    than reaching the read-back, so without this the honest but
            //    useless answer would be "database error 1048" where the old
            //    code at least said what to look at. 1048 is "column cannot
            //    be null", and the table and column are names this class
            //    already holds — our own words about our own names, not the
            //    database's.
            $words = $e->getMessage();
            if ($e instanceof \mysqli_sql_exception) {
                $words = 'the database refused it (database error ' . (string) $e->getCode() . ')';
                if ((int) $e->getCode() === 1048) {
                    $words .= ' — error 1048 means a column cannot be NULL, so `' . $table . '`.`' . $column
                        . '` is most likely declared NOT NULL in this database, which this portal\'s install '
                        . 'script says it should not be';
                }
            }

            return 'Nothing was recorded and nothing was changed: ' . $words;
        }

        return '';
    }

    /**
     * 🧾 Every decision recorded for one request, for showing on the page.
     *
     * Needed as well as `itemsFor()`, and the reason is worth knowing: a line
     * that has been EMPTIED is no longer an item — there is nothing in the cell
     * to decide about — so without this the page would show no sign that
     * anything had been dealt with, and somebody would wonder whether their
     * press had worked.
     *
     * It holds no text, because the decisions table holds none.
     *
     * @return list<array{table: string, column: string, rowKey: int, decision: string, decidedBy: int, decidedAt: string}>
     */
    public static function decisionsRecorded(mysqli $db, int $requestId): array
    {
        $ignored = [];
        $out     = [];
        foreach (self::decisionsFor($db, $requestId, $ignored) as $key => $what) {
            $parts  = explode('.', $key);
            $rowKey = (int) array_pop($parts);
            $column = (string) array_pop($parts);
            $out[]  = [
                'table'     => implode('.', $parts),
                'column'    => $column,
                'rowKey'    => $rowKey,
                'decision'  => (string) $what['decision'],
                'decidedBy' => (int) $what['decidedByID'],
                'decidedAt' => (string) $what['decidedAt'],
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$a['table'], $a['column'], $a['rowKey']]
            <=> [$b['table'], $b['column'], $b['rowKey']]);

        return $out;
    }

    // -------------------------------------------------------------------------
    // internals
    // -------------------------------------------------------------------------

    /**
     * The catalogue, read as PHP. Remembered for the life of the request.
     *
     * An unreadable catalogue answers an EMPTY list here, unlike
     * `GdprEraser::fromPersonalDataCatalogue()`, which throws. The difference
     * is deliberate: there, an empty list would quietly erase less, which is
     * the worst outcome that class has. Here an empty list means "no column
     * needs a review", which is caught one level up — `whyNotReadyToClose()`
     * is only one of two gates, and the gate that matters for a missing
     * catalogue is the erasure's own refusal to run at all.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function catalogue(): array
    {
        static $remembered = null;
        if ($remembered !== null) {
            return $remembered;
        }
        $file = __DIR__ . DIRECTORY_SEPARATOR . 'personal-data-catalogue.php';
        if (is_readable($file) === false) {
            $remembered = [];

            return $remembered;
        }
        $catalogue = require $file;
        $remembered = is_array($catalogue) === true ? $catalogue : [];

        return $remembered;
    }

    /** A name safe to put in a statement. Nothing here comes from a request. */
    private static function isPlainName(string $name): bool
    {
        return preg_match('/\A[A-Za-z0-9_]+\z/', $name) === 1;
    }

    /**
     * The account columns of one table, in `LINK_COLUMNS` order — the same
     * narrowing `GdprEraser::fromPersonalDataCatalogue()` makes, so the review
     * reaches exactly as far as the erasure and not one row further.
     *
     * @return list<string>
     */
    private static function linkColumnsOf(string $table): array
    {
        $meta    = self::catalogue()[$table] ?? [];
        $columns = array_map('strval', (array) ($meta['columns'] ?? []));
        $links   = array_values(array_intersect(GdprEraser::LINK_COLUMNS, $columns));

        return array_values(array_filter($links, [self::class, 'isPlainName']));
    }

    /**
     * A table's own key column: exactly one, named by the database.
     *
     * Asked of `information_schema` rather than written down here, so that no
     * second list of key columns can drift from the schema. A table with a key
     * made of several columns answers '' and is reported rather than guessed
     * at: a row in this review has to be named by ONE value, because that is
     * what a decision is recorded against.
     */
    private static function keyColumnOf(mysqli $db, string $table): string
    {
        static $remembered = [];
        if (isset($remembered[$table]) === true) {
            return $remembered[$table];
        }
        $found = [];
        try {
            $stmt = $db->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_KEY = \'PRI\''
            );
            if ($stmt !== false) {
                $stmt->bind_param('s', $table);
                $stmt->execute();
                $rs = $stmt->get_result();
                while ($row = $rs->fetch_row()) {
                    $found[] = (string) $row[0];
                }
                $stmt->close();
            }
        } catch (\Throwable $e) {
            $found = [];
        }

        $answer = (count($found) === 1 && self::isPlainName($found[0]) === true) ? $found[0] : '';
        $remembered[$table] = $answer;

        return $answer;
    }

    /**
     * 🏛️ Which organisation an erasure request belongs to, or 0.
     *
     * Read from the request rather than passed in, and that is deliberate: a
     * caller that passed it could pass the WRONG one, and then the review
     * would show whichever organisation the caller happened to have open
     * rather than the one the request was filed in. Both pages already check
     * that the request is theirs before they get here; this is the value the
     * narrowing is done with, and there is one source for it.
     *
     * 0 means it could not be read, which is NOT treated as "no narrowing
     * needed": the caller reports a problem and refuses, because showing a
     * person's own words to the wrong organisation is the one thing this must
     * not do.
     *
     * @param list<string> $problems Added to when the request cannot be read.
     */
    private static function siteOfRequest(mysqli $db, int $requestId, array &$problems): int
    {
        try {
            $stmt = $db->prepare('SELECT `siteID` FROM `tblErasureRequest` WHERE `requestID` = ? LIMIT 1');
            if ($stmt === false) {
                $problems[] = 'this request\'s organisation could not be read (database error '
                    . (string) $db->errno . ')';

                return 0;
            }
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
        } catch (\Throwable $e) {
            $errno = $e instanceof \mysqli_sql_exception ? (int) $e->getCode() : 0;
            $problems[] = 'this request\'s organisation could not be read (database error '
                . (string) $errno . ')';

            return 0;
        }

        if ($row === null) {
            $problems[] = 'this request is not in the database any more, so which organisation it belongs to '
                . 'could not be read';

            return 0;
        }

        return (int) $row[0];
    }

    /**
     * 🏛️ The column one table uses to say which organisation a row belongs to,
     *    or '' when it has none.
     *
     * Asked of `information_schema`, never written down. A hand list of the
     * tables that carry a `siteID` would be right on the day it was written
     * and wrong the day a table gained the column — and wrong in the silent
     * direction, because the missing filter shows MORE than it should. Four of
     * the reviewable tables carry one today; that number is not recorded
     * anywhere, on purpose (#678).
     */
    private static function siteColumnOf(mysqli $db, string $table): string
    {
        static $remembered = [];
        if (isset($remembered[$table]) === true) {
            return $remembered[$table];
        }
        $answer = '';
        try {
            $stmt = $db->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
            );
            if ($stmt !== false) {
                $wanted = self::SITE_COLUMN;
                $stmt->bind_param('ss', $table, $wanted);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                if ($row !== null && self::isPlainName((string) $row[0]) === true) {
                    $answer = (string) $row[0];
                }
            }
        } catch (\Throwable $e) {
            // 🛡️ IT ANSWERS "YES, NARROW IT" WHEN IT COULD NOT TELL, and that
            //    is the safe direction rather than a guess. Answering '' would
            //    drop the organisation filter SILENTLY, which is the one
            //    outcome this narrowing exists to prevent. Saying the column
            //    is there instead means the row read below names it; if the
            //    table really has no such column that read fails with error
            //    1054, which is recorded as a problem — and a problem refuses
            //    the close on its own. So an unreadable schema costs a
            //    refusal, never a disclosure.
            $answer = self::SITE_COLUMN;
        }
        $remembered[$table] = $answer;

        return $answer;
    }

    /**
     * 📐 THE ONE RULE FOR "WHICH ROWS ARE THIS PERSON'S LINES OF FREE TEXT".
     *
     * Written once and used by every one of the four questions asked about it
     * — the page's list, the total, the undecided count, and `record()`'s
     * look-up of one line. That is what stops the thing that decides whether a
     * request may close from drifting away from the thing that shows it, which
     * is exactly what went wrong when one capped list did both jobs.
     *
     * Every name in it has already been matched against `isPlainName()`; the
     * values are always bound. The column is tested with
     * `IS NOT NULL AND <> ''` because a cell with nothing in it is not a
     * decision anybody needs to make, and offering one would bury the real
     * items.
     *
     * @param list<string> $links      Account columns to match on.
     * @param string       $siteColumn '' when the table records no organisation.
     */
    private static function holdingWhere(string $table, array $links, string $column, string $siteColumn): string
    {
        $q     = '`' . $table . '`.';
        $parts = [];
        foreach ($links as $link) {
            $parts[] = $q . '`' . $link . '` = ?';
        }
        $sql = '(' . implode(' OR ', $parts) . ') '
            . 'AND ' . $q . '`' . $column . '` IS NOT NULL AND ' . $q . '`' . $column . '` <> \'\'';
        if ($siteColumn !== '') {
            $sql .= ' AND ' . $q . '`' . $siteColumn . '` = ?';
        }

        return $sql;
    }

    /**
     * The values `holdingWhere()` binds, in its own order. All integers.
     *
     * @param list<string> $links
     *
     * @return list<int>
     */
    private static function holdingValues(array $links, int $userId, string $siteColumn, int $siteId): array
    {
        $values = array_fill(0, count($links), $userId);
        if ($siteColumn !== '') {
            $values[] = $siteId;
        }

        return $values;
    }

    /**
     * The LEFT JOIN on to the decisions table, so that "has this line been
     * answered?" is one question the database answers rather than something
     * worked out in PHP from a list that has a size.
     *
     * Its three placeholders come FIRST in the statement, because a JOIN is
     * written before the WHERE — so a caller binds these three, then
     * `holdingValues()`.
     *
     * The unique key `uq_errd_item` covers (requestID, tableName, columnName,
     * rowKey), so at most one decision row can match and a COUNT over the join
     * cannot be inflated.
     */
    private static function decisionJoin(string $table, string $keyColumn): string
    {
        return 'LEFT JOIN `' . self::DECISIONS_TABLE . '` `errd` '
            . 'ON `errd`.`requestID` = ? AND `errd`.`tableName` = ? AND `errd`.`columnName` = ? '
            . 'AND `errd`.`rowKey` = `' . $table . '`.`' . $keyColumn . '`';
    }

    /**
     * 🔢 How many lines one column holds for this person, and how many of them
     *    nobody has answered about. NO LIMIT ANYWHERE — this is the counting
     *    half, and a limit here is the fault this split exists to stop.
     *
     * @param list<string> $links
     * @param bool         $canCount Whether the decisions table exists. When
     *   it does not, nothing can have been answered, so every line counts as
     *   undecided — and `whyNotReadyToClose()` has already answered '' for
     *   that case, so this only affects what the page prints.
     * @param list<string> $problems Added to, by reference. Error 1146 ("no
     *   such table") is NOT a problem: an app that is not installed holds
     *   nothing.
     *
     * ⚠️ EVERY FAILING PATH ANSWERS `undecided => 0`, WHICH READS LIKE
     *    "NOTHING TO DECIDE" — and it is safe ONLY because the same path adds
     *    to `$problems`, which refuses the close on its own (#647's -1-versus-0
     *    rule). The two go together: anybody who ever drops one of those
     *    `$problems[]` lines, or folds `problems` into "nothing to review",
     *    turns a transient database fault into a request that closes over
     *    somebody's own words. The `prepare() === false` branches are belt and
     *    braces on top of that — this connection is strict (#593), so a
     *    statement error throws into the catch instead.
     *
     * @return array{total: int, undecided: int}
     */
    private static function countHolding(
        mysqli $db,
        string $table,
        string $keyColumn,
        string $column,
        array $links,
        int $userId,
        string $siteColumn,
        int $siteId,
        int $requestId,
        bool $canCount,
        array &$problems
    ): array {
        $where  = self::holdingWhere($table, $links, $column, $siteColumn);
        $values = self::holdingValues($links, $userId, $siteColumn, $siteId);

        try {
            // The total, with nothing joined and nothing limited.
            $stmt = $db->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $where);
            if ($stmt === false) {
                $problems[] = $table . '.' . $column . ': it could not be counted (database error '
                    . (string) $db->errno . ')';

                return ['total' => 0, 'undecided' => 0];
            }
            $stmt->bind_param(str_repeat('i', count($values)), ...$values);
            $stmt->execute();
            $total = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
            $stmt->close();

            if ($canCount === false) {
                return ['total' => $total, 'undecided' => $total];
            }

            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM `' . $table . '` ' . self::decisionJoin($table, $keyColumn)
                . ' WHERE ' . $where . ' AND `errd`.`decisionID` IS NULL'
            );
            if ($stmt === false) {
                $problems[] = $table . '.' . $column . ': the undecided lines could not be counted (database '
                    . 'error ' . (string) $db->errno . ')';

                return ['total' => $total, 'undecided' => 0];
            }
            $joinValues = [$requestId, $table, $column];
            $allValues  = array_merge($joinValues, $values);
            $stmt->bind_param('iss' . str_repeat('i', count($values)), ...$allValues);
            $stmt->execute();
            $undecided = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
            $stmt->close();
        } catch (\Throwable $e) {
            $errno = $e instanceof \mysqli_sql_exception ? (int) $e->getCode() : 0;
            if ($errno !== 1146) {
                // 📏 The fault's own words are kept SHORT and are not the
                //    place to look for the text: a database message can quote
                //    a value, and this one may be reported on a page.
                $problems[] = $table . '.' . $column . ': it could not be counted (database error '
                    . (string) $errno . ')';
            }

            return ['total' => 0, 'undecided' => 0];
        }

        return ['total' => $total, 'undecided' => $undecided];
    }

    /**
     * The rows of one table whose reviewable column holds something, and which
     * name this person in at least one of their account columns — A PAGE OF
     * THEM, UNDECIDED FIRST.
     *
     * ⚠️ `SHOW_AT_MOST` IS A DISPLAY CAP AND NOTHING ELSE. Until 3 November
     *    2026 a bare `LIMIT 500` sat here with no comment at all, and
     *    `whyNotReadyToClose()` read this list — so the 501st line of
     *    somebody's own words was never counted and a request closed over it.
     *    Nothing that decides anything may read this method's answer; see
     *    `countUndecided()` and `oneItem()`.
     *
     * UNDECIDED FIRST is what makes a capped page a working worklist rather
     * than a wall: answering the lines on screen brings the next page-full
     * into view. Without it a page-full of KEPT lines would sit there for ever
     * and the lines behind them would never be seen.
     *
     * @param list<string> $links
     * @param list<string> $problems Added to, by reference, when a table
     *   cannot be read. Error 1146 ("no such table") is NOT a problem: an app
     *   that is not installed holds nothing.
     *
     * @return list<array{rowKey: int, text: string, matchedBy: list<string>}>
     */
    private static function rowsHolding(
        mysqli $db,
        string $table,
        string $keyColumn,
        string $column,
        array $links,
        int $userId,
        string $siteColumn,
        int $siteId,
        int $requestId,
        bool $canCount,
        array &$problems
    ): array {
        $rows   = [];
        $where  = self::holdingWhere($table, $links, $column, $siteColumn);
        $values = self::holdingValues($links, $userId, $siteColumn, $siteId);
        $q      = '`' . $table . '`.';

        $select = [$q . '`' . $keyColumn . '`', $q . '`' . $column . '`'];
        foreach ($links as $link) {
            $select[] = $q . '`' . $link . '`';
        }

        if ($canCount === true) {
            $sql = 'SELECT ' . implode(', ', $select) . ' FROM `' . $table . '` '
                . self::decisionJoin($table, $keyColumn)
                . ' WHERE ' . $where
                . ' ORDER BY (`errd`.`decisionID` IS NOT NULL), ' . $q . '`' . $keyColumn . '` '
                . 'LIMIT ' . (string) self::SHOW_AT_MOST;
            $types  = 'iss' . str_repeat('i', count($values));
            $binds  = array_merge([$requestId, $table, $column], $values);
        } else {
            // No decisions table, so nothing can have been answered and there
            // is nothing to order by. Plain and limited, exactly as before.
            $sql = 'SELECT ' . implode(', ', $select) . ' FROM `' . $table . '` '
                . 'WHERE ' . $where
                . ' ORDER BY ' . $q . '`' . $keyColumn . '` '
                . 'LIMIT ' . (string) self::SHOW_AT_MOST;
            $types = str_repeat('i', count($values));
            $binds = $values;
        }

        try {
            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                $problems[] = $table . '.' . $column . ': it could not be read (database error '
                    . (string) $db->errno . ')';

                return [];
            }
            $stmt->bind_param($types, ...$binds);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($row = $rs->fetch_row()) {
                $rows[] = [
                    'rowKey'    => (int) $row[0],
                    'text'      => (string) $row[1],
                    'matchedBy' => self::matchedBy($row, $links, $userId),
                ];
            }
            $stmt->close();
        } catch (\Throwable $e) {
            $errno = $e instanceof \mysqli_sql_exception ? (int) $e->getCode() : 0;
            if ($errno !== 1146) {
                // 📏 The fault's own words are kept SHORT and are not the
                //    place to look for the text: a database message can quote
                //    a value, and this one may be reported on a page.
                $problems[] = $table . '.' . $column . ': it could not be read (database error '
                    . (string) $errno . ')';
            }

            return [];
        }

        return $rows;
    }

    /**
     * Which of the account columns on one fetched row really name this person.
     * Column 0 is the key and column 1 is the text, so the links start at 2 —
     * written once because three callers read the same row shape.
     *
     * @param list<mixed>  $row
     * @param list<string> $links
     *
     * @return list<string>
     */
    private static function matchedBy(array $row, array $links, int $userId): array
    {
        $matchedBy = [];
        foreach ($links as $n => $link) {
            if ($row[2 + $n] !== null && (int) $row[2 + $n] === $userId) {
                $matchedBy[] = $link;
            }
        }

        return $matchedBy;
    }

    /**
     * 🎯 ONE line, looked up directly, or null when it is not one of this
     *    request's review items.
     *
     * This is what `record()` validates against, and it exists because
     * searching `itemsFor()`'s list would have made a line past
     * `SHOW_AT_MOST` impossible to decide — so a request with more than a
     * page-full of one column's lines could never be closed by anybody.
     *
     * It asks the SAME rule as the page's list (`holdingWhere()`), narrowed to
     * one row, so a line this accepts is a line that list would have shown and
     * nothing else: the posted table, column and row must be a marked column
     * of a table with an account column and a readable key, the row must still
     * hold text, it must name this person, and it must be in this request's
     * own organisation where the table records one.
     *
     * ⚠️ IT ANSWERS null FOR "NOT AN ITEM" AND FOR "COULD NOT BE READ", and it
     *    does NOT separate the two. That is on purpose — both must refuse, and
     *    a decision recorded about a line nobody could read would be a record
     *    of nothing — but it means the CALLER cannot say which happened, so
     *    `record()`'s refusal names several possible causes and asserts none.
     *
     * @return array<string, mixed>|null
     */
    private static function oneItem(
        mysqli $db,
        int $requestId,
        int $userId,
        string $table,
        string $column,
        int $rowKey
    ): ?array {
        $marked = self::markedColumns();
        $reason = (string) ($marked[$table][$column] ?? '');
        if ($reason === '') {
            return null;
        }
        $links = self::linkColumnsOf($table);
        if ($links === []) {
            return null;
        }
        $keyColumn = self::keyColumnOf($db, $table);
        if ($keyColumn === '') {
            return null;
        }
        $siteColumn = self::siteColumnOf($db, $table);
        $ignored    = [];
        $siteId     = self::siteOfRequest($db, $requestId, $ignored);
        if ($siteColumn !== '' && $siteId === 0) {
            return null;
        }

        $q      = '`' . $table . '`.';
        $select = [$q . '`' . $keyColumn . '`', $q . '`' . $column . '`'];
        foreach ($links as $link) {
            $select[] = $q . '`' . $link . '`';
        }
        $where  = self::holdingWhere($table, $links, $column, $siteColumn);
        $values = self::holdingValues($links, $userId, $siteColumn, $siteId);
        $values[] = $rowKey;

        try {
            $stmt = $db->prepare(
                'SELECT ' . implode(', ', $select) . ' FROM `' . $table . '` '
                . 'WHERE ' . $where . ' AND ' . $q . '`' . $keyColumn . '` = ? LIMIT 1'
            );
            if ($stmt === false) {
                return null;
            }
            $stmt->bind_param(str_repeat('i', count($values)), ...$values);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
        } catch (\Throwable $e) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        $matchedBy = self::matchedBy($row, $links, $userId);
        $actedOnly = true;
        foreach ($matchedBy as $matched) {
            if (in_array($matched, GdprEraser::ACTOR_COLUMNS, true) === false) {
                $actedOnly = false;
            }
        }

        return [
            'table'      => $table,
            'column'     => $column,
            'keyColumn'  => $keyColumn,
            'rowKey'     => (int) $row[0],
            'text'       => (string) $row[1],
            'reason'     => $reason,
            'matchedBy'  => $matchedBy,
            'actedOnly'  => $actedOnly,
            'oneOrgOnly' => $siteColumn !== '',
        ];
    }

    /**
     * The decisions already recorded for one request, keyed
     * `table.column.rowKey`.
     *
     * @param list<string> $problems Added to when the decisions cannot be read,
     *   because "no decisions on file" and "we could not look" must not be the
     *   same answer.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function decisionsFor(mysqli $db, int $requestId, array &$problems): array
    {
        if (self::decisionsTableExists($db) === false) {
            return [];
        }
        $out = [];
        try {
            $stmt = $db->prepare(
                'SELECT `tableName`, `columnName`, `rowKey`, `decision`, `decidedByID`, `decidedAtUtc` '
                . 'FROM `' . self::DECISIONS_TABLE . '` WHERE `requestID` = ?'
            );
            if ($stmt === false) {
                $problems[] = 'the decisions already recorded could not be read (database error '
                    . (string) $db->errno . ')';

                return [];
            }
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($row = $rs->fetch_assoc()) {
                $key = (string) $row['tableName'] . '.' . (string) $row['columnName'] . '.'
                    . (string) (int) $row['rowKey'];
                $out[$key] = [
                    'decision'    => (string) $row['decision'],
                    'decidedByID' => (int) ($row['decidedByID'] ?? 0),
                    'decidedAt'   => (string) ($row['decidedAtUtc'] ?? ''),
                ];
            }
            $stmt->close();
        } catch (\Throwable $e) {
            $problems[] = 'the decisions already recorded could not be read';

            return [];
        }

        return $out;
    }
}
