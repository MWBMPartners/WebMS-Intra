<?php
// Path: _core/AccountMerge.php
/**
 * -----------------------------------------------------------------------------
 * Merging two accounts that belong to one person 🔀 (#1064; D-185, D-190, D-191)
 * -----------------------------------------------------------------------------
 * The ONE place that decides what merging a duplicate account into the account
 * that stays does, whether it may be done, and that does it.
 *
 * WHO DOES WHAT (the owner, 16 November 2026 — D-190, D-191)
 * -------------------------------------------------------------------------
 * An administrator holding `accounts.merge` in every organisation either
 * account belongs to STARTS a merge (`start()`) and sees counts only. It
 * COMPLETES only when the person themselves proves, in one browser, that they
 * can sign in to BOTH accounts (`agree()`, twice). No administrator alone, and
 * no two administrators, can complete it. The installation's own administrator
 * needs the permission like anybody else.
 *
 * THE TWO-SIDED PROOF
 * -------------------------------------------------------------------------
 * Each agreement needs the #731 proof — `SelfServicePassword::recordProof()`,
 * written only by a path that really just re-authenticated somebody, good for
 * five minutes, single use. The page asks for it and passes the answer in as a
 * fact. The FIRST agreement stores a random browser token's SHA-256 and the page
 * puts the token in a cookie; the SECOND must be the other account, within
 * `AGREE_WINDOW_SECONDS`, presenting that token. A sign-in as a different
 * person drops every session key (`Auth::beginSessionFor()`), which is why the
 * token is a cookie and not a session value. Agreeing without the token
 * restarts the request from that account: so somebody controlling ONE of the
 * accounts can never make the other side agree in their own browser.
 *
 * THE DUPLICATE IS NEVER DELETED
 * -------------------------------------------------------------------------
 * Deleting a `tblUsers` row fires 38 `ON DELETE CASCADE` links and 134
 * `SET NULL` links (measured with `information_schema.REFERENTIAL_CONSTRAINTS`
 * on `full_schema.sql` in MySQL 8.0.36, 15 November 2026). It is CLOSED instead
 * (`closeDuplicate()`), and the completed request says where it went.
 *
 * WHY EVERY COLUMN IS LISTED HERE, AND WHAT KEEPS THE LIST TRUE
 * -------------------------------------------------------------------------
 * A merge that missed one table would leave rows naming an account nobody can
 * use (#706). `tools/audit-checks/check_account_merge_columns.py` compares the
 * list with the install script both ways; `tools/account-merge-selftest.php`
 * compares it with a LOADED database and drives every treatment. Which columns
 * sit in a unique key is read from `information_schema` at run time
 * (`uniqueKeysOf()`), never copied.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1064
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

final class AccountMerge
{
    /**
     * The name a closed (merged-away) account carries. Written by the merge
     * when it closes the duplicate, and again by an erasure of the account it
     * was merged into (`GdprEraser::eraseMergeRecords()`), which empties the
     * personal details written onto the closed row since. (The addresses
     * recorded for the merged account are emptied separately, when that
     * erasure request closes: `GdprEraser::closeRequest()`.)
     */
    public const CLOSED_NAME = 'Merged account';

    // =========================================================================
    // 🏷️ What completing a merge does to the duplicate's rows in one column
    // =========================================================================

    /** Re-pointed to the account that stays. */
    public const MOVE = 'move';

    /**
     * Re-pointed; where a unique key allows the person only ONE such row and the
     * kept account already has it, the duplicate's copy is removed. Only for rows
     * where the two copies say the same thing, or where the cost of losing the
     * duplicate's copy is stated beside the entry.
     */
    public const KEEP_KEPT_ON_CLASH = 'keep-kept-on-clash';

    /**
     * A person's own choice about being contacted or about sharing. Where both
     * accounts hold one, the STRICTER choice is written to the kept account's row
     * and the duplicate's copy removed: an opt-out is never undone by a merge
     * (D-058, D-073). The order of strictness is `CLASH_RULES[...]['stricter']`.
     */
    public const STRICTER_ON_CLASH = 'stricter-on-clash';

    /**
     * Where both accounts hold one and they say DIFFERENT things, the merge is
     * refused; where they agree, the duplicate's copy is removed. For membership
     * and role rows with flags (keeping the wider would grant access nobody
     * granted; keeping the narrower would take access away), and for answers a
     * person gave (an RSVP, a phone number) where neither can be assumed right.
     */
    public const SAME_OR_REFUSE = 'same-or-refuse';

    /** Where both accounts hold the one allowed row, refused: money or a relied-on record. */
    public const REFUSE_ON_CLASH = 'refuse-on-clash';

    /** Left naming the duplicate: history, not rewritten (D-116). */
    public const KEEP = 'keep';

    /** Microsoft/Google links and passkeys: moved only when the person ticks "bring them", else deleted. */
    public const SIGN_IN_IF_TICKED = 'sign-in-if-ticked';

    /** Deleted, never moved (D-018, D-190): the kept account keeps its own password and two-step setup. */
    public const SIGN_IN_DROPPED = 'sign-in-dropped';

    /** Refused while a STANDING row exists for either account (`REFUSE_WHEN`); rows no longer standing move. */
    public const REFUSE_IF_ANY = 'refuse-if-any';

    /**
     * Every column holding an account number, by what completing a merge does.
     * Each name appears exactly ONCE across groups (`columns()` throws otherwise).
     *
     * @var array<string, list<string>>
     */
    public const BY_TREATMENT = [
        // 🚚 LIVE state of the person, and nothing else (D-199 applied
        //    consistently, round 5 of #1064). Every record of who DID, decided,
        //    recorded or was SENT something at a past moment stays (KEEP below),
        //    EXCEPT where the portal uses "who made it" to decide who may see or
        //    change the record: those move, and the move is logged as its own
        //    entry (D-201, the owner, 16 November 2026; the ownership group at
        //    the end of this list).
        self::MOVE => [
            // records ABOUT the person, or that belong to them: their own claim, form response, prayer
            // request, booking, card, device, milestone, child's profile, care case, safeguarding check,
            // visitor record they became.
            'tblCareCase.personUserID', 'tblDbsChecks.userID', 'tblEventRegistrations.registeredForUserID',
            'tblExpenseClaims.userID', 'tblFormResponses.submitterID', 'tblKidProfiles.parentUserID',
            'tblPaymentMethod.userID', 'tblPrayerRequests.submitterID', 'tblPushSubscriptions.userID',
            'tblResourceBooking.bookedByID', 'tblUserMilestone.userID', 'tblVisitor.convertedUserID',
            // money about the person: their gifts, declarations, pledges and payments (a clash on a
            // kept-donor, statement or pledge row refuses instead).
            'tblCountEnvelopes.giverID', 'tblGiftAidDeclaration.donorID', 'tblGivingEntry.donorID',
            'tblPayment.userID', 'tblProjectPledge.donorID',
            // what the person is CURRENTLY given or asked to do, and the copies of memberships taken away
            // when somebody was placed in no organisation (#533; no unique key, so nothing can clash).
            'tblAssetLicenseAssignments.userID', 'tblAssetLoans.counterpartyUserID', 'tblAssetOwners.userID',
            'tblCareVisit.followUpAssignedToID', 'tblEventCrewMembers.userID', 'tblEventJobAssignments.userID',
            'tblEventPeople.userID', 'tblExternalAudienceMembers.userID', 'tblLeadershipAssignments.userID',
            'tblPrayerRequests.assignedToUserID', 'tblRotaSlot.assignedToID', 'tblRotaSwapRequest.requestedByID',
            'tblRotaSwapRequest.targetUserID', 'tblSalvationCards.assignedToID', 'tblServicePlanItem.presenterID',
            'tblSmallGroupPlaces.hostUserID', 'tblSmallGroups.hostUserID', 'tblTasks.assignedToID',
            'tblUserDeptsUnplaced.userID', 'tblUserGroupsUnplaced.userID', 'tblUserRolesUnplaced.userID',
            'tblVisitor.assignedToID',
            // OWNERSHIP of a live record, where the portal uses the creator to decide who sees or may change
            // it: their own tasks list, their unpublished recording notes, their private saved reports, the
            // attendance sessions they may delete (#529), the loan requests they may cancel or check in
            // (AssetRegister, $isRequester), and their own photos, which they see before approval and in
            // the original (photos/view, serve, serve-raw). Round 6 found the last two by searching every kept
            // column for a comparison in PHP as well as in SQL; round 5 had searched SQL only. Moving them
            // is chosen over teaching each permission check about merged numbers: one place, rather than
            // every page that asks, and a page that forgot would quietly lose the person's right.
            'tblAssetLoans.requestedByID', 'tblAttendanceSessions.createdByID', 'tblPhoto.uploadedByUserID',
            'tblRecordingNote.createdByID', 'tblReportDefinitions.createdByID', 'tblTasks.createdByID',
        ],

        // 🤝 The two copies say the same thing (the same group, role, meeting,
        //    tour), so removing the duplicate's loses nothing. ONE exception, with
        //    its cost said: `tblExternalApprovalNotices` is the memory of which day
        //    the person was last told about waiting imported events, and dropping
        //    the duplicate's can mean one repeated notice e-mail.
        self::KEEP_KEPT_ON_CLASH => [
            'tblExternalApprovalNotices.userID', 'tblSmallGroupMeetingAttendance.userID',
            'tblUserGroups.userID', 'tblUserRoles.userID', 'tblUserTours.userID',
        ],

        // 🔕 A person's own choice; the stricter one survives (see CLASH_RULES).
        self::STRICTER_ON_CLASH => [
            'tblNewsletterSubscription.userID', 'tblUserOutsideServicePref.userID', 'tblUserTranslationPref.userID',
        ],

        // ⚖️ Refused where the two copies differ (see CLASH_RULES for what is compared).
        self::SAME_OR_REFUSE => [
            'tblEventAttendance.userID', 'tblEventCoordinators.userID', 'tblEventRSVPs.userID',
            'tblPathwayEnrolments.userID', 'tblPathwayProgress.userID', 'tblReadingPlanEnrollment.userID',
            'tblSmallGroupMembers.userID', 'tblUserDepts.userID', 'tblUserSites.userID',
            'tblUserSmsPreference.userID', 'tblZoomAccount.userID',
        ],

        // 💷 Money, or a record somebody relies on.
        self::REFUSE_ON_CLASH => [
            'tblGivingKeptDonors.donorID', 'tblGivingStatementLog.donorID', 'tblPledges.userID',
        ],

        // 📜 HISTORY: left EXACTLY as written, under the duplicate's number
        //    (the owner, 16 November 2026 — D-199; D-116 whole). A merge never
        //    reassigns "who did it": the activity log, the change history, the
        //    access, scan, consent, send and usage logs and every record of a
        //    decision keep the number they were made under. Instead the merge
        //    writes a NEW activity entry saying the person on those earlier
        //    actions is now the kept account, and reading, exporting and
        //    ERASING the kept account follow completed merges
        //    (`mergedInto()`; `GdprEraser::execute()`, `inventory()` and the
        //    data copy ask it), so nothing here is out of an erasure's reach.
        //    (An earlier version of this package moved these; D-199 reversed it.)
        //    Round 5 applied that to EVERY such column, not only the logs: who
        //    created, changed, approved, decided, recorded, counted, moderated
        //    or was sent something. 121 columns that had been moving now stay
        //    (119 since round 6 moved two of them back as ownership).
        //    D-201 (the owner, 16 November 2026): the one exception is a
        //    "who made it" number the portal uses to decide who may see or
        //    change the record; those are in MOVE's ownership group. The
        //    activity log and the change history are never rewritten.
        //    The costs, said once here: a list a page draws by "who made it"
        //    (an announcement's author, a workflow the person started) does not
        //    show the kept account the rows made as the duplicate; and an
        //    approval given as the duplicate is not the kept account's, so a
        //    claim needing that person's approval asks the kept account again
        //    (the safe direction: never one person counted twice). A text held
        //    for quiet hours for the duplicate is refused when it comes due,
        //    because its account is closed (`Sms::whyNotThisPerson()`).
        self::KEEP => [
            'tblAccountMergeRequest.requestedByID', 'tblAccountMergeSide.userID', 'tblActivityLogs.userID',
            'tblAiUsage.userID', 'tblAnnouncements.createdByID', 'tblAnnouncements.updatedByID',
            'tblApiKeys.createdByID', 'tblApiKeys.revokedByID', 'tblAssetAudit.actorUserID',
            'tblAssetEventAssignments.assignedByID', 'tblAssetIdentifiers.createdByID',
            'tblAssetKioskTokens.createdByID', 'tblAssetLicenseAssignments.linkedByID',
            'tblAssetLicenseAssignments.releasedByID', 'tblAssetLoans.approvedByID',
            'tblAssetMaintenance.createdByID', 'tblAssetMaintenance.performedByUserID',
            'tblAssetResources.uploadedByID', 'tblAssetScanLog.actorUserID', 'tblAssetStocktakeItems.scannedByID',
            'tblAssetStocktakes.closedByID', 'tblAssetStocktakes.startedByID', 'tblAssetValueHistory.recordedByID',
            'tblAssets.createdByID', 'tblAttendanceSessions.updatedByID', 'tblAuditTrail.userID',
            'tblBankImports.importedByID', 'tblBankTxns.matchedByID', 'tblCapabilityMap.decidedByID',
            'tblCareAccessLog.viewerID', 'tblCareCase.openedByID', 'tblCareVisit.visitedByID',
            'tblCcliUsage.operatorID', 'tblConsentLog.userID', 'tblCountSessions.closedByID',
            'tblCountSessions.counter1ID', 'tblCountSessions.counter2ID', 'tblCountSessions.createdByID',
            'tblDbsChecks.recordedByID', 'tblDecisionMoments.recordedByID', 'tblDocuments.uploadedByID',
            'tblErasureRequest.processedByID', 'tblErasureReviewDecisions.decidedByID', 'tblErrors.resolvedByID',
            'tblErrors.userID', 'tblEventAttendance.markedByID', 'tblEventBroadcasts.sentByID',
            'tblEventCoordinators.grantedByID', 'tblEventHubResources.createdByID', 'tblEventHubVideos.createdByID',
            'tblEventOccurrenceOverrides.createdByID', 'tblEventRSVPInvites.createdByID',
            'tblEventRegistrations.reviewedByID', 'tblEventRegistrations.submittedByUserID', 'tblEvents.createdByID',
            'tblEvents.moderatedByID', 'tblEvents.statusChangedByID', 'tblEvents.submittedByID',
            'tblEvents.updatedByID', 'tblExpenseClaimApprovals.userID', 'tblExpenseClaimPayments.paidByID',
            'tblExternalAudienceMembers.createdByID', 'tblExternalEventApprovals.decidedByID',
            'tblExternalEventChoices.createdByID', 'tblExternalEventChoices.updatedByID',
            'tblExternalFeedRules.createdByID', 'tblExternalFeedRules.updatedByID',
            'tblExternalFeedRuns.triggeredByID', 'tblExternalFeeds.createdByID', 'tblExternalFeeds.updatedByID',
            'tblForms.createdByID', 'tblGivingEntry.recordedByID', 'tblGivingStatementLog.createdByID',
            'tblInvitation.acceptedByID', 'tblInvitation.createdByID', 'tblInvitation.revokedByID',
            'tblKidCheckins.checkedInByID', 'tblKidCheckins.checkedOutByID', 'tblLeadershipAssignments.createdByID',
            'tblLeadershipAssignments.updatedByID', 'tblLiveChatMessages.moderatedByID',
            'tblLivePrompts.createdByID', 'tblMigrations.executedByID', 'tblNewsletter.createdByID',
            'tblNewsletterRecipient.userID', 'tblNoticeboardPosters.createdByID',
            'tblNoticeboardPosters.updatedByID', 'tblNoticeboardUploads.createdByID',
            'tblOffboarding.offboardedByID', 'tblOffboarding.rehiredByID', 'tblPathwayEnrolments.enrolledByID',
            'tblPathwayProgress.markedByID', 'tblPathwayProgress.revokedByID', 'tblPathways.createdByID',
            'tblPhoto.moderatedByID', 'tblPhotoAlbum.createdByID',
            'tblPledgeCampaigns.createdByID', 'tblPrayerRequests.moderatorID', 'tblProject.createdByID',
            'tblProjectUpdate.postedByID', 'tblPublicHosts.approvedByID', 'tblReadingPlan.createdByID',
            'tblRecording.presenterID', 'tblRecording.uploadedByID', 'tblRecordingPlay.userID',
            'tblReportDefinitions.updatedByID', 'tblResourceBooking.approvedByID', 'tblRotaSlot.createdByID',
            'tblServicePlan.preparedByID', 'tblServicePlanMessages.createdByID', 'tblServicePlanState.updatedByID',
            'tblServicePlans.createdByID', 'tblSmallGroupMeetingAttendance.markedByID',
            'tblSmallGroupMeetings.recordedByID', 'tblSmallGroupMembers.addedByID',
            'tblSmallGroupPlaces.placeEnteredByID', 'tblSmallGroups.createdByID', 'tblSmallGroups.placeEnteredByID',
            'tblSmsMessage.recipientUserID', 'tblSongs.createdByID', 'tblUserDepts.addedByID',
            'tblUserGroups.addedByID', 'tblUserRoles.grantedByID', 'tblVenueAgreementFiles.uploadedByID',
            'tblVenueAgreements.createdByID', 'tblVenueBookingGroups.createdByID', 'tblVenueBookings.createdByID',
            'tblVenueBookings.updatedByID', 'tblVenueImportBatches.createdByID',
            'tblVenueInvoicePayments.recordedByID', 'tblVenueInvoices.createdByID',
            'tblVenueUsageTypeWindows.createdByID', 'tblVenues.createdByID', 'tblVisitor.createdByID',
            'tblVisitorContact.contactedByID', 'tblWebhooks.createdByID', 'tblWorkflowActions.actedByID',
            'tblWorkflowInstances.startedByID', 'tblZoomMeeting.createdByID',
        ],

        // 🔑 Moved only because the person has just proved BOTH accounts, and
        //    only when they tick the box (D-190).
        self::SIGN_IN_IF_TICKED => [
            'tblLinkedAccounts.userID', 'tblWebAuthnCredentials.userID',
        ],

        // 🔒 Never moved. A password is chosen by its account; the kept account's
        //    password, two-step codes, remembered devices, reset links and kiosk
        //    PIN are its own and stay; the duplicate's go with it.
        self::SIGN_IN_DROPPED => [
            'tblAssetKioskPins.userID', 'tblLocalAccounts.userID', 'tblPasswordResets.userID',
            'tblTotpBackupCodes.userID', 'tblTrustedDevices.userID',
        ],

        // ⛔ See REFUSE_WHEN.
        self::REFUSE_IF_ANY => [
            'tblErasureRequest.userID', 'tblOffboarding.userID', 'tblRestoreHeldRows.userID',
        ],
    ];

    /**
     * Columns holding an account number on SOME rows only. `when` is fixed SQL
     * written with `{t}` for the table, so it can be applied to either side of a
     * join. The hard gate cannot discover a new one; it only checks these exist.
     *
     * @var array<string, array{treatment: string, when: string}>
     */
    public const POLYMORPHIC = [
        'tblWorkflowSteps.assigneeValue'   => ['treatment' => self::MOVE, 'when' => "{t}.`assigneeType` = 'user'"],
        // One person on one audience list once (`uq_extaud_owner_member`); the two copies say the same thing.
        'tblExternalAudienceMembers.refID' => ['treatment' => self::KEEP_KEPT_ON_CLASH, 'when' => "{t}.`kind` = 'person'"],
        'tblAuditTrail.recordID'           => ['treatment' => self::KEEP, 'when' => "{t}.`tableName` = 'tblUsers'"],
        // A made-up person Demo Data created and will later wipe (that wipe
        // cascades through 38 tables). Merging INTO one would hand real records
        // to an account due to be wiped; merging OUT of one would make a made-up
        // person's rows real. Either way, refused.
        'tblDemoDataRegister.rowID'        => ['treatment' => self::REFUSE_IF_ANY, 'when' => "{t}.`tableName` = 'tblUsers'"],
    ];

    /** @var array<string, string> Name-shaped columns that are not accounts. Fails both ways in the gate. */
    public const NOT_AN_ACCOUNT = [
        'tblVenueAgreements.supersededByID' => 'The agreement that replaced this one, not a person.',
    ];

    /**
     * Which rows of a refuse-if-any column are STANDING (fixed SQL, `{t}` for the
     * table). Only these refuse; the rest move. A cancelled erasure request is
     * over; an offboarding undone by a rehire is history (rehire.php reactivates
     * the account within the undo window); a held row already put back has had
     * its copy emptied (#694). A Demo Data person always stands.
     *
     * @var array<string, string>
     */
    public const REFUSE_WHEN = [
        'tblErasureRequest.userID'  => "{t}.`status` <> 'cancelled'",
        'tblOffboarding.userID'     => '{t}.`rehiredAt` IS NULL',
        'tblRestoreHeldRows.userID' => '{t}.`putBackAtUtc` IS NULL',
        'tblDemoDataRegister.rowID' => '1 = 1',
    ];

    /**
     * Per-column clash rules. `stricter`: column => values strictest FIRST.
     * `same`: what must agree, as column names or `{t}` expressions; compared
     * NULL-safely (`<=>`). Every STRICTER_ON_CLASH and SAME_OR_REFUSE column has
     * exactly one entry here, and nothing else does (the self-test checks both).
     *
     * @var array<string, array{stricter?: array<string, list<string>>, same?: list<string>}>
     */
    public const CLASH_RULES = [
        'tblNewsletterSubscription.userID'  => ['stricter' => ['optedIn' => ['0', '1']]],
        'tblUserOutsideServicePref.userID'  => ['stricter' => ['choice' => ['off', 'on']]],
        'tblUserTranslationPref.userID'     => ['stricter' => ['autoTranslate' => ['0', '1']]],
        'tblEventAttendance.userID'         => ['same' => ['notes']],
        'tblEventCoordinators.userID'       => ['same' => ['permissions', '({t}.`revokedAt` IS NULL)']],
        'tblEventRSVPs.userID'              => ['same' => ['response', 'status', 'guestCount']],
        'tblPathwayEnrolments.userID'       => ['same' => ['status']],
        'tblPathwayProgress.userID'         => ['same' => ['({t}.`revokedAt` IS NULL)']],
        'tblReadingPlanEnrollment.userID'   => ['same' => ['currentDay', '({t}.`completedAt` IS NULL)']],
        'tblSmallGroupMembers.userID'       => ['same' => ['memberRole', 'status']],
        'tblUserDepts.userID'               => ['same' => ['isDeptLead', 'isDeptAssistant', 'isDeptSecretary', 'isApprover', 'isMandatoryApprover']],
        'tblUserSites.userID'               => ['same' => ['isActive', 'isSiteAdmin', 'isSiteRootAdmin']],
        'tblUserSmsPreference.userID'       => ['same' => ['phoneNumber', 'categories']],
        'tblZoomAccount.userID'             => ['same' => ['zoomUserId']],
    ];

    /**
     * What each treatment means, in the words both preview pages show, in the
     * order they are shown: [heading, note]. Plain English on purpose (the
     * owner's standing rule): no table names, no decision numbers.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const TREATMENT_WORDS = [
        self::MOVE => ['Would move to the account that stays', ''],
        self::KEEP_KEPT_ON_CLASH => ['Would move, except where the account that stays already has the same one',
            'Where both accounts have the same one (the same group, the same meeting), the duplicate\'s copy is removed because it says nothing more.'],
        self::STRICTER_ON_CLASH => ['Choices about being contacted or sharing',
            'Where both accounts made a choice, the stricter one is kept: if either said no, the answer stays no.'],
        self::SAME_OR_REFUSE => ['Would move, unless both accounts have one that says something different',
            'For example a different reply to the same event, or a different role in the same group. Then the merge waits until they are made the same.'],
        self::REFUSE_ON_CLASH => ['Money records that would move, unless both accounts have one',
            'A pledge, a Gift Aid record or a giving statement. If both accounts have one for the same thing, the merge waits until it is sorted out by hand.'],
        // ⚠️ TRUE SINCE D-199, and only since: round 2 of this package moved
        //    these rows while this sentence said they were never rewritten.
        self::KEEP => ['History, left exactly as it is',
            'The activity log, the change history and the other records of who did, approved, decided, recorded or was '
            . 'sent something keep the account number they were made under and are never rewritten. (Records the person '
            . 'manages, such as their loans, photos, tasks and bookings, move instead.) A new entry records that those '
            . 'earlier actions are now the account that stays. If the person later asks for their details to be erased, '
            . 'these are erased with them.'],
        self::SIGN_IN_IF_TICKED => ['Microsoft or Google sign-ins and passkeys',
            'These move only if the person ticks the box when they confirm. Otherwise they are deleted with the duplicate.'],
        self::SIGN_IN_DROPPED => ['Other ways of signing in, which are never moved',
            'The duplicate\'s password, two-step codes, remembered devices, password-reset links and kiosk PIN are deleted. The account that stays keeps its own.'],
        self::REFUSE_IF_ANY => ['Records that stop a merge',
            'An open erasure request, an offboarding that has not been undone, records a restore is still holding back, or a made-up Demo Data person.'],
    ];

    /**
     * The one heading the KEPT account sees over its own records (finding F of
     * the third check): every per-treatment heading above describes what happens
     * to the DUPLICATE's records, so none of them is true of the account that
     * stays — its records simply stay.
     */
    public const KEPT_SIDE_WORDS = ['This account\'s own records, which all stay with it',
        'Nothing of this account moves or is deleted. Its own password, Microsoft or Google sign-ins and passkeys stay.'];

    /**
     * 📜 What D-199 costs, said to the person and to the organisation in every
     *    preview (round 6: until then only comments said it). The first line is
     *    the heading; each further line is one cost, in plain words. Printed by
     *    `templates/merge-counts.php`, so both previews show the same words.
     *    Round 7: the person AND the administrator read them, so they speak of
     *    "the person" and never "you".
     */
    public const HISTORY_COSTS = [
        'What moves, and what stays under the old account number',
        'The records the person manages move to the account that stays, and the move is written in the activity log: '
            . 'loans they asked for, photos they uploaded, tasks they created, their saved reports and recording notes, '
            . 'attendance sessions they created, their room bookings and their rota-swap requests. The activity log, the '
            . 'change history and the other records of who did, approved, decided, recorded or was sent something stay '
            . 'under the old account number and are never rewritten. They are still counted as the person\'s: in a copy '
            . 'of their data, in what the portal says it holds about them, and if they ask for their details to be '
            . 'erased. Three things follow.',
        'Lists that show what somebody made (an announcement\'s author, a workflow somebody started) will not show the '
            . 'account that stays the things made under the old number.',
        'An approval given under the old number is not counted as the account that stays: if a claim needs that '
            . 'person\'s approval, they are asked again. Nobody is ever counted twice.',
        'A text message held back for quiet hours for the old number is not sent when its time comes, because that '
            . 'account is closed.',
    ];

    /** How long a request stays open, and how long the second agreement may follow the first. */
    public const REQUEST_DAYS          = 14;
    public const AGREE_WINDOW_SECONDS  = 1800;

    /**
     * An agreement is refused from an account whose way in (password, or the
     * e-mail address a reset link goes to) was chosen by somebody else within
     * this many days BEFORE the request started, or at any time since
     * (`tblUsers.signInChangedByOtherAtUtc`). See `R_SIGN_IN_CHANGED`. Ninety
     * days is a judgement, put to the owner on #1064.
     */
    public const SIGN_IN_CHANGE_DAYS = 90;

    /**
     * Nobody can agree to a merge until this many days after its start notice
     * was sent (the owner, 16 November 2026 — D-200), so a person who did not
     * ask for it has a week to see the notice and cancel. Stored on the request
     * as `notBeforeUtc` when it is started.
     */
    public const WAIT_DAYS = 7;

    /**
     * The browser cookie that carries the first agreement to the second. On
     * https it carries the `__Host-` prefix (Secure, Path=/, no Domain), so a
     * neighbouring sub-domain cannot plant one; on plain http — a test server,
     * or an installation without a certificate — a browser refuses a `__Host-`
     * cookie, so the plain name is used there. `cookieName()` is the one rule.
     */
    public const BROWSER_COOKIE = 'portal_account_merge';

    /** The cookie's name for this request (see BROWSER_COOKIE). */
    public static function cookieName(bool $https): string
    {
        return $https === true ? '__Host-' . self::BROWSER_COOKIE : self::BROWSER_COOKIE;
    }

    // =========================================================================
    // 🚫 Refusals and outcomes — the words are here and nowhere else
    // =========================================================================

    public const R_NO_PERMISSION        = 'no_permission';
    public const R_SAME_ACCOUNT         = 'same_account';
    public const R_NOT_FOUND            = 'not_found';
    public const R_ERASED               = 'erased';
    public const R_WHOLE_PORTAL_ADMIN   = 'whole_portal_admin';
    public const R_NEEDS_GLOBAL         = 'needs_global';
    public const R_NEEDS_GLOBAL_SINGLE  = 'needs_global_single';
    public const R_IS_YOU               = 'is_you';
    public const R_ADMINISTRATOR        = 'administrator';
    public const R_CLOSED               = 'closed';
    public const R_REFUSE_IF_ANY        = 'held_elsewhere';
    public const R_CLASH                = 'clash';
    public const R_CHOICES_DIFFER       = 'choices_differ';
    public const R_UNKNOWN_CLASH        = 'unknown_clash';
    public const R_SIGN_IN_CHANGED      = 'sign_in_changed';
    public const R_CHANGED_DURING       = 'changed_during';
    public const R_TOO_SOON             = 'too_soon';
    public const R_MERGED_AWAY          = 'merged_away';

    /**
     * What the PERSON reads when their agreement is refused — their own words,
     * never the administrator's ("you need the permission"), and never a fact
     * about the OTHER account beyond what the final step shows anyway.
     */
    private const PERSON_WORDS = [
        // D-200: say how long the bar lasts, and that a new request is needed.
        self::R_SIGN_IN_CHANGED => 'This account\'s password or e-mail address was changed by somebody other than you '
            . 'in the 90 days before this request was made, or since. Neither account can agree to this request: once '
            . '90 days have passed since that change, an administrator will need to make a new request. If you did not '
            . 'expect that change, tell whoever looks after the portal for your organisation.',
        self::R_TOO_SOON => 'A merge can only be agreed to from 7 days after you were told about it, so that you have '
            . 'time to see the message and cancel if it was not you. Please come back then. You can cancel it now.',
        self::R_CHANGED_DURING => 'Something changed while the accounts were being merged, so nothing was merged. '
            . 'Please try again in a minute.',
        self::R_CHOICES_DIFFER => 'Both accounts have a record that can only be kept once, and the two say different '
            . 'things, for example two different replies to the same event. Change one of them so they match, then agree again.',
        self::R_CLASH => 'Both accounts have a money record that can only be kept once, such as a pledge or a Gift Aid '
            . 'record. The people who look after giving need to sort that out first.',
    ];

    /**
     * The person's words for a refusal: their own sentence where one is written,
     * else one plain sentence that this merge cannot go ahead now and who can help.
     */
    public static function personRefusalWords(string $code): string
    {
        self::refusalWords($code); // an unknown code still throws

        return self::PERSON_WORDS[$code] ?? 'This merge cannot go ahead at the moment. Nothing was changed. Whoever '
            . 'looks after the portal for your organisation can tell you why.';
    }

    private const REFUSAL_WORDS = [
        self::R_NO_PERMISSION => 'You cannot merge accounts here. It needs the "Merge two accounts" permission in '
            . 'every organisation either account belongs to.',
        self::R_SAME_ACCOUNT => 'Those are the same account. Choose two different accounts.',
        // One sentence for "does not exist" AND "not visible here" (AccountGuard row 3).
        self::R_NOT_FOUND => 'No account with that number was found here.',
        self::R_ERASED => 'One of those accounts has been erased on request. An erased account cannot be merged.',
        self::R_WHOLE_PORTAL_ADMIN => 'One of those accounts has administrator access to the whole portal. Only a '
            . 'global administrator can remove that access, and it must be removed before the account can be merged.',
        self::R_NEEDS_GLOBAL => 'One of those accounts also belongs to another organisation, so only a global '
            . 'administrator can merge it.',
        // ⚠️ Single-organisation mode has no "other organisation" to speak of
        //    (finding 3 of the independent check): the account holds membership
        //    records for organisation numbers other than this one, which only a
        //    global administrator may change.
        self::R_NEEDS_GLOBAL_SINGLE => 'One of those accounts has membership records outside this organisation\'s own, '
            . 'so only a global administrator can merge it.',
        self::R_IS_YOU => 'One of those accounts is your own. Somebody else must start this merge.',
        self::R_ADMINISTRATOR => 'One of those accounts is an organisation administrator\'s. Remove its administrator '
            . 'access first, then merge it.',
        self::R_CLOSED => 'One of those accounts is switched off. Switch it back on first if it really is a duplicate.',
        self::R_MERGED_AWAY => 'One of those accounts was already merged into another account, and nothing is left on it '
            . 'to merge. Use the account it was merged into instead.',
        self::R_REFUSE_IF_ANY => 'One of those accounts has an open erasure request, an offboarding that has not been '
            . 'undone, records a restore is still holding back, or is a made-up person created by Demo Data. Deal with '
            . 'that first; a merge must not hide it.',
        self::R_CLASH => 'Both accounts hold a record a person can have only one of, and it is about money or a record '
            . 'somebody relies on (a pledge, a Gift Aid record, a giving statement sent). Sort that out by hand first.',
        self::R_CHOICES_DIFFER => 'Both accounts hold a record a person can have only one of, and the two say different '
            . 'things (for example a different reply to the same event, or a different role in the same group). '
            . 'Make them the same on one account first, so nothing is decided by guessing.',
        self::R_UNKNOWN_CLASH => 'The portal does not know how to merge one of the kinds of record these accounts '
            . 'have. Nothing has been changed. Please report this.',
        self::R_SIGN_IN_CHANGED => 'The password or e-mail address of one of those accounts was changed by somebody '
            . 'other than its owner in the 90 days before this request, or since, so its owner cannot agree to it. Once '
            . '90 days have passed since that change, a new request will be needed.',
        self::R_CHANGED_DURING => 'Something changed while the accounts were being merged, so nothing was merged.',
        self::R_TOO_SOON => 'Nobody can agree to this merge until 7 days after the notice was sent.',
    ];

    /** Words for a refusal code; an unknown code throws rather than printing nothing. */
    public static function refusalWords(string $code): string
    {
        if (array_key_exists($code, self::REFUSAL_WORDS) === false) {
            throw new \InvalidArgumentException('AccountMerge: unknown refusal "' . $code . '"');
        }

        return self::REFUSAL_WORDS[$code];
    }

    // =========================================================================
    // 📋 The list, flattened, and described in plain words
    // =========================================================================

    /**
     * @return array<string, array{treatment: string, when: string}>
     * @throws \LogicException When a column is listed twice.
     */
    public static function columns(): array
    {
        $out = [];
        foreach (self::BY_TREATMENT as $treatment => $names) {
            foreach ($names as $name) {
                if (isset($out[$name]) === true) {
                    throw new \LogicException('AccountMerge: ' . $name . ' is listed twice');
                }
                $out[$name] = ['treatment' => $treatment, 'when' => ''];
            }
        }
        foreach (self::POLYMORPHIC as $name => $entry) {
            if (isset($out[$name]) === true) {
                throw new \LogicException('AccountMerge: ' . $name . ' is listed twice');
            }
            $out[$name] = $entry;
        }

        return $out;
    }

    /** Words that read wrong when split out of a table name. */
    private const WORD_FIXES = [
        'Ccli' => 'CCLI song licence', 'Dbs' => 'DBS (criminal record)', 'Sms' => 'text message',
        'Ai' => 'AI assistant', 'Api' => 'API (programme access)', 'RSVPs' => 'replies to events',
        'RSVPInvites' => 'event invitations', 'Totp' => 'two-step sign-in', 'Webhooks' => 'web hooks',
        'Kid' => 'children\'s', 'Zoom' => 'Zoom',
    ];

    /** Column names that mean "the record is the person's own". */
    private const OWN_COLUMNS = [
        'userID', 'memberID', 'donorID', 'submitterID', 'recipientUserID', 'convertedUserID', 'personUserID',
        'registeredForUserID', 'acceptedByID', 'giverID', 'counterpartyUserID', 'hostUserID', 'parentUserID',
        'leaderID', 'assigneeValue', 'refID', 'rowID', 'recordID', 'presenterID', 'operatorID', 'viewerID',
        'counter1ID', 'counter2ID',
    ];

    /**
     * A plain description of one column's records, for the preview pages, worked
     * out by one rule from its name (no hand-written label can go stale):
     * "Prayer requests (the person's own)", "Events (who created them)".
     */
    public static function describe(string $name): string
    {
        [$table, $column] = self::split($name);
        $words = [];
        foreach (preg_split('/(?<=[a-z0-9])(?=[A-Z])/', substr($table, 3)) ?: [] as $word) {
            $words[] = self::WORD_FIXES[$word] ?? strtolower($word);
        }
        $what = ucfirst(implode(' ', $words));
        if (in_array($column, self::OWN_COLUMNS, true) === true) {
            return $what . ' (the person\'s own)';
        }
        if ($column === 'createdByID') {
            return $what . ' (who created them)';
        }
        if ($column === 'updatedByID') {
            return $what . ' (who last changed them)';
        }
        $bare = (string) preg_replace('/(User)?ID$/', '', $column);
        $verb = strtolower(implode(' ', preg_split('/(?<=[a-z])(?=[A-Z])/', $bare) ?: [$bare]));
        if (str_ends_with($verb, ' by')) {
            return $what . ' (who ' . substr($verb, 0, -3) . ' them)';
        }
        if (str_ends_with($verb, ' to')) {
            return $what . ' (who they are ' . $verb . ')';
        }

        return $what . ' (' . $verb . ')';
    }

    // =========================================================================
    // ⚖️ The rule — FACTS in, a refusal code (or '') out. No database here.
    // =========================================================================

    /**
     * May these two accounts be merged with this person as the one who asks?
     * '' means yes. Asked by the preview page, by `start()`, and by `agree()`
     * under the lock with the REQUESTER as the actor.
     *
     * Facts: mayMergeHere, mayMergeEverywhere (bool), actorId, keptId,
     * duplicateId, single (bool: single-organisation mode), accounts: id =>
     * [guard, guardReason, isActive, adminAnywhere, heldElsewhere]; a missing key
     * means the account does not exist OR is not visible (factsFor() reads
     * nothing more about one it cannot see).
     *
     * ⚠️ "everywhere" is asked AFTER "not found": refusing on it first would say
     *    an unseen account belongs to another organisation (AccountGuard row 3).
     *
     * @param array<string, mixed> $facts
     */
    public static function whyNotAllowed(array $facts): string
    {
        if (($facts['mayMergeHere'] ?? false) !== true) {
            return self::R_NO_PERMISSION;
        }
        $kept = (int) ($facts['keptId'] ?? 0);
        $dup  = (int) ($facts['duplicateId'] ?? 0);
        if ($kept === $dup) {
            return self::R_SAME_ACCOUNT;
        }
        $accounts = $facts['accounts'] ?? [];
        foreach ([$kept, $dup] as $id) {
            if ($id <= 0 || isset($accounts[$id]) === false || $accounts[$id]['guard'] === AccountGuard::NOT_FOUND) {
                return self::R_NOT_FOUND;
            }
        }
        foreach ([$kept, $dup] as $id) {
            if ($accounts[$id]['guard'] === AccountGuard::ERASED) {
                return self::R_ERASED;
            }
            // Round 5: AccountGuard's row 3b. Never "switch it back on" —
            // a merged-away account can never be switched back on.
            if ($accounts[$id]['guard'] === AccountGuard::MERGED) {
                return self::R_MERGED_AWAY;
            }
        }
        foreach ([$kept, $dup] as $id) {
            if ($accounts[$id]['guard'] !== AccountGuard::ALLOW) {
                // AccountGuard says why: rows 6/7 (the account is a global or a
                // portal-wide administrator) or row 9 (it belongs elsewhere).
                $reason = (string) ($accounts[$id]['guardReason'] ?? '');
                if ($reason === 'global_admin' || $reason === 'portal_admin') {
                    return self::R_WHOLE_PORTAL_ADMIN;
                }

                return ($facts['single'] ?? false) === true ? self::R_NEEDS_GLOBAL_SINGLE : self::R_NEEDS_GLOBAL;
            }
        }
        if (($facts['mayMergeEverywhere'] ?? false) !== true) {
            return self::R_NO_PERMISSION;
        }
        $actor = (int) ($facts['actorId'] ?? 0);
        if ($kept === $actor || $dup === $actor) {
            return self::R_IS_YOU;
        }
        foreach ([$kept, $dup] as $id) {
            if ($accounts[$id]['adminAnywhere'] === true) {
                return self::R_ADMINISTRATOR;
            }
        }
        foreach ([$kept, $dup] as $id) {
            if ($accounts[$id]['isActive'] !== true) {
                return self::R_CLOSED;
            }
        }
        foreach ([$kept, $dup] as $id) {
            if ($accounts[$id]['heldElsewhere'] !== 0) {
                return self::R_REFUSE_IF_ANY;
            }
        }

        return '';
    }

    // =========================================================================
    // 🔍 Reading the facts
    // =========================================================================

    /**
     * Read the facts `whyNotAllowed()` needs.
     *
     * ⚠️ NOTHING MORE IS READ ABOUT AN ACCOUNT THIS ACTOR CANNOT SEE (finding 7).
     *    An earlier version read memberships, standing records and the permission
     *    in other organisations for an account that then came back "not found",
     *    so "exists elsewhere" took measurably longer than "does not exist" with
     *    the same page. Both now issue the same statements, which the self-test
     *    counts.
     *
     * @return array<string, mixed>
     */
    public static function factsFor(
        mysqli $db,
        int $actorId,
        bool $actorIsGlobal,
        bool $actorIsRoot,
        int $siteId,
        bool $singleOrganisation,
        int $keptId,
        int $duplicateId
    ): array {
        $ids      = array_values(array_unique(array_filter([$keptId, $duplicateId], static fn (int $i): bool => $i > 0)));
        $guard    = AccountGuard::facts($ids, $siteId);
        $accounts = [];
        $sites    = [$siteId];
        foreach ($ids as $id) {
            if (isset($guard[$id]) === false) {
                continue;
            }
            $verdict = AccountGuard::decide($actorIsGlobal, $guard[$id], AccountGuard::REACH_ACCOUNT, $singleOrganisation);
            if ($verdict['verdict'] === AccountGuard::NOT_FOUND) {
                continue;
            }
            $row = self::one(
                $db,
                'SELECT u.`isActive`, u.`isAdmin`, u.`isRootAdmin`, '
                . '(SELECT COUNT(*) FROM `tblUserSites` us WHERE us.`userID` = u.`userID` '
                . 'AND (us.`isSiteAdmin` = 1 OR us.`isSiteRootAdmin` = 1)) AS `siteAdminRows` '
                . 'FROM `tblUsers` u WHERE u.`userID` = ?',
                $id
            );
            foreach (self::all($db, 'SELECT `siteID` FROM `tblUserSites` WHERE `userID` = ?', $id) as $m) {
                $sites[] = (int) $m['siteID'];
            }
            $accounts[$id] = [
                'guard'         => $verdict['verdict'],
                'guardReason'   => $verdict['reason'],
                'isActive'      => self::flag($row['isActive'] ?? 0),
                'adminAnywhere' => self::flag($row['isAdmin'] ?? 0) || self::flag($row['isRootAdmin'] ?? 0)
                    || (int) ($row['siteAdminRows'] ?? 0) > 0,
                'heldElsewhere' => self::standingRows($db, $id),
            ];
        }

        $passes = static fn (int $site): bool => $actorId > 0
            && self::isAdministratorOf($db, $actorId, $site)
            && Capabilities::allows($db, $actorId, $site, Capabilities::ACCOUNTS_MERGE, $actorIsRoot);
        $here       = $passes($siteId);
        $everywhere = $here;
        foreach (array_unique($sites) as $site) {
            if ($everywhere === false) {
                break;
            }
            $everywhere = $passes($site);
        }

        return [
            'mayMergeHere'       => $here,
            'mayMergeEverywhere' => $everywhere,
            'actorId'            => $actorId,
            'keptId'             => $keptId,
            'duplicateId'        => $duplicateId,
            'single'             => $singleOrganisation,
            'accounts'           => $accounts,
        ];
    }

    /**
     * Is this person an administrator of that organisation — `App::isAdmin()`'s
     * four grounds, asked of a named organisation.
     *
     * ⚠️ The `us.isActive = 1` term CANNOT CHANGE ANY ANSWER TODAY: it is always
     *    asked with the permission, whose role already needs a switched-on
     *    membership (`Roles::hasAnyOf()`). Planted out, the self-test stayed
     *    green (15 November 2026). Kept so the method means what it says alone.
     */
    private static function isAdministratorOf(mysqli $db, int $userId, int $siteId): bool
    {
        $row = self::one(
            $db,
            'SELECT u.`isAdmin`, u.`isRootAdmin`, '
            . '(SELECT COUNT(*) FROM `tblUserSites` us WHERE us.`userID` = u.`userID` AND us.`siteID` = ? '
            . 'AND us.`isActive` = 1 AND (us.`isSiteAdmin` = 1 OR us.`isSiteRootAdmin` = 1)) AS `here` '
            . 'FROM `tblUsers` u WHERE u.`userID` = ? AND u.`isActive` = 1',
            $siteId,
            $userId
        );
        if ($row === null) {
            return false;
        }

        return self::flag($row['isAdmin']) || self::flag($row['isRootAdmin']) || (int) $row['here'] > 0;
    }

    /** How many STANDING refuse-if-any rows name this account. */
    private static function standingRows(mysqli $db, int $userId): int
    {
        $total = 0;
        foreach (self::columns() as $name => $entry) {
            if ($entry['treatment'] !== self::REFUSE_IF_ANY) {
                continue;
            }
            [$table, $column] = self::split($name);
            $t   = '`' . $table . '`';
            $sql = 'SELECT COUNT(*) AS `n` FROM ' . $t . ' WHERE ' . $t . '.`' . $column . '` = ?'
                . self::cond($entry['when'], $t) . self::cond(self::REFUSE_WHEN[$name] ?? '1 = 0', $t);
            $total += (int) (self::one($db, $sql, self::bindFor($entry, $userId))['n'] ?? 0);
        }

        return $total;
    }

    // =========================================================================
    // 🧮 The preview — counts only, changes nothing
    // =========================================================================

    /**
     * What merging would do, as COUNTS, and the refusal the counts cause. One
     * line per listed column that names the duplicate, plus a line for a
     * column where only the KEPT account has standing rows (so "Stops a merge"
     * is shown for either side).
     *
     * @return array{lines: list<array{table: string, column: string, words: string, treatment: string, rows: int, clashes: int, differences: int}>, refusal: string, unknown: list<string>}
     */
    public static function preview(mysqli $db, int $keptId, int $duplicateId): array
    {
        $keys    = self::uniqueKeysOf($db);
        $lines   = [];
        $unknown = [];
        $money   = false;
        $differ  = false;

        foreach (self::columns() as $name => $entry) {
            [$table, $column] = self::split($name);
            $t     = '`' . $table . '`';
            $rows  = (int) (self::one(
                $db,
                'SELECT COUNT(*) AS `n` FROM ' . $t . ' WHERE ' . $t . '.`' . $column . '` = ?' . self::cond($entry['when'], $t),
                self::bindFor($entry, $duplicateId)
            )['n'] ?? 0);
            $treatment = $entry['treatment'];
            $inKeys    = $keys[$table][$column] ?? [];
            if ($inKeys !== [] && $treatment === self::MOVE) {
                $unknown[] = $name;
            }
            $clashes = 0;
            $differences = 0;
            $handlesClash = in_array($treatment, [self::KEEP_KEPT_ON_CLASH, self::STRICTER_ON_CLASH, self::SAME_OR_REFUSE, self::REFUSE_ON_CLASH], true);
            if ($rows > 0 && $inKeys !== [] && $handlesClash === true) {
                $clashes = self::clashCount($db, $table, $column, $entry, $inKeys, $keptId, $duplicateId, '');
                if ($treatment === self::SAME_OR_REFUSE && $clashes > 0) {
                    $differences = self::clashCount($db, $table, $column, $entry, $inKeys, $keptId, $duplicateId, self::differs($name));
                }
            }
            if ($treatment === self::REFUSE_ON_CLASH && $clashes > 0) {
                $money = true;
            }
            if ($differences > 0) {
                $differ = true;
            }
            if ($rows > 0) {
                $lines[] = ['table' => $table, 'column' => $column, 'words' => self::describe($name),
                    'treatment' => $treatment, 'rows' => $rows, 'clashes' => $clashes, 'differences' => $differences];
            }
        }
        $refusal = '';
        if ($differ === true) {
            $refusal = self::R_CHOICES_DIFFER;
        }
        if ($money === true) {
            $refusal = self::R_CLASH;
        }
        if ($unknown !== []) {
            $refusal = self::R_UNKNOWN_CLASH;
        }

        return ['lines' => $lines, 'refusal' => $refusal, 'unknown' => $unknown];
    }

    /**
     * One account's counts of ITS OWN records, in the preview's shape, and
     * NOTHING compared with any other account (clashes and differences are 0).
     *
     * ⚠️ This is what a person sees before both agreements are in (adversarial
     *    check, finding 1). `preview()` counts the DUPLICATE's records and
     *    compares them with the kept account's, so showing it to either side
     *    early told a person about an account they had not yet proved was
     *    theirs — "the victim has a pastoral care case" to anybody who could
     *    persuade an administrator the victim's account was "my old duplicate".
     *
     * @return array{lines: list<array{table: string, column: string, words: string, treatment: string, rows: int, clashes: int, differences: int}>, refusal: string, unknown: list<string>}
     */
    public static function ownCounts(mysqli $db, int $userId): array
    {
        $lines = [];
        foreach (self::columns() as $name => $entry) {
            [$table, $column] = self::split($name);
            $t    = '`' . $table . '`';
            $rows = (int) (self::one(
                $db,
                'SELECT COUNT(*) AS `n` FROM ' . $t . ' WHERE ' . $t . '.`' . $column . '` = ?' . self::cond($entry['when'], $t),
                self::bindFor($entry, $userId)
            )['n'] ?? 0);
            if ($rows > 0) {
                $lines[] = ['table' => $table, 'column' => $column, 'words' => self::describe($name),
                    'treatment' => $entry['treatment'], 'rows' => $rows, 'clashes' => 0, 'differences' => 0];
            }
        }

        return ['lines' => $lines, 'refusal' => '', 'unknown' => []];
    }

    /** The NULL-safe "the two copies differ" condition for a same-or-refuse column. */
    private static function differs(string $name): string
    {
        $parts = [];
        foreach (self::CLASH_RULES[$name]['same'] ?? [] as $what) {
            $parts[] = 'NOT (' . self::expr($what, 'k') . ' <=> ' . self::expr($what, 'd') . ')';
        }

        return $parts === [] ? '' : '(' . implode(' OR ', $parts) . ')';
    }

    /** A column name, or a `{t}` expression, written for one alias. */
    private static function expr(string $what, string $alias): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $what) === 1) {
            return $alias . '.`' . $what . '`';
        }

        return str_replace('{t}', $alias, $what);
    }

    /** ` AND (condition)` for a `{t}` condition, or '' when there is none. */
    private static function cond(string $when, string $alias): string
    {
        return $when === '' ? '' : ' AND (' . str_replace('{t}', $alias, $when) . ')';
    }

    /**
     * How many of the duplicate's rows collide with one the kept account holds,
     * under ANY unique key that includes the column — optionally only those that
     * also meet `$extra` (a `k`/`d` condition). Other key columns are compared
     * with `=`, never `<=>`: a unique key does not bind a row with an empty part
     * (#487). A polymorphic column's condition applies to BOTH sides.
     *
     * @param list<list<string>> $otherColumnsPerKey
     */
    private static function clashCount(mysqli $db, string $table, string $column, array $entry, array $otherColumnsPerKey, int $keptId, int $duplicateId, string $extra): int
    {
        $parts  = [];
        $params = [self::bindFor($entry, $duplicateId)];
        foreach ($otherColumnsPerKey as $others) {
            $match = '';
            foreach ($others as $other) {
                $match .= ' AND k.`' . $other . '` = d.`' . $other . '`';
            }
            $parts[]  = 'EXISTS (SELECT 1 FROM `' . $table . '` k WHERE k.`' . $column . '` = ?' . self::cond($entry['when'], 'k')
                . $match . ($extra === '' ? '' : ' AND ' . $extra) . ')';
            $params[] = self::bindFor($entry, $keptId);
        }
        $sql = 'SELECT COUNT(*) AS `n` FROM `' . $table . '` d WHERE d.`' . $column . '` = ?' . self::cond($entry['when'], 'd')
            . ' AND (' . implode(' OR ', $parts) . ')';

        return (int) (self::one($db, $sql, ...$params)['n'] ?? 0);
    }

    /**
     * Every unique key (PRIMARY included), as table => listed column => list of
     * "the key's other columns". A generated column built from the account column
     * counts as the account part (`tblEventAttendance.attendeeRef`).
     *
     * @return array<string, array<string, list<list<string>>>>
     */
    public static function uniqueKeysOf(mysqli $db): array
    {
        $generatedFrom = [];
        foreach (self::all(
            $db,
            "SELECT `TABLE_NAME` AS `t`, `COLUMN_NAME` AS `c`, `GENERATION_EXPRESSION` AS `g` "
            . "FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `GENERATION_EXPRESSION` <> ''"
        ) as $row) {
            $generatedFrom[$row['t']][$row['c']] = (string) $row['g'];
        }
        $keys = [];
        foreach (self::all(
            $db,
            'SELECT `TABLE_NAME` AS `t`, `INDEX_NAME` AS `i`, `COLUMN_NAME` AS `c` FROM `information_schema`.`STATISTICS` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `NON_UNIQUE` = 0 ORDER BY `TABLE_NAME`, `INDEX_NAME`, `SEQ_IN_INDEX`'
        ) as $row) {
            $keys[$row['t']][$row['i']][] = (string) $row['c'];
        }
        $out = [];
        foreach (self::columns() as $name => $entry) {
            [$table, $column] = self::split($name);
            foreach ($keys[$table] ?? [] as $keyColumns) {
                $accountPart = [];
                foreach ($keyColumns as $keyColumn) {
                    $expression = $generatedFrom[$table][$keyColumn] ?? '';
                    if ($keyColumn === $column || str_contains($expression, '`' . $column . '`') === true) {
                        $accountPart[] = $keyColumn;
                    }
                }
                if ($accountPart !== []) {
                    $out[$table][$column][] = array_values(array_diff($keyColumns, $accountPart));
                }
            }
        }

        return $out;
    }

    // =========================================================================
    // 📨 The request: start, read, cancel
    // =========================================================================

    /**
     * An administrator asks the person to agree. Re-asks the rule under BOTH
     * account locks (lower number first), refuses a second open request for the
     * same pair, and records the request and its two sides. The person is told
     * at both addresses AFTER the commit.
     *
     * @return array{outcome: string, requestId: int}
     *         outcome: 'started', 'already_waiting', or a refusal code.
     */
    public static function start(mysqli $db, int $requesterId, bool $actorIsGlobal, bool $actorIsRoot, int $siteId, bool $single, int $keptId, int $duplicateId): array
    {
        $db->begin_transaction();
        try {
            self::lockAccounts($db, $keptId, $duplicateId);
            $why = self::whyNotAllowed(self::factsFor($db, $requesterId, $actorIsGlobal, $actorIsRoot, $siteId, $single, $keptId, $duplicateId));
            if ($why === '') {
                $why = self::preview($db, $keptId, $duplicateId)['refusal'];
            }
            if ($why !== '') {
                $db->rollback();

                return ['outcome' => $why, 'requestId' => 0];
            }
            $open = self::one(
                $db,
                "SELECT r.`requestID` FROM `tblAccountMergeRequest` r "
                . 'JOIN `tblAccountMergeSide` a ON a.`requestID` = r.`requestID` AND a.`userID` = ? '
                . 'JOIN `tblAccountMergeSide` b ON b.`requestID` = r.`requestID` AND b.`userID` = ? '
                . "WHERE r.`status` = 'waiting' AND r.`expiresAtUtc` > UTC_TIMESTAMP() LIMIT 1",
                $keptId,
                $duplicateId
            );
            if ($open !== null) {
                $db->commit();

                return ['outcome' => 'already_waiting', 'requestId' => (int) $open['requestID']];
            }
            // ⏳ D-200: open for REQUEST_DAYS, and nobody can agree before
            //    WAIT_DAYS have passed since this notice. The request must outlast
            //    the wait by a week, or it could expire before it could be agreed.
            $days = self::REQUEST_DAYS + self::WAIT_DAYS;
            $wait = self::WAIT_DAYS;
            self::run(
                $db,
                'INSERT INTO `tblAccountMergeRequest` (`siteID`, `requestedByID`, `requestedAtUtc`, `expiresAtUtc`, `notBeforeUtc`, `status`) '
                . "VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL {$days} DAY, UTC_TIMESTAMP() + INTERVAL {$wait} DAY, 'waiting')",
                $siteId,
                $requesterId
            );
            $requestId = (int) $db->insert_id;
            self::run($db, "INSERT INTO `tblAccountMergeSide` (`requestID`, `userID`, `side`) VALUES (?, ?, 'kept'), (?, ?, 'duplicate')",
                $requestId, $keptId, $requestId, $duplicateId);
            // ✉️ D-200: ALSO the address each account held before somebody
            //    else changed it, so a person whose address an administrator
            //    replaced still hears about it.
            $addresses = self::addressesOf($db, $keptId, $duplicateId, true);
            Logger::activity('AccountMergeStarted', 'Asked the person to agree to merging account ' . $duplicateId
                . ' into account ' . $keptId . ' (request ' . $requestId . ')');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        // ✉️ No name and no record of either account is in the message: only the
        //    two numbers, and the portal's own address (PortalAddress, never the
        //    request's host name). Where the portal has no address to give, the
        //    message says where to go instead of printing a bare "/page".
        $where = self::pageAddress($siteId);
        self::tell(
            $addresses,
            'Please confirm: two of your accounts may be merged',
            "An administrator has asked to merge two accounts that may both be yours (account numbers {$keptId} and "
            . "{$duplicateId}).\n\nNothing happens unless you agree, and you cannot agree until " . self::WAIT_DAYS . " days "
            . "after this message, so that you have time to cancel it if it was not you. You can cancel it now: sign in to "
            . "either account and go to "
            . ($where === '' ? 'your Account page, where a "Merge my accounts" button is shown while a request is waiting' : $where)
            . ". To agree later, follow the steps on the same page.\n\nIf somebody other than you changed the password or "
            . "e-mail address of either account in the " . self::SIGN_IN_CHANGE_DAYS . " days before this request, or "
            . "changes it before you agree, the merge cannot go ahead; a new request will be needed once "
            . self::SIGN_IN_CHANGE_DAYS . " days have passed since that change.\n",
            $siteId
        );

        return ['outcome' => 'started', 'requestId' => $requestId];
    }

    /**
     * One request as one of its two people sees it, or null when it does not
     * exist OR the viewer is neither side — one answer for both, so a request
     * number tells nobody anything (pass viewer 0 for an administrator lookup by
     * organisation, with `$siteId`).
     *
     * @return array<string, mixed>|null
     */
    public static function requestFor(mysqli $db, int $requestId, int $viewerId, int $siteId = 0): ?array
    {
        $row = self::one(
            $db,
            'SELECT r.`requestID`, r.`siteID`, r.`requestedByID`, r.`status`, r.`browserTokenHash`, '
            . '(r.`expiresAtUtc` <= UTC_TIMESTAMP()) AS `expired`, k.`userID` AS `keptId`, d.`userID` AS `duplicateId`, '
            . 'r.`notBeforeUtc`, (r.`notBeforeUtc` > UTC_TIMESTAMP()) AS `tooSoon`, '
            . 'TIMESTAMPDIFF(SECOND, k.`confirmedAtUtc`, UTC_TIMESTAMP()) AS `keptAgreedAgo`, '
            . 'TIMESTAMPDIFF(SECOND, d.`confirmedAtUtc`, UTC_TIMESTAMP()) AS `duplicateAgreedAgo` '
            . 'FROM `tblAccountMergeRequest` r '
            . "JOIN `tblAccountMergeSide` k ON k.`requestID` = r.`requestID` AND k.`side` = 'kept' "
            . "JOIN `tblAccountMergeSide` d ON d.`requestID` = r.`requestID` AND d.`side` = 'duplicate' "
            . 'WHERE r.`requestID` = ?',
            $requestId
        );
        if ($row === null) {
            return null;
        }
        $isSide = $viewerId > 0 && ($viewerId === (int) $row['keptId'] || $viewerId === (int) $row['duplicateId']);
        $isOrg  = $viewerId === 0 && $siteId > 0 && $siteId === (int) $row['siteID'];

        return ($isSide === true || $isOrg === true) ? $row : null;
    }

    /**
     * The open requests naming one account, or (viewer 0) one organisation's.
     *
     * @return list<array<string, mixed>>
     */
    public static function openRequests(mysqli $db, int $userId, int $siteId = 0): array
    {
        $out = [];
        $sql = $userId > 0
            ? "SELECT DISTINCT s.`requestID` FROM `tblAccountMergeSide` s JOIN `tblAccountMergeRequest` r ON r.`requestID` = s.`requestID` "
              . "WHERE s.`userID` = ? AND r.`status` = 'waiting' AND r.`expiresAtUtc` > UTC_TIMESTAMP() ORDER BY s.`requestID`"
            : "SELECT `requestID` FROM `tblAccountMergeRequest` WHERE `siteID` = ? AND `status` = 'waiting' "
              . 'AND `expiresAtUtc` > UTC_TIMESTAMP() ORDER BY `requestID`';
        foreach (self::all($db, $sql, $userId > 0 ? $userId : $siteId) as $r) {
            $row = self::requestFor($db, (int) $r['requestID'], $userId, $siteId);
            if ($row !== null) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Cancel a waiting request: by either of its two accounts, or by an
     * administrator of the organisation it was started in (the page asks the
     * permission). 'not_found' covers "no such request" and "not yours" alike.
     */
    public static function cancel(mysqli $db, int $requestId, int $byUserId, bool $asAdministrator, int $siteId): string
    {
        $db->begin_transaction();
        try {
            $locked = self::one($db, 'SELECT `requestID` FROM `tblAccountMergeRequest` WHERE `requestID` = ? FOR UPDATE', $requestId);
            $row    = $locked === null ? null : self::requestFor($db, $requestId, $asAdministrator === true ? 0 : $byUserId, $siteId);
            if ($row === null) {
                $db->rollback();

                return 'not_found';
            }
            if ($row['status'] !== 'waiting') {
                $db->rollback();

                return 'not_waiting';
            }
            self::run($db, "UPDATE `tblAccountMergeRequest` SET `status` = 'cancelled', `finishedAtUtc` = UTC_TIMESTAMP(), "
                . '`cancelledBy` = ? WHERE `requestID` = ?', $asAdministrator === true ? 'administrator' : 'person', $requestId);
            Logger::activity('AccountMergeCancelled', 'Request ' . $requestId . ' to merge account ' . (int) $row['duplicateId']
                . ' into account ' . (int) $row['keptId'] . ' was cancelled by ' . ($asAdministrator === true ? 'an administrator' : 'the person'));
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        return 'cancelled';
    }

    // =========================================================================
    // ✍️ The person's agreement, and completion
    // =========================================================================

    /**
     * One account of the pair agrees. Facts in: who is signed in, whether their
     * #731 proof is fresh and names them, the browser token the cookie carries,
     * the typed duplicate number and the tick.
     *
     * The FIRST agreement (or one without a valid token from the other side's
     * agreement) is recorded on this side, clears the other side's, and returns a
     * new token for the cookie. The SECOND, by the other account with the token
     * inside the window and the duplicate's number typed, COMPLETES the merge.
     *
     * @return array{outcome: string, token: string}
     *   outcome: not_found, done_already, cancelled, expired, needs_proof,
     *   typed_wrong, agreed_first, completed, or a refusal code.
     */
    public static function agree(mysqli $db, int $requestId, int $actingUserId, bool $freshProof, string $browserToken, int $typedDuplicate, bool $moveSignIns): array
    {
        $peek = self::requestFor($db, $requestId, $actingUserId);
        if ($peek === null) {
            return ['outcome' => self::R_NOT_FOUND, 'token' => ''];
        }
        $keptId = (int) $peek['keptId'];
        $dupId  = (int) $peek['duplicateId'];

        $addresses = [];
        $db->begin_transaction();
        try {
            // 🔒 Lower account number first (#647/#697), then the request and sides.
            self::lockAccounts($db, $keptId, $dupId);
            $req = self::one($db, 'SELECT `requestID`, `siteID`, `requestedByID`, `status`, `browserTokenHash`, '
                . '(`expiresAtUtc` <= UTC_TIMESTAMP()) AS `expired`, (`notBeforeUtc` > UTC_TIMESTAMP()) AS `tooSoon` '
                . 'FROM `tblAccountMergeRequest` WHERE `requestID` = ? FOR UPDATE', $requestId);
            self::all($db, 'SELECT `sideID` FROM `tblAccountMergeSide` WHERE `requestID` = ? FOR UPDATE', $requestId);
            $state = self::requestFor($db, $requestId, $actingUserId);
            if ($req === null || $state === null) {
                $db->rollback();

                return ['outcome' => self::R_NOT_FOUND, 'token' => ''];
            }
            if ($req['status'] === 'completed') {
                $db->rollback();

                return ['outcome' => 'done_already', 'token' => ''];
            }
            if ($req['status'] !== 'waiting') {
                $db->rollback();

                return ['outcome' => 'cancelled', 'token' => ''];
            }
            if (self::flag($req['expired']) === true) {
                $db->rollback();

                return ['outcome' => 'expired', 'token' => ''];
            }
            if ($freshProof !== true) {
                $db->rollback();

                return ['outcome' => 'needs_proof', 'token' => ''];
            }
            // ⏳ D-200: nobody agrees — first OR second — until WAIT_DAYS after
            //    the notice. Cancelling stays open all the time (`cancel()`).
            if (self::flag($req['tooSoon']) === true) {
                $db->rollback();

                return ['outcome' => self::R_TOO_SOON, 'token' => ''];
            }

            $siteId = (int) $req['siteID'];

            // 🔑 The ACTING account's own way in (finding 2 of the adversarial
            //    check). Only this account is asked here: the first agreement
            //    asks nothing about the other one, whose answers would describe
            //    an account this person has not yet proved is theirs (finding 1).
            if (self::wayInChangedRecently($db, $requestId, $actingUserId) === true) {
                $db->rollback();

                return ['outcome' => self::R_SIGN_IN_CHANGED, 'token' => ''];
            }

            if (self::isSecondStep($state, $actingUserId, $browserToken) === false) {
                // First agreement, or a restart from this account.
                $token = bin2hex(random_bytes(32));
                self::run($db, 'UPDATE `tblAccountMergeSide` SET `confirmedAtUtc` = IF(`userID` = ?, UTC_TIMESTAMP(), NULL) WHERE `requestID` = ?',
                    $actingUserId, $requestId);
                self::run($db, 'UPDATE `tblAccountMergeRequest` SET `browserTokenHash` = ? WHERE `requestID` = ?', hash('sha256', $token), $requestId);
                $db->commit();

                return ['outcome' => 'agreed_first', 'token' => $token];
            }
            if ($typedDuplicate !== $dupId) {
                $db->rollback();

                return ['outcome' => 'typed_wrong', 'token' => ''];
            }

            // ⚖️ Both sides have proved themselves in this browser: now EVERY
            //    refusal, on locked facts, with the REQUESTER as the actor, and
            //    the way-in rule for BOTH accounts.
            $requester = (int) ($req['requestedByID'] ?? 0);
            $root      = self::flag(self::one($db, 'SELECT `isRootAdmin` FROM `tblUsers` WHERE `userID` = ?', $requester)['isRootAdmin'] ?? 0);
            $why       = self::whyNotAllowed(self::factsFor($db, $requester, $root, $root, $siteId, AccountGuard::isSingleOrganisation(), $keptId, $dupId));
            $finalPreview = ['lines' => [], 'refusal' => ''];
            if ($why === '') {
                $finalPreview = self::preview($db, $keptId, $dupId);
                $why          = $finalPreview['refusal'];
            }
            if ($why === '' && (self::wayInChangedRecently($db, $requestId, $keptId) === true || self::wayInChangedRecently($db, $requestId, $dupId) === true)) {
                $why = self::R_SIGN_IN_CHANGED;
            }
            if ($why !== '') {
                $db->rollback();

                return ['outcome' => $why, 'token' => ''];
            }

            $addresses   = self::addressesOf($db, $keptId, $dupId);
            // 📧 The duplicate's address as it is NOW, kept on its side row so an
            //    erasure of the kept account can find mail sent to it (the send
            //    log, waiting mail) after closeDuplicate() has replaced it. That
            //    erasure empties it when its request closes
            //    (GdprEraser::closeRequest()), never earlier, so a retried run
            //    can still search by it.
            //    With it, every address the duplicate had held before (D-200's
            //    `earlierEmails`, round 6), to which start notices went, for the
            //    same reason; closeDuplicate() then empties the account row's list.
            $dupNow      = self::one($db, 'SELECT `emailAddress`, `earlierEmails` FROM `tblUsers` WHERE `userID` = ?', $dupId);
            $formerEmail = (string) ($dupNow['emailAddress'] ?? '');
            $formerEarly = self::addressList($dupNow['earlierEmails'] ?? null);
            try {
                $photos = self::applyMerge($db, $keptId, $dupId, $moveSignIns);
            } catch (MergeRefused $refused) {
                $db->rollback();

                return ['outcome' => $refused->code(), 'token' => ''];
            }
            self::run($db, "UPDATE `tblAccountMergeSide` SET `formerEmail` = ?, `formerEarlierEmails` = NULLIF(?, '') "
                . 'WHERE `requestID` = ? AND `userID` = ?', $formerEmail,
                $formerEarly === [] ? '' : (string) json_encode($formerEarly), $requestId, $dupId);
            self::run($db, 'UPDATE `tblAccountMergeSide` SET `confirmedAtUtc` = UTC_TIMESTAMP() WHERE `requestID` = ? AND `userID` = ?',
                $requestId, $actingUserId);
            self::run($db, "UPDATE `tblAccountMergeRequest` SET `status` = 'completed', `finishedAtUtc` = UTC_TIMESTAMP(), "
                . '`movedSignIns` = ?, `browserTokenHash` = NULL WHERE `requestID` = ?', $moveSignIns === true ? 1 : 0, $requestId);

            // 🔒 THE LAST LOOK, WITH A LOCKING READ (finding 3). The checks above
            //    read a snapshot; a row written meanwhile in a table with no
            //    foreign key (an erasure request filed for the duplicate, say) is
            //    not held back by the account lock, and an UPDATE would have
            //    moved it to the kept account — running that request would then
            //    erase the whole merged person. So: never move a standing row
            //    (applyMerge), and here count, locking, everything that still
            //    names the duplicate. Anything at all refuses the whole merge.
            $left = self::stillNaming($db, $dupId);
            if ($left !== '') {
                $db->rollback();

                return ['outcome' => $left, 'token' => ''];
            }
            // 📜 D-199: the NEW entry that says who the earlier actions belong
            //    to, since they are never reassigned. Two numbers and fixed
            //    words. It names the KEPT account explicitly: the person may be
            //    signed in as the duplicate at this moment.
            Logger::activity('AccountsMerged', 'The person on the earlier actions of account ' . $dupId
                . ' is now account ' . $keptId . ': the two accounts were merged (request ' . $requestId . ', ways of signing in '
                . ($moveSignIns === true ? 'brought across' : 'not brought across') . ')', $keptId);
            // 📋 Round 6: WHAT MOVED is written down too, so a move is logged
            //    rather than silent (D-199's rule for history, applied to the
            //    live records that do move). Fixed words from describe() and
            //    counts, read from the same preview the merge was judged on.
            $movedWords = self::movedSummary($finalPreview['lines'], $moveSignIns);
            if ($movedWords !== '') {
                Logger::activity('AccountsMergedRecordsMoved', 'Moved from account ' . $dupId . ' to account ' . $keptId
                    . ' by merge request ' . $requestId . ': ' . $movedWords, $keptId);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        // 🖼️ After the commit: the duplicate's photo file, when it was not moved
        //    to the kept account. A failure is recorded WITHOUT the path.
        foreach ($photos as $photo) {
            try {
                $gone = GdprEraser::deleteUnusedProfileImage($db, $photo);
            } catch (\Throwable $e) {
                $gone = false;
            }
            if ($gone === false) {
                Logger::activity('AccountMergePhotoKept', 'The photo file of merged account ' . $dupId
                    . ' could not be deleted and needs removing by hand');
            }
        }

        // ✉️ After the commit, to BOTH addresses as they were before the merge.
        //    Numbers and fixed words only.
        self::tell(
            $addresses,
            'Two of your accounts have been merged',
            "Account {$dupId} has been merged into account {$keptId}, after you signed in to both and agreed.\n\n"
            . "Account {$dupId}'s records about you, what you belong to, what you have been given to do, your gifts and "
            . "choices, and the records you manage (loans you asked for, photos you uploaded, tasks, saved reports, "
            . "recording notes, attendance sessions you created, room bookings and rota-swap requests) have moved to "
            . "account {$keptId}, and the move is written in the activity log. Account {$dupId}'s activity log and change "
            . "history stay under its number and are never rewritten; they are still counted as yours. Account "
            . "{$dupId} is closed. This cannot be undone. If you did not do this, contact whoever looks after the "
            . "portal for your organisation straight away.\n",
            $siteId
        );

        return ['outcome' => 'completed', 'token' => ''];
    }

    /**
     * Was this account's way in chosen by somebody else within
     * `SIGN_IN_CHANGE_DAYS` before the request started, or at any time since?
     */
    private static function wayInChangedRecently(mysqli $db, int $requestId, int $userId): bool
    {
        $row = self::one(
            $db,
            'SELECT (u.`signInChangedByOtherAtUtc` IS NOT NULL AND u.`signInChangedByOtherAtUtc` >= r.`requestedAtUtc` - INTERVAL '
            . self::SIGN_IN_CHANGE_DAYS . ' DAY) AS `changed` FROM `tblUsers` u JOIN `tblAccountMergeRequest` r ON r.`requestID` = ? '
            . 'WHERE u.`userID` = ?',
            $requestId,
            $userId
        );

        return self::flag($row['changed'] ?? 0);
    }

    /**
     * The last look, under a LOCKING read: '' when nothing but history names the
     * duplicate; R_REFUSE_IF_ANY when a standing record (an erasure request, an
     * offboarding, a held row, a Demo Data mark) appeared; R_CHANGED_DURING when
     * anything else still names it. A locking read sees rows committed after the
     * snapshot and holds the index range until the commit.
     */
    private static function stillNaming(mysqli $db, int $dupId): string
    {
        $found = '';
        foreach (self::columns() as $name => $entry) {
            if ($entry['treatment'] === self::KEEP) {
                continue;
            }
            [$table, $column] = self::split($name);
            $t = '`' . $table . '`';
            $n = (int) (self::one(
                $db,
                'SELECT COUNT(*) AS `n` FROM ' . $t . ' WHERE ' . $t . '.`' . $column . '` = ?' . self::cond($entry['when'], $t) . ' FOR UPDATE',
                self::bindFor($entry, $dupId)
            )['n'] ?? 0);
            if ($n > 0) {
                if ($entry['treatment'] === self::REFUSE_IF_ANY) {
                    return self::R_REFUSE_IF_ANY;
                }
                $found = self::R_CHANGED_DURING;
            }
        }

        return $found;
    }

    /**
     * Is THIS agreement the second one — the one that completes? The ONE rule,
     * asked by the page (to draw the final form) and by `agree()` under the lock.
     *
     * True only when the OTHER account agreed between nought and
     * `AGREE_WINDOW_SECONDS` ago (a negative age — from the future — is refused,
     * as #731 refuses a proof from the future) AND this browser presents the
     * token that agreement made (compared with `hash_equals()`).
     *
     * @param array<string, mixed> $request A row from `requestFor()`.
     */
    public static function isSecondStep(array $request, int $actingUserId, string $browserToken): bool
    {
        $actingIsKept = $actingUserId === (int) $request['keptId'];
        $otherAgo     = $actingIsKept === true ? $request['duplicateAgreedAgo'] : $request['keptAgreedAgo'];
        $otherFresh   = $otherAgo !== null && (int) $otherAgo >= 0 && (int) $otherAgo <= self::AGREE_WINDOW_SECONDS;
        $tokenOk      = $browserToken !== '' && is_string($request['browserTokenHash'] ?? null) === true
            && hash_equals((string) $request['browserTokenHash'], hash('sha256', $browserToken));

        return $otherFresh === true && $tokenOk === true;
    }

    /**
     * Apply every treatment, inside the caller's transaction, then close the
     * duplicate. Answers the duplicate's photo values whose FILES must be deleted
     * after the commit (empty when the photo moved to the kept account).
     *
     * @return list<string>
     */
    private static function applyMerge(mysqli $db, int $keptId, int $dupId, bool $moveSignIns): array
    {
        // 📜 The change history is NOT touched here (D-199): its rows, values
        //    included, stay exactly as written. An erasure of the kept account
        //    follows the merge and empties the values about the duplicate's own
        //    account and memberships then, by #707's rule
        //    (`GdprEraser::emptyChangeHistoryAbout()`). (Round 2 of this package
        //    emptied them at merge time; D-199 reversed that.)

        $keys = self::uniqueKeysOf($db);
        foreach (self::columns() as $name => $entry) {
            [$table, $column] = self::split($name);
            $t         = '`' . $table . '`';
            $treatment = $entry['treatment'];
            $d         = self::bindFor($entry, $dupId);
            $k         = self::bindFor($entry, $keptId);
            $where     = $t . '.`' . $column . '` = ?' . self::cond($entry['when'], $t);

            if ($treatment === self::KEEP) {
                continue;
            }
            if ($treatment === self::SIGN_IN_DROPPED || ($treatment === self::SIGN_IN_IF_TICKED && $moveSignIns === false)) {
                self::run($db, 'DELETE FROM ' . $t . ' WHERE ' . $where, $d);
                continue;
            }
            if ($treatment === self::REFUSE_IF_ANY) {
                // ⚠️ Only rows that do NOT stand move — the condition is in the
                //    statement itself, never assumed from the earlier count: a
                //    row filed since that count must never be moved to the kept
                //    account (finding 3). stillNaming() then refuses on it.
                self::run($db, 'UPDATE ' . $t . ' SET `' . $column . '` = ? WHERE ' . $where
                    . ' AND NOT (' . str_replace('{t}', $t, self::REFUSE_WHEN[$name] ?? '1 = 1') . ')', $k, $d);
                continue;
            }
            // 🔒 A table with a unique key on this column: LOCK both accounts'
            //    rows in it first (finding D of the third check). The preview
            //    that judged "same" or "no clash" read a snapshot; a table with
            //    no foreign key (RSVPs, among others) lets another connection
            //    change a row meanwhile, and the account lock does not hold that
            //    back. Under this lock nothing can change them until the commit.
            if (($keys[$table][$column] ?? []) !== []) {
                self::all($db, 'SELECT 1 FROM ' . $t . ' WHERE ' . $t . '.`' . $column . '` IN (?, ?)' . self::cond($entry['when'], $t) . ' FOR UPDATE', $k, $d);
            }
            foreach ($keys[$table][$column] ?? [] as $others) {
                $on = '';
                foreach ($others as $other) {
                    $on .= ' AND k.`' . $other . '` = d.`' . $other . '`';
                }
                if ($treatment === self::REFUSE_ON_CLASH) {
                    // 💷 NEVER delete a money record: a clash left in place makes
                    //    the UPDATE below hit the key, which is a refusal.
                    continue;
                }
                if ($treatment === self::STRICTER_ON_CLASH) {
                    $set = [];
                    foreach (self::CLASH_RULES[$name]['stricter'] ?? [] as $col => $order) {
                        $case = 'CASE';
                        foreach ($order as $value) {
                            $v = "'" . $db->real_escape_string($value) . "'";
                            $case .= ' WHEN k.`' . $col . '` = ' . $v . ' OR d.`' . $col . '` = ' . $v . ' THEN ' . $v;
                        }
                        $set[] = 'k.`' . $col . '` = ' . $case . ' ELSE k.`' . $col . '` END';
                    }
                    self::run($db, 'UPDATE ' . $t . ' k JOIN ' . $t . ' d ON d.`' . $column . '` = ?' . self::cond($entry['when'], 'd') . $on
                        . ' SET ' . implode(', ', $set) . ' WHERE k.`' . $column . '` = ?' . self::cond($entry['when'], 'k'), $d, $k);
                }
                // Keep-kept, stricter (after the write above) and same-or-refuse
                // remove D's copy — same-or-refuse ONLY where the two still agree,
                // asked INSIDE the delete on the locked rows, never trusted from
                // the preview. A copy that now differs is left, and the UPDATE
                // below then hits the key: a refusal, not a guess.
                $same = $treatment === self::SAME_OR_REFUSE && self::differs($name) !== '' ? ' AND NOT ' . self::differs($name) : '';
                self::run($db, 'DELETE d FROM ' . $t . ' d JOIN ' . $t . ' k ON k.`' . $column . '` = ?' . self::cond($entry['when'], 'k') . $on
                    . ' WHERE d.`' . $column . '` = ?' . self::cond($entry['when'], 'd') . $same, $k, $d);
            }
            try {
                self::run($db, 'UPDATE ' . $t . ' SET `' . $column . '` = ? WHERE ' . $where, $k, $d);
            } catch (\mysqli_sql_exception $clash) {
                // 1062: a duplicate key — a copy that differs, a money record,
                // or a row written meanwhile. Never an error page: a refusal.
                if ($clash->getCode() !== 1062) {
                    throw $clash;
                }
                throw new MergeRefused($treatment === self::REFUSE_ON_CLASH ? self::R_CLASH
                    : ($treatment === self::SAME_OR_REFUSE ? self::R_CHOICES_DIFFER : self::R_CHANGED_DURING));
            }
        }

        // 🔕 Notification choices live in one column of tblUsers; the stricter
        //    wins key by key, through their one writer.
        $raw = self::one($db, 'SELECT `notifyPrefs` FROM `tblUsers` WHERE `userID` = ?', $dupId)['notifyPrefs'] ?? null;
        NotifyPrefs::stricterInsideTransaction($db, $keptId, $raw === null ? null : (string) $raw);

        // 🖼️ The photo (finding 4). If the kept account has none, the
        //    duplicate's moves to it — the person's own photo, now reachable by
        //    an erasure of the kept account. Otherwise its file is deleted after
        //    the commit: once the columns below are emptied nothing could ever
        //    find it again.
        $photos = [];
        $dup    = self::one($db, 'SELECT `avatarPath`, `displayPhoto` FROM `tblUsers` WHERE `userID` = ?', $dupId) ?? [];
        $kept   = self::one($db, 'SELECT `avatarPath`, `displayPhoto` FROM `tblUsers` WHERE `userID` = ?', $keptId) ?? [];
        $hasOwn = trim((string) ($kept['avatarPath'] ?? '')) !== '' || trim((string) ($kept['displayPhoto'] ?? '')) !== '';
        $dupHas = trim((string) ($dup['avatarPath'] ?? '')) !== '' || trim((string) ($dup['displayPhoto'] ?? '')) !== '';
        if ($dupHas === true && $hasOwn === false) {
            $stmt = $db->prepare('UPDATE `tblUsers` SET `avatarPath` = ?, `displayPhoto` = ? WHERE `userID` = ?');
            $a    = $dup['avatarPath'] ?? null;
            $p    = $dup['displayPhoto'] ?? null;
            $stmt->bind_param('ssi', $a, $p, $keptId);
            $stmt->execute();
            $stmt->close();
        } elseif ($dupHas === true) {
            $photos = array_values(array_unique(array_filter(
                [trim((string) ($dup['avatarPath'] ?? '')), trim((string) ($dup['displayPhoto'] ?? ''))],
                static fn (string $v): bool => $v !== ''
            )));
        }

        self::closeDuplicate($db, $dupId);

        return $photos;
    }

    /**
     * Close the duplicate: switched off, its own details emptied so no second
     * copy of the person lives on a row nobody can use, and every session of it
     * ended through `Auth::endOtherSessionsOf()` — the ONE writer of the sign-in
     * version, which raises it by a random amount (#598). The e-mail column is
     * unique, so it becomes a placeholder no real address can be. Never deleted.
     */
    private static function closeDuplicate(mysqli $db, int $dupId): void
    {
        self::run(
            $db,
            "UPDATE `tblUsers` SET `isActive` = 0, `fullName` = '" . self::CLOSED_NAME . "', "
            . "`emailAddress` = CONCAT('merged-', `userID`, '@merged.invalid'), `phoneNumber` = NULL, `avatarPath` = NULL, "
            . '`notifyPrefs` = NULL, `totpSecret` = NULL, `totpEnabled` = 0, `calendarToken` = NULL, `displayBio` = NULL, '
            . '`displayPhoto` = NULL, `displayPhone` = NULL, `displayAddress` = NULL, `latitude` = NULL, `longitude` = NULL, '
            . '`what3words` = NULL, `locale` = NULL, `earlierEmails` = NULL WHERE `userID` = ?',
            $dupId
        );
        Auth::endOtherSessionsOf($db, $dupId);
    }

    /**
     * Both accounts' addresses, read before anything is changed; with
     * `$withEarlier`, also EVERY address each has held before its current one
     * (`earlierEmails`, D-200; round 6 of #1064). Each address once.
     *
     * Every address, and not only the one held before somebody else first
     * changed it: that single address missed the person's own later change (an
     * administrator fixes a typo, the person moves to a new mailbox, a hostile
     * administrator replaces it — the notice went to the hostile address and a
     * dead one, never the person's own), and a second change by somebody else
     * could not be told from the first. The cost, stated: a notice can reach an
     * address the person gave up long ago, including one another account holds
     * now. It names two account numbers and links to the page; it holds nothing
     * else about them. (An erasure, by contrast, never touches mail sent to an
     * address another account holds now: `heldByOthers()`.)
     *
     * @return list<string>
     */
    private static function addressesOf(mysqli $db, int $keptId, int $dupId, bool $withEarlier = false): array
    {
        $out = [];
        foreach (self::all($db, 'SELECT `emailAddress`, `earlierEmails` FROM `tblUsers` WHERE `userID` IN (?, ?)', $keptId, $dupId) as $row) {
            $candidates = [(string) $row['emailAddress']];
            if ($withEarlier === true) {
                // EVERY earlier address, including one another account holds
                // now (D-200). Round 7 left those out; that was a decision
                // taken without the owner and round 8 put it back.
                $candidates = array_merge($candidates, self::addressList($row['earlierEmails'] ?? null));
            }
            foreach ($candidates as $address) {
                if (filter_var($address, FILTER_VALIDATE_EMAIL) !== false && in_array($address, $out, true) === false) {
                    $out[] = $address;
                }
            }
        }

        return $out;
    }

    /**
     * Every account number merged INTO this one by a completed merge, followed
     * through chains (A into B, then B into C: both A and B for C). The ONE
     * answer to "which numbers are this same person" for reading, exporting and
     * erasing (D-199). Never includes `$survivor` itself.
     *
     * @return list<int>
     */
    public static function mergedInto(mysqli $db, int $survivor): array
    {
        return array_keys(self::mergedIntoWithAddresses($db, $survivor));
    }

    /**
     * 🔒 THE ONE DEFINITION of a merged-away account (round 5 of #1064): the
     * DUPLICATE side of a COMPLETED merge. Read from the merge's own record,
     * never from the account row, so nothing done to the row (a name typed
     * in, a tick on Active) can make it stop being one. `AccountGuard` asks
     * it in `facts()`, so every page that changes an account refuses a
     * merged-away one exactly as it refuses an erased one; and the readers
     * that follow a merge ask `stillClosedSql()` below as well, defensively.
     * Fixed text from code; `$alias` is a table alias written in code.
     */
    public static function mergedAwaySql(string $alias): string
    {
        return '(EXISTS (SELECT 1 FROM `tblAccountMergeSide` `mas` JOIN `tblAccountMergeRequest` `mar` '
            . 'ON `mar`.`requestID` = `mas`.`requestID` WHERE `mas`.`userID` = ' . $alias . '.`userID` '
            . "AND `mas`.`side` = 'duplicate' AND `mar`.`status` = 'completed'))";
    }

    /**
     * 📋 What a completed merge moved, as fixed words and counts, for the
     *    activity entry (round 6). Every line of the preview whose rows go to
     *    the kept account: everything but history (kept), the sign-in methods
     *    that are deleted, and those deleted because the box was not ticked.
     *    A row whose copy is removed because the kept account already has the
     *    same one still counts here, because the kept account then holds it.
     *
     * @param list<array{words: string, treatment: string, rows: int}> $lines
     */
    private static function movedSummary(array $lines, bool $moveSignIns): string
    {
        $parts = [];
        foreach ($lines as $line) {
            $treatment = (string) $line['treatment'];
            if ($treatment === self::KEEP || $treatment === self::SIGN_IN_DROPPED
                || ($treatment === self::SIGN_IN_IF_TICKED && $moveSignIns === false)) {
                continue;
            }
            $parts[] = (string) $line['words'] . ' (' . (int) $line['rows'] . ')';
        }

        return implode('; ', $parts);
    }

    /**
     * 📧 THE ONE PIECE OF SQL that remembers an account's address when it is
     *    replaced (D-200; round 6 of #1064). Put it FIRST in the SET list of any
     *    statement that changes `emailAddress`: MySQL applies a SET left to
     *    right, so `emailAddress` here is still the OLD address. Binds ONE
     *    string, the new address. Every change is remembered, by the person or
     *    by anybody else, so a merge's start notice reaches every address the
     *    account has held (`addressesOf()`). Nothing is added when the address
     *    does not change, when it was empty, or when it is already listed.
     *    Three statements use it: the administrator's user page, the person's
     *    own account page, and an erasure's account statement (round 10 of
     *    #1064, so a retried erasure still searches by the address it
     *    replaced). Nothing else changes an address, apart from the merge
     *    itself, which empties the closed account's list (the users API
     *    cannot, measured by reading its update list).
     *
     *    A stored value that is not valid JSON is treated as an empty list
     *    (round 10): the column is TEXT, so it can hold anything, and
     *    `JSON_CONTAINS()` on such a value is an ERROR, which stopped the
     *    erasure's account statement outright (measured with the eraser
     *    self-test's made-up account, whose every column holds plain text).
     *    `addressList()` already reads such a value as no addresses, so
     *    nothing that could be read is lost.
     */
    public static function rememberReplacedAddressSql(): string
    {
        return '`earlierEmails` = IF(NOT (`emailAddress` <=> ?) AND COALESCE(`emailAddress`, \'\') <> \'\' '
            . 'AND NOT JSON_CONTAINS(IF(JSON_VALID(`earlierEmails`), `earlierEmails`, JSON_ARRAY()), JSON_QUOTE(`emailAddress`)), '
            . 'JSON_ARRAY_APPEND(IF(JSON_VALID(`earlierEmails`), `earlierEmails`, JSON_ARRAY()), \'$\', `emailAddress`), `earlierEmails`), ';
    }

    /**
     * The addresses in a stored JSON list (`earlierEmails`,
     * `formerEarlierEmails`): only well-formed addresses, each once, in order.
     * Anything unreadable gives an empty list rather than an error, because a
     * notice or an erasure must still go ahead with the addresses it has.
     *
     * @return list<string>
     */
    public static function addressList(mixed $stored): array
    {
        if (is_string($stored) === false || $stored === '') {
            return [];
        }
        $list = json_decode($stored, true);
        if (is_array($list) === false) {
            return [];
        }
        $out = [];
        foreach ($list as $address) {
            if (is_string($address) === true && filter_var($address, FILTER_VALIDATE_EMAIL) !== false
                && in_array($address, $out, true) === false) {
                $out[] = $address;
            }
        }

        return $out;
    }

    /**
     * 📧 Which of these addresses another account holds NOW (round 7). An
     *    address an account gave up can be taken by somebody else, and mail to
     *    it from then on is theirs: an erasure must not search it. (A merge's
     *    start notice still goes there, by D-200: see `addressesOf()`.)
     *    Compared without capitals, as the address column's own collation
     *    does. `$personIds` are the person's own account numbers. Answers the
     *    addresses as given.
     *
     * @param list<string> $addresses
     * @param list<int>    $personIds
     *
     * @return list<string>
     */
    public static function heldByOthers(mysqli $db, array $addresses, array $personIds): array
    {
        if ($addresses === []) {
            return [];
        }
        $ids  = $personIds === [] ? [0] : array_values(array_map('intval', $personIds));
        $held = [];
        foreach (self::bound(
            $db,
            'SELECT LOWER(`emailAddress`) AS `a` FROM `tblUsers` WHERE LOWER(`emailAddress`) IN ('
                . implode(', ', array_fill(0, count($addresses), '?')) . ') AND `userID` NOT IN ('
                . implode(', ', array_fill(0, count($ids), '?')) . ')',
            array_merge(array_map(static fn (string $a): string => mb_strtolower($a), array_values($addresses)), $ids)
        )->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $held[(string) $row['a']] = true;
        }

        return array_values(array_filter($addresses, static fn (string $a): bool => isset($held[mb_strtolower($a)])));
    }

    /**
     * 🔒 Is this account merged away? Asked UNDER A LOCK by a writer about to
     *    change the account row (round 7; #647's rule: the check and the write
     *    in one transaction). Call it INSIDE that transaction, before the
     *    UPDATE. It locks the account row first, so a merge in progress, which
     *    holds that lock until it commits, is waited for; then it reads the
     *    merge record. That second read is ALSO a locking read, so it reads the
     *    latest committed rows whatever this transaction has read before. That
     *    is a choice, not a measured necessity: under REPEATABLE READ a plain
     *    read takes its snapshot at the transaction's first plain read, so it
     *    would also see the merge unless the caller had already made a plain
     *    read earlier in the same transaction (which the callers today do not).
     *    What is measured (tools/account-merge-selftest.php J16-J18): with the
     *    merge paused part-way, a save from the duplicate's session waits and
     *    is then refused with nothing written; with this check removed it
     *    writes over the closed account. The plain-read variant was planted
     *    and the same tests stayed green, which is what the paragraph above
     *    predicts. True means: roll back and refuse. Asking AccountGuard
     *    before the transaction is not enough: a save begun before the merge
     *    committed would wait on the merge's lock and then write over the
     *    closed account.
     */
    public static function mergedAwayUnderLock(mysqli $db, int $userId): bool
    {
        self::one($db, 'SELECT `userID` FROM `tblUsers` WHERE `userID` = ? FOR UPDATE', $userId);
        $row = self::one(
            $db,
            'SELECT COUNT(*) AS `n` FROM `tblAccountMergeSide` `mas` JOIN `tblAccountMergeRequest` `mar` '
            . "ON `mar`.`requestID` = `mas`.`requestID` WHERE `mas`.`userID` = ? AND `mas`.`side` = 'duplicate' "
            . "AND `mar`.`status` = 'completed' FOR UPDATE",
            $userId
        );

        return (int) ($row['n'] ?? 0) > 0;
    }

    /**
     * 🚩 Accounts the merge record says were merged into this one (chains
     *    followed) but which are no longer as the merge closed them (round 7).
     *    Nothing in the portal can reopen one any more, so only a hand-written
     *    change gets here; an erasure must not then skip it in silence, because
     *    its old records may be the person's or may be somebody else's now.
     *
     * @return list<int>
     */
    public static function reopenedInto(mysqli $db, int $survivor): array
    {
        return array_values(array_diff(
            array_keys(self::mergedIntoWithAddresses($db, $survivor, false)),
            array_keys(self::mergedIntoWithAddresses($db, $survivor))
        ));
    }

    /**
     * The account a merged-away account's records now belong to, following a
     * chain of merges forward (D into K, then K into L, answers L); null when
     * the account was never merged away. Asked before an erasure files or runs
     * a request naming an account (#1064 round 5).
     */
    public static function survivorOf(mysqli $db, int $userId): ?int
    {
        $seen    = [$userId => true];
        $current = $userId;
        while (true) {
            $row = self::one(
                $db,
                'SELECT k.`userID` AS `kept` FROM `tblAccountMergeRequest` r '
                . "JOIN `tblAccountMergeSide` d ON d.`requestID` = r.`requestID` AND d.`side` = 'duplicate' "
                . "JOIN `tblAccountMergeSide` k ON k.`requestID` = r.`requestID` AND k.`side` = 'kept' "
                . "WHERE r.`status` = 'completed' AND d.`userID` = ? ORDER BY r.`requestID` DESC LIMIT 1",
                $current
            );
            $kept = $row === null ? 0 : (int) $row['kept'];
            if ($kept <= 0 || isset($seen[$kept]) === true) {
                return $current === $userId ? null : $current;
            }
            $seen[$kept] = true;
            $current     = $kept;
        }
    }

    /**
     * Is a merged-away account still exactly as the merge closed it — switched
     * off, under its made-up address? The readers that FOLLOW a merge (an
     * erasure, "what we hold about you", the data copy) follow only such an
     * account. Nothing can reopen one (`AccountGuard` refuses every change),
     * so this is a second line: if one were ever found open, it may be
     * somebody else's account now, and following it would hand that person's
     * records to the survivor, or erase them.
     */
    public static function stillClosedSql(string $alias): string
    {
        return '(' . $alias . '.`isActive` = 0 AND ' . $alias . ".`emailAddress` = CONCAT('merged-', " . $alias
            . ".`userID`, '@merged.invalid'))";
    }

    /**
     * As `mergedInto()`, with the addresses recorded for each duplicate when
     * it was merged: the one it held then (`formerEmail`) and every one it had
     * held before that (`formerEarlierEmails`, D-200), each left out once an
     * erasure has emptied it. Mail was sent to all of them, so an erasure
     * searches the send log and the mail queue by each.
     *
     * Follows ONLY a duplicate that is still exactly as the merge closed it
     * (`stillClosedSql()`): nothing can reopen one (`AccountGuard` refuses every
     * change), but if one were ever found open it may be somebody else's
     * account now, and following it would hand their records to the survivor,
     * or erase them (round 5 of #1064).
     *
     * @return array<int, list<string>> duplicate number => recorded addresses
     */
    public static function mergedIntoWithAddresses(mysqli $db, int $survivor, bool $onlyClosed = true): array
    {
        $found = [];
        $todo  = [$survivor];
        while ($todo !== []) {
            $next = array_shift($todo);
            foreach (self::all(
                $db,
                "SELECT d.`userID` AS `dup`, d.`formerEmail` AS `email`, d.`formerEarlierEmails` AS `earlier` "
                . 'FROM `tblAccountMergeRequest` r '
                . "JOIN `tblAccountMergeSide` k ON k.`requestID` = r.`requestID` AND k.`side` = 'kept' "
                . "JOIN `tblAccountMergeSide` d ON d.`requestID` = r.`requestID` AND d.`side` = 'duplicate' "
                . 'JOIN `tblUsers` u ON u.`userID` = d.`userID` '
                . "WHERE r.`status` = 'completed' AND k.`userID` = ?" . ($onlyClosed === true ? ' AND ' . self::stillClosedSql('u') : ''),
                $next
            ) as $row) {
                $dup = (int) $row['dup'];
                if ($dup !== $survivor && isset($found[$dup]) === false) {
                    $found[$dup] = array_values(array_unique(array_filter(
                        array_merge([(string) ($row['email'] ?? '')], self::addressList($row['earlier'] ?? null)),
                        static fn (string $a): bool => $a !== ''
                    )));
                    $todo[] = $dup;
                }
            }
        }

        return $found;
    }

    /**
     * The whole address of the person's merge page, or '' when the portal has
     * no address to give (`PortalAddress::absolute()`). Never throws: an e-mail
     * without a link is better than no e-mail.
     */
    private static function pageAddress(int $siteId): string
    {
        try {
            return PortalAddress::absolute('account/merge', $siteId);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Tell the person. Never throws: the merge has happened, a broken mail route must not hide that. */
    private static function tell(array $addresses, string $subject, string $body, int $siteId): void
    {
        foreach ($addresses as $address) {
            try {
                Mailer::send($address, $subject, $body, [], ['siteId' => $siteId, 'bodyIsText' => true]);
            } catch (\Throwable $e) {
                // Recorded by the mail route's own log; nothing more to do here.
            }
        }
    }

    /** Lock both account rows, lower number first, in one statement (index order). */
    private static function lockAccounts(mysqli $db, int $a, int $b): void
    {
        self::all($db, 'SELECT `userID` FROM `tblUsers` WHERE `userID` IN (?, ?) ORDER BY `userID` FOR UPDATE', min($a, $b), max($a, $b));
    }

    // =========================================================================
    // 🧰 Small helpers
    // =========================================================================

    /** @return array{0: string, 1: string} */
    private static function split(string $name): array
    {
        if (preg_match('/^(tbl[A-Za-z0-9_]+)\.([A-Za-z0-9_]+)$/', $name, $m) !== 1) {
            throw new \LogicException('AccountMerge: "' . $name . '" is not table.column');
        }

        return [$m[1], $m[2]];
    }

    /** A polymorphic text column is compared as text; '5abc' must never equal 5. */
    private static function bindFor(array $entry, int $id): int|string
    {
        return $entry['when'] === '' ? $id : (string) $id;
    }

    private static function flag(mixed $value): bool
    {
        return $value === 1 || $value === '1';
    }

    /** @return array<string, mixed>|null */
    private static function one(mysqli $db, string $sql, int|string ...$params): ?array
    {
        return self::all($db, $sql, ...$params)[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    private static function all(mysqli $db, string $sql, int|string ...$params): array
    {
        $stmt = self::bound($db, $sql, $params);
        $result = $stmt->get_result();
        $rows   = [];
        while ($result !== false && ($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    private static function run(mysqli $db, string $sql, int|string ...$params): void
    {
        self::bound($db, $sql, $params)->close();
    }

    /** @param list<int|string> $params */
    private static function bound(mysqli $db, string $sql, array $params): \mysqli_stmt
    {
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('AccountMerge: a statement could not be prepared');
        }
        if ($params !== []) {
            $types = '';
            foreach ($params as $p) {
                $types .= is_int($p) === true ? 'i' : 's';
            }
            $stmt->bind_param($types, ...$params);
        }
        if ($stmt->execute() === false) {
            throw new \RuntimeException('AccountMerge: a statement failed');
        }

        return $stmt;
    }
}
