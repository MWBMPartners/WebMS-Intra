<?php
// Path: _core/personal-data-catalogue.php
/**
 * -----------------------------------------------------------------------------
 * Where personal information lives, and what happens to it \U0001F5C4
 * -----------------------------------------------------------------------------
 * One decision for every table in this portal that holds something about a
 * person. It is the single list behind BOTH of the rights somebody has over
 * their own information:
 *
 *   - asking for a COPY of everything held about them;
 *   - asking for it to be DELETED.
 *
 * Both need the same thing first: an accurate list of where that information
 * actually is. One list, used by both, so the two can never drift apart.
 *
 * WHY THIS FILE EXISTS AT ALL
 * ---------------------------
 * The list used to be remembered rather than written down. Tables were added to
 * the deletion routine as somebody happened to think of them.
 *
 * On 11 September 2026 the difference was measured. 126 tables hold something
 * personal. Seventy-seven of them were in NEITHER the deletion list nor the
 * download. Two of those were found by accident, while looking at something
 * else entirely - and the gap included a child's allergies and medical notes,
 * real mobile numbers, and criminal record check information.
 *
 * That was not a list. It was a sample.
 *
 * THE FOUR DECISIONS, AND WHY THERE ARE FOUR
 * ------------------------------------------
 * "Holds something about a person" and "must be deleted on request" are not the
 * same thing. Treating them as the same would destroy the organisation's own
 * work. So every table gets one of four answers:
 *
 *   erase         Delete the rows. For information that is ABOUT the person: a
 *                 visitor record, an event registration, a message sent to them.
 *
 *   unlink        Keep the row, cut the link to the person. For "who created
 *                 this": a song, a rota entry, an announcement, an activity log.
 *                 Deleting those would destroy the ORGANISATION'S material
 *                 because a departed volunteer happened to type it in. The
 *                 content stays; the name goes.
 *
 *   retain        Keep it, because the law requires it. Gift Aid declarations,
 *                 financial records, safeguarding records. Each carries the
 *                 reason and the period, so the organisation can show why.
 *
 *   not-personal  It only looks personal. The map position of a room. A cache of
 *                 place names. Nothing about a person at all.
 *
 * A FIFTH THING, WHICH IS NOT A FIFTH DECISION: `notEmptiedHere`
 * -------------------------------------------------------------
 * Added 2 November 2026 (#635). An entry's `columns` list names what makes the
 * table personal. A table WITHOUT a hand-written instruction, though, got one
 * instruction per ACCOUNT NUMBER column and nothing else - so a column holding
 * an address, a hand-typed name or a note was named as personal and emptied by
 * nothing. (A hand-written entry could always name extra columns; most tables
 * do not have one.) That was #626 on the activity log, and it was true of
 * thirty-five other tables.
 *
 * `notEmptiedHere` is where an entry answers, column by column, "this one is
 * not emptied by this table's instruction, and here is why that is right". The
 * reason is in ORDINARY WORDS on purpose: this is the file a request for
 * somebody's information is answered from, so a person asking why a column they
 * care about is still there should be able to read the answer.
 *
 * There are three honest reasons, and each appears below. A FOURTH state is
 * allowed only while work is in flight and must name its issue: a column that is
 * TO BE emptied, where the routine cannot yet reach it. NO LINE IS IN THAT STATE
 * TODAY. `tblCareVisit.notes` was the one, and #709 closed it by teaching the
 * routine to match rows through a PARENT table - so a visit's note is now
 * emptied through the case it belongs to. The line was then forced out of this
 * file by the check that refuses an explanation for a column that IS emptied,
 * which is exactly what that check is for: an in-flight state that nothing
 * removes becomes a permanent excuse.
 *
 *   it is deliberately KEPT    The row is the organisation's record of a thing
 *                              or a transaction, not the person's own record -
 *                              an asset loan, an invoice, an order of service.
 *                              Whoever typed the note is detached by cutting
 *                              the account link; the text is not theirs to
 *                              erase. (The owner's rule, 1 November 2026:
 *                              decide by what the ROW is.)
 *
 *   it is emptied ELSEWHERE    Something other than this table's instruction
 *                              deals with it, and the order matters. The two
 *                              profile-image columns are the example.
 *
 *   it is UNREACHABLE          The column really is personal, and the only
 *                              column an instruction could match on belongs to
 *                              a DIFFERENT person. Emptying it would delete a
 *                              third party's details from rows that have
 *                              nothing to do with the person who asked, and
 *                              still not touch anything of theirs. Several
 *                              tables are in this state and each says so. No
 *                              number is given here on purpose: a count in
 *                              prose goes stale, and the entries below are the
 *                              authority.
 *
 * A SIXTH THING, AND IT IS A MARKER RATHER THAN A REASON: `needsReview`
 * ---------------------------------------------------------------------
 * Added 3 November 2026 (#724). Some of the columns `notEmptiedHere` explains
 * are free text where the right answer depends on the request in front of you.
 * "Jane's laptop" on a licence seat is a person's name; "spare projector" on
 * the next row is not, and no rule can tell them apart. So those columns are
 * MARKED, and a person — whoever holds the `data_controller` role — looks at
 * each line and decides: empty it, or keep it.
 *
 * `needsReview` lists the column names on the entry. The REASON stays where it
 * already was, in `notEmptiedHere`, so one place still describes one column and
 * the review page shows the catalogue's own words for why no rule decides it.
 *
 * WHY A MARKER AND NOT A RULE READ OUT OF THE REASON TEXT. A reason is prose.
 * Deciding "is this one for a person to look at?" from the words of a sentence
 * would drift the first time somebody reworded one, and it would drift
 * silently. `tools/audit-checks/check_erasure_review_items.py` pins the marked
 * set BOTH WAYS against the owner's decision of 3 November 2026: a column
 * marked without that decision fails, and one of the marked columns losing its
 * marker fails too. It also fails when a marked column is declared NOT NULL,
 * because a NOT NULL column cannot be emptied and the refusal is quiet (#715).
 *
 * A MARKER IS NOT COVERAGE. It says a person will be SHOWN this column where a
 * row of it can be matched to the person asking. Where nothing on the row says
 * whose the text is — a small group's home address, a response card's subject —
 * there is no row to show and the marker is deliberately absent. The reason
 * beside such a column is the honest answer, and it has not changed.
 *
 * WHAT STOPS THIS BECOMING AN EXCUSE LIST. Three checks in
 * `tools/gdpr-coverage-selftest.php`: a reason must be long enough to be a
 * reason, an explanation for a column the entry does not name is stale and
 * fails, and an explanation for a column that IS now emptied fails - so the
 * list shrinks as the work is done. It replaced a list of 35 table names kept
 * in that test file with no reason attached to any of them.
 *
 * ONE STANDARD FOR EVERYBODY
 * --------------------------
 * Deliberately no variation by where somebody lives. Working out a person's
 * country is itself processing their personal data, it is unreliable, and two
 * sets of rules doubles the chance of a mistake in the sensitive one. The right
 * to erasure exists under UK and EU law, and now under Brazilian and Californian
 * law too. One high standard is simpler and holds up everywhere.
 *
 * KEEPING THIS HONEST
 * -------------------
 * `tools/audit-checks/check_personal_data_coverage.py` fails when a table
 * holding personal data has no decision here. That is what stops this list
 * becoming a sample again the next time somebody adds a feature.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/479
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/**
 * Every table holding personal information, and what happens to it.
 *
 * Each entry:
 *   decision  one of: erase, unlink, retain, not-personal
 *   reason    why, in plain words - shown to whoever reviews an erasure
 *   columns   the columns that made this table personal, so a reviewer can see
 *             what is actually at stake without going to the schema
 *             NAMING A PERSON COLUMN HERE MATTERS (since 11 October 2026,
 *             #492). For a table with no hand-written instruction in
 *             GdprEraser::catalogue(), every person column named here gets an
 *             instruction of its own: the first is what a delete or a "keep"
 *             matches on, and every other "who did it" column is emptied
 *             wherever it names the person. A person column left out is not
 *             looked at.
 *   period    for 'retain' only: how long, and under what obligation
 */
return [
    // =========================================================================
    // ERASE - information about the person themselves
    // =========================================================================
    // Deleted outright when somebody asks to be forgotten.
    // 91 entries in this section (counted 14 September 2026; it said 85, which
    // was already out of date, and several entries here are really 'unlink').

    'tblAiUsage' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblAnonymousCheckins' => [
        'decision' => 'retain',
        // CORRECTED by the #525 round-1 independent check, 18 September 2026:
        // this said 'erase' while the reason right below it explains why an
        // erasure request can never reach this table at all — the
        // machine-readable half was claiming something the code cannot do,
        // while the human-readable half beside it said plainly that it
        // cannot. 'retain' is the value that actually matches the reason:
        // the row is kept, not because the law requires it (the usual reason
        // for 'retain' elsewhere in this file), but because there is no
        // column to erase it BY — and it is kept only until the retention
        // timer clears the personal part of it, which 'period' below says.
        //
        // KEEP 'decision' => '...' on the line straight after the opening
        // bracket above, with nothing but whitespace between them: an
        // earlier draft of this fix put this whole comment BEFORE the
        // 'decision' line instead of after it, which reads fine to a person
        // but silently broke tools/audit-checks/check_personal_data_coverage.py
        // — its parser is a plain regex
        // ('tblXxx' => [ \s* 'decision' => '...') that reads the catalogue as
        // TEXT rather than running the PHP (deliberately, so the check needs
        // no PHP interpreter — see that script's own docstring), and `\s*`
        // does not match a `//` comment line. The check started reporting
        // this table as having "no decision recorded" even though it
        // plainly does; caught by actually running that check against this
        // file rather than only the newer gdpr-coverage-selftest.php, which
        // parses the catalogue by executing it and did not notice.
        // Honest wording, replacing "Holds information about the person
        // themselves", which was true but misleading about what can be done
        // about it. An anonymous check-in is somebody pressing a button at the
        // door without signing in. There is NO column anywhere on the row that
        // links it to an account, so a "delete everything you hold about me"
        // request can never reach one: there is nothing to match the person
        // against, and the erasure routine therefore skips this table entirely.
        // That is not a gap being ignored — since #525 the scrambled sender
        // address is emptied out on a timer instead (the setting
        // attend.detailRetentionDays, 90 days by default, set per
        // organisation at /admin/settings/attendance).
        //
        // ⚠️ "0 TO KEEP FOR EVER" USED TO BE WRITTEN HERE AND IN BOTH
        //    SENTENCES BELOW, AND #685 MADE IT UNTRUE. 0 is the LARGEST
        //    value for this setting, so since #685 it survives ONLY where
        //    the installation itself allows for ever. Measured: with the
        //    installation at 90 — which is what full_schema.sql seeds, so
        //    every fresh installation — an organisation storing 0 is cleared
        //    at 90 days. #706's own rule is that a 'period' must be checked
        //    against the purging code; #685 changed the purging code and
        //    these two sentences were not revisited. They feed inventory(),
        //    which is the "what we hold about you" answer a person receives,
        //    so an overstatement here is a promise made to a data subject.
        //    Ask AnonymousCheckins::readRetentionDays() for the real figure;
        //    the sentences below no longer name one that can go stale.
        // Nobody should have to ask for something that cannot be found by
        // asking. See issue #479.
        //
        // #530 / migration 201: a "browser description" column (what kind
        // of device the check-in came from) used to sit on this row too.
        // Nothing anywhere ever read it, before #525 or after, so it has
        // been dropped rather than left as an unused column with no stated
        // purpose. The scrambled sender address (ipHash) is the only
        // personal detail left on this table.
        'reason'   => 'Somebody checking in at the door without signing in. Nothing on the row links it to an account, '
                    . 'so an erasure request cannot reach it — there is nothing to match the person against. The '
                    . 'scrambled sender address is cleared on a timer instead '
                    . '(attend.detailRetentionDays, 90 days by default, set by the organisation and held down to '
                    . 'what this installation allows). See #479, #525, #530 and #685.',
        // Required whenever decision is 'retain' (tools/gdpr-coverage-selftest.php
        // checks every 'retain' entry has one). Not a legal minimum — this row
        // is cleared by a technical timer the organisation controls, not by law
        // — but the self-test's rule is the same either way: say for how long.
        'period'   => '90 days by default, per the organisation\'s attend.detailRetentionDays setting, held down to '
                    . 'whatever this installation allows (#685). The organisation may choose "keep for ever" only '
                    . 'where the installation allows it too; otherwise the installation\'s own period applies',
        'columns'  => ['ipHash'],
    ],
    'tblAnonymousCheckinDays' => [
        'decision' => 'not-personal',
        'reason'   => 'Counts and dates only. It exists so that clearing the personal detail on an anonymous check-in '
                    . 'does not change a number that was already correct: the "probably how many different senders" '
                    . 'figure is worked out from the scrambled sender address, so it is written down before that '
                    . 'address is emptied. Nothing here can be traced to a person, and nothing here names an event.',
        'columns'  => [],
    ],
    'tblAssetEventAssignments' => [
        'decision' => 'unlink',
        'reason'   => 'Who assigned equipment to an event. The assignment is the organisation\'s record, not the person\'s. Deleting it because a volunteer left would lose the event\'s kit list.',
        'columns'  => ['notes', 'assignedByID'],
        'notEmptiedHere' => [
            'notes' => 'The row is the organisation\'s record of a thing, not the person\'s own record, so under the owner\'s rule of 1 November 2026 the note stays. Whoever typed it is detached by cutting the account link; the text itself is not theirs to erase. An event kit list: where a piece of the organisation\'s property went and in what condition.',
        ],
    ],
    'tblAssetIdentifiers' => [
        'decision' => 'unlink',
        'reason'   => 'Barcodes and tags identifying the organisation\'s property.',
        'columns'  => ['notes', 'createdByID'],
        'notEmptiedHere' => [
            'notes' => 'The row is the organisation\'s record of a thing, not the person\'s own record, so under the owner\'s rule of 1 November 2026 the note stays. Whoever typed it is detached by cutting the account link; the text itself is not theirs to erase. A barcode, serial number or asset tag is a fact about a thing the organisation owns; the note describes the identifier.',
        ],
    ],
    'tblAssetKioskPins' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblAssetLicenseAssignments' => [
        'decision' => 'unlink',
        'reason'   => 'Who a software licence seat was allotted to. The organisation still needs to know how many seats are in use.',
        'columns'  => ['userID', 'notes', 'releasedByID', 'linkedByID', 'deviceName', 'seatLabel'],
        // 🧑‍⚖️ #724: both of these are the example the owner gave. "Jane's laptop"
        //    is a name; "spare projector" on the next row is not, and no rule
        //    can tell them apart. `notes` is NOT marked: it is the
        //    organisation's own record of a thing under the rule of
        //    1 November 2026, which is a decision already taken.
        'needsReview' => ['deviceName', 'seatLabel'],
        'notEmptiedHere' => [
            'deviceName' => 'What the device is called. In practice this is written as "Jane\'s laptop", so it '
                . 'carries a name; nothing empties it',
            'seatLabel' => 'Which seat of a licence this is, as free text. Used the same way as the device name',
            'notes' => 'The row is the organisation\'s record of a thing, not the person\'s own record, so under the owner\'s rule of 1 November 2026 the note stays. Whoever typed it is detached by cutting the account link; the text itself is not theirs to erase. A software licence seat and what it is used for.',
        ],
    ],
    'tblAssetLoans' => [
        'decision' => 'unlink',
        'reason'   => 'A loan of a piece of equipment, in or out. Deleting the loan would erase the item\'s history, and the '
                    . 'organisation still needs to know where its property went. So on erasure the loan is kept — which item, '
                    . 'when it went out, when it came back — and what goes is the borrower\'s account link, the free-text name '
                    . 'recorded for them, and the telephone number or e-mail address typed into the loan\'s "Contact details" '
                    . 'box (#492 part 2, 3 November 2026). Where the borrower is somebody OUTSIDE the organisation nothing on '
                    . 'the row links it to an account, so an erasure request cannot find it at all and an administrator clears '
                    . 'those details on the loan instead. Detaching the row because the person who APPROVED or ASKED FOR the '
                    . 'loan has left would take away the borrower\'s own details on somebody else\'s record, so those two links '
                    . 'are cut on their own and nothing else on the row is touched. One limit, recorded in the erasure '
                    . 'instruction\'s own reason because it cannot be recorded here as well: a loan names its counterparty by '
                    . 'either an account or a typed name and never both, so emptying the typed name on a row found through the '
                    . 'account can never actually do anything, and it is named only so that a future change filling both in '
                    . 'would already be reached.',
        'columns'  => ['notes', 'counterpartyUserID', 'approvedByID', 'requestedByID', 'counterpartyName', 'counterpartyContact', 'declineReason', 'conditionOutNotes', 'conditionInNotes'],
        // 📞 `counterpartyContact`'s REASON WAS UNTRUE OF THE COMMONEST CASE,
        //    and the cure was not a better sentence — it was the owner's
        //    decision of 3 November 2026 (#492 part 2) that the borrower is
        //    the person a loan record is about. The old reason read "they need
        //    not have an account, so nothing links the row to one and an
        //    erasure request cannot reach it", which is right for a loan to an
        //    outside person and WRONG for a loan to a member: the loan form
        //    shows that box whichever kind of borrower is chosen, so it is
        //    routinely a member's own telephone number on a row that names
        //    their account — a row an erasure does reach. Both columns are
        //    emptied now by the `counterpartyUserID` instruction in
        //    GdprEraser, so neither may be explained here as not-emptied: the
        //    #635 check fails a column given a reason while an instruction
        //    empties it, because the two mechanisms cannot both describe one
        //    column. `counterpartyName` is the one that proved it: this entry
        //    explained it AND the instruction empties it, and the check went
        //    red on the first run of this change. Its limit is recorded in the
        //    instruction's reason and in this entry's own `reason` above,
        //    which is the one a data subject reads.
        'notEmptiedHere' => [
            'declineReason' => 'Why a request to borrow something was refused. The organisation\'s own reasoning about '
                . 'its own property',
            'conditionOutNotes' => 'What condition an item was in when it went out. About the item',
            'conditionInNotes' => 'What condition it was in when it came back. About the item',
            'notes' => 'The row is the organisation\'s record of a thing, not the person\'s own record, so under the owner\'s rule of 1 November 2026 the note stays. Whoever typed it is detached by cutting the account link; the text itself is not theirs to erase. The organisation\'s record of lending its property out, kept as the loan\'s own history.',
        ],
    ],
    'tblAssetOrgs' => [
        'decision' => 'retain',
        // #535, 11 October 2026. This said 'erase' with the stock reason "Holds information about the person themselves",
        // and the erasure routine did nothing at all to the table, without a
        // word in the audit trail: none of the columns listed ties a row to an
        // account, so there was nothing to find the person by. The
        // machine-readable half promised something the code cannot do. It now
        // follows the tblAnonymousCheckins precedent above (#525): 'retain',
        // because the row IS kept, with the true reason and a 'period' that
        // does not pretend there is a time limit. Searched on that day: no
        // scheduled job and no page clears this table by age. Choosing a time
        // limit is a separate decision for the owner, not made here.
        'reason'   => 'An outside organisation that lends or hires equipment, with the name, email address and '
                    . 'telephone number of its contact. That contact is somebody outside the portal: nothing on the '
                    . 'row links it to an account, so an erasure request cannot reach it. An administrator can edit '
                    . 'or clear the contact on the organisation\'s page in the asset register',
        'period'   => 'as long as the organisation keeps the row: nothing clears it on a timer today',
        'columns'  => ['contactName', 'contactEmail', 'contactPhone', 'notes'],
    ],
    'tblAssetOwners' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'notes'],
    ],
    'tblAssetStocktakeItems' => [
        // #708, 2 November 2026. This used to name a `notes` column and
        // explain, at length, why a note on a stocktake line is kept rather
        // than emptied on an erasure request. Migration 235 DROPPED that
        // column: nothing in the portal had ever written to it since the
        // table was created (4764c06), so the explanation was answering a
        // question nobody could ask, and two such paragraphs made the
        // erasure coverage read wider than it is.
        //
        // `scannedByID` was ADDED by the same edit, not left behind by it, and
        // that is the more useful half: before this, the ONLY column this
        // entry named was the dead one, so an entry saying "unlink" named
        // nothing an erasure could unlink. The column it now names is the one
        // that really does tie a row here to a person and the one
        // `GdprEraser`'s instruction really acts on.
        'decision' => 'unlink',
        'reason'   => 'An item counted during a stocktake. An audit record; deleting it would leave a gap in the count.',
        'columns'  => ['scannedByID'],
    ],
    'tblAssetStocktakes' => [
        // #708, 2 November 2026. A dead `notes` column was dropped from this
        // table too (migration 235) for the reason written beside
        // tblAssetStocktakeItems above. `closedByID` is named alongside
        // `startedByID` because a run records TWO people and an erasure
        // detaches both -- the entry named only one of them, which read as
        // though the other were somebody else's business. Half of what it
        // acts on, where the sibling entry above named NONE of it: the same
        // fault, two sizes, found by writing the columns down rather than by
        // reading the code.
        'decision' => 'unlink',
        'reason'   => 'A stocktake of the organisation\'s property - an audit record.',
        'columns'  => ['startedByID', 'closedByID'],
    ],
    'tblAttendanceCounts' => [
        'decision' => 'not-personal',
        // #535, 11 October 2026. This said 'erase', and the erasure routine did
        // nothing to it, silently. It was listed because of its `sessionID`
        // column, a name that elsewhere means a browser session. Here it is the
        // number of an ATTENDANCE session (tblAttendanceSessions): which service
        // the count belongs to. The row is a group label and a head count.
        'reason'   => 'Head counts only: how many adults, children or visitors were counted at one service. '
                    . 'Its sessionID is the number of the attendance session, not of anybody\'s browser session, '
                    . 'and nothing on the row is about a person.',
        'columns'  => ['sessionID'],
    ],
    'tblAttendanceSessions' => [
        'decision' => 'unlink',
        'reason'   => 'An attendance session. Deleting it would destroy the record of who came, for everybody who came.',
        'columns'  => ['notes', 'createdByID', 'updatedByID'],
        'notEmptiedHere' => [
            'notes' => 'The row is the organisation\'s record of a thing, not the person\'s own record, so under the owner\'s rule of 1 November 2026 the note stays. Whoever typed it is detached by cutting the account link; the text itself is not theirs to erase. A note about the gathering itself, not about anybody who came to it.',
        ],
    ],
    'tblConsentLog' => [
        'decision' => 'erase',
        // Its sessionID has been a FINGERPRINT of the browser session's
        // identifier since #622, as in tblActivityLogs below: it says which
        // session decided, and is no use for signing in. (Kept BELOW the
        // 'decision' line: check_personal_data_coverage.py reads an entry's
        // decision from the line straight after its name.)
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'sessionID', 'ipAddress', 'userAgent'],
    ],
    'tblCountEnvelopes' => [
        'decision' => 'retain',
        // Roll-out package A2b. This table had no entry at all, so the giver
        // of a counted envelope stayed on it for ever.
        //
        // 'retain', like the gift table, and for the same reason: the
        // erasure's giving-records step (GdprEraser::unnameCountEnvelopes())
        // takes the giver off every envelope EXCEPT one in a count that is
        // not yet closed whose gift will keep the donor's name. This said
        // 'unlink' for a few hours, and every envelope lost its giver: the
        // gift made when such a count was closed then had no donor and
        // dropped out of the tax claim.
        'reason'   => 'One named envelope on an offering count sheet. The sheet is a financial control record and stays with its amount. Who the envelope was from is removed at once, unless the count is not yet closed and the gift it will become is one that keeps the donor\'s name; then the giver stays until the organisation\'s keep-until date, like that gift.',
        'period'   => 'set by each organisation, in years after the end of the financial year of the donor\'s last kept gift: Admin, Settings, Giving records',
        'columns'  => ['giverID', 'giverName'],
    ],
    'tblCountSessions' => [
        'decision' => 'unlink',
        'reason'   => 'A two-person offering count - a FINANCIAL control record, deliberately requiring two people. Deleting it on one person\'s request defeats the whole point of it.',
        'columns'  => ['notes', 'createdByID', 'counter1ID', 'counter2ID', 'closedByID'],
        'notEmptiedHere' => [
            'notes' => 'The row is the organisation\'s record of a thing, not the person\'s own record, so under the owner\'s rule of 1 November 2026 the note stays. Whoever typed it is detached by cutting the account link; the text itself is not theirs to erase. A note about a counting session: the organisation\'s own figure for how many were present.',
        ],
    ],
    'tblDecisionMoments' => [
        'decision' => 'unlink',
        'reason'   => 'Who wrote the entry down, not who it is about. Deleting it would destroy somebody else\'s record because the person holding the pen asked to be forgotten.',
        'columns'  => ['notes', 'recordedByID'],
        'notEmptiedHere' => [
            'notes' => 'The row is the organisation\'s record of a thing, not the person\'s own record, so under the owner\'s rule of 1 November 2026 the note stays. Whoever typed it is detached by cutting the account link; the text itself is not theirs to erase. This row has no human subject at all: it is a running tally keyed on the event and the kind of response.',
        ],
    ],
    // 🧪 The Demo Data page's list of the rows it created (#498).
    //
    //    This said "not personal" until 14 September 2026, on the grounds that
    //    an entry holds only a table name, a row number, an organisation
    //    number and a one-way fingerprint. Codex pointed out that this was too
    //    broad. An entry naming tblUsers and a number points straight at an
    //    account, and a made-up demo person can be edited into a real one and
    //    kept. A number that identifies somebody is personal data even with no
    //    name beside it.
    //
    //    So an entry pointing at a person's own account (tableName 'tblUsers')
    //    or at one of their memberships is deleted when that person is erased,
    //    and included in their data export. Since #717 "memberships" means all
    //    four audited tables - 'tblUserSites', 'tblUserDepts', 'tblUserGroups'
    //    and 'tblUserRoles' - and section 21 of
    //    `tools/gdpr-coverage-selftest.php` compares that set against the set
    //    anything audits, so a fifth cannot be added without being noticed.
    //    Entries for announcements are not about a person, and are left alone.
    //
    //    The generic handling that reads this list CANNOT do that. It matches
    //    one column against the person's number, and here rowID only means
    //    "this person" together with tableName. Matching rowID alone would
    //    also remove, say, the entry for announcement number 7 when person
    //    number 7 is erased. Neither column below is a name GdprEraser treats
    //    as a link, so the generic handling skips this table rather than
    //    guessing. The work is done by a dedicated step instead,
    //    GdprEraser::eraseDemoDataRegisterEntries(), and by the
    //    'demoDataRegister' block in web/_apps/auth/account/data-export.php.
    //    tools/gdpr-coverage-selftest.php fails if either is missing.
    'tblDemoDataRegister' => [
        'decision' => 'erase',
        'reason'   => 'The Demo Data page\'s list of rows it created. An entry naming'
                    . ' this person\'s account or one of their memberships points at'
                    . ' them, so it is deleted and included in their data export.'
                    . ' Entries for announcements are not about a person and are kept.',
        'columns'  => ['tableName', 'rowID'],
    ],
    'tblEmailLog' => [
        'decision' => 'unlink',
        // #592, 13 October 2026. The send log has always been dealt with on
        // an erasure, by a step of its own (GdprEraser::eraseEmailLogRecipients()),
        // and had no entry here. So this list did not know the table held
        // anybody's address, and the check that guards this list could not
        // see it either (the column is a list of addresses, `toRecipients`,
        // a name that check did not know). Both are put right together.
        'reason'   => 'The record that a message was sent, or tried. The person\'s address is taken out of its list of recipients; the row itself (which route, what became of it, the subject, when) is the organisation\'s own record and is kept',
        'columns'  => ['toRecipients', 'fromAddress', 'subject'],
            'notEmptiedHere' => [
            'fromAddress' => 'The address the message was sent FROM, which is the organisation\'s own sending '
                . 'mailbox, not a member\'s. The recipient\'s address IS taken out (see `toRecipients`)',
            'subject' => 'The subject line of the message. The organisation\'s own wording, from its own '
                . 'templates — but a subject built from a person\'s name would keep it, and nothing '
                . 'empties this',
        ],
    ],
    'tblErasureRequest' => [
        'decision' => 'unlink',
        // 🛑 #510, 11 October 2026. This said 'erase', and that one word broke
        //    every erasure. The table walk deleted the request it was in the
        //    middle of carrying out; the database deleted the request's audit
        //    lines with it; the next audit line had no request to belong to;
        //    and the run crashed with the person half erased and no record of
        //    what had been done.
        //
        //    The request is the organisation's record that an erasure was
        //    asked for and carried out, so the row and its audit lines stay.
        //    What goes is what identifies the person, and it goes LAST, once
        //    every other step has succeeded.
        //
        //    The table walk does NOT do this, on purpose: this table is in
        //    GdprEraser::FINAL_STEP_TABLES and GdprEraser::closeRequest() does
        //    the work. tools/gdpr-coverage-selftest.php fails if any
        //    instruction ever deletes from this table or from tblErasureAudit.
        //
        //    `processedByID` (the administrator who handled a request) is
        //    cleared by the same last step when that administrator is the
        //    person being erased. Until 11 October 2026 nothing cleared it.
        'reason'   => 'The organisation\'s record that an erasure was asked for, when, and that it was done. The '
                    . 'request, its dates, its outcome and its audit lines are kept. The account number, name, email '
                    . 'address and notes are cleared from it as the last step, once everything else has succeeded. '
                    . 'On other people\'s requests that this person handled as an administrator, only who handled '
                    . 'it is cleared.',
        'columns'  => ['userID', 'subjectEmail', 'subjectName', 'notes', 'processedByID'],
    ],
    'tblErasureReviewDecisions' => [
        'decision' => 'unlink',
        // 🧑‍⚖️ #724, migration 242. One row per line of free text somebody
        //    LOOKED AT and decided about: which table, which column, which
        //    row, and which of the two answers. It holds NO TEXT — not the
        //    words that were emptied, not a typed reason, nothing. That is the
        //    #710 rule: a box asking somebody to describe what they did invites
        //    them to quote the words being erased into a record.
        //
        //    'unlink' follows the precedent one entry up: when the person who
        //    DECIDED later asks to be erased, their number comes off the row
        //    and the decision itself stays, exactly as `processedByID` on a
        //    request is cleared while the request stays. The row is the
        //    organisation's record that the judgement was made.
        //
        //    ⚠️ NOT in `GdprEraser::DEDICATED_STEP_TABLES` and not hand-written:
        //       the derived route gives it one instruction on `decidedByID`,
        //       which is all it needs. And NOT absent from this file, which is
        //       what `tblErasureAudit` is (#510 rule 7d) — that is the SEALED
        //       trail; this is a working record, and a working record with no
        //       decision beside it is the #706 fault.
        'reason'   => 'One line of free text a person looked at during an erasure, and which of the two answers '
                    . 'they gave: empty it, or keep it. It holds no text at all — only which row was judged, '
                    . 'what was decided and who decided. The decision is the organisation\'s record that '
                    . 'somebody took it, so the row stays; when the person who decided later asks to be erased, '
                    . 'their account number comes off it.',
        'columns'  => ['decidedByID'],
    ],
    'tblEventAttendance' => [
        'decision' => 'unlink',
        'reason'   => 'Attendance at an event. The record of who was there matters to the organisation, so the row stays and the name goes.',
        'columns'  => ['userID', 'notes', 'markedByID', 'walkinName'],
            'notEmptiedHere' => [
            'walkinName' => 'A name typed in for somebody with no account, so that they can be counted. The note IS '
                . 'emptied and the account link cut, but this name is not: there is no account to match '
                . 'it to, which is the whole reason it exists. Deliberately kept EMPTY where the person '
                . 'is known by their account instead (#487)',
        ],
    ],
    'tblEventCoordinators' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'grantedByID'],
    ],
    'tblEventCrewMembers' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'externalName'],
            'notEmptiedHere' => [
            'externalName' => 'A name typed in for somebody who is NOT a portal user - the column\'s own schema '
                . 'comment says so. Listing it under a table whose rows are DELETED reads as though it '
                . 'goes, and it does not: the delete matches on the account number, and a row naming an '
                . 'outside person has none. So it is as unreachable as a walk-in\'s name on an attendance '
                . 'register, and for the same reason. An administrator can clear it on the event',
        ],
    ],
    'tblEventJobAssignments' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'externalName'],
            'notEmptiedHere' => [
            'externalName' => 'A name typed in for somebody who is NOT a portal user - the column\'s own schema '
                . 'comment says so. Listing it under a table whose rows are DELETED reads as though it '
                . 'goes, and it does not: the delete matches on the account number, and a row naming an '
                . 'outside person has none. So it is as unreachable as a walk-in\'s name on an attendance '
                . 'register, and for the same reason. An administrator can clear it on the event',
        ],
    ],
    'tblEventOccurrenceOverrides' => [
        'decision' => 'unlink',
        'reason'   => 'A change to one date of a repeating event. Deleting it would silently move the event back to its original time for everybody.',
        'columns'  => ['notes', 'createdByID'],
        'notEmptiedHere' => [
            'notes' => 'The row is the organisation\'s record of a thing, not the person\'s own record, so under the owner\'s rule of 1 November 2026 the note stays. Whoever typed it is detached by cutting the account link; the text itself is not theirs to erase. A note about one date of a repeating event.',
        ],
    ],
    'tblEventPeople' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'externalName'],
            'notEmptiedHere' => [
            'externalName' => 'A name typed in for somebody who is NOT a portal user - the column\'s own schema '
                . 'comment says so. Listing it under a table whose rows are DELETED reads as though it '
                . 'goes, and it does not: the delete matches on the account number, and a row naming an '
                . 'outside person has none. So it is as unreachable as a walk-in\'s name on an attendance '
                . 'register, and for the same reason. An administrator can clear it on the event',
        ],
    ],
    'tblEventRSVPInvites' => [
        'decision' => 'unlink',
        'reason'   => 'Invitations sent for an event. They concern the people invited, not the person who sent them.',
        'columns'  => ['email', 'displayName', 'createdByID'],
        'notEmptiedHere' => [
            'email' => 'PERSONAL, AND UNREACHABLE. The only account column is the COORDINATOR who sent the invitation, so emptying here would delete a non-member invitee\'s e-mail address from every invitation that coordinator ever sent, and would not touch the erased person\'s own invitation. Kept with the limit recorded (the owner, 1 November 2026).',
            'displayName' => 'PERSONAL, AND UNREACHABLE, for the same reason as email.',
        ],
    ],
    'tblEventRSVPs' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    // 📝 WHOSE RECORD A REGISTRATION IS (#492 part 3, the owner, 3 November
    //    2026, migration 246). The row is deleted on an erasure request, which
    //    is right — it holds a child's name, date of birth, allergies and
    //    medical notes. What was wrong was WHICH column found it:
    //    `submittedByUserID` records only whoever was signed in when the form
    //    was sent, and nothing tested their relationship to the child, so
    //    erasing a volunteer who had registered somebody else's child deleted
    //    that child's registration. `registeredForUserID` now says whose
    //    record it is, the delete goes on that, and the submitter is merely
    //    detached.
    'tblEventRegistrations' => [
        'decision' => 'erase',
        'reason'   => 'A registration for an event, holding the registered person\'s own details — their name, date of birth, '
                    . 'allergies and medical notes, with a parent or guardian\'s telephone number and e-mail address beside '
                    . 'them. Where the registration is the person\'s own the whole row is deleted on request. Where they only '
                    . 'TYPED IT IN for somebody else, or only reviewed it, the row stays with the registered person\'s details '
                    . 'and their own link to it is cut: it is not their record to erase. Registrations made by somebody with no '
                    . 'account cannot be found by an erasure request at all, and are deleted on a timer instead — see '
                    . '`registrationRetentionDays` on the event',
        'columns'  => ['fullName', 'dateOfBirth', 'gender', 'allergies', 'medicalNotes', 'parentName', 'parentPhone', 'parentEmail', 'emergencyContactName', 'emergencyContactPhone', 'registeredForUserID', 'submittedByUserID', 'reviewedByID', 'notes'],
    ],
    // 🛑 tblExpenseClaimApprovals said "delete" until 11 September 2026, and
    //    the column it matched on is named userID - which everywhere else in
    //    this portal means "the person this record is about". Here it does not.
    //    The column's own description in the schema says so: "the approver".
    //
    //    So an approver asking to be forgotten would have deleted the approval
    //    history of every claim they had ever signed off - destroying the audit
    //    trail of a FINANCIAL decision, belonging to the claimant, on somebody
    //    else's request.
    //
    //    Nothing based on column NAMES could have caught this. It was found by
    //    reading what the schema says the column actually means.
    'tblExpenseClaimApprovals' => [
        'decision' => 'unlink',
        'reason'   => 'Who approved an expense claim. The approval belongs to the claim and its audit trail, not to the approver, so it is kept and the approver\'s name is removed.',
        'columns'  => ['userID'],
    ],
    'tblExternalApprovalNotices' => [
        'decision' => 'erase',
        // #514 part 9. One row for each administrator in each organisation:
        // the day they were last sent the daily e-mail about imported events
        // waiting for approval, and the newest waiting event they were sent
        // a push about. It is the scheduled job's memory of who has been
        // told, so that nobody is told twice. It holds no text: no title, no
        // address, no name.
        //
        // No hand-written step in GdprEraser is needed: an entry that says
        // "erase" and names `userID` gets a delete of its own (see the note
        // at the top of this list). Deleting the account would remove the
        // row anyway (the link cascades); the entry makes an erasure that
        // KEEPS the account row remove it too.
        'reason'   => 'A record that this administrator was told about imported events waiting for approval. It is about them, and of no use once they are gone',
        'columns'  => ['userID'],
    ],
    'tblExternalAudienceMembers' => [
        'decision' => 'erase',
        // #514 part P1. A "person" row names somebody as allowed to see an
        // outside calendar's events (or, in a later part, one date or a
        // rule), so it is about them and goes. Rows naming a small group, a
        // leadership role, a role, a user group or a department carry no
        // userID and are not about any one person, so they stay. The same
        // shape as the tblUserGroups entry further down: createdByID (who
        // added the entry) is a separate, hand-written instruction in
        // GdprEraser::catalogue() that only empties the name, because an
        // entry somebody ELSE is on must not vanish because the person who
        // typed it in asked to be forgotten.
        'reason'   => "Names a person as allowed to see an outside calendar's events. The table also carries createdByID, saying who added the entry; that is a separate instruction (#514), and it only removes the name.",
        'columns'  => ['userID', 'createdByID'],
    ],
    'tblFormResponses' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['submitterID', 'submitterIP'],
    ],
    'tblGiftAidDeclaration' => [
        'decision' => 'retain',
        // 🔒 Changed from 'erase' on 11 October 2026, on Salem874's decision
        //    (roll-out decision 8). Deleting a declaration when the donor
        //    asked to be forgotten destroyed the evidence for tax the
        //    organisation had already reclaimed. The file header of this very
        //    list names Gift Aid declarations as the first example of
        //    something to KEEP; this entry said the opposite.
        //
        //    HOW LONG is each organisation's own setting since roll-out
        //    package A2b. This entry used to say "six years" and name the
        //    United Kingdom's tax office, as if that were true of every
        //    customer, and to warn that nothing ever removed a declaration's
        //    details. The retention job now empties the address, postcode,
        //    signature and notes when the organisation's keep-until date has
        //    passed (GdprEraser::clearKeptGivingIdentity()). The row itself,
        //    its dates and its account number stay.
        //
        //    The erasure's audit trail records each of these as kept on
        //    purpose, with this reason. tools/gdpr-coverage-selftest.php and
        //    tools/gdpr-eraser-selftest.php both fail if it is ever set back.
        'reason'   => 'A tax declaration is the organisation\'s evidence for tax relief it has claimed on a donor\'s '
                    . 'gifts. Kept unchanged, with the donor\'s name and home address held beside it, until the '
                    . 'organisation\'s keep-until date; its address and notes are then emptied',
        'period'   => 'set by each organisation, in years after the end of the financial year of the donor\'s last kept gift: Admin, Settings, Giving records',
        'columns'  => ['donorID', 'address', 'postcode', 'notes', 'acceptedIP'],
            'notEmptiedHere' => [
            'acceptedIP' => 'The network address the declaration was made from, kept as part of the evidence that '
                . 'it was made. The whole declaration is kept because HMRC requires it, and the address '
                . 'is part of what makes it stand up',
        ],
    ],
    'tblGivingEntry' => [
        'decision' => 'retain',
        // (`'decision'` stays on the line straight after the opening one:
        // tools/audit-checks/check_personal_data_coverage.py reads it there.)
        // 🧾 'retain' since roll-out package A2b (it said 'unlink'). The
        //    gifts an organisation keeps identified for tax reasons keep the
        //    donor's account number until its keep-until date; every OTHER
        //    gift of the person's loses it at once, in the erasure's
        //    giving-records step (GdprEraser::keepGivingRecords()), which
        //    runs before the table walk. No gift is ever deleted and no
        //    amount, date or method changes. Who typed a gift in
        //    (`recordedByID`) is still detached, by an instruction of its own.
        'reason'   => 'A contribution record. The gift always stays, with its amount, date and method. Gifts with a tax declaration, receipt or statement (or every gift, if the organisation has chosen that) keep the donor\'s name until the organisation\'s keep-until date; every other gift loses the donor\'s name at once. If the gift was given towards a pledge, the pledge is deleted and the gift loses only its link to it.',
        'period'   => 'set by each organisation, in years after the end of the financial year of the donor\'s last kept gift: Admin, Settings, Giving records',
        'columns'  => ['donorID', 'donorName', 'notes', 'recordedByID'],
    ],
    'tblGivingKeptDonors' => [
        'decision' => 'retain',
        // Roll-out package A2b. Written BY an erasure, never erased by one:
        // it is where a donor's name and home address are held, for tax
        // reasons, once their account has been emptied. The retention job
        // empties the name, address and postcode when the organisation's
        // keep-until date has passed, and the row stays as the record that
        // it happened.
        'reason'   => 'The name and home address of a donor who asked to be erased, kept only for the giving records their organisation must keep for tax reasons',
        'period'   => 'set by each organisation, in years after the end of the financial year of the donor\'s last kept gift: Admin, Settings, Giving records. Never cleared before an administrator has confirmed that number, or while a hold is on',
        'columns'  => ['donorID', 'fullName', 'address', 'postcode'],
    ],
    'tblInvitation' => [
        'decision' => 'erase',
        // Salem874's decision of 12 October 2026. This said 'unlink' and only
        // ever cleared who SENT an invitation. An invitation the erased
        // person ACCEPTED was left exactly as it was: it holds their own
        // email address (`email`, which may not be empty), tied to their
        // account by `acceptedByID`. That one is deleted now. `acceptedByID`
        // is the first of this table's person columns on GdprEraser's list,
        // so it is the one the delete matches on; the other two below are
        // "who did it" columns and are emptied on OTHER people's invitations.
        //
        // What erasure does NOT do: an invitation sent to the person's
        // address that they never accepted is tied to no account, so it
        // cannot be found.
        'reason'   => 'An invitation to join. One this person ACCEPTED holds their own email address and is deleted with them. One they only SENT to somebody else, or revoked, is kept, with who sent or revoked it detached.',
        'columns'  => ['email', 'acceptedByID', 'createdByID', 'revokedByID'],
    ],
    'tblKidProfiles' => [
        'decision' => 'unlink',
        'reason'   => 'A CHILD\'S record, reached through the parent. The parent\'s right to erasure is their own and not their child\'s, and a child in current safeguarding arrangements must not disappear because an adult closed their account. The parent link is emptied; the child\'s record stays, and is covered by the time limit instead.',
        'columns'  => ['fullName', 'dateOfBirth', 'allergies', 'medicalNotes', 'parentUserID', 'pickupAuthorisedNames'],
        'notEmptiedHere' => [
            'pickupAuthorisedNames' => 'The adults allowed to collect this child, by name. Third parties who may well have no '
                . 'account here, recorded for the child\'s safety, and kept for the same reason the rest '
                . 'of the profile is: the organisation\'s duty of care does not end because one parent '
                . 'asked to be erased',
            'fullName' => 'A child\'s own record, kept deliberately (the owner, 1 November 2026): the guardian link is cut and the child\'s details stay. The child is not the person asking, and this table holds exactly ONE guardian column, so "another guardian still has access" is not a state this schema can represent.',
            'dateOfBirth' => 'Kept with the child\'s record. See fullName.',
            'allergies' => 'Kept deliberately, and this is the reason the whole row is kept: allergies are safety-critical for a child who still attends, and destroying them because an adult closed their account could put that child at risk.',
            'medicalNotes' => 'Kept for the same reason as allergies. (pickupAuthorisedNames on this table names other adults and is in no entry at all - #706.)',
        ],
    ],
    'tblLeadershipAssignments' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'personName', 'personEmail', 'notes', 'createdByID', 'updatedByID'],
    ],
    'tblLinkedAccounts' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'providerEmail'],
    ],
    'tblLiveChatMessages' => [
        'decision' => 'unlink',
        // #535, 11 October 2026. This said 'erase' and listed only the typed
        // display name, so the erasure routine had no column to find anybody
        // by and did nothing, silently. The one link to an account on this
        // table is `moderatedByID`: the administrator who approved or hid a
        // message. That is somebody acting on another person's message, so
        // the message stays and their number goes.
        //
        // WHAT ERASURE CANNOT FIND: the SENDER. A message is posted without
        // signing in, under a name the sender types, with a random session
        // token and the address it came from. Nothing ties it to an account,
        // so a request to be forgotten cannot find a person's own messages.
        // Nothing clears them on a timer either (searched 11 October 2026).
        'reason'   => 'A message in a live-stream chat. The only link to an account is which administrator moderated '
                    . 'it, so the message stays and that number goes. A sender\'s own messages cannot be found by an '
                    . 'account: they are posted without signing in, under a typed name, and nothing links them to '
                    . 'anybody.',
        'columns'  => ['displayName', 'senderIP', 'moderatedByID'],
        'notEmptiedHere' => [
            'displayName' => 'PERSONAL, AND UNREACHABLE - more so than it first looks. A chat sender has NO account at all: they are identified by `sessionToken`, which the schema calls an anonymous identity, and they type this name themselves. The table\'s only link to an account is `moderatedByID`, the MODERATOR, so an instruction that emptied this would blank the name a visitor typed from every message the erased moderator ever handled, while reaching nothing of the erased person\'s own. The owner chose on 1 November 2026 to keep it and record the limit rather than delete somebody else\'s data.',
            'senderIP' => 'PERSONAL, AND UNREACHABLE, for the same reason as displayName: the address belongs to whoever sent the message, who has no account, and the only account column on the row is the moderator\'s. Its own schema comment says it is kept for moderation audit.',
        ],
    ],
    'tblLocalAccounts' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblMailQueue' => [
        'decision' => 'erase',
        // #592. No account number ties a waiting message to a person: they
        // are in it as an e-mail address, in a list. So it is erased by a
        // step of its own (GdprEraser::eraseMailQueueMessages()), which
        // finds the person by address.
        'reason'   => 'Mail waiting to be sent. A message for this person alone is deleted, with its attached files; in a message that is also for other people their address is taken off the list, so it is not sent to them',
        // `origin` is here because, for a password reset, it holds the
        // person's account number ('reset:5'). Such a message is for that
        // person alone, so the step deletes the whole row.
        'columns'  => ['toRecipients', 'subject', 'bodyHtml', 'bodyText', 'lastError', 'origin'],
    ],
    'tblMailQueueFiles' => [
        'decision' => 'erase',
        'reason'   => 'Files attached to mail waiting to be sent (a giving statement, for one). Deleted with the waiting message they belong to, by the same step; the stored copy on disk goes too',
        'columns'  => ['storedName', 'realName'],
    ],
    'tblNewsletterRecipient' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'emailAddress'],
    ],
    'tblNewsletterSubscription' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblNoticeboardUploads' => [
        'decision' => 'unlink',
        'reason'   => 'Posters and media on the notice board. The organisation\'s material.',
        'columns'  => ['createdByID', 'storedName'],
            'notEmptiedHere' => [
            'storedName' => 'The name a noticeboard file is stored under. It is NEVER derived from what the '
                . 'uploader sent: the schema\'s own comment says so, and it is a random sixteen-byte value '
                . 'with the file\'s extension. It is on the list of personal column names because a stored '
                . 'file name usually IS taken from the uploader\'s own, which is why it is named here with '
                . 'the reason it is not',
        ],
    ],
    'tblNoticeboardFoyer' => [
        'decision' => 'not-personal',
        'reason'   => 'The foyer slideshow\'s secret link, one per organisation (#493 P2b): an organisation, a random '
                    . 'token and the time it was made. Who made or replaced it is recorded in the activity log, not here.',
        'columns'  => [],
    ],
    'tblPublicHosts' => [
        'decision' => 'unlink',
        'reason'   => 'Each organisation\'s optional public address for its public pages (#493 P3, D-095): an '
                    . 'organisation, a host name such as events.example.org, the moment it was set, and — since D-101 '
                    . '(#493 P4a) — the moment and the account of the installation\'s global administrator who approved '
                    . 'it. The address is the organisation\'s, so the row stays; only the approver\'s link to their '
                    . 'account is emptied when they are erased (the approval itself stands, and who approved is also in '
                    . 'the activity log).',
        'columns'  => ['approvedByID'],
    ],
    'tblPublicDoorNames' => [
        'decision' => 'not-personal',
        'reason'   => 'Each organisation\'s public door name (#493 P4a, D-097): an organisation, a random 24-character '
                    . 'name the portal made, and the moment it was set. It names no person; who turned the door on, '
                    . 'replaced its name or switched it off is recorded in the activity log, not here.',
        'columns'  => [],
    ],
    'tblOffboarding' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'rehiredByID', 'offboardedByID'],
    ],
    'tblPasswordResets' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'createdIP'],
    ],
    'tblPathwayEnrolments' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'enrolledByID'],
    ],
    'tblPathwayProgress' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'notes', 'markedByID', 'revokedByID'],
    ],
    'tblPayment' => [
        'decision' => 'unlink',
        'reason'   => 'A payment record. Financial records must be kept, so the payment stays and the payer\'s name is removed.',
        'columns'  => ['userID'],
    ],
    'tblPaymentMethod' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblPledges' => [
        'decision' => 'erase',
        // Deleting a pledge empties `pledgeID` on the gifts given towards it
        // (`fk_ge_pledge`, ON DELETE SET NULL). Kept as it is, on purpose: the
        // gift keeps its campaign, amount, date and method, so no total
        // changes. Written down on 11 October 2026 because the reasons here
        // did not mention it.
        'reason'   => 'A promise to give: about the person themselves, so it is deleted. The gifts already given towards it are kept; they lose only the link to the pledge, and keep their campaign, amount and date.',
        'columns'  => ['userID'],
    ],
    'tblPrayerRequests' => [
        'decision' => 'unlink',
        'reason'   => 'A prayer request. The request itself is kept for the people praying; the name and contact details are removed.',
        'columns'  => ['submitterID', 'moderatorID', 'assignedToUserID', 'submitterName', 'submitterEmail', 'submitterIP', 'subject', 'body', 'partnerNote'],
        // 🙏 #715, the owner's decision of 3 November 2026. `subject`, `body` and
        //    `partnerNote` USED to be explained here as not emptied. They are
        //    emptied now, on the submitter's own instruction, so their
        //    explanations are gone rather than reworded — a reason for a column
        //    the routine does empty is an untruth about somebody's own data,
        //    and the coverage test fails on one.
        //
        //    What was hard to defend was the half-measure: the portal emptied
        //    their name, e-mail and network address from the very same row and
        //    left what they had said about themselves.
        //
        // 🙏 #1009, D-176. A request sent through the ANONYMOUS form has no
        //    account (`submitterID` is empty, even for a member who was signed
        //    in: #988), so the instruction above can never reach it. A request
        //    filed by the visitor's e-mail address does instead
        //    (GdprEraser::executeVisitorAddress(), its prayer step), and
        //    empties the SAME columns (GdprEraser::VISITOR_PRAYER_NULL_COLS;
        //    tools/gdpr-coverage-selftest.php fails if the two lists differ).
        //    A request sent with no e-mail address cannot be found at all.
    ],
    'tblProjectPledge' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['donorID', 'donorName', 'donorEmail'],
    ],
    'tblPushSubscriptions' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'userAgent'],
    ],
    'tblReadingPlanEnrollment' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblRecordingPlay' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'ipHash'],
    ],
    'tblResourceBooking' => [
        'decision' => 'unlink',
        'reason'   => 'A room or equipment booking. The booking belongs to whoever made it, so on erasure the account link goes and so does the booker\'s stated reason for wanting the room (#723); the booking itself is kept, because the room\'s history belongs to the organisation. Deleting the row because the APPROVER left would cancel other people\'s bookings. Two limits are recorded in notEmptiedHere below: the booker\'s own `notes` are KEPT by decision, and `declineReason` is named by the instruction but nothing writes it.',
        'columns'  => ['notes', 'approvedByID', 'bookedByID', 'purpose', 'declineReason'],
        // 🧑‍⚖️ #724: the booker's OWN words, kept by the owner's decision of
        //    3 November 2026 rather than by any principle — which is exactly
        //    why a person may now decide it request by request. `declineReason`
        //    is not marked: nothing writes it, so there is never anything to
        //    look at.
        'needsReview' => ['notes'],
        'notEmptiedHere' => [
            'notes' => 'KEPT, and this reason was WRONG and is corrected (#723, 3 November 2026). It said the '
                . 'note was "the organisation\'s own — a caretaker\'s remark about access, a charge to be '
                . 'made — rather than anything the booker wrote". That is untrue: `_apps/resources/book.php` '
                . 'is the ONLY writer of this column (grep -rn "tblResourceBooking" web/ | grep -iE '
                . '"INSERT|UPDATE " finds one INSERT there and two UPDATEs in action.php that touch only '
                . 'status, approvedByID and approvedAt), and it reads $_POST[\'notes\'] from the booker\'s '
                . 'own form, on the row whose bookedByID is that booker. So this IS the booker\'s own text, '
                . 'and it is kept because the owner decided so on 3 November 2026 when told exactly that — '
                . 'not because it belongs to somebody else. `purpose` IS emptied by the `bookedByID` '
                . 'instruction in GdprEraser; the difference between the two is a decision, not a principle.',
        ],
    ],
    // 🤝 The next three, and tblTasks and tblVisitor further down, said 'erase'
    //    with the stock reason "Holds information about the person themselves"
    //    until 11 October 2026 (roll-out package A2a). Not one of them is
    //    about the person an erasure matches: the column that names them says
    //    who the record was GIVEN to. So erasing a follow-up worker deleted
    //    every rota slot, task, visitor and salvation card assigned to them,
    //    and every swap request sent to them. Salem874's decision: keep the
    //    record, clear the worker. GdprEraser now refuses to delete on
    //    `assignedToID` or `targetUserID` at all, so setting one of these back
    //    to 'erase' is refused rather than obeyed.
    'tblRotaSlot' => [
        'decision' => 'unlink',
        'reason'   => 'A slot on the organisation\'s rota. It stays. Whoever was filling it is detached, so the slot is unfilled, and so is whoever created it.',
        'columns'  => ['assignedToID', 'notes', 'createdByID'],
        'notEmptiedHere' => [
            'notes' => 'The row is the organisation\'s record of a thing, not the person\'s own record, so under the owner\'s rule of 1 November 2026 the note stays. Whoever typed it is detached by cutting the account link; the text itself is not theirs to erase. A duty on the rota exists before anybody is assigned and goes on existing unfilled. The note tells whoever is on duty something about the DUTY, and the reminder e-mail prints it.',
        ],
    ],
    'tblRotaSwapRequest' => [
        'decision' => 'unlink',
        // `requestedByID` (who asked) is cleared too since 12 October 2026,
        // when migration 208 let the column be empty. What erasure does NOT
        // do: `requestMessage` is the asker's own words and is kept.
        'reason'   => 'A request to swap a rota slot. The record that it was asked for stays. Whoever asked, and whoever it was aimed at, are detached; a request still waiting when either of them is erased is cancelled, because nobody is left to ask or to answer.',
        'columns'  => ['targetUserID', 'requestedByID'],
    ],
    'tblSalvationCards' => [
        'decision' => 'unlink',
        // What erasure does NOT do: a card ABOUT the erased person cannot be
        // found. The name, email and telephone on a card are typed by hand,
        // and no column ties the card's subject to an account.
        'reason'   => 'A response card filled in by, or about, somebody who came to an event. It is about THAT person, not the follow-up worker it was given to, so it stays and only the worker is detached. A card about the erased person themselves cannot be found: nothing ties a card\'s subject to an account.',
        'columns'  => ['fullName', 'email', 'phone', 'address', 'assignedToID', 'notes', 'prayerRequest'],
        // 🧑‍⚖️ #724, and BE EXACT ABOUT WHAT THE REVIEW REACHES HERE, because
        //    the gap looks like coverage. The only account column on this row
        //    is the follow-up worker it was given to, so the review offers the
        //    cards THE ERASED PERSON WAS GIVEN — not a card ABOUT them that
        //    somebody else was given, which still cannot be found by anything
        //    (see `fullName` below). What the review adds is that a person now
        //    LOOKS at the prayer request on the cards that can be reached, and
        //    decides. `notes` is not marked: it is follow-up notes about a
        //    third party, and that decision stands.
        'needsReview' => ['prayerRequest'],
        'notEmptiedHere' => [
            'prayerRequest' => 'What the person asked to be prayed for, in their own words, up to a thousand '
                . 'characters. The owner decided on 3 November 2026 that a person\'s own words about '
                . 'themselves should be emptied on request, and that is done for a prayer request and '
                . 'a pastoral case (#715) - but it CANNOT BE DONE HERE, for the reason recorded '
                . 'against `fullName` below: nothing ties a card\'s SUBJECT to an account. The only '
                . 'account column on the row is the follow-up worker the card was given to, so '
                . 'emptying on it would destroy a card about somebody else because a volunteer left, '
                . 'and reach none of the erased person\'s own. The limit is recorded rather than '
                . 'guessed at by matching a typed e-mail address (#715).',
            'fullName' => 'This describes the card\'s SUBJECT - the person who came to an event - not the erased follow-up worker the card was given to. Only the worker is detached. A card about the erased person themselves cannot be found: nothing ties a card\'s subject to an account. The owner decided on 1 November 2026 to record that limit rather than guess by matching on a typed e-mail address, which would miss a mistyped one and could erase a different person who shares a mailbox.',
            'email' => 'The card subject\'s, not the erased worker\'s. See fullName.',
            'phone' => 'The card subject\'s, not the erased worker\'s. See fullName.',
            'address' => 'The card subject\'s, not the erased worker\'s. See fullName.',
            'notes' => 'Follow-up notes about the card\'s subject, a third party. Emptying them because the worker asked to be forgotten would destroy somebody else\'s record. (prayerRequest on this table is the subject\'s own words and is named in no entry at all - #706.)',
        ],
    ],
    'tblServicePlanItem' => [
        'decision' => 'unlink',
        // #535, 11 October 2026. This said 'erase' and listed only `notes`, so
        // the erasure routine had no column to find anybody by and did
        // nothing, silently. The real link is `presenterID`: who leads one
        // item of a service. 'erase' would have been wrong even had it worked:
        // it would delete an item from the organisation's running order
        // because of who was down to present it.
        //
        // WHAT ERASURE CANNOT FIND: `presenterText` is a name typed by hand,
        // and may still spell the person's name (#492).
        'reason'   => 'One item in a service running order. The running order is the organisation\'s own working '
                    . 'document, so the item stays and the number of whoever was down to present it goes.',
        'columns'  => ['presenterID', 'notes', 'presenterText'],
        // 🧑‍⚖️ #724: a person's name typed by hand. It may be the erased
        //    person's own name, or somebody else's who happened to take that
        //    part of the service, so a person reads it and decides.
        'needsReview' => ['presenterText'],
        'notEmptiedHere' => [
            'presenterText' => 'Who is taking this part of the service, typed by hand instead of chosen from the '
                . 'member list — so it is a person\'s name as free text. Nothing empties it',
            'notes' => 'The row is the organisation\'s record of a thing, not the person\'s own record, so under the owner\'s rule of 1 November 2026 the note stays. Whoever typed it is detached by cutting the account link; the text itself is not theirs to erase. A line in the order of service. (presenterText, a hand-typed presenter name, is the personal one here and is named in no entry - #706.)',
        ],
    ],
    'tblServicePlanItems' => [
        'decision' => 'retain',
        // #535, 11 October 2026. This said 'erase' with the stock reason "Holds information about the person themselves",
        // and the erasure routine did nothing at all to the table, without a
        // word in the audit trail: none of the columns listed ties a row to an
        // account, so there was nothing to find the person by. The
        // machine-readable half promised something the code cannot do. It now
        // follows the tblAnonymousCheckins precedent above (#525): 'retain',
        // because the row IS kept, with the true reason and a 'period' that
        // does not pretend there is a time limit. Searched on that day: no
        // scheduled job and no page clears this table by age. Choosing a time
        // limit is a separate decision for the owner, not made here.
        'reason'   => 'One slide in a worship presentation, with the operator\'s own notes. The notes are free text '
                    . 'and may mention somebody, but nothing on the row links it to an account, so an erasure '
                    . 'request cannot reach it. It goes when an operator deletes the slide or its plan',
        'period'   => 'as long as the organisation keeps the plan: nothing clears it on a timer today',
        'columns'  => ['slideNotes'],
    ],
    'tblServicePlans' => [
        'decision' => 'unlink',
        'reason'   => 'A service running order. The organisation\'s own working document.',
        'columns'  => ['notes', 'createdByID'],
        'notEmptiedHere' => [
            'notes' => 'The row is the organisation\'s record of a thing, not the person\'s own record, so under the owner\'s rule of 1 November 2026 the note stays. Whoever typed it is detached by cutting the account link; the text itself is not theirs to erase. The organisation\'s order of service for a gathering.',
        ],
    ],
    'tblSmallGroupMeetingAttendance' => [
        'decision' => 'unlink',
        'reason'   => 'Attendance at a group meeting. Deleting it would leave the register wrong for everybody else who was there.',
        'columns'  => ['userID'],
    ],
    'tblSmallGroupMeetings' => [
        'decision' => 'unlink',
        'reason'   => 'Who took the register at a meeting. Deleting it would destroy the attendance record for everybody who was there, because one person asked to be forgotten.',
        'columns'  => ['notes', 'recordedByID'],
        'notEmptiedHere' => [
            'notes' => 'The row is the organisation\'s record of a thing, not the person\'s own record, so under the owner\'s rule of 1 November 2026 the note stays. Whoever typed it is detached by cutting the account link; the text itself is not theirs to erase. A note about one meeting of a group.',
        ],
    ],
    'tblSmallGroupMembers' => [
        'decision' => 'erase',
        'reason'   => 'Somebody\'s own membership of a group - which group, which role. That is about them, so it goes. Note the table ALSO carries addedByID, saying who put them in the group; that is a separate instruction, and it only removes the name.',
        'columns'  => ['userID'],
    ],
    'tblSmallGroups' => [
        'decision' => 'unlink',
        'reason'   => 'The group itself - its name, its purpose, its members. Deleting it because its founder left would dissolve the group and remove everybody else from it. Where it usually meets is its named HOST\'s home (#809, D-124): erasing that host empties the address from the group (D-122), and the group stays. An address with NO host named - every group from before migration 275 until somebody names one, and any place entered without a host or marked as not anybody\'s home - is NOT emptied by anybody\'s erasure, because nothing records whose home it is.',
        'columns'  => ['postcode', 'latitude', 'longitude', 'what3words', 'createdByID', 'addressLine1', 'addressLine2', 'city', 'region',
                       'description', 'meetingNotes', 'hostUserID', 'placeEnteredByID'],
        // 🧑‍⚖️ #724: notes about a group's meetings, which may or may not be
        //    about the erased person. Only `meetingNotes` is marked.
        //
        // 🏠 #809 (D-120, D-122, D-124, D-128): the address columns USED to be
        //    explained here as "unreachable, because nothing records WHOSE home
        //    it is". That stopped being true: the place now names its HOST
        //    (`hostUserID`, a member chosen on the form), and GdprEraser's
        //    hand-written `hostUserID` instruction empties the address, the
        //    host and the agreement when that host is erased — so those
        //    explanations are gone rather than reworded (a reason for a column
        //    the routine does empty is an untruth, and the coverage test fails
        //    on one). Who only TYPED the place in (`placeEnteredByID`) is an
        //    actor: erasing them unlinks them and empties nothing. A place
        //    marked "not anybody's home" has no host, so no erasure empties it.
        'needsReview' => ['meetingNotes'],
        'notEmptiedHere' => [
            'description' => 'What the group is for, in the organisation\'s own words. It can name a leader in '
                . 'passing; under the owner\'s rule of 1 November 2026 the organisation\'s own text about '
                . 'its own group stays',
            'meetingNotes' => 'Notes about the group\'s meetings, written by its leaders. The organisation\'s record of '
                . 'its own activity, not the member\'s own record',
        ],
    ],
    'tblSmallGroupPlaces' => [
        'decision' => 'unlink',
        'reason'   => 'The homes a small group takes turns meeting in (#809, D-123). Each is its named HOST\'s home (D-124): erasing that host empties its address, its name and the agreement to a map, and takes it off the group\'s list (D-122). The row stays so that a meeting held there still says it met somewhere no longer recorded. Who only typed a home in is unlinked, and their typing empties nothing. A home entered with NO host named, and a place marked "not anybody\'s home" (D-128), is NOT emptied by anybody\'s erasure, because nothing records whose home it is; it stays until somebody removes it from the list.',
        'columns'  => ['hostUserID', 'placeEnteredByID', 'placeLabel', 'addressLine1', 'addressLine2', 'city', 'region', 'postcode',
                       'countryCode', 'latitude', 'longitude', 'what3words'],
    ],
    'tblSmsMessage' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['recipientUserID', 'recipientNumber'],
    ],
    'tblTasks' => [
        'decision' => 'unlink',
        // See the note above tblRotaSlot. The title and description are the
        // organisation's own words and are not searched for a name.
        'reason'   => 'A task on the organisation\'s to-do list. It stays. Whoever it was given to is detached, so it is unassigned, and so is whoever created it.',
        'columns'  => ['assignedToID', 'createdByID'],
    ],
    // 🛟 #694, migration 233. A whole-database restore from an old-form
    //    snapshot keeps the rows a cascade was about to take, so an
    //    administrator can put them back instead of losing them in silence.
    //    One of the two tables it keeps rows from is tblTotpBackupCodes, so a
    //    held row can carry a hash of somebody's single-use sign-in code: a
    //    SECOND COPY of authentication material, outside the table that
    //    normally holds it. Erasing by userID is what stops it surviving an
    //    erasure, and was the condition of keeping those rows at all.
    //
    //    A held tblSettings row has a NULL userID and is untouched: it belongs
    //    to an organisation, not a person.
    'tblRestoreHeldRows' => [
        'decision' => 'erase',
        'reason'   => "Rows a restore could not put back, kept so somebody can. A kept row from tblTotpBackupCodes carries a hash of this person's sign-in code, so it is a second copy of what the main table holds and goes with them. The whole row is deleted rather than anonymised: a kept row with its person taken out could not be put back anywhere.",
        'columns'  => ['userID'],
    ],
    'tblTotpBackupCodes' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblTrustedDevices' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'createdIP'],
    ],
    'tblUserDepts' => [
        'decision' => 'erase',
        'reason'   => "Which department somebody belongs to, with its flags, and in which organisation - that is about them, so it goes. The table also carries addedByID, saying who added them; that is a separate instruction (#517), and it only removes the name.",
        'columns'  => ['userID'],
    ],
    'tblUserDeptsUnplaced' => [
        'decision' => 'erase',
        'reason'   => 'A parked department membership waiting for a global administrator to place it; about the person themselves (#517)',
        'columns'  => ['userID'],
    ],
    'tblUserGroups' => [
        'decision' => 'erase',
        'reason'   => "Which user group somebody belongs to, and in which organisation - that is about them, so it goes. The table also carries addedByID, saying who added them; that is a separate instruction (#517), and it only removes the name.",
        'columns'  => ['userID'],
    ],
    'tblUserGroupsUnplaced' => [
        'decision' => 'erase',
        'reason'   => 'A parked group membership waiting for a global administrator to place it; about the person themselves (#517)',
        'columns'  => ['userID'],
    ],
    'tblUserMilestone' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblUserRoles' => [
        'decision' => 'erase',
        'reason'   => "Which role somebody holds, and in which organisation - that is about them, so it goes. The table also carries grantedByID, saying who gave them the role; that is a separate instruction (#516), and it only removes the name.",
        'columns'  => ['userID'],
    ],
    'tblUserRolesUnplaced' => [
        'decision' => 'erase',
        'reason'   => 'A parked role key waiting for a global administrator to place it; about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblUserSites' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblUserSmsPreference' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'phoneNumber'],
    ],
    'tblUserTours' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblUserTranslationPref' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    // 🛂 #778: a member's own choice about each outside service (Gravatar, a
    //    sign-in provider's photo, a map of their home). It says nothing but
    //    "this person allowed or refused that company", which is about them.
    'tblUserOutsideServicePref' => [
        'decision' => 'erase',
        'reason'   => 'A member\'s own choices about which outside companies may be sent something about them. Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblRecording' => [
        'decision' => 'unlink',
        // The columns were listed as `createdByID`, which this table does not
        // have (corrected by #535, 11 October 2026; the instructions in
        // GdprEraser were already right). `presenterID` was added then too:
        // it was never emptied before.
        'reason'   => 'Who uploaded a recording, and who presented it. The recording is the organisation\'s, so it stays and both numbers go.',
        'columns'  => ['uploadedByID', 'presenterID', 'presenterText'],
        // 🧑‍⚖️ #724: the same shape as the presenter on a service plan item.
        'needsReview' => ['presenterText'],
            'notEmptiedHere' => [
            'presenterText' => 'Who gave this talk, typed by hand instead of chosen from the member list, so it is a '
                . 'person\'s name as free text. Nothing empties it. The same shape as the presenter on a '
                . 'service plan item',
        ],
    ],
    'tblCapabilityMap' => [
        'decision' => 'unlink',
        // 🚪 #727, migration 243. One row per organisation per gated
        //    capability, saying that organisation HAS chosen which of its roles
        //    open that door. The only thing about a person on it is
        //    `decidedByID`: who last chose.
        //
        //    'unlink' and not 'erase', for the same reason as
        //    `tblErasureReviewDecisions` one entry away: the ROW is an access
        //    rule the organisation is relying on, and deleting it would quietly
        //    put a door back to the role the portal ships with — a change of
        //    who can open a confidential register, made as a side effect of
        //    somebody else's erasure request. The number comes off; the rule
        //    stays.
        //
        //    The database already agrees: `fk_capmap_decider` is
        //    ON DELETE SET NULL, so an account removed outright leaves the
        //    mapping standing with nobody named against it.
        'reason'   => 'Which of an organisation\'s roles open one gated feature. The only thing about a person '
                    . 'here is who last chose. The choice itself is an access rule the organisation relies on, '
                    . 'so the row stays and the account number comes off it — deleting the row would silently '
                    . 'put the door back to the role the portal ships with, which would change who can open a '
                    . 'confidential register as a side effect of somebody else\'s erasure.',
        'columns'  => ['decidedByID'],
    ],
    'tblCapabilityMapRole' => [
        'decision' => 'not-personal',
        // 🚪 #727, migration 243. The other half of the pair above: which role
        //    keys open one capability. A role key ('data_controller',
        //    'privacy_officer') and the numbers of the organisation and of the
        //    choice it belongs to. Nothing on it is about a person — not even
        //    indirectly: a role is a job, and who HOLDS one is in
        //    `tblUserRoles`, which has its own entry.
        //
        //    Written down rather than left out, because an absence looks
        //    exactly like the #706 fault, where sixty-six tables had no
        //    decision recorded and the gate that exists to catch that was
        //    green over every one of them.
        'reason'   => 'Which roles open one feature for one organisation: a role key and two row numbers. '
                    . 'Nothing here is about a person — who holds a role is in tblUserRoles, which has its '
                    . 'own entry.',
        'columns'  => [],
    ],
    'tblCareAccessLog' => [
        'decision' => 'erase',
        // The column was listed as `userID`; the table's column is `viewerID`
        // (corrected by #535, 11 October 2026). Deleting on an "acted on it"
        // column is allowed here only because the table is named, with its
        // reason, in GdprEraser::DELETE_ON_ACTOR_COLUMN.
        'reason'   => 'A record of this person opening somebody\'s pastoral file. It is about their own actions and is removed with them.',
        'columns'  => ['viewerID'],
    ],
    'tblAssetAudit' => [
        'decision' => 'unlink',
        // The column was listed as `userID`; it is `actorUserID` (#535).
        'reason'   => 'Who changed something in the asset register. The change history stays; the name goes.',
        'columns'  => ['actorUserID', 'ipHash', 'changeSet'],
            'notEmptiedHere' => [
            'ipHash' => 'A one-way fingerprint of the address a change was made from, not the address itself. '
                . 'Kept as part of the equipment\'s own history; it cannot be turned back into an address',
            'changeSet' => 'What was changed about a piece of equipment. The organisation\'s record of its own '
                . 'property',
        ],
    ],
    'tblAssetScanLog' => [
        'decision' => 'unlink',
        // The column was listed as `userID`; it is `actorUserID` (#535).
        'reason'   => 'Who scanned an asset label. The scan history stays; the name goes.',
        'columns'  => ['actorUserID', 'ipHash', 'userAgentHash'],
            'notEmptiedHere' => [
            'ipHash' => 'A one-way fingerprint of the address a label was scanned from. Kept so repeated '
                . 'scanning can be seen; it cannot be turned back into an address. It does NOT last '
                . 'indefinitely: AssetRegister::purgeExpiredScanLog() clears old rows from the nightly '
                . 'asset job',
            'userAgentHash' => 'A one-way fingerprint of the browser string, for the same purpose and with the same '
                . 'limit',
        ],
    ],
    'tblAssetValueHistory' => [
        'decision' => 'unlink',
        // The column was listed as `createdByID`; it is `recordedByID` (#535).
        'reason'   => 'Who recorded a change in an item\'s value. A financial history, so it stays; the name goes.',
        'columns'  => ['recordedByID'],
    ],
    'tblUsers' => [
        'decision' => 'unlink',
        // `avatarPath`, `displayPhoto` and `displayBio` were not named here, and
        // were not cleared, until 11 October 2026: an erased account kept its
        // photo and the biography its owner had written.
        'reason'   => 'The account itself. It is emptied rather than deleted: hundreds of records point at it, and deleting the row would drag them all down with it. Name, email, telephone, address, coordinates, biography and photo are all cleared, a photo file held in the portal\'s uploads folder is deleted, and the account is left as a tombstone with no name on it. Most records that point at it lose the account NUMBER. SOME keep it on purpose: the person\'s expense claims, their own DBS checks, their tax declarations, an approval step given to them by name and the record of each giving statement issued to them. Some keep their name as well: gifts kept for tax (or every gift, where an organisation keeps them all), offering envelopes that will become such gifts, their tax declarations and a giving statement\'s file at an organisation keeping their name, where every gift on it is kept or its period cannot be told from it - with any home address held for them, in tblGivingKeptDonors, until the organisation\'s keep-until date if it has confirmed one, or longer while a hold is on - and the pastoral visits, care cases, DBS checks or children\'s check-ins they made, which keep their name beside the number (the owner\'s decisions D-016, D-110, D-111). Separately, records kept for security and as a history of changes can mention the number, in what they describe or in a stored copy of a changed record: the activity log (tblActivityLogs), deleted by the nightly clear-out after audit.retentionDays for the organisation it was recorded under (365 days unless changed) if that scheduled job has been set up - and where that period is for ever, which is so only where the installation keeps the log for ever and the organisation has not chosen a number of days of its own, nothing deletes it; the change history of records (tblAuditTrail, tblAssetAudit), which nothing clears; and rate-limit records (tblApiRateLimits), tidied away only when a later request of the same kind happens to trigger it, so one can stay for a long time. Neither list is every place the number may be written: free text typed about the person can mention it too. Through that number, anyone who can read the database or a backup can match their other kept records back to them (D-113).',
        'columns'  => ['userID', 'fullName', 'emailAddress', 'displayAddress', 'displayBio', 'avatarPath', 'displayPhoto', 'latitude', 'longitude', 'what3words', 'phoneNumber', 'displayPhone'],
        'notEmptiedHere' => [
            'avatarPath' => 'Emptied elsewhere, on purpose: GdprEraser::eraseProfileImages() reads this column to find the file, deals with the file, and only then empties both columns in one statement of its own. Emptying it from this instruction instead would throw away the only record of where the file is and leave the image on disk.',
            'displayPhoto' => 'Emptied elsewhere, with avatarPath, by GdprEraser::eraseProfileImages() - after the file itself is dealt with, for the same reason.',
        ],
    ],
    'tblVenueAgreements' => [
        'decision' => 'unlink',
        'reason'   => 'A hire agreement with a venue owner - a contract. It does not stop existing because the person who recorded it left.',
        'columns'  => ['notes', 'createdByID'],
        'notEmptiedHere' => [
            'notes' => 'The row is the organisation\'s record of a thing, not the person\'s own record, so under the owner\'s rule of 1 November 2026 the note stays. Whoever typed it is detached by cutting the account link; the text itself is not theirs to erase. A hire agreement\'s own terms, which the organisation must be able to show.',
        ],
    ],
    'tblVenueBookings' => [
        'decision' => 'unlink',
        'reason'   => 'Agreed bookings of a hired building. They belong to the organisation and the venue owner, not to whoever entered them.',
        'columns'  => ['notes', 'createdByID', 'updatedByID'],
        'notEmptiedHere' => [
            'notes' => 'The row is the organisation\'s record of a thing, not the person\'s own record, so under the owner\'s rule of 1 November 2026 the note stays. Whoever typed it is detached by cutting the account link; the text itself is not theirs to erase. The organisation\'s record of a booking it made.',
        ],
    ],
    'tblVenueImportRows' => [
        'decision' => 'retain',
        // #535, 11 October 2026. This said 'erase' with the stock reason "Holds information about the person themselves",
        // and the erasure routine did nothing at all to the table, without a
        // word in the audit trail: none of the columns listed ties a row to an
        // account, so there was nothing to find the person by. The
        // machine-readable half promised something the code cannot do. It now
        // follows the tblAnonymousCheckins precedent above (#525): 'retain',
        // because the row IS kept, with the true reason and a 'period' that
        // does not pretend there is a time limit. Searched on that day: no
        // scheduled job and no page clears this table by age. Choosing a time
        // limit is a separate decision for the owner, not made here.
        'reason'   => 'One row of a spreadsheet of venue bookings, held while it is being imported. Its notes cell is '
                    . 'copied as it was typed and may mention somebody, but nothing on the row links it to an '
                    . 'account, so an erasure request cannot reach it. (Who ran the import is on the batch, '
                    . 'tblVenueImportBatches, and that IS unlinked.)',
        'period'   => 'as long as the organisation keeps the import batch: nothing clears it on a timer today',
        'columns'  => ['rawNotes'],
    ],
    'tblVenueInvoicePayments' => [
        'decision' => 'unlink',
        'reason'   => 'Who recorded a payment. This is a FINANCIAL record. Deleting it because the person who typed it in left would put the organisation\'s accounts out of balance.',
        'columns'  => ['notes', 'recordedByID'],
        'notEmptiedHere' => [
            'notes' => 'The row is the organisation\'s record of a thing, not the person\'s own record, so under the owner\'s rule of 1 November 2026 the note stays. Whoever typed it is detached by cutting the account link; the text itself is not theirs to erase. A payment against an invoice: a financial record.',
        ],
    ],
    'tblVenueInvoices' => [
        'decision' => 'unlink',
        'reason'   => 'A FINANCIAL record of money owed for venue hire. Deleting it because of who entered it would put the accounts out of balance.',
        'columns'  => ['notes', 'createdByID'],
        'notEmptiedHere' => [
            'notes' => 'The row is the organisation\'s record of a thing, not the person\'s own record, so under the owner\'s rule of 1 November 2026 the note stays. Whoever typed it is detached by cutting the account link; the text itself is not theirs to erase. An invoice: a financial record the organisation must be able to show.',
        ],
    ],
    'tblVenueUsageTypeWindows' => [
        'decision' => 'unlink',
        'reason'   => 'Default hours a venue may be used. Organisation-wide settings, not personal information.',
        'columns'  => ['note', 'createdByID'],
        'notEmptiedHere' => [
            'note' => 'The row is the organisation\'s record of a thing, not the person\'s own record, so under the owner\'s rule of 1 November 2026 the note stays. Whoever typed it is detached by cutting the account link; the text itself is not theirs to erase. When a venue may be used for a kind of activity: a rule about a building, set per venue.',
        ],
    ],
    'tblVenues' => [
        'decision' => 'unlink',
        'reason'   => 'The venue register itself. Deleting it because of who typed it in would remove every hired building the organisation uses, and take its bookings, invoices and hire agreements with it.',
        'columns'  => ['caretakerName', 'caretakerPhone', 'notes', 'createdByID', 'addressLine1', 'addressLine2', 'postcode', 'latitude', 'longitude', 'what3words'],
        'notEmptiedHere' => [
            'latitude' => 'Where the venue is, to seven decimal places. A place\'s position — but a venue can be '
                . 'somebody\'s home, and then it pinpoints their home. Nothing empties it, like the '
                . 'address lines and postcode above',
            'longitude' => 'The other half of that position, with the same reasoning',
            'what3words' => 'The same place in three words. Personal in exactly the rows where the venue is a home, '
                . 'and reached by nothing',
            'addressLine1' => 'The venue\'s street address. A place\'s address — but a venue can be somebody\'s home, '
                . 'and then it is theirs as well, and nothing empties it',
            'addressLine2' => 'The rest of that address, with the same reasoning',
            'postcode' => 'The venue\'s postcode, with the same reasoning',
            'notes' => 'The row is the organisation\'s record of a thing, not the person\'s own record, so under the owner\'s rule of 1 November 2026 the note stays. Whoever typed it is detached by cutting the account link; the text itself is not theirs to erase. Operational detail about a building - how to get in, where to park. Its own schema comment says so.',
            'caretakerName' => 'PERSONAL, AND UNREACHABLE. The caretaker is a named third party - the key-holder for a building - and this table\'s only account column is createdByID, whoever added the venue. An instruction that emptied this would wipe the caretaker\'s name from every venue the erased staff member ever added, losing the only record of who to ring for keys, and would still not touch anything of the erased person\'s own. The owner chose on 1 November 2026 to keep it and record the limit.',
            'caretakerPhone' => 'PERSONAL, AND UNREACHABLE, for the same reason as caretakerName: a third party\'s telephone number, reachable only by matching whoever added the venue.',
        ],
    ],
    'tblVisitor' => [
        'decision' => 'erase',
        // 'erase' is right for ONE of this table's three person columns only,
        // and GdprEraser::catalogue() carries a hand-written instruction for
        // each (roll-out package A2a, 11 October 2026):
        //   convertedUserID  the visitor became a member and this is them:
        //                    their own record, deleted. Nothing found it
        //                    before.
        //   assignedToID     the follow-up worker: the record is KEPT and the
        //                    worker detached. It used to be DELETED, which
        //                    removed other people's records whenever a worker
        //                    was erased.
        //   createdByID      who typed it in: kept, detached.
        // The decision stays 'erase' because that is what happens to the
        // record of the person an erasure is about; tools/gdpr-coverage-selftest.php
        // compares this word with the instruction for the record's subject.
        'reason'   => 'A visitor\'s record: name, email, telephone and follow-up notes. The record of a visitor who later became this member is their own and is deleted. A record of SOMEBODY ELSE that this person followed up or typed in is kept, and only this person is detached from it.',
        'columns'  => ['fullName', 'email', 'phone', 'assignedToID', 'notes', 'convertedUserID', 'createdByID'],
    ],
    'tblWebAuthnCredentials' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblZoomAccount' => [
        'decision' => 'erase',
        // The meetings made through this connection are deleted with it by
        // the database (`fk_zm_account`). On purpose: see tblZoomMeeting.
        'reason'   => 'The person\'s own connection to Zoom: their Zoom account\'s number and the keys that let the portal act as them. Deleted, and the meetings made through it go with it.',
        'columns'  => ['userID', 'zoomAccountEmail'],
    ],

    // =========================================================================
    // UNLINK - keep what was made, remove who made it
    // =========================================================================
    // The row stays because it is the organisation's own material or its own r
    // ecord of what happened. Only the connection to the person is cut.
    // 33 tables.

    'tblActivityLogs' => [
        'decision' => 'unlink',
        // WHAT TWO OF THESE COLUMNS HOLD SINCE #622 (13 October 2026).
        //   sessionID            a FINGERPRINT of the browser session's
        //                        identifier ("sha256:" and 64 characters),
        //                        never the identifier, which is the sign-in
        //                        cookie. The same for every record of one
        //                        session; no use for signing in.
        //   sessionDataSnapshot  a short list of facts about the session:
        //                        signed in or not, the account's number, the
        //                        organisation chosen, an account waiting for
        //                        its second step, an account at a kiosk.
        //                        Numbers and true/false only. It used to be a
        //                        copy of the whole session.
        // Both are written in one place, Logger::sessionFingerprint() and
        // Logger::sessionFacts(), and migration 220 brought the older rows
        // into line.
        // WHAT AN ERASURE DOES TO THEM: empties all six, since #626
        // (27 October 2026). The record of WHAT happened, when, and under
        // which organisation is kept on purpose.
        //
        // It reaches a record by its `userID` COLUMN. That was not enough at
        // first: Logger::activity() took the number as an optional argument
        // that roughly half its callers left out, so an ordinary sign-in record
        // had an empty column while the facts beside it named the person, and
        // an erasure went past it. The logger now fills the column, and
        // ActivityLogCleaner brings records written before that into line —
        // or clears them where the person has already been erased. Migration
        // 221 sends the cleaner back through the log.
        //
        // **So an installation that has not finished that cleaning still has
        // older records an erasure cannot reach.** The health page says while
        // any are left.
        //
        // Until that date it emptied `userID` and nothing else, because a
        // table listed here without a hand-written instruction gets one
        // instruction per ACCOUNT column and empties nothing else. All five
        // of the others were named here and left in place for months: the
        // facts even held the account number that had just been emptied in
        // the column beside them. This comment said "nothing", truthfully,
        // and nothing acted on it. The instruction is now hand-written in
        // GdprEraser::catalogue(), and three checks in
        // tools/gdpr-coverage-selftest.php fail if it is taken away or hidden.
        // 🗣️ #703 round 6: "the link to the person is cut, so it can no longer be
        //    traced back to them" was untrue - what a record SAYS is kept, and it
        //    can name the person or give their account number. Said as it is now.
        //    (Until #933 its example was a sign-in provider's e-mail address on a
        //    link or unlink record; #933 made those numbers only, so the example
        //    is now a failed sign-in, which keeps the typed name by D-137.)
        // 🗣️ #940 (D-117, D-118, D-119): the period is the organisation's own
        //    (set by its own administrator, within the installation's), a
        //    record belonging to no organisation follows the installation's,
        //    and either level may choose to keep the log for ever - the
        //    organisation only where the installation has. #933: a record's
        //    words can still give a name or an e-mail address (a failed
        //    sign-in, D-137; a refused child collection; a change to the
        //    organisation's support e-mail address; an organisation's labels,
        //    D-138 - the deliberate list, which GdprEraser's comment above
        //    its activity-log reason ties to the gate's ON_PURPOSE), and
        //    under "for ever" they last for ever.
        'reason'   => 'Kept for running and repairing the portal, until the nightly clear-out deletes it after'
                    . ' audit.retentionDays for the organisation it was recorded under (365 days unless changed;'
                    . ' that organisation\'s own administrator sets it, within the limit for the whole installation),'
                    . ' or the installation\'s figure for a record that belongs to no organisation, if that scheduled'
                    . ' job has been set up. Where the installation has chosen to keep the activity log for ever, that'
                    . ' is the longest any organisation may keep it, not a rule for all: nothing deletes the records of'
                    . ' an organisation that has chosen for ever or has not chosen a number of days of its own, nor'
                    . ' records that belong to no organisation, but an organisation that has chosen a number of days is'
                    . ' still cleared after it. On'
                    . ' the person\'s own records the account number, the address, the browser, the headers and'
                    . ' the session details are removed, but what each record SAYS is kept as written for as long'
                    . ' as the record is, so it can still name them, give their e-mail address or give their account'
                    . ' number. A failed sign-in, for instance, keeps the sign-in name that was typed, which is'
                    . ' often an e-mail address (D-137). Records other people made can mention them too',
        'columns'  => ['userID', 'requestHeaders', 'sessionID', 'visitorIP', 'userAgent', 'sessionDataSnapshot'],
    ],
    'tblAnnouncements' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID', 'updatedByID'],
    ],
    'tblApiKeys' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID', 'revokedByID', 'lastUsedIP'],
            'notEmptiedHere' => [
            'lastUsedIP' => 'The network address the key was last used from, kept so an administrator can see where '
                . 'a key is being used. Not emptied: the key belongs to the installation rather than to a '
                . 'person, and the record is what makes a stolen key noticeable',
        ],
    ],
    'tblAssetKioskTokens' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID'],
    ],
    'tblAssetMaintenance' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID', 'performedByUserID', 'performedByName', 'details'],
            'notEmptiedHere' => [
            'performedByName' => 'Who serviced a piece of equipment, typed in by hand — usually an outside engineer or '
                . 'firm with no account here. Kept as part of the item\'s service history, which is what '
                . 'the organisation needs to show the work was done',
            'details' => 'What was done to the item. About the item',
        ],
    ],
    'tblAssets' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID'],
    ],
    'tblAuditTrail' => [
        'decision' => 'unlink',
        // 🗣️ #703 round 6: it said "the link to the person is cut, so it can no
        //    longer be traced back to them". Untrue: a row ABOUT the account keeps
        //    the account's number as its `recordID`, and the stored copies of OTHER
        //    records (an expense claim's userID, a loan's counterpartyUserID) keep
        //    it too. The change history is kept for ever (the owner's decision
        //    D-116), so this says so.
        'reason'   => 'The change history: a copy of what a record held each time it was created, changed or'
                    . ' deleted. Kept for ever (the owner\'s decision D-116). Who made a change is detached, but the'
                    . ' history still says WHICH record changed, so an entry about the person\'s own account keeps'
                    . ' its account number, and the stored copies of other records (an expense claim, a loan) can'
                    . ' hold it too. Since #707 the recorded VALUES are emptied too, for a row'
                    . ' about this person: the convention is that a creation records the whole'
                    . ' new row, so a change to somebody\'s own account wrote their NAME AND'
                    . ' E-MAIL ADDRESS in here — measured, and not the telephone or postal address'
                    . ' an earlier draft of this claimed. Who acted, when, which'
                    . ' table and which record all remain, so the trail still shows the account'
                    . ' was changed. The owner chose anonymising over deleting on 2 November 2026',
        'columns'  => ['userID', 'ipAddress', 'oldValue', 'newValue', 'changeSet'],
            'notEmptiedHere' => [
            // 🧾 All three are EMPTIED ELSEWHERE, following the same pattern as
            //    tblUsers.avatarPath above: a column a dedicated step deals with
            //    is recorded here with the reason, because the ordinary
            //    instruction cannot do it.
            'oldValue' => 'Emptied elsewhere, on purpose: GdprEraser::eraseAuditTrailAboutPerson() (#707) '
                . 'empties it for a row about this person\'s ACCOUNT or their ORGANISATION membership. '
                . 'NOT "every row about this person", which an earlier draft claimed and an independent check '
                . 'measured false: a row about their department, group or role membership is audited too and '
                . 'is not reached (those payloads carry account numbers and no names, and the account itself '
                . 'is emptied by the walk, so nothing identifying is left - but the sentence was wrong; #717). '
                . 'It CANNOT be done by this table\'s ordinary '
                . 'instruction, and that is the whole point — the ordinary instruction matches `userID`, '
                . 'which is whoever MADE a change, so emptying the values there would wipe the '
                . 'before-and-after of changes this person made to OTHER people\'s records. A row is about '
                . 'somebody only through its `(tableName, recordID)` pair, so the dedicated step matches '
                . 'on that instead. It holds the whole new row for a creation and the whole old row for a '
                . 'deletion, which is why a change to somebody\'s own account wrote their name and '
                . 'e-mail address in here. Measured: the two places that audit this table pass a name, an '
                . 'e-mail address and three yes/no flags, and nothing audits a deletion of it, so neither a '
                . 'telephone number nor a postal address has ever been written here',
            'newValue' => 'Emptied elsewhere with oldValue, by the same step and for the same reason',
            'changeSet' => 'Emptied elsewhere with the two above. It is the whole set of changes as one piece '
                . 'of machine-readable text, so it carries the same contents',
        ],
    ],
    'tblErrors' => [
        'decision' => 'unlink',
        // 🗣️ #703 round 6: the same untrue "can no longer be traced back to them"
        //    as the two above. `errorDetail` and `requestURL` are kept and can
        //    name somebody (said in notEmptiedHere below); they expire on this
        //    table's own timer, errors.retentionDays.
        'reason'   => 'Kept for running and repairing the portal, until the nightly clear-out deletes it after'
                    . ' errors.retentionDays (365 days unless changed), if that scheduled job has been set up. The'
                    . ' account link, address and browser are removed; the fault\'s own detail and the address that'
                    . ' was asked for are kept until then and can still name the person',
        'columns'  => ['userID', 'visitorIP', 'userAgent', 'requestHeaders', 'resolvedByID', 'errorDetail', 'requestURL'],
            'notEmptiedHere' => [
            'errorDetail' => 'The full detail of a fault, including the trace through the code. A trace can quote a '
                . 'value that was being handled, so it can name somebody. The network address, the '
                . 'browser string and the request headers ARE emptied; this is not. KEPT BECAUSE IT '
                . 'EXPIRES ANYWAY: this table is the one on this list that already has a time limit '
                . '(`errors.retentionDays`, 365 days by default), and the nightly sweep deletes the '
                . 'whole row. Nobody intends this column to hold a name — it holds one only when a '
                . 'fault happens to quote a value — so a time limit is the proportionate answer and '
                . 'emptying a trace would destroy the one thing it is for (#715). ONE CONDITION ON '
                . 'THAT, said plainly: the sweep is a scheduled job the customer has to set up, so on '
                . 'an installation where nothing runs it, nothing expires and this column is simply '
                . 'kept. The health page says whether the job is running.',
            'requestURL' => 'The whole address asked for, including everything after the question mark — which can '
                . 'carry an e-mail address or a name. Not emptied, and kept for the same reason as the '
                . 'detail beside it: the row is deleted outright by the nightly sweep once '
                . '`errors.retentionDays` has passed — on an installation where that scheduled job '
                . 'is set up and running, which is the same condition as the detail beside it (#715).',
        ],
    ],
    'tblEventHubResources' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID'],
    ],
    'tblEventHubVideos' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID'],
    ],
    'tblEvents' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID', 'updatedByID', 'moderatedByID', 'submittedByID', 'statusChangedByID', 'submitterName', 'submitterEmail', 'locationAddress', 'locationPhone', 'locationEmail'],
            'notEmptiedHere' => [
            // 🧾 #964, D-140. These two belong to somebody with NO account,
            //    so no instruction here can reach them: an instruction matches
            //    a person by account number. A request filed by an
            //    administrator for the visitor's e-mail address does, through
            //    a step of its own. "Emptied elsewhere" is the avatarPath
            //    precedent for a column a dedicated step empties.
            'submitterName' => 'Emptied elsewhere, on purpose: the name somebody gave when they suggested an event without '
                . 'signing in. They have no account, so an ordinary erasure cannot find it. On their request, an '
                . 'administrator files an erasure request for the e-mail address they gave, after checking outside '
                . 'the portal that it is really them, and GdprEraser::executeVisitorAddress() empties this and the '
                . 'address beside it on every such suggestion at that organisation (D-140, #964). The event itself is '
                . 'kept. NOT emptied automatically when a suggestion is decided: the owner ruled that out (D-140). '
                . 'Where the event was ADDED by a pattern change the copy carries no submitter at all (#669)',
            'submitterEmail' => 'Emptied elsewhere, on purpose, with the name beside it and by the same step: the address '
                . 'somebody gave when they suggested an event without signing in, used only to tell them it was '
                . 'approved (D-134). The same request also takes the address out of the send log\'s record of that '
                . 'e-mail and deletes one still waiting in the mail queue (D-140, #964)',
            'locationAddress' => 'Where the event is, as free text. The address of a PLACE, which is the organisation\'s '
                . 'own information about its own event — but note that an event held at a member\'s home '
                . 'makes it that member\'s home address, and nothing empties it',
            'locationPhone' => 'A telephone number for the venue. A place\'s number, not a person\'s, unless the venue '
                . 'is somebody\'s home',
            'locationEmail' => 'An e-mail address for the venue. As above',
        ],
    ],
    'tblExternalEventApprovals' => [
        'decision' => 'unlink',
        // #514 part P7. One row per date of an outside calendar's event that
        // waited for, or had, an administrator's agreement to be shown more
        // widely. The only thing about a person is `decidedByID` — who
        // approved or declined — which is history of the ORGANISATION's
        // decision: the name goes, the decision stays.
        //
        // The snapshot columns hold the outside calendar's own text (a title,
        // maybe a location), not tied to any account; that is the calendar's
        // content, like the event row itself. `requestedAudienceSummary` holds
        // counts only, never names, on purpose (migration 206's header), so
        // erasure is never defeated by a name hidden inside a label.
        //
        // `decidedByID` was not one of the names `GdprEraser` recognised by
        // itself, so `GdprEraser::catalogue()` carries a hand-written entry
        // for this table. #535 added the name to `GdprEraser::LINK_COLUMNS` on
        // 11 October 2026; the hand-written entry still wins.
        // `check_personal_data_coverage.py` knows the name too.
        'reason'   => 'Records which administrator approved or declined showing an'
                    . ' outside calendar\'s event more widely. The decision is the'
                    . ' organisation\'s and stays; the name goes',
        'columns'  => ['decidedByID'],
    ],
    'tblExternalEventChoices' => [
        'decision' => 'unlink',
        // #514 part P7. An administrator's choice of who sees one date, or
        // every date, of an outside calendar's event. The setting belongs to
        // the organisation; who made or last changed it goes.
        //
        // WHAT ERASURE CANNOT FIND: `note` is the administrator's own free
        // text and may name somebody. Nothing ties a note to the account it
        // mentions, so a request to be forgotten cannot find it — the same as
        // every other administrator note in the portal.
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes. The free-text note may name somebody and is not tied'
                    . ' to an account, so erasure cannot find it',
        'columns'  => ['createdByID', 'updatedByID', 'note'],
            'notEmptiedHere' => [
            'note' => 'A note about why an outside event was accepted or refused at this level of detail, '
                . 'written by whoever decided. The organisation\'s own reasoning about somebody else\'s '
                . 'calendar; the account link beside it is cut',
        ],
    ],
    'tblExternalFeedRules' => [
        'decision' => 'unlink',
        // #514 part P7. An administrator's rule for which events of an
        // outside calendar are shown to whom. The setting belongs to the
        // organisation; who made or last changed it goes. (Its conditions,
        // `tblExternalRuleConditions`, name no person and need no entry.)
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID', 'updatedByID'],
    ],
    'tblExternalFeeds' => [
        'decision' => 'unlink',
        // #514 part P6 added `updatedByID`, so this entry now names both. Two
        // columns, one decision: a subscription to an outside calendar is the
        // organisation's, not the volunteer's who typed the address in.
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID', 'updatedByID'],
    ],
    'tblExternalFeedRuns' => [
        'decision' => 'unlink',
        // #514 part P6. One row per attempt to refresh an outside calendar.
        // It is a record of what the PORTAL did — what it downloaded, how
        // many events it wrote, what went wrong — and the only thing about a
        // person on it is `triggeredByID`, which says who pressed the Refresh
        // button. So the history stays and the name goes.
        //
        // #535, fixed 11 October 2026. From part P6 until then the erasure
        // routine SKIPPED this table: it worked out how to reach a table from
        // a list of column names that mean "a person", and `triggeredByID` was
        // not on it. The name is now in `GdprEraser::LINK_COLUMNS` (and in
        // ACTOR_COLUMNS, because pressing Refresh is something a person DID),
        // so the column is emptied wherever it names the person being erased.
        // tools/gdpr-eraser-selftest.php proves it on a real database.
        'reason'   => 'A record of what the portal did when it refreshed an outside'
                    . ' calendar. The only thing about a person is who pressed'
                    . ' Refresh, so the record stays and the name goes',
        'columns'  => ['triggeredByID'],
    ],
    'tblForms' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID'],
    ],
    'tblLivePrompts' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID'],
    ],
    'tblLivestreamSessions' => [
        'decision' => 'retain',
        // #535, 11 October 2026. This said 'unlink' with the stock reason "the link to the person is cut",
        // and the erasure routine did nothing at all to the table, without a
        // word in the audit trail: none of the columns listed ties a row to an
        // account, so there was nothing to find the person by. The
        // machine-readable half promised something the code cannot do. It now
        // follows the tblAnonymousCheckins precedent above (#525): 'retain',
        // because the row IS kept, with the true reason and a 'period' that
        // does not pretend there is a time limit. Searched on that day: no
        // scheduled job and no page clears this table by age. Choosing a time
        // limit is a separate decision for the owner, not made here.
        'reason'   => 'One viewer of a live stream: a random token made by the player, when they joined and left, '
                    . 'their browser description and a two-letter country. Viewers do not sign in, and nothing on '
                    . 'the row links it to an account, so an erasure request cannot reach it',
        'period'   => 'as long as the organisation keeps the row: nothing clears it on a timer today',
        'columns'  => ['sessionToken', 'userAgent', 'ipCountry'],
    ],
    'tblNewsletter' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID'],
    ],
    'tblNoticeboardPosters' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID', 'updatedByID'],
    ],
    'tblPathways' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID'],
    ],
    'tblPhoto' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['uploadedByUserID', 'moderatedByID'],
    ],
    'tblPhotoAlbum' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID'],
    ],
    'tblPledgeCampaigns' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID'],
    ],
    'tblProject' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID'],
    ],
    'tblReadingPlan' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID'],
    ],
    'tblRecordingNote' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID'],
    ],
    'tblReportDefinitions' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID', 'updatedByID'],
    ],
    'tblServicePlanMessages' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID'],
    ],
    'tblServicePlanState' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['updatedByID'],
    ],
    'tblSongs' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID'],
    ],
    'tblVenueBookingGroups' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID'],
    ],
    'tblVenueImportBatches' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID'],
    ],
    'tblWebhooks' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID'],
    ],
    'tblWorkflowInstances' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['startedByID', 'subjectLabel', 'contextJson'],
            'notEmptiedHere' => [
            'subjectLabel' => 'A copy of the name of the thing being approved, kept so an approval list can be read '
                . 'without opening every item. Today that is always an announcement\'s TITLE, and an '
                . 'announcement is the organisation\'s own notice rather than the person\'s record — so '
                . 'under the owner\'s rule of 1 November 2026 it stays, exactly as the announcement it '
                . 'copies does. Whoever started the approval is detached by cutting the account link. It '
                . 'is also used in the subject line of the approval e-mail. THE LIMIT TO WATCH: '
                . '`tableName` is documented as "Source table (e.g. tblExpenseClaims)", so the moment a '
                . 'second kind of thing can be approved this could hold a person\'s name directly, and '
                . 'the decision would have to be made again (#714).',
            'contextJson' => 'A small amount of machine-readable context for the item being approved — today just a '
                . 'link to it, which names nobody. Kept for the same reason as the label beside it, and '
                . 'with the same limit: it is whatever the thing being approved needs, so a second kind '
                . 'of approval could put something else in it (#714).',
        ],
    ],
    'tblZoomMeeting' => [
        'decision' => 'unlink',
        // This said only "the content stays; the name goes" until 11 October
        // 2026, which was untrue for one case: a meeting made through the
        // erased person's OWN Zoom connection is deleted with that connection
        // (the database does it; `fk_zm_account`). Salem874 decided to keep
        // that behaviour and make the words true. Such a meeting is a room in
        // their Zoom account, which the portal cannot open once the
        // connection is gone. tools/gdpr-eraser-selftest.php names this as
        // the one allowed case of a delete reaching a kept table.
        'reason'   => 'A Zoom meeting set up from the portal. One made through SOMEBODY ELSE\'s Zoom connection stays, and only who created it is detached. One made through the erased person\'s own connection is a room in their Zoom account, and is deleted together with that connection.',
        'columns'  => ['createdByID'],
    ],

    // -------------------------------------------------------------------------
    // 🖊️ Thirteen tables that only say WHO DID SOMETHING, added on 11 October
    //    2026 (roll-out package A2a; Salem874's decision: keep the record,
    //    clear who did it).
    //
    //    None of them was in this list at all. Each carries an account number
    //    and had no instruction, so the number of somebody who had asked to be
    //    forgotten stayed on it for ever. Twelve were found by package A's
    //    count of tables that link to an account; expense payments and
    //    children's check-ins by its independent check.
    //
    //    For three of them the column could not be emptied until migration 208
    //    (visitor follow-up notes, and the two kinds of attached file).
    // -------------------------------------------------------------------------
    'tblAssetResources' => [
        'decision' => 'unlink',
        'reason'   => 'A file or link attached to a piece of equipment: a manual, a receipt, an insurance paper. It is the organisation\'s. It stays; who attached it is detached.',
        'columns'  => ['uploadedByID'],
    ],
    'tblBankImports' => [
        'decision' => 'unlink',
        'reason'   => 'A bank statement that was imported. A FINANCIAL record: it stays; who imported it is detached.',
        'columns'  => ['importedByID'],
    ],
    'tblBankTxns' => [
        'decision' => 'unlink',
        // What erasure does NOT do: `description` and `reference` are the
        // bank's own text for the line and may spell a payer's name. Nothing
        // ties that text to an account, so it is not searched.
        'reason'   => 'One line of an imported bank statement. A FINANCIAL record: it stays, with its date, amount and the bank\'s description; who matched it to a gift is detached.',
        'columns'  => ['matchedByID'],
    ],
    'tblCcliUsage' => [
        'decision' => 'unlink',
        'reason'   => 'A line in the song-licence log: which song was shown, and when. The organisation reports these to its licensing body, so the line stays; who was operating is detached.',
        'columns'  => ['operatorID'],
    ],
    'tblDocuments' => [
        'decision' => 'unlink',
        'reason'   => 'A document shared with the organisation. It stays; who uploaded it is detached.',
        'columns'  => ['uploadedByID'],
    ],
    'tblEventBroadcasts' => [
        'decision' => 'unlink',
        'reason'   => 'A message sent to the people coming to an event. The record that it was sent stays; who sent it is detached.',
        'columns'  => ['sentByID'],
    ],
    'tblExpenseClaimPayments' => [
        'decision' => 'unlink',
        'reason'   => 'A payment made against an expense claim. A FINANCIAL record: it stays, with its amount, method and reference; who entered it is detached.',
        'columns'  => ['paidByID'],
    ],
    'tblKidCheckins' => [
        // 📋 #929 (the owner's decision D-142): a WORKER whose name is on this
        //    record sees it LISTED - kind, day and organisation, nothing else -
        //    in their own downloads, by GdprEraser::recordsCarryingYourName().
        //    A new kept worker column goes there too. The gate
        //    tools/audit-checks/check_worker_records_listed.py fails until it does
        //    ONLY for a column whose name ends in `ByID`: worker columns named
        //    otherwise exist (performedByUserID, uploadedByUserID, assignedToID,
        //    followUpAssignedToID...), all emptied on erasure today, and the gate
        //    would not see one of those being kept. Widening the pattern would
        //    also catch a person's OWN records (bookedByID, requestedByID).
        // 🧾 'retain' SINCE #703 (the owner's decision D-111, 10 November 2026,
        //    extending D-016). It said 'unlink' and both helpers were detached;
        //    D-111 keeps them, by number and by name (`checkedInByName`,
        //    `checkedOutByName`, migration 272, written by `kids/checkin-do.php`
        //    and `kids/checkout.php`). With both instructions now "keep",
        //    'unlink' would promise a change nothing makes, which section 7b of
        //    `tools/gdpr-coverage-selftest.php` refuses.
        //
        // Two person columns, so GdprEraser::catalogue() carries a
        // hand-written instruction for each. What erasure does NOT do:
        // `pickupName` is typed by hand and may spell the erased person's
        // name; nothing ties it to an account.
        'decision' => 'retain',
        'reason'   => 'A child being checked in to, and out of, a session. It is the CHILD\'S record and stays. Whichever helper checked the child in, and whichever checked them out, is KEPT by name and account number when that helper is erased (the owner\'s decision D-111, #703): who handed a child over, and to whom, must stay answerable. Through that number, anyone who can read the database or a backup can match their other kept records back to them (D-113).',
        'period'   => 'as long as the child\'s own record is kept: nothing clears check-ins on a timer today',
        'columns'  => ['checkedInByID', 'checkedInByName', 'checkedOutByID', 'checkedOutByName', 'pickupName'],
        'notEmptiedHere' => [
            'pickupName' => 'PERSONAL, AND UNREACHABLE. Nothing on this row links the name to an account - the two account columns are the HELPERS who did the checking - so the only possible match is a text comparison against the erased person\'s stored name, which would miss a nickname and hit a namesake. It is also the only record of who collected a child, so a wrong match destroys a safeguarding trail. The owner decided on 1 November 2026 to keep it and record the limit; anything better needs a real link from a check-in to the collecting adult (#708).',
        ],
    ],
    'tblMigrations' => [
        'decision' => 'unlink',
        // Not in this list at all until the independent check of 12 October
        // 2026 found it: the database does not link `executedByID` to the
        // account table, so nothing that looked for links could see it.
        'reason'   => 'The portal\'s own record of which database upgrades have been run. It must stay, or an upgrade would be run twice; which administrator pressed the button is detached.',
        'columns'  => ['executedByID'],
    ],
    'tblProjectUpdate' => [
        'decision' => 'unlink',
        'reason'   => 'A progress update on one of the organisation\'s projects. The update stays; who posted it is detached.',
        'columns'  => ['postedByID'],
    ],
    'tblServicePlan' => [
        'decision' => 'unlink',
        'reason'   => 'The plan for a service. It is the organisation\'s and stays; who prepared it is detached.',
        'columns'  => ['preparedByID'],
    ],
    'tblVenueAgreementFiles' => [
        'decision' => 'unlink',
        'reason'   => 'A file attached to a hire agreement with a venue owner. Part of a contract, so it stays; who attached it is detached.',
        'columns'  => ['uploadedByID'],
    ],
    'tblVisitorContact' => [
        'decision' => 'unlink',
        // A note about the erased person's OWN visitor record goes with that
        // record (the database deletes a visitor's notes with the visitor).
        'reason'   => 'A follow-up note about a visitor: how they were contacted and what was said. It is about the VISITOR, so it stays; who wrote it is detached.',
        'columns'  => ['contactedByID', 'summary'],
        'notEmptiedHere' => [
            'summary' => 'The erased person\'s OWN summary is already gone, by a different route: tblVisitor\'s instruction is \'delete\' matched on convertedUserID, and the link from a contact to its visitor cascades. What can survive is a summary about SOMEBODY ELSE, reachable only through the worker who wrote it - and emptying it there would destroy a third party\'s record because the person who typed it asked to be forgotten. Limit, under the owner\'s decision of 1 November 2026: a visitor who never got an account cannot be found at all.',
        ],
    ],
    'tblWorkflowActions' => [
        'decision' => 'unlink',
        'reason'   => 'One step in an approval: approved, rejected or commented, with any comment written at the time. The organisation\'s record of how a decision was reached, so it stays; who acted is detached.',
        'columns'  => ['actedByID', 'comment'],
        'notEmptiedHere' => [
            'comment' => 'The row is the organisation\'s record of a thing, not the person\'s own record, so under the owner\'s rule of 1 November 2026 the note stays. Whoever typed it is detached by cutting the account link; the text itself is not theirs to erase. One step in the audit trail of how the organisation decided about a record belonging to SOMEBODY ELSE - the subject is named on the parent row, never here. Their authorship goes when actedByID is cut, after which the comment is unattributed text. Said plainly because it is arguable: an approval can be read as "a decision they made", which the owner\'s rule puts on the empty side. It is read here as the organisation\'s record of its own process.',
        ],
    ],

    // =========================================================================
    // RETAIN - the law requires this to be kept
    // =========================================================================
    // Kept deliberately, with the reason and the period stated. Never quietly 
    // deleted, and never quietly kept either.
    // 5 tables.

    'tblCareCase' => [
        // 📋 #929 (the owner's decision D-142): a WORKER whose name is on this
        //    record sees it LISTED - kind, day and organisation, nothing else -
        //    in their own downloads, by GdprEraser::recordsCarryingYourName().
        //    A new kept worker column goes there too. The gate
        //    tools/audit-checks/check_worker_records_listed.py fails until it does
        //    ONLY for a column whose name ends in `ByID`: worker columns named
        //    otherwise exist (performedByUserID, uploadedByUserID, assignedToID,
        //    followUpAssignedToID...), all emptied on erasure today, and the gate
        //    would not see one of those being kept. Widening the pattern would
        //    also catch a person's OWN records (bookedByID, requestedByID).
        'decision' => 'unlink',
        // 🧾 #703, the owner's decision D-110 (10 November 2026, extending
        //    D-016): who OPENED a case is kept when that worker is erased, by
        //    number and by name (`openedByName`, migration 272, written by
        //    `care/case-save.php`). Until #703 it was detached. The number
        //    stays beside the name by D-113, which says what that costs.
        'reason'   => 'A pastoral or wellbeing case. Safeguarding continuity matters to the remaining team, so the case stays and the person\'s name and details are removed. Kept rather than deleted because safeguarding records carry their own retention obligations. Who OPENED the case is kept, by name and account number, when that worker is erased (the owner\'s decision D-110, #703). Through that number, anyone who can read the database or a backup can match their other kept records back to them (D-113).',
        'columns'  => ['personName', 'summary', 'openedByID', 'openedByName'],
        'notEmptiedHere' => [
            'openedByName' => 'KEPT ON PURPOSE, by the owner\'s decision D-110 (10 November 2026, #703, extending '
                . 'D-016): when the worker who opened a pastoral case is erased, their name stays on the case as '
                . 'the person who opened it, so the case stays accountable after they leave. Their number '
                . '(`openedByID`) is kept too, by a "retain" instruction (D-113 says what that costs); their own '
                . 'account is still emptied, which is why the name is written onto the case when it is opened.',
        ],
        // 🤝 #715, the owner's decision of 3 November 2026. `summary` used to be
        //    explained here as not emptied, and the explanation said the thing
        //    that made it indefensible out loud: the VISIT NOTES on the case
        //    are emptied (#709) "this summary is not, so it can still describe
        //    them". Both are a third party's words about the erased person's
        //    health or circumstances. It is emptied now, and the explanation is
        //    gone rather than reworded.
    ],
    'tblCareVisit' => [
        // 📋 #929 (the owner's decision D-142): a WORKER whose name is on this
        //    record sees it LISTED - kind, day and organisation, nothing else -
        //    in their own downloads, by GdprEraser::recordsCarryingYourName().
        //    A new kept worker column goes there too. The gate
        //    tools/audit-checks/check_worker_records_listed.py fails until it does
        //    ONLY for a column whose name ends in `ByID`: worker columns named
        //    otherwise exist (performedByUserID, uploadedByUserID, assignedToID,
        //    followUpAssignedToID...), all emptied on erasure today, and the gate
        //    would not see one of those being kept. Widening the pattern would
        //    also catch a person's OWN records (bookedByID, requestedByID).
        'decision' => 'unlink',
        // 🧾 WHO MADE THE VISIT IS KEPT (#703, the owner's decision D-016,
        //    3 November 2026, which "rules out unlinking the worker"). When a
        //    member of staff or a volunteer is erased, the visits they made keep
        //    their number AND their name. A pastoral or safeguarding record that
        //    cannot say who carried it out is worse for the people it protects.
        //
        //    The NAME is `visitedByName` (migration 272), written when the visit
        //    is recorded. It has to be: the worker's own account row is emptied
        //    by the erasure, so `visitedByID` alone would point at "[Deleted
        //    User]" and say nothing. That number-only shape is what this entry
        //    had until 11 October 2026 (decision 'retain', `visitedByID` left in
        //    place), and migration 208 then made the column emptiable and the
        //    erasure emptied it - which D-016 rules out. Neither is put back.
        //
        //    The decision stays 'unlink' because the row still loses something
        //    on an erasure: the note, when the person CARED FOR is erased (#709),
        //    and the follow-up worker's number. 'retain' would forbid both.
        //
        // 🧾 #708, and the owner's decision of 2 November 2026: RECORD THE
        //    LIMIT, change nothing. `followUpAssignedToID`'s instruction is
        //    correct and can NEVER MATCH A ROW on the code as it stands,
        //    because nothing writes the column — `_apps/care/visit-save.php`
        //    inserts only `caseID`, `visitedByID`, `visitedByName`, `kind`,
        //    `notes` and `followUpAt`. So the coverage here looks wider than it
        //    is, and that is the whole reason this note exists. It was NOT
        //    deleted, and the page was NOT changed to fill it: whether a
        //    pastoral visit should have an assignable follow-up owner is a
        //    question about how pastoral care is run, which is the owner's and
        //    not a tidy-up. D-016 does not reach it either: being GIVEN a
        //    follow-up is not making the visit. If the page ever sets it, this
        //    note comes out and nothing else needs to change.
        //
        // The CASE HISTORY stays for as long as safeguarding policy keeps the
        // case - but the note on a visit is the subject's own record and is
        // emptied, matched through the parent table (#709). An earlier version
        // of this comment and of `reason` said the notes were kept on
        // safeguarding grounds, which contradicted the line in `notEmptiedHere`
        // below.
        'reason'   => 'A pastoral visit. The case history is kept for as long as safeguarding policy keeps the '
                    . 'case. Who made the visit is KEPT, by name and account number, when that person is erased (the owner\'s decision '
                    . 'D-016, #703): a pastoral record must still say who carried it out. Through that number, anyone who can read the database or a backup can match their other kept records back to them (D-113). Who its follow-up was given '
                    . 'to is detached. The note on a visit is the subject\'s own record and IS emptied when the person '
                    . 'cared for is erased, matched through the case (#709)',
        'columns'  => ['visitedByID', 'visitedByName', 'followUpAssignedToID', 'notes'],
        'notEmptiedHere' => [
            'visitedByName' => 'KEPT ON PURPOSE, by the owner\'s decision D-016 (3 November 2026, #703): when the '
                . 'person who made a pastoral visit is erased, their name stays on the visit as the person who made '
                . 'it, because a pastoral or safeguarding record that cannot say who carried it out is worse for the '
                . 'people it protects. Their number (`visitedByID`) is kept too, by a "retain" instruction; their own '
                . 'account is still emptied, which is why the name is written onto the visit when it is recorded.',
        ],
    ],
    'tblDbsChecks' => [
        // 📋 #929 (the owner's decision D-142): a WORKER whose name is on this
        //    record sees it LISTED - kind, day and organisation, nothing else -
        //    in their own downloads, by GdprEraser::recordsCarryingYourName().
        //    A new kept worker column goes there too. The gate
        //    tools/audit-checks/check_worker_records_listed.py fails until it does
        //    ONLY for a column whose name ends in `ByID`: worker columns named
        //    otherwise exist (performedByUserID, uploadedByUserID, assignedToID,
        //    followUpAssignedToID...), all emptied on erasure today, and the gate
        //    would not see one of those being kept. Widening the pattern would
        //    also catch a person's OWN records (bookedByID, requestedByID).
        'decision' => 'retain',
        // 🧾 WHO RECORDED THE CHECK IS KEPT (#703, the owner's decision D-016,
        //    3 November 2026). From 11 October 2026 until #703 `recordedByID`
        //    was cleared by the derived "who handled it" instruction; D-016
        //    rules that out. GdprEraser now writes this table's instructions by
        //    hand, both of them "retain", and the recorder's NAME is
        //    `recordedByName` (migration 272), written when the check is
        //    recorded, because the recorder's own account is emptied by an
        //    erasure and the number alone would point at "[Deleted User]".
        'reason'   => 'Safeguarding record. Kept and flagged, never quietly removed. Who recorded the check is kept '
                    . 'too, by name and account number, when that person is erased (the owner\'s decision D-016, #703). '
                    . 'Through that number, anyone who can read the database or a backup can match their other kept records back to them (D-113).',
        'period'   => 'as required by safeguarding policy',
        'columns'  => ['userID', 'notes', 'recordedByID', 'recordedByName'],
    ],
    // 🧾 THE CLAIMANT IS KEPT WITH THE CLAIM, AS A DECISION (#492 part 4, the
    //    owner, 3 November 2026). The reason here used to end "...and the
    //    claimant's name is removed", which was UNTRUE: `userID` on this table
    //    is `INT NOT NULL`, so the erasure's attempt to empty it is refused by
    //    the database and recorded in the sealed trail as a step that could do
    //    nothing. So the position was settled by a column nobody had decided
    //    about, and the reason a data subject reads described the opposite of
    //    what happened. The owner has now decided it deliberately: the claim is
    //    kept for six years because HMRC requires it, and a claim that cannot
    //    say who was paid is of little use as one.
    //
    //    The wording below says what REALLY happens, including the failed
    //    attempt, rather than implying the portal chose not to try.
    'tblExpenseClaims' => [
        'decision' => 'unlink',
        'reason'   => 'An expense claim: what was claimed, how much, and who was paid. The claim and its amounts are kept for '
                    . 'six years because HMRC requires financial records to be kept, and the claimant is kept with them '
                    . 'DELIBERATELY (the owner, 3 November 2026) — a record of a payment that cannot say who was paid is of '
                    . 'little use as one. Be clear about the mechanism as well as the decision, because they are not the same '
                    . 'thing: the erasure does still TRY to remove the account link, the database refuses it (the column '
                    . 'cannot be empty on this table), and the report of the erasure records that it could do nothing here. '
                    . 'The account itself is emptied, so the claim points at an account with no name on it, but the account '
                    . 'NUMBER stays — and that does not stop the claim naming the claimant: if they made pastoral visits, '
                    . 'opened care cases, recorded DBS checks or checked children in or out, those records keep their name '
                    . 'beside the same number on purpose (D-016, D-110, D-111), so anyone who can read the database or a '
                    . 'backup can match the claim back to them (D-113). Receipts attached to the claim keep the filename they '
                    . 'were uploaded with, which is often a person\'s name',
        'columns'  => ['userID'],
    ],
    'tblGivingStatementLog' => [
        'decision' => 'retain',
        // The period said '6 years', with nothing to say whose rule that was
        // and nothing that ever acted on it. It is each organisation's own
        // setting since roll-out package A2b.
        // 🗣️ #703 round 10: round 9's "except one whose name does not give its
        //    period, which is kept" was untrue at an organisation keeping
        //    nothing of the donor's - eraseGivingStatementFiles() checks
        //    isset($keptAt[$siteId]) BEFORE statementFileIsKept(), so such a
        //    file is deleted there (measured by the check: 15 of 15 deleted, 50
        //    of 50 kept where a kept row holds the name).
        // 🗣️ #703 round 9: this said "the statement file is removed when the
        //    organisation's keep-until date has passed". Only true of a KEPT
        //    file: GdprEraser::eraseGivingStatementFiles() deletes at once a file
        //    listing a gift that loses the name (statementFileIsKept()), and no
        //    date passes until an organisation has confirmed its years.
        'reason'   => 'Proof of what was issued to whom, and when: the record of each giving statement, with its period and totals, kept with the donor\'s giving records and their account number. The address it was mailed to is emptied at once. The statement\'s file, which holds the donor\'s name, any home address held for them and every gift on it, is kept only at an organisation that is keeping the donor\'s name, and there only where every gift on it keeps the name, or where the file\'s name does not give its period (kept, because keeping is the mistake that can be put right). A kept file is removed with the name, when the organisation\'s keep-until date has passed (once it has confirmed how many years, and with no hold on). Every other statement file - including every file at an organisation keeping nothing of the donor\'s, whatever its name - is deleted when the erasure runs',
        'period'   => 'set by each organisation, in years after the end of the financial year of the donor\'s last kept gift: Admin, Settings, Giving records',
        'columns'  => ['donorID', 'createdByID'],
    ],

    // =========================================================================
    // NOT PERSONAL - it only looks that way
    // =========================================================================
    // About a place or a thing, not a person. Left alone.
    // 4 tables.

    'tblMailRouteState' => [
        'decision' => 'not-personal',
        // #592. One row for the installation's mail route: what is wrong
        // with it (a certificate that failed its check, a sign-in the mail
        // server refused) and when the mail job last ran. The certificate's
        // names are a mail SERVER's. The mail server's own words about a
        // refused sign-in can name the organisation's mailbox, which is a
        // setting and not a person's record.
        'reason'   => 'About a mail server and the portal\'s own scheduled job, not about a person',
        'columns'  => [],
    ],
    'tblAssetLocations' => [
        'decision' => 'not-personal',
        'reason'   => 'Where a piece of equipment is kept. About a place, not a'
                    . ' person.',
        'columns'  => ['latitude', 'longitude', 'what3words'],
    ],
    'tblGeocodeCache' => [
        'decision' => 'not-personal',
        'reason'   => 'A store of place names and their map positions. About places,'
                    . ' not people.',
        'columns'  => ['latitude', 'longitude'],
    ],
    'tblResource' => [
        'decision' => 'not-personal',
        'reason'   => 'A bookable room or piece of equipment. Its position is the'
                    . ' position of a thing.',
        'columns'  => ['latitude', 'longitude', 'what3words'],
    ],


    // =========================================================================
    // 🧾 #706 — the tables that had NO entry at all (2 November 2026)
    // =========================================================================
    //
    // Sixty-six tables were in this file nowhere. They were not an oversight in
    // the way #635's were: an erasure instruction says "this table, this
    // column, this person's number", and NOT ONE of these sixty-six carries a
    // person's number. They are lists of kinds (`tblEventTypes`), settings
    // (`tblAiPrompt`), and child tables keyed by their parent
    // (`tblExpenseClaimFiles.claimID`). So they were unreachable by
    // construction, and the check that was meant to notice them could not —
    // its list of personal column names is written by hand, so it only ever
    // found a column somebody had already thought of.
    //
    // Each one now carries a decision and a reason. Fourteen of them needed a
    // fact established by reading the code that WRITES the column, rather than
    // guessing from its name; those reasons say what was found, including where
    // the honest answer is "nothing ever removes this".
    //
    // 🛑 FOUR OF THESE REASONS RECORD A FAULT RATHER THAN A POSITION, and each
    //    has an issue: #712 (network addresses nothing clears), #713 (payment
    //    messages kept in full, before the check on who sent them is acted on),
    //    #714 (three places an erasure cannot reach). An entry saying "nothing
    //    clears this" is a description of today, not a defence of it.

    // --- Classifications and lists of kinds -------------------------------
    // Every one of these is a list an administrator maintains. The *Name
    // columns name a CATEGORY, not a person, which is the distinction the new
    // sweep has to make: `categoryName` is a thing, `reporterName` is not.

    'tblAssetCategories' => [
        'decision' => 'not-personal',
        'reason'   => 'The kinds of equipment an organisation owns. About a classification, not a person.',
        'columns'  => ['categoryName'],
    ],
    'tblAssetIdentifierTypes' => [
        'decision' => 'not-personal',
        'reason'   => 'The kinds of serial number and barcode the asset register understands, with the pattern each one follows. Installation-wide reference data about numbering schemes.',
        'columns'  => ['label', 'description'],
    ],
    'tblAttendanceServiceTypes' => [
        'decision' => 'not-personal',
        'reason'   => 'The kinds of service or session attendance is counted against. A classification.',
        'columns'  => ['typeName', 'description'],
    ],
    'tblDocCategories' => [
        'decision' => 'not-personal',
        'reason'   => 'The folders documents are filed under. A classification.',
        'columns'  => ['categoryName', 'description'],
    ],
    'tblEventCategories' => [
        'decision' => 'not-personal',
        'reason'   => 'The categories events are filed under. A classification.',
        'columns'  => ['categoryName'],
    ],
    'tblEventThemes' => [
        'decision' => 'not-personal',
        'reason'   => 'Themes an event can be tagged with. A classification.',
        'columns'  => ['themeName'],
    ],
    'tblEventTypes' => [
        'decision' => 'not-personal',
        'reason'   => 'The kinds of event an organisation runs. A classification.',
        'columns'  => ['typeName'],
    ],
    'tblGivingCategory' => [
        'decision' => 'not-personal',
        'reason'   => 'The funds and appeals a donation can be given to. About where money goes, not who gave it: the donor is on tblGiving.',
        'columns'  => ['name', 'description'],
    ],
    'tblLeadershipRoles' => [
        'decision' => 'not-personal',
        'reason'   => 'The list of leadership posts that exist. Who HOLDS a post is tblLeadershipAssignments; this is the post itself.',
        'columns'  => ['roleName', 'description'],
    ],
    'tblRotaRoleType' => [
        'decision' => 'not-personal',
        'reason'   => 'The kinds of duty a rota can ask for. Who is rostered is elsewhere.',
        'columns'  => ['name', 'description'],
    ],
    'tblRoles' => [
        'decision' => 'not-personal',
        'reason'   => 'The portal\'s own list of what a role is called and what it means. Who holds a role is tblUserRoles.',
        'columns'  => ['roleName', 'description'],
    ],
    'tblVenueStatuses' => [
        'decision' => 'not-personal',
        'reason'   => 'The states a venue booking can be in, and what each one counts as. A classification.',
        'columns'  => ['statusName'],
    ],
    'tblVenueUsageTypes' => [
        'decision' => 'not-personal',
        'reason'   => 'The ways a venue can be used, and whether each is bookable. A classification.',
        'columns'  => ['typeName'],
    ],
    'tblRecordingTopic' => [
        'decision' => 'not-personal',
        'reason'   => 'Topic words used to file recordings, with a count of how often each is used. About subject matter, not people.',
        'columns'  => ['topic'],
    ],
    'tblDepts' => [
        'decision' => 'not-personal',
        'reason'   => 'The departments an organisation is divided into. Who belongs to one is tblUserDepts.',
        'columns'  => ['deptName'],
    ],

    // --- Configuration the installation or organisation sets ---------------

    'tblAiPrompt' => [
        'decision' => 'not-personal',
        'reason'   => 'The wording the portal sends to an outside language service when asked to draft something. An instruction written by an administrator, not information about anybody.',
        'columns'  => ['promptTemplate'],
    ],
    'tblEmailTemplates' => [
        'decision' => 'not-personal',
        'reason'   => 'The wording of the portal\'s own e-mails, with placeholders where a name or a date is filled in when a message is sent. The template holds the placeholder; the finished message is tblEmailLog.',
        'columns'  => ['subject', 'bodyHtml', 'description'],
    ],
    'tblRoutes' => [
        'decision' => 'not-personal',
        'reason'   => 'Which web address is answered by which file. About the software, not about people.',
        'columns'  => ['routeKey', 'targetFile'],
    ],
    'tblSettings' => [
        'decision' => 'not-personal',
        'reason'   => 'How an installation and each organisation on it are configured. Some values are an ORGANISATION\'S own details (its postal address, its support mailbox); those belong to the organisation, not to a person, and a global administrator maintains them on the Settings pages. No value here is tied to a member\'s account.',
        'columns'  => ['settingKey', 'settingValue'],
    ],
    'tblSites' => [
        'decision' => 'not-personal',
        'reason'   => 'The organisations this installation serves: name, web address, branding and time zone. About an organisation, not a person.',
        'columns'  => ['siteName', 'hostPattern', 'copyrightOrg'],
    ],
    'tblTours' => [
        'decision' => 'not-personal',
        'reason'   => 'The guided tours the portal can show a new user, and the steps in each. Which person has SEEN a tour is kept elsewhere; this is the tour itself.',
        'columns'  => ['title', 'steps', 'forRoles'],
    ],
    'tblWorkflows' => [
        'decision' => 'not-personal',
        'reason'   => 'The approval processes an organisation has defined. A process, not a person. The steps are tblWorkflowSteps and a particular run is tblWorkflowInstances.',
        'columns'  => ['workflowName', 'description'],
    ],
    'tblNewsletterSegment' => [
        'decision' => 'not-personal',
        'reason'   => 'A saved rule describing who a newsletter should go to ("members of this group"). It holds the RULE, not the list of people it matches, which is worked out when the newsletter is sent.',
        'columns'  => ['name', 'ruleJson'],
    ],
    'tblRecurrenceRules' => [
        'decision' => 'not-personal',
        'reason'   => 'How often a series of events repeats. About dates, not people.',
        'columns'  => ['dayOfWeek'],
    ],
    'tblLivestreamChannel' => [
        'decision' => 'not-personal',
        'reason'   => 'An organisation\'s own streaming channel and how to embed it. About the organisation\'s channel, not a viewer.',
        'columns'  => ['name', 'channelOrVideoId', 'embedHtmlOverride'],
    ],
    'tblLivestreamSchedule' => [
        'decision' => 'not-personal',
        'reason'   => 'The times of day a channel is expected to be live. About a timetable, not a person.',
        'columns'  => ['timezone'],
    ],

    // --- The organisation's own structure and content ----------------------
    // The owner's rule of 1 November 2026 applies to all of these: the row is
    // the ORGANISATION'S record of a thing, not the person's own record, so
    // the text stays and only an account link is cut. Where a person's name
    // may have been typed INTO free text, that is said rather than denied.

    'tblEventSeries' => [
        'decision' => 'not-personal',
        'reason'   => 'A named run of events ("Sunday Morning Worship") and its description. The organisation\'s own programme.',
        'columns'  => ['seriesName', 'description'],
    ],
    'tblCalendarMonthThemes' => [
        'decision' => 'not-personal',
        'reason'   => 'A line of text an organisation puts at the top of a month in the calendar. Its own words about its own programme.',
        'columns'  => ['themeText'],
    ],
    'tblEventCrews' => [
        'decision' => 'not-personal',
        'reason'   => 'The named teams an event is organised into ("Sound", "Welcome"). Who is IN a crew is tblEventCrewMembers, which is catalogued separately and does hold a name for somebody with no account.',
        'columns'  => ['name', 'gradesAccepted'],
    ],
    'tblEventJobs' => [
        'decision' => 'not-personal',
        'reason'   => 'The jobs an event needs doing, and how many people each needs. Who is assigned is tblEventJobAssignments, which is catalogued separately.',
        'columns'  => ['name', 'description'],
    ],
    'tblEventLinks' => [
        'decision' => 'not-personal',
        'reason'   => 'Web links an event carries, with the words shown for each. The organisation\'s own references.',
        'columns'  => ['linkURL', 'linkLabel'],
    ],
    'tblEventMaterials' => [
        'decision' => 'not-personal',
        'reason'   => 'Files attached to an event, by stored name and path. The organisation\'s own material.',
        'columns'  => ['fileName', 'filePath'],
    ],
    'tblEventOrgs' => [
        'decision' => 'not-personal',
        'reason'   => 'Another organisation taking part in an event, by name and web address. About an organisation. Note that where that organisation is one person trading under their own name, the name is both at once; nothing on this row links it to an account, so an erasure request cannot reach it, and an administrator edits it on the event.',
        'columns'  => ['orgName', 'orgUrl'],
    ],
    'tblEventThemeMap' => [
        'decision' => 'not-personal',
        'reason'   => 'Which theme is attached to which event. Two numbers.',
        'columns'  => [],
    ],
    'tblExternalCategoryMap' => [
        'decision' => 'not-personal',
        'reason'   => 'How a category name in somebody else\'s calendar is translated into one of ours. About categories.',
        'columns'  => ['externalCategory'],
    ],
    'tblExternalEventTags' => [
        'decision' => 'not-personal',
        'reason'   => 'Tags read off an imported event. About subject matter.',
        'columns'  => ['tag'],
    ],
    'tblExternalRuleConditions' => [
        'decision' => 'not-personal',
        'reason'   => 'The tests a calendar import rule applies to an incoming event, by field and value. About matching text in a calendar, not about people.',
        'columns'  => ['matchValue'],
    ],
    'tblFormFields' => [
        'decision' => 'not-personal',
        'reason'   => 'The questions on a form, with their labels and help text. The ANSWERS somebody gives are tblFormResponses, which is catalogued separately; this is the question.',
        'columns'  => ['label', 'helpText', 'configJson'],
    ],
    'tblGroups' => [
        'decision' => 'not-personal',
        'reason'   => 'The groups an organisation runs, with a description. Membership is tblUserGroups. Where a description names a leader in passing it is the organisation\'s own text about its own group, so under the owner\'s rule of 1 November 2026 it stays; it is reached by no account link.',
        'columns'  => ['groupName', 'description'],
    ],
    'tblGroupsUnplaced' => [
        'decision' => 'not-personal',
        'reason'   => 'A group kept aside when the structure it belonged to was changed, so it is not lost. The same content as tblGroups.',
        'columns'  => ['groupName', 'description'],
    ],
    'tblHymnals' => [
        'decision' => 'not-personal',
        'reason'   => 'The hymn books an organisation uses, by code, name and publisher. About published books.',
        'columns'  => ['name', 'publisher'],
    ],
    'tblPathwaySteps' => [
        'decision' => 'not-personal',
        'reason'   => 'The steps that make up a discipleship pathway, and the rule that marks each one done. A person\'s PROGRESS through them is kept separately. This is the definition.',
        'columns'  => ['name', 'description', 'completionHint'],
    ],
    'tblReadingPlanDay' => [
        'decision' => 'not-personal',
        'reason'   => 'One day of a reading plan: its label and the passage. The organisation\'s own material.',
        'columns'  => ['label', 'content'],
    ],
    'tblVenueRooms' => [
        'decision' => 'not-personal',
        'reason'   => 'The rooms in a venue, with capacity. About a place.',
        'columns'  => ['roomName', 'description'],
    ],
    'tblNewsletterContent' => [
        'decision' => 'not-personal',
        'reason'   => 'The blocks a newsletter is built from, in order, with the text or settings of each. The organisation\'s own words, written to be published. Where a block names somebody it does so deliberately, as published content.',
        'columns'  => ['payload'],
    ],
    'tblNewsletterLink' => [
        // #620. One row per web address a newsletter links to, so the click
        // counter forwards only to an address the newsletter really holds.
        // It does NOT record who clicked what: that is still only
        // tblNewsletterRecipient.clickedAt, catalogued above.
        'decision' => 'not-personal',
        'reason'   => 'The web addresses one newsletter links to, each with a random token the click-counting link carries instead of the address. Copied from the newsletter\'s own published content when it is sent, the same for everybody it went to; nothing here says who received or clicked a link. Removed with the newsletter.',
        'columns'  => ['targetUrl'],
    ],
    'tblVenueInvoiceLines' => [
        'decision' => 'not-personal',
        'reason'   => 'The lines of a venue invoice, as amounts against bookings. Who the invoice is to is on the invoice, which is catalogued separately.',
        'columns'  => [],
    ],
    'tblExpenseClaimItems' => [
        // #535's precedent, and the coverage test enforces it: an entry may not
        // promise an action the erasure cannot perform. Nothing on this row ties
        // it to an account — the claimant is named on the claim above it — so
        // there is no person to find here, and "unlink" would be a promise the
        // code cannot keep. The claim itself is kept six years for HMRC and has
        // the claimant's account number KEPT on purpose (#703, D-113), so these
        // lines are NOT detached from anybody: they hang off a claim that names
        // the claimant. (This said "has its account link cut, which is what
        // detaches these lines from anybody", which was untrue.)
        'decision' => 'retain',
        'period'   => 'as long as the claim, which HMRC requires for six years',
        'reason'   => 'What a person is claiming for, line by line, with the supplier and the amount. The claim itself names the claimant, and the organisation must keep what it paid out and why; so the line stays, and the claim above it keeps the claimant\'s account number on purpose (D-113). Note that the supplier may be a sole trader trading under their own name.',
        'columns'  => ['itemName', 'description', 'supplier'],
    ],

    // --- Caches and run logs that name nobody ------------------------------

    'tblHymnLookupCache' => [
        'decision' => 'not-personal',
        'reason'   => 'A short-lived copy of an answer from an outside hymn service, kept so the same question is not asked twice. Keyed by a hash of the question, which is a hymn title or number.',
        'columns'  => ['resultsJson'],
    ],
    'tblAssetReminderLog' => [
        'decision' => 'not-personal',
        'reason'   => 'A record that a reminder about a piece of equipment went out, with a COUNT of recipients and no names. Kept so the same reminder is not sent twice.',
        'columns'  => [],
    ],
    'tblEventReminderLog' => [
        'decision' => 'not-personal',
        'reason'   => 'A record that an event reminder went out, with a COUNT of recipients and no names, kept so the same reminder is not sent twice. Who was actually written to is tblEmailLog.',
        'columns'  => [],
    ],
    'tblUserReminderLog' => [
        'decision' => 'not-personal',
        'reason'   => 'A record that a reminder went out, with a COUNT and no names. Despite the table\'s name it identifies no user.',
        'columns'  => [],
    ],
    'tblVenueReminderLog' => [
        'decision' => 'not-personal',
        'reason'   => 'A record that a venue reminder went out, with a COUNT of recipients and no names.',
        'columns'  => [],
    ],
    'tblApiRateLimits' => [
        // #712, 2 November 2026. This entry used to record a live fault: two
        // kinds of bucket put the visitor's network address in unchanged, and
        // for those two the opportunistic tidy-up only ever matched the exact
        // bucket again — so a bucket never used a second time, which is the
        // normal case for a one-off code check or a single push sign-up, was
        // never tidied at all. The two-factor one paired an ACCOUNT NUMBER with
        // an address, making it a record of which person signed in from where,
        // kept indefinitely. Both halves are fixed: the address is hashed at
        // all three call sites, and each passes a family prefix so a later hit
        // tidies every stale bucket of that kind.
        'decision' => 'retain',
        'reason'   => 'How many times something was attempted, so a limit can be applied. Every bucket name '
                    . 'is an account number, an API-key number or a salted one-way fingerprint: no bucket '
                    . 'holds a network address. Nothing ties a row to an account in a way an erasure request '
                    . 'could match, because the bucket is one piece of text, so what reaches these rows is '
                    . 'the clear-out below and not an erasure. Some buckets DO contain an account NUMBER. '
                    . 'Nothing here empties that number — the account it points at is emptied by the main '
                    . 'erasure walk, which is a different thing: the row still says "account 4211 did this", '
                    . 'and what changes is that account 4211 has no name on it any more. That does not always '
                    . 'make the row anonymous: if the person made pastoral visits, opened care cases, recorded '
                    . 'DBS checks or checked children in or out, those records keep their name beside the same '
                    . 'number on purpose (D-016, D-110, D-111), so the row can still be matched back to them by '
                    . 'anyone who can read the database or a backup (D-113).',
        'period'   => 'twice the limit\'s own window, tidied opportunistically (about one request in 64, up '
                    . 'to 500 rows at a time) and BY FAMILY, so a bucket never used a second time is still '
                    . 'reached — which is the normal case for a one-off code check, a single push sign-up or '
                    . 'one form submission (#712). Every place that records a hit passes a family (count them '
                    . 'with `grep -rn "RateLimiter::recordHit(" web`; the "eleven" this said was stale before '
                    . '#598 added three more, a per-person reset cap, a per-account password-check limit and '
                    . 'the anonymous prayer form, each with a family). Eight did not until #712, including the per-user second tier of the '
                    . 'two-factor limit, which an earlier version of this entry wrongly described as '
                    . 'covered. A row older than the period can change no answer the limiter gives.',
        'columns'  => ['bucket'],
    ],
    'tblLiveRateLimits' => [
        // #712, 2 November 2026, and migration 236. This entry used to record a
        // live fault: the column held the viewer's address UNCHANGED and
        // NOTHING removed a row — no clear-out anywhere, no foreign key that
        // could cascade in, no scheduled job — under an index named
        // `idx_ratelimit_prune`, for a prune that did not exist. The column is
        // now a SHA-256 (`senderFingerprint`, made in one place,
        // `LiveChat::senderFingerprint()`), and `recordSend()` tidies the table
        // as it writes.
        //
        // DO NOT confuse this with `tblLiveChatMessages.senderIP`, which is a
        // different table and stays RAW on purpose: the moderation screen shows
        // it to an administrator deciding whether to block somebody. That one
        // has its own entry further up, and its own reason.
        'decision' => 'retain',
        'reason'   => 'How often a viewer sent a message to the live chat, so flooding can be stopped. The '
                    . 'address is kept only as a one-way fingerprint, which is all a limit needs — nothing '
                    . 'reads it back and nothing displays it. The session token beside it is also stored '
                    . 'next to a typed display name on the chat messages, so the two can still be joined '
                    . 'while both rows exist. Nothing on the row links it to an account, so an erasure '
                    . 'request cannot match it; what reaches these rows is the clear-out below.',
        'period'   => 'twice the chat\'s own throttle window (`chat.windowSeconds`), tidied as messages are '
                    . 'sent — about one send in 64, up to 500 rows at a time (#712). A row older than that '
                    . 'can change no answer the throttle gives.',
        'columns'  => ['senderFingerprint', 'sessionToken'],
    ],
    'tblTranscript' => [
        'decision' => 'retain',
        'reason'   => 'The written-out words of a recording, made by an outside transcription service, and '
                    . 'the same words again with timings. Whatever was said aloud is here unchanged, '
                    . 'including any name, address or telephone number somebody read out. Nothing on the '
                    . 'row links it to an account: it belongs to a recording, and an erasure request '
                    . 'deliberately KEEPS the recording and only detaches who uploaded it, so the usual '
                    . 'cascade never happens — and no column says whose words these are, so an erasure '
                    . 'has nothing to match on anyway. It is also a record of a meeting involving other '
                    . 'people, so deleting it on one participant\'s request would destroy what everybody '
                    . 'else said. The owner decided (3 November 2026) on a recorded limit and a time '
                    . 'limit instead. Nothing drains the transcription queue by itself (#672), so on most '
                    . 'installations this table is empty.',
        'period'   => 'the organisation\'s own transcript period, capped by the installation '
                    . '(`transcription.retentionDays`, 365 days unless changed). Past it the transcript '
                    . 'row is DELETED by the nightly clear-out and its search copy goes with it; the '
                    . 'recording itself is untouched. A transcript that never finished has no date and no '
                    . 'words, and is left alone (#714).',
        'columns'  => ['fullText', 'jsonSegments'],
    ],
    'tblTranscriptSearch' => [
        'decision' => 'retain',
        'reason'   => 'A second copy of the words above, held in a form that can be searched quickly. It is '
                    . 'the same text, so it carries the same risk, and it is two steps away from the '
                    . 'recording rather than one. Removed when the transcript is — which now happens on '
                    . 'a timer as well as when an administrator deletes the recording, because '
                    . '`fk_ts_transcript` cascades from `tblTranscript`. There is no way for this copy to '
                    . 'outlive the original.',
        'period'   => 'the same period as the transcript it mirrors, and by the same statement: deleting '
                    . 'the transcript row takes this one with it (#714).',
        'columns'  => ['body'],
    ],
    'tblWebhookDeliveries' => [
        // #713, 2 November 2026. This entry used to record a live fault: there
        // was no time limit at all, and only deleting the webhook itself ever
        // removed a row. The payload and the stored reply are now emptied by
        // the nightly sweep once the period has passed.
        //
        // WHY NOT AN ERASURE INSTRUCTION, since the row plainly holds personal
        // details: NOTHING ON IT LINKS IT TO A PERSON. The only link is
        // `webhookID`, which names a customer's endpoint, so an instruction
        // could not match a row and an entry promising one would be the #535
        // fault. Searching the JSON for a name was rejected for #707's reason:
        // it would match a namesake and miss a nickname. A time limit is the
        // only mechanism there is, and the owner chose it.
        'decision' => 'retain',
        'reason'   => 'A record of each message the portal sent to another system, with the message itself. '
                    . 'Some of those messages really do carry personal details: a registration message '
                    . 'carries the person\'s full name, an event submitted by somebody not signed in '
                    . 'carries their e-mail address, and a prayer-request message carries the subject line, '
                    . 'which is free text. Until #615 the first 500 characters of the reply the other '
                    . 'system sent back were kept as well, and what is in a reply is decided by whoever runs '
                    . 'it; a delivery made since then keeps only one word saying why it failed, never the '
                    . 'reply, and older replies are emptied by the time limit below. Nothing on the row links it to an '
                    . 'account, so an erasure request cannot match it; what reaches these rows is the time '
                    . 'limit below. The row itself is kept even once emptied, because it is the record that '
                    . 'something was sent, when, after how many attempts and with what answer.',
        'period'   => 'the organisation\'s own webhook payload period, capped by the installation '
                    . '(`webhooks.payload.retentionDays`, 90 days unless changed). Past it the message and '
                    . 'the stored reply are emptied and the row kept. A delivery still waiting or still '
                    . 'being retried is NEVER emptied, because the retry sends from the stored payload '
                    . '(#713).',
        'columns'  => ['payload', 'responseSnippet'],
    ],
    'tblWebhookEvent' => [
        // #713, 2 November 2026. This entry used to record two live faults, and
        // both are fixed. The message was stored BEFORE the signature check was
        // acted on, so anybody who could reach the address had their JSON kept
        // for ever; `Payments::ingestWebhook()` now keeps a body only once the
        // signature has verified, and writes the row either way so that the
        // attempt is still on record. And nothing cleared the table at all;
        // the nightly sweep now empties bodies past the period.
        //
        // The table still has no link to any other table, so no cascade can
        // reach it and no erasure instruction could match a row — which is why
        // the period below is the whole of the answer rather than part of it.
        'decision' => 'retain',
        'reason'   => 'The whole message a payment provider sent us, exactly as it arrived, kept as the '
                    . 'record of what we were told. A payment message routinely carries the payer\'s name, '
                    . 'e-mail address and billing address, so those are here in full while the message is '
                    . 'kept. A message whose signature did not verify is recorded WITHOUT its body, so a '
                    . 'request from somebody who is not the provider leaves a note and nothing else. '
                    . 'Nothing on the row links it to an account, so an erasure request cannot match it.',
        'period'   => 'the installation\'s webhook payload period '
                    . '(`webhooks.payload.retentionDays`, 90 days unless changed). There is no '
                    . 'per-organisation figure here, because the table has no organisation column and the '
                    . 'payment provider\'s credentials are installation-wide. Past the period the body is '
                    . 'emptied and the row kept, because the row is what stops the same event being '
                    . 'accepted twice (#713).',
        'columns'  => ['payload'],
    ],
    'tblReadingPlanProgress' => [
        // Said carefully. These rows DO go when somebody is erased — but not
        // because of anything written here: the row carries no account number,
        // so no instruction can find the person in it. It hangs off the
        // person's enrolment, which does carry one and IS erased, and the
        // database removes these rows along with it. The coverage test refuses
        // an entry that claims an action with no column to act on (#535), so
        // this is recorded as kept, with the truth in the reason.
        'decision' => 'retain',
        'period'   => 'removed with the enrolment it belongs to, which an erasure deletes',
        'reason'   => 'Which day of a reading plan somebody has finished, and when. The row itself is two '
                    . 'numbers and a date — no name, address or free text — but it is a record of what a '
                    . 'named person did, so it goes. It carries no account number of its own: it hangs off '
                    . 'the person\'s enrolment, which does carry one and is itself erased, and the database '
                    . 'removes these rows with it. That is why this entry lists no column to act on — the '
                    . 'work is done by the enrolment\'s own instruction',
        'columns'  => [],
    ],
    'tblWorkflowSteps' => [
        'decision' => 'retain',
        'reason'   => 'The steps of an approval process, including who each step is given to. Where a step '
                    . 'is given to one named person that is an account number, not a name — the page asks '
                    . 'for a number and every piece of code that reads it treats it as one. It is '
                    . 'deliberately NOT cleared, and the reason is written beside the erasure code: it says '
                    . 'who MUST act, not who DID, so emptying it would leave a step nobody can approve. '
                    . 'What such a step should become instead is a decision nobody has taken. Note the box '
                    . 'is not checked when it is filled in, so a name typed there by mistake would be kept '
                    . 'as typed',
        'period'   => 'as long as the approval process exists: deliberately not cleared, because an empty '
                    . 'step cannot be approved by anybody',
        'columns'  => ['assigneeValue'],
    ],
    'tblOffsiteSyncLog' => [
        'decision' => 'not-personal',
        'reason'   => 'A record of each attempt to send a copy of the database off this server, with the '
                    . 'run log. Checked rather than assumed: no part of it is built from anybody\'s '
                    . 'details. Snapshot names are a date and time, the packing step is not set to list '
                    . 'files, and the script does not print its own settings. Who started a run is recorded '
                    . 'as an account number. The one thing that could appear is the organisation\'s OWN '
                    . 'destination — a server name or login it configured — if the copying tool fails and '
                    . 'prints its own error; that is the organisation\'s setting, not a member\'s data',
        'columns'  => ['output', 'errorMsg', 'triggeredBy'],
    ],
    'tblExpenseClaimFiles' => [
        // #714, 3 November 2026, and migration 238. This entry used to record a
        // live fault, and ALSO got one detail wrong in the reassuring
        // direction: it said the stored name was "made up by the portal", when
        // the stored name was built FROM the uploader's own
        // (`$claimID . '_' . time() . '_' . preg_replace(…, $orig)`). So the
        // name was kept twice — in the database and as the name of the file on
        // disk, where it appears in any directory listing, backup or off-site
        // copy and where no erasure reaches it either.
        //
        // Both names are generated now, at submission rather than on erasure
        // (the owner, 3 November 2026), so there is nothing left to clear and
        // no link to break.
        'decision' => 'retain',
        'reason'   => 'The receipts attached to an expense claim. Both names are made up by the portal: the '
                    . 'label is `Claim_<id>_Receipt_<n>_<date>.<ext>` for an upload and '
                    . '`Claim_<id>_<Stage>.pdf` for a form the portal generated, and the name on disk '
                    . 'carries nothing from the uploader either. So there is no personal detail in either '
                    . 'name to reach. Said exactly: that is true of everything uploaded from 3 November '
                    . '2026. Migration 238 relabelled any row written before then, but it could not '
                    . 'rename the FILES already on disk - it is SQL and they are not - so on an '
                    . 'installation that had already received uploads those filenames would need '
                    . 'dealing with by hand. There is no live installation of this portal, so there '
                    . 'are none. That matters here more than elsewhere, because an erasure request '
                    . 'could not have reached one: the claim is kept for six years for HMRC, with the '
                    . 'claimant\'s account number kept on purpose, so the row is never deleted, the cascade never fires, '
                    . 'and matching through the parent cannot help because the parent is retained on '
                    . 'purpose. The receipt FILE itself is evidence and is kept with the claim.',
        'period'   => 'as long as the claim, which HMRC requires for six years. Nothing in the names '
                    . 'expires, because nothing in them identifies anybody (#714).',
        'columns'  => ['fileLabel'],
    ],
    'tblHymnalEntries' => [
        'decision' => 'not-personal',
        'reason'   => 'One hymn in a hymn book: its number, title, first line, tune, metre and the '
                    . 'publisher\'s copyright line. The author is the person who WROTE the hymn — a '
                    . 'published writer named in a book, usually long dead — and the copyright line names '
                    . 'the rights holder. Nothing on this table or the book above it links to an account. '
                    . 'Both are free text, so a portal user could in principle be typed into the author '
                    . 'box, but no code puts one there and nothing invites it',
        'columns'  => ['author', 'copyrightLine'],
    ],
    'tblAssetFoundReports' => [
        'decision' => 'retain',
        'reason'   => 'Somebody outside the organisation reporting that they have found a piece of its '
                    . 'equipment, with the name and contact details they chose to give and a message. '
                    . 'Nothing links it to an account — the person reporting need not have one — so an '
                    . 'erasure request cannot reach it, following the same reasoning as the outside '
                    . 'organisations in the asset register. The network address is kept as a one-way '
                    . 'fingerprint, not in full. IT IS CLEARED ON A TIMER, which an earlier draft of this '
                    . 'entry denied: AssetRegister::purgeExpiredFoundReports() deletes a report older than '
                    . '`assets.found_report_retention_days`, and the nightly asset job calls it. An '
                    . 'administrator can also deal with a report and clear it by hand sooner',
        'period'   => '`assets.found_report_retention_days`, 180 days unless the organisation changes it, '
                    . 'applied by AssetRegister::purgeExpiredFoundReports() from the nightly asset job',
        'columns'  => ['reporterName', 'reporterContact', 'message', 'ipHash'],
    ],
    'tblContentTranslation' => [
        'decision' => 'retain',
        // ⚠️ THIS ENTRY SAID, UNTIL 6 NOVEMBER 2026, that content translation
        //    was "not reachable by anybody today (#485)" and that this table
        //    was "empty on every installation". #485/#761 made it reachable
        //    on 4 November 2026, so both halves had become untrue, in the file
        //    a request for somebody's information is answered from (#800).
        // ⚠️ AND UNTIL 7 NOVEMBER 2026 IT SAID nothing ever refreshed a row, citing
        //    #800 for that — #800 was about this entry's OTHER sentence. #799
        //    made an edited original translate afresh into the same row, so
        //    the period is "replaced on the next request", not "never".
        'reason'   => 'A machine translation of the organisation\'s OWN published words, kept so the same text '
                    . 'is not paid for twice. Only three things can be translated (ContentTranslation::SOURCES): '
                    . 'an announcement, a project\'s description and an update posted on a project. A prayer '
                    . 'request is never translated, on purpose (D-049), because it is a person\'s own words, '
                    . 'often about somebody else. Because a row is a copy of published text, it holds whatever '
                    . 'that text held: an announcement naming a person is translated with the name in it. '
                    . 'Nothing on the row links it to an account (it names the table, row and field it came '
                    . 'from, and the organisation), so an erasure request cannot reach it. The words are the '
                    . 'organisation\'s, and changing or removing the original is how a name is taken out of '
                    . 'them. Each row carries a fingerprint of the exact words it was made from (#799, '
                    . 'migration 256), and is shown only while those are the words in the original now. So '
                    . 'once the original is EDITED its old translation is never shown again, and it is '
                    . 'REPLACED in its row the next time anybody asks for that piece in that language; until '
                    . 'then the old translated words stay stored although nothing shows them. Once the '
                    . 'original is DELETED or stops being shown, its translation is not shown either (it can '
                    . 'only be asked for through the original), but nothing removes the row',
        'period'   => 'no time limit: nothing removes a row. A row is REPLACED the next time its piece is '
                    . 'translated into the same language after the original was edited (#799); a row whose '
                    . 'original is deleted, or never asked for again, is kept indefinitely though never shown',
        'columns'  => ['translatedContent'],
    ],
    'tblTranslationUsage' => [
        'decision' => 'not-personal',
        // #762, migration 254. Written down rather than left out: an absence
        // looks exactly like the #706 fault, where tables had no decision.
        'reason'   => 'How many translations one organisation asked for in one month, and what the '
                    . 'translation company reported they cost: an organisation number, a month and two '
                    . 'counts. It says nothing about which member pressed the button or what was translated',
        'columns'  => [],
    ],
    'tblScheduledJobRun' => [
        'decision' => 'not-personal',
        // #775, migration 253. It had no entry, which reads exactly like the
        // #706 fault; nothing enforces an entry for a table with no personal
        // column, so this is written down by hand.
        'reason'   => 'When each of the portal\'s own scheduled jobs last started and finished, and one word '
                    . 'for how it ended. One row per job for the whole installation; it names a job, never a '
                    . 'person, and deliberately never records the words of a fault',
        'columns'  => [],
    ],
    // 🚫 tblErasureAudit is DELIBERATELY ABSENT, and this note is here so the
    //    gap is not read as the #706 fault it looks like. It is the sealed
    //    record of what an erasure did — the proof the work happened — and
    //    rule 7d of tools/gdpr-coverage-selftest.php REFUSES any instruction
    //    naming it (the #510 shape: an instruction once deleted the request
    //    being carried out, the database took every audit line with it, and
    //    the run stopped half way). A `retain` entry is still an instruction,
    //    so even "keep this" is refused here. Its columns name tables and how
    //    rows were matched, not the person. Whether a line ABOUT the erased
    //    person is reachable at all is #707.

];
