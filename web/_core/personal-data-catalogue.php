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
        // attend.detailRetentionDays, 90 days by default, 0 to keep for
        // ever, set per organisation at /admin/settings/attendance).
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
                    . '(attend.detailRetentionDays, 90 days by default, 0 means keep for ever). See #479, #525 and #530.',
        // Required whenever decision is 'retain' (tools/gdpr-coverage-selftest.php
        // checks every 'retain' entry has one). Not a legal minimum — this row
        // is cleared by a technical timer the organisation controls, not by law
        // — but the self-test's rule is the same either way: say for how long.
        'period'   => '90 days by default, per the organisation\'s attend.detailRetentionDays setting (0 = keep for ever)',
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
    ],
    'tblAssetIdentifiers' => [
        'decision' => 'unlink',
        'reason'   => 'Barcodes and tags identifying the organisation\'s property.',
        'columns'  => ['notes', 'createdByID'],
    ],
    'tblAssetKioskPins' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblAssetLicenseAssignments' => [
        'decision' => 'unlink',
        'reason'   => 'Who a software licence seat was allotted to. The organisation still needs to know how many seats are in use.',
        'columns'  => ['userID', 'notes', 'releasedByID', 'linkedByID'],
    ],
    'tblAssetLoans' => [
        'decision' => 'unlink',
        'reason'   => 'Who borrowed an item. Deleting the loan would erase the item\'s history, and the organisation still needs to know where its property went. The name goes, the loan stays.',
        'columns'  => ['notes', 'counterpartyUserID', 'approvedByID', 'requestedByID'],
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
        'decision' => 'unlink',
        'reason'   => 'An item counted during a stocktake. An audit record; deleting it would leave a gap in the count.',
        'columns'  => ['notes'],
    ],
    'tblAssetStocktakes' => [
        'decision' => 'unlink',
        'reason'   => 'A stocktake of the organisation\'s property - an audit record.',
        'columns'  => ['startedByID', 'notes'],
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
        'columns'  => ['sessionID', 'notes', 'createdByID', 'updatedByID'],
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
    ],
    'tblDecisionMoments' => [
        'decision' => 'unlink',
        'reason'   => 'Who wrote the entry down, not who it is about. Deleting it would destroy somebody else\'s record because the person holding the pen asked to be forgotten.',
        'columns'  => ['notes', 'recordedByID'],
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
    //    or at one of their memberships ('tblUserSites') is deleted when that
    //    person is erased, and included in their data export. Entries for
    //    announcements are not about a person, and are left alone.
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
        'columns'  => ['toRecipients'],
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
    'tblEventAttendance' => [
        'decision' => 'unlink',
        'reason'   => 'Attendance at an event. The record of who was there matters to the organisation, so the row stays and the name goes.',
        'columns'  => ['userID', 'notes', 'markedByID'],
    ],
    'tblEventCoordinators' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'grantedByID'],
    ],
    'tblEventCrewMembers' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblEventJobAssignments' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblEventOccurrenceOverrides' => [
        'decision' => 'unlink',
        'reason'   => 'A change to one date of a repeating event. Deleting it would silently move the event back to its original time for everybody.',
        'columns'  => ['notes', 'createdByID'],
    ],
    'tblEventPeople' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblEventRSVPInvites' => [
        'decision' => 'unlink',
        'reason'   => 'Invitations sent for an event. They concern the people invited, not the person who sent them.',
        'columns'  => ['email', 'displayName', 'createdByID'],
    ],
    'tblEventRSVPs' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblEventRegistrations' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['fullName', 'dateOfBirth', 'gender', 'allergies', 'medicalNotes', 'parentName', 'parentPhone', 'parentEmail', 'emergencyContactName', 'emergencyContactPhone', 'submittedByUserID', 'reviewedByID'],
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
        'columns'  => ['userID'],
    ],
    'tblFormResponses' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['submitterID'],
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
        'columns'  => ['donorID', 'address', 'postcode', 'notes'],
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
        'columns'  => ['fullName', 'dateOfBirth', 'allergies', 'medicalNotes', 'parentUserID'],
    ],
    'tblLeadershipAssignments' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'personName', 'personEmail', 'notes', 'createdByID', 'updatedByID'],
    ],
    'tblLinkedAccounts' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
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
        'columns'  => ['storedName', 'createdByID'],
    ],
    'tblOffboarding' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'rehiredByID', 'offboardedByID'],
    ],
    'tblPasswordResets' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
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
        'columns'  => ['submitterID', 'moderatorID', 'assignedToUserID'],
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
        'columns'  => ['userID'],
    ],
    'tblResourceBooking' => [
        'decision' => 'unlink',
        'reason'   => 'Who approved a room or equipment booking. The booking belongs to whoever made it. Deleting it because the approver left would cancel other people\'s bookings.',
        'columns'  => ['notes', 'approvedByID', 'bookedByID'],
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
        'columns'  => ['fullName', 'email', 'phone', 'address', 'assignedToID', 'notes'],
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
        'columns'  => ['presenterID', 'notes'],
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
    ],
    'tblSmallGroupMembers' => [
        'decision' => 'erase',
        'reason'   => 'Somebody\'s own membership of a group - which group, which role. That is about them, so it goes. Note the table ALSO carries addedByID, saying who put them in the group; that is a separate instruction, and it only removes the name.',
        'columns'  => ['userID'],
    ],
    'tblSmallGroups' => [
        'decision' => 'unlink',
        'reason'   => 'The group itself - its name, its purpose, its members. Deleting it because its founder left would dissolve the group and remove everybody else from it.',
        'columns'  => ['postcode', 'latitude', 'longitude', 'what3words', 'createdByID'],
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
    'tblTotpBackupCodes' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblTrustedDevices' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
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
        'columns'  => ['userID'],
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
    'tblRecording' => [
        'decision' => 'unlink',
        // The columns were listed as `createdByID`, which this table does not
        // have (corrected by #535, 11 October 2026; the instructions in
        // GdprEraser were already right). `presenterID` was added then too:
        // it was never emptied before.
        'reason'   => 'Who uploaded a recording, and who presented it. The recording is the organisation\'s, so it stays and both numbers go.',
        'columns'  => ['uploadedByID', 'presenterID'],
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
        'columns'  => ['actorUserID'],
    ],
    'tblAssetScanLog' => [
        'decision' => 'unlink',
        // The column was listed as `userID`; it is `actorUserID` (#535).
        'reason'   => 'Who scanned an asset label. The scan history stays; the name goes.',
        'columns'  => ['actorUserID'],
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
        'reason'   => 'The account itself. It is emptied rather than deleted: hundreds of records point at it, and deleting the row would drag them all down with it. Name, email, telephone, address, coordinates, biography and photo are all cleared, a photo file held in the portal\'s uploads folder is deleted, and the account is left as a tombstone that names nobody.',
        'columns'  => ['userID', 'fullName', 'emailAddress', 'displayAddress', 'displayBio', 'avatarPath', 'displayPhoto', 'latitude', 'longitude', 'what3words'],
    ],
    'tblVenueAgreements' => [
        'decision' => 'unlink',
        'reason'   => 'A hire agreement with a venue owner - a contract. It does not stop existing because the person who recorded it left.',
        'columns'  => ['notes', 'createdByID'],
    ],
    'tblVenueBookings' => [
        'decision' => 'unlink',
        'reason'   => 'Agreed bookings of a hired building. They belong to the organisation and the venue owner, not to whoever entered them.',
        'columns'  => ['notes', 'createdByID', 'updatedByID'],
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
    ],
    'tblVenueInvoices' => [
        'decision' => 'unlink',
        'reason'   => 'A FINANCIAL record of money owed for venue hire. Deleting it because of who entered it would put the accounts out of balance.',
        'columns'  => ['notes', 'createdByID'],
    ],
    'tblVenueUsageTypeWindows' => [
        'decision' => 'unlink',
        'reason'   => 'Default hours a venue may be used. Organisation-wide settings, not personal information.',
        'columns'  => ['note', 'createdByID'],
    ],
    'tblVenues' => [
        'decision' => 'unlink',
        'reason'   => 'The venue register itself. Deleting it because of who typed it in would remove every hired building the organisation uses, and take its bookings, invoices and hire agreements with it.',
        'columns'  => ['postcode', 'latitude', 'longitude', 'what3words', 'caretakerName', 'caretakerPhone', 'notes', 'createdByID'],
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
        'columns'  => ['userID'],
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
        // (27 October 2026) — **but only on the records whose `userID` COLUMN
        // holds the person's number.** An instruction matches one column, and
        // Logger::activity() takes the number as an optional argument that 279
        // of its 582 callers under web/ leave out. On those records the column
        // is empty while the session facts name the person, so an erasure
        // never reaches them: their address, browser name, headers and account
        // number survive it. **#626 is therefore NOT fully closed**, and the
        // rest is a decision for the owner, written up on that issue. The
        // record of WHAT happened, when, and under which organisation is kept
        // on purpose.
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
        'reason'   => 'Kept for running and repairing the portal. The record stays;'
                    . ' the link to the person is cut, so it can no longer be traced'
                    . ' back to them',
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
        'columns'  => ['createdByID', 'revokedByID'],
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
        'columns'  => ['createdByID', 'performedByUserID'],
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
        'reason'   => 'Kept for running and repairing the portal. The record stays;'
                    . ' the link to the person is cut, so it can no longer be traced'
                    . ' back to them',
        'columns'  => ['userID', 'ipAddress'],
    ],
    'tblErrors' => [
        'decision' => 'unlink',
        'reason'   => 'Kept for running and repairing the portal. The record stays;'
                    . ' the link to the person is cut, so it can no longer be traced'
                    . ' back to them',
        'columns'  => ['userID', 'visitorIP', 'userAgent', 'requestHeaders', 'resolvedByID'],
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
        'columns'  => ['createdByID', 'updatedByID', 'moderatedByID', 'submittedByID', 'statusChangedByID'],
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
        'columns'  => ['createdByID', 'updatedByID'],
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
        'columns'  => ['startedByID'],
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
        'decision' => 'unlink',
        // Two person columns, so GdprEraser::catalogue() carries a
        // hand-written instruction for each. What erasure does NOT do:
        // `pickupName` is typed by hand and may spell the erased person's
        // name; nothing ties it to an account.
        'reason'   => 'A child being checked in to, and out of, a session. It is the CHILD\'S record and stays. Whichever helper checked the child in, and whichever checked them out, is detached.',
        'columns'  => ['checkedInByID', 'checkedOutByID', 'pickupName'],
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
    ],
    'tblWorkflowActions' => [
        'decision' => 'unlink',
        'reason'   => 'One step in an approval: approved, rejected or commented, with any comment written at the time. The organisation\'s record of how a decision was reached, so it stays; who acted is detached.',
        'columns'  => ['actedByID', 'comment'],
    ],

    // =========================================================================
    // RETAIN - the law requires this to be kept
    // =========================================================================
    // Kept deliberately, with the reason and the period stated. Never quietly 
    // deleted, and never quietly kept either.
    // 5 tables.

    'tblCareCase' => [
        'decision' => 'unlink',
        'reason'   => 'A pastoral or wellbeing case. Safeguarding continuity matters to the remaining team, so the case stays and the person\'s name and details are removed. Kept rather than deleted because safeguarding records carry their own retention obligations.',
        'columns'  => ['personName'],
    ],
    'tblCareVisit' => [
        'decision' => 'unlink',
        // This said 'retain' until 11 October 2026 (roll-out package A2a),
        // because `visitedByID` could not be empty: the number of whoever
        // made the visit was left in place, pointing at an emptied account.
        // Migration 208 made the column emptiable and GdprEraser::catalogue()
        // now empties it, and `followUpAssignedToID`, with an instruction
        // each. The visit and its notes still stay with the case, for as long
        // as safeguarding policy keeps the case.
        //
        // NOT DECIDED: whether the NAME of whoever made a visit should be
        // kept on safeguarding grounds. That is for the organisation's
        // safeguarding lead. As built, it is not kept.
        'reason'   => 'A pastoral visit. Safeguarding and pastoral record: the visit and its notes are kept with '
                    . 'the case. Who made the visit, and who its follow-up was given to, are detached',
        'columns'  => ['visitedByID', 'followUpAssignedToID', 'notes'],
    ],
    'tblDbsChecks' => [
        'decision' => 'retain',
        // `recordedByID` (who typed the check in) IS cleared, since 11 October
        // 2026: the check itself, and whose it is, stay exactly as they are.
        // Whether the name of whoever recorded a check should be kept on
        // safeguarding grounds has NOT been decided; it is the same question
        // as for pastoral visits, for the organisation's safeguarding lead.
        'reason'   => 'Safeguarding record. Kept and flagged, never quietly removed',
        'period'   => 'as required by safeguarding policy',
        'columns'  => ['userID', 'notes', 'recordedByID'],
    ],
    'tblExpenseClaims' => [
        'decision' => 'unlink',
        'reason'   => 'An expense claim. Financial records must be kept for six years for HMRC, so the claim and its amounts stay and the claimant\'s name is removed.',
        'columns'  => ['userID'],
    ],
    'tblGivingStatementLog' => [
        'decision' => 'retain',
        // The period said '6 years', with nothing to say whose rule that was
        // and nothing that ever acted on it. It is each organisation's own
        // setting since roll-out package A2b.
        'reason'   => 'Proof of what was issued to whom, and when. Kept with the donor\'s giving records; the address it was mailed to is emptied at once, and the statement file is removed when the organisation\'s keep-until date has passed',
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

];
