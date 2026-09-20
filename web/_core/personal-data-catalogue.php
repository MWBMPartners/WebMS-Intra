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
        'columns'  => ['userID', 'notes'],
    ],
    'tblAssetLoans' => [
        'decision' => 'unlink',
        'reason'   => 'Who borrowed an item. Deleting the loan would erase the item\'s history, and the organisation still needs to know where its property went. The name goes, the loan stays.',
        'columns'  => ['notes', 'counterpartyUserID'],
    ],
    'tblAssetOrgs' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
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
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['sessionID'],
    ],
    'tblAttendanceSessions' => [
        'decision' => 'unlink',
        'reason'   => 'An attendance session. Deleting it would destroy the record of who came, for everybody who came.',
        'columns'  => ['sessionID', 'notes', 'createdByID', 'updatedByID'],
    ],
    'tblConsentLog' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'sessionID', 'ipAddress', 'userAgent'],
    ],
    'tblCountSessions' => [
        'decision' => 'unlink',
        'reason'   => 'A two-person offering count - a FINANCIAL control record, deliberately requiring two people. Deleting it on one person\'s request defeats the whole point of it.',
        'columns'  => ['notes', 'createdByID'],
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
    'tblErasureRequest' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'notes'],
    ],
    'tblEventAttendance' => [
        'decision' => 'unlink',
        'reason'   => 'Attendance at an event. The record of who was there matters to the organisation, so the row stays and the name goes.',
        'columns'  => ['userID', 'notes'],
    ],
    'tblEventCoordinators' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
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
        'columns'  => ['fullName', 'dateOfBirth', 'gender', 'allergies', 'medicalNotes', 'parentName', 'parentPhone', 'parentEmail', 'emergencyContactName', 'emergencyContactPhone', 'submittedByUserID'],
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
    'tblFormResponses' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['submitterID'],
    ],
    'tblGiftAidDeclaration' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['donorID', 'address', 'postcode', 'notes'],
    ],
    'tblGivingEntry' => [
        'decision' => 'unlink',
        'reason'   => 'A contribution record. Financial records must be kept, so the entry stays and the donor\'s name is removed.',
        'columns'  => ['donorID', 'donorName', 'notes'],
    ],
    'tblInvitation' => [
        'decision' => 'unlink',
        'reason'   => 'An invitation for somebody else to join. It is about the person invited.',
        'columns'  => ['email', 'createdByID'],
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
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['displayName'],
    ],
    'tblLocalAccounts' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
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
        'columns'  => ['userID'],
    ],
    'tblPasswordResets' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblPathwayEnrolments' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblPathwayProgress' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'notes'],
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
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblPrayerRequests' => [
        'decision' => 'unlink',
        'reason'   => 'A prayer request. The request itself is kept for the people praying; the name and contact details are removed.',
        'columns'  => ['submitterID'],
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
        'columns'  => ['notes', 'approvedByID'],
    ],
    'tblRotaSlot' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['assignedToID', 'notes', 'createdByID'],
    ],
    'tblRotaSwapRequest' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['targetUserID'],
    ],
    'tblSalvationCards' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['fullName', 'email', 'phone', 'address', 'assignedToID', 'notes'],
    ],
    'tblServicePlanItem' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['notes'],
    ],
    'tblServicePlanItems' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
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
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
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
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblUserGroups' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblUserMilestone' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblUserRoles' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
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
        'reason'   => 'Who uploaded a recording. The recording is the organisation\'s, so it stays and the name goes.',
        'columns'  => ['createdByID'],
    ],
    'tblCareAccessLog' => [
        'decision' => 'erase',
        'reason'   => 'A record of this person opening somebody\'s pastoral file. It is about their own actions and is removed with them.',
        'columns'  => ['userID'],
    ],
    'tblAssetAudit' => [
        'decision' => 'unlink',
        'reason'   => 'Who changed something in the asset register. The change history stays; the name goes.',
        'columns'  => ['userID'],
    ],
    'tblAssetScanLog' => [
        'decision' => 'unlink',
        'reason'   => 'Who scanned an asset label. The scan history stays; the name goes.',
        'columns'  => ['userID'],
    ],
    'tblAssetValueHistory' => [
        'decision' => 'unlink',
        'reason'   => 'Who recorded a change in an item\'s value. A financial history, so it stays; the name goes.',
        'columns'  => ['createdByID'],
    ],
    'tblUsers' => [
        'decision' => 'unlink',
        'reason'   => 'The account itself. It is emptied rather than deleted: hundreds of records point at it, and deleting the row would drag them all down with it. Name, email, telephone, address and coordinates are all cleared, and the account is left as a tombstone that names nobody.',
        'columns'  => ['userID', 'fullName', 'emailAddress', 'displayAddress', 'latitude', 'longitude', 'what3words'],
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
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
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
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['fullName', 'email', 'phone', 'assignedToID', 'notes', 'convertedUserID', 'createdByID'],
    ],
    'tblWebAuthnCredentials' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblZoomAccount' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
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
        'columns'  => ['createdByID'],
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
        'columns'  => ['createdByID'],
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
        'columns'  => ['userID', 'visitorIP', 'userAgent', 'requestHeaders'],
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
        'columns'  => ['createdByID', 'updatedByID'],
    ],
    'tblExternalFeeds' => [
        'decision' => 'unlink',
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID'],
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
        'decision' => 'unlink',
        'reason'   => 'Kept for running and repairing the portal. The record stays;'
                    . ' the link to the person is cut, so it can no longer be traced'
                    . ' back to them',
        'columns'  => ['sessionID', 'userAgent'],
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
        'columns'  => ['uploadedByUserID'],
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
        'reason'   => 'Only records who created or last changed this. Deleting the'
                    . ' row would destroy the organisation\'s own work because a'
                    . ' departed volunteer typed it in. The content stays; the name'
                    . ' goes',
        'columns'  => ['createdByID'],
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
        'decision' => 'retain',
        'reason'   => 'Safeguarding and pastoral record',
        'period'   => 'as required by safeguarding policy',
        'columns'  => ['notes'],
    ],
    'tblDbsChecks' => [
        'decision' => 'retain',
        'reason'   => 'Safeguarding record. Kept and flagged, never quietly removed',
        'period'   => 'as required by safeguarding policy',
        'columns'  => ['userID', 'notes'],
    ],
    'tblExpenseClaims' => [
        'decision' => 'unlink',
        'reason'   => 'An expense claim. Financial records must be kept for six years for HMRC, so the claim and its amounts stay and the claimant\'s name is removed.',
        'columns'  => ['userID'],
    ],
    'tblGivingStatementLog' => [
        'decision' => 'retain',
        'reason'   => 'Proof of what was issued to whom, and when',
        'period'   => '6 years',
        'columns'  => ['donorID', 'createdByID'],
    ],

    // =========================================================================
    // NOT PERSONAL - it only looks that way
    // =========================================================================
    // About a place or a thing, not a person. Left alone.
    // 3 tables.

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
