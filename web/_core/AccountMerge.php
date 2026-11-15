<?php
// Path: _core/AccountMerge.php
/**
 * -----------------------------------------------------------------------------
 * Merging two accounts that belong to one person 🔀 (#1064, D-185)
 * -----------------------------------------------------------------------------
 * The ONE place that decides what merging a duplicate account into the account
 * that stays would do, and whether it may be done at all.
 *
 * WHY THIS EXISTS
 * -------------------------------------------------------------------------
 * The owner, 13 November 2026 (D-185): a Microsoft or Google sign-in may make a
 * new account, "however also needs to have a mechanism to 'merge' such
 * duplicate accounts, for cleanup". A duplicate is the empty account somebody
 * gets when a sign-in finds no account it recognises (#1042), or one made any
 * other way.
 *
 * WHAT IS BUILT, AND WHAT IS NOT (read this before adding a "merge" button)
 * -------------------------------------------------------------------------
 * Built: the list of every column that holds an account number and what a
 * merge does to each (`BY_TREATMENT`, `POLYMORPHIC`), the refusals
 * (`whyNotAllowed()`), and the preview (`preview()`), which COUNTS what would
 * happen and changes nothing.
 *
 * NOT built: the move itself. It waits on two questions only the owner can
 * answer, recorded on #1064: who must agree before two accounts become one (an
 * administrator alone, or the person by signing in to both), and whether the
 * installation's administrator may merge without being given the role. The
 * reason is in the first: an administrator who controls a THIRD account could
 * otherwise merge a member into it and read everything the member holds, or
 * move their own Microsoft or Google sign-in onto a member's account — the
 * shape D-018 forbids for a password. There is deliberately no half-built
 * `execute()` here: a method nothing calls, under an authoritative docblock,
 * is worse than none (#687).
 *
 * THE DUPLICATE IS NEVER DELETED (for whoever builds the move)
 * -------------------------------------------------------------------------
 * Deleting a `tblUsers` row fires 38 `ON DELETE CASCADE` links (whole rows go)
 * and 134 `SET NULL` links (records lose who they belong to) — measured with
 * `information_schema.REFERENTIAL_CONSTRAINTS` on `full_schema.sql` loaded into
 * MySQL 8.0.36 on 15 November 2026. The design is to close the duplicate and
 * mark which account it went into, so history stays readable.
 *
 * WHY EVERY COLUMN IS LISTED HERE, AND WHAT KEEPS THE LIST TRUE
 * -------------------------------------------------------------------------
 * A merge that misses one table leaves a record naming an account nobody can
 * use, and nobody notices (#706: a guard built from somebody's memory). So the
 * list is checked against the schema both ways by the hard gate
 * `tools/audit-checks/check_account_merge_columns.py` (a column holding an
 * account number that is not listed fails; a listed column that no longer
 * exists fails), and against a REAL loaded database by
 * `tools/account-merge-selftest.php`, which reads `information_schema`.
 *
 * Clashes are NOT listed by hand. Which columns sit in a unique key is read
 * from `information_schema` at run time (`uniqueKeysOf()`), because a copy of
 * the keys would go stale the day a migration adds one.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1064
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

final class AccountMerge
{
    // =========================================================================
    // 🏷️ What a merge does to the duplicate's rows in one column
    // =========================================================================

    /** Re-pointed to the account that stays. */
    public const MOVE = 'move';

    /**
     * Re-pointed; where a unique key allows the person only ONE such row and the
     * account that stays already has it, the duplicate's copy goes and the kept
     * account's row stays. Only for rows whose loss costs nothing: a membership
     * the kept account already holds, a second "going" answer to the same event.
     */
    public const KEEP_KEPT_ON_CLASH = 'keep-kept-on-clash';

    /**
     * Re-pointed; where the kept account already holds the one row allowed, the
     * merge is REFUSED, because dropping either row loses money or a record
     * somebody relies on (a pledge, a Gift Aid record, a statement sent).
     */
    public const REFUSE_ON_CLASH = 'refuse-on-clash';

    /**
     * Left naming the duplicate, on purpose: it is history and history is not
     * rewritten (D-116). The merge record says which account the number became.
     */
    public const KEEP = 'keep';

    /**
     * A way to sign in, or something that acts for the person (a saved card, a
     * device that receives their notifications, a Zoom connection). What happens
     * to these is owner question 1 on #1064, so the preview counts them and says
     * so, and nothing is decided here.
     */
    public const CREDENTIAL = 'credential';

    /** The merge is refused while EITHER account holds any such row. */
    public const REFUSE_IF_ANY = 'refuse-if-any';

    /**
     * Every column holding an account number, by what a merge does to it.
     *
     * Each name appears exactly ONCE across all groups; `treatmentOf()` throws
     * otherwise. `tblUsers.userID` is not here: it is the account itself.
     *
     * @var array<string, list<string>>
     */
    public const BY_TREATMENT = [
        // ➡️ Records about the person, or things they did. Moving an "acted on
        //    it" column is TRUE after a merge: the same person did it.
        self::MOVE => [
            'tblAnnouncements.createdByID', 'tblAnnouncements.updatedByID', 'tblApiKeys.createdByID',
            'tblApiKeys.revokedByID', 'tblAssetEventAssignments.assignedByID', 'tblAssetIdentifiers.createdByID',
            'tblAssetKioskTokens.createdByID', 'tblAssetLicenseAssignments.linkedByID',
            'tblAssetLicenseAssignments.releasedByID', 'tblAssetLicenseAssignments.userID',
            'tblAssetLoans.approvedByID', 'tblAssetLoans.counterpartyUserID', 'tblAssetLoans.requestedByID',
            'tblAssetMaintenance.createdByID', 'tblAssetMaintenance.performedByUserID', 'tblAssetOwners.userID',
            'tblAssetResources.uploadedByID', 'tblAssetStocktakeItems.scannedByID',
            'tblAssetStocktakes.closedByID', 'tblAssetStocktakes.startedByID',
            'tblAssetValueHistory.recordedByID', 'tblAssets.createdByID', 'tblAttendanceSessions.createdByID',
            'tblAttendanceSessions.updatedByID', 'tblBankImports.importedByID', 'tblBankTxns.matchedByID',
            'tblCareCase.openedByID', 'tblCareCase.personUserID', 'tblCareVisit.followUpAssignedToID',
            'tblCareVisit.visitedByID', 'tblCcliUsage.operatorID', 'tblCountEnvelopes.giverID',
            'tblCountSessions.closedByID', 'tblCountSessions.counter1ID', 'tblCountSessions.counter2ID',
            'tblCountSessions.createdByID', 'tblDbsChecks.recordedByID', 'tblDbsChecks.userID',
            'tblDecisionMoments.recordedByID', 'tblDocuments.uploadedByID', 'tblEventAttendance.markedByID',
            'tblEventBroadcasts.sentByID', 'tblEventCoordinators.grantedByID', 'tblEventCrewMembers.userID',
            'tblEventHubResources.createdByID', 'tblEventHubVideos.createdByID', 'tblEventJobAssignments.userID',
            'tblEventOccurrenceOverrides.createdByID', 'tblEventPeople.userID',
            'tblEventRSVPInvites.createdByID', 'tblEventRegistrations.registeredForUserID',
            'tblEventRegistrations.reviewedByID', 'tblEventRegistrations.submittedByUserID',
            'tblEvents.createdByID', 'tblEvents.moderatedByID', 'tblEvents.statusChangedByID',
            'tblEvents.submittedByID', 'tblEvents.updatedByID', 'tblExpenseClaimApprovals.userID',
            'tblExpenseClaimPayments.paidByID', 'tblExpenseClaims.userID',
            'tblExternalAudienceMembers.createdByID', 'tblExternalAudienceMembers.userID',
            'tblExternalEventApprovals.decidedByID', 'tblExternalEventChoices.createdByID',
            'tblExternalEventChoices.updatedByID', 'tblExternalFeedRules.createdByID',
            'tblExternalFeedRules.updatedByID', 'tblExternalFeeds.createdByID', 'tblExternalFeeds.updatedByID',
            'tblFormResponses.submitterID', 'tblForms.createdByID', 'tblGiftAidDeclaration.donorID',
            'tblGivingEntry.donorID', 'tblGivingEntry.recordedByID', 'tblGivingStatementLog.createdByID',
            'tblInvitation.acceptedByID', 'tblInvitation.createdByID', 'tblInvitation.revokedByID',
            'tblKidCheckins.checkedInByID', 'tblKidCheckins.checkedOutByID', 'tblKidProfiles.parentUserID',
            'tblLeadershipAssignments.createdByID', 'tblLeadershipAssignments.updatedByID',
            'tblLeadershipAssignments.userID', 'tblLiveChatMessages.moderatedByID', 'tblLivePrompts.createdByID',
            'tblNewsletter.createdByID', 'tblNoticeboardPosters.createdByID',
            'tblNoticeboardPosters.updatedByID', 'tblNoticeboardUploads.createdByID',
            'tblPathwayEnrolments.enrolledByID', 'tblPathwayProgress.markedByID',
            'tblPathwayProgress.revokedByID', 'tblPathways.createdByID', 'tblPayment.userID',
            'tblPhoto.moderatedByID', 'tblPhoto.uploadedByUserID', 'tblPhotoAlbum.createdByID',
            'tblPledgeCampaigns.createdByID', 'tblPrayerRequests.assignedToUserID',
            'tblPrayerRequests.moderatorID', 'tblPrayerRequests.submitterID', 'tblProject.createdByID',
            'tblProjectPledge.donorID', 'tblProjectUpdate.postedByID', 'tblPublicHosts.approvedByID',
            'tblReadingPlan.createdByID', 'tblRecording.presenterID', 'tblRecording.uploadedByID',
            'tblRecordingNote.createdByID', 'tblReportDefinitions.createdByID',
            'tblReportDefinitions.updatedByID', 'tblResourceBooking.approvedByID',
            'tblResourceBooking.bookedByID', 'tblRotaSlot.assignedToID', 'tblRotaSlot.createdByID',
            'tblRotaSwapRequest.requestedByID', 'tblRotaSwapRequest.targetUserID',
            'tblSalvationCards.assignedToID', 'tblServicePlan.preparedByID', 'tblServicePlanItem.presenterID',
            'tblServicePlanMessages.createdByID', 'tblServicePlanState.updatedByID',
            'tblServicePlans.createdByID', 'tblSmallGroupMeetingAttendance.markedByID',
            'tblSmallGroupMeetings.recordedByID', 'tblSmallGroupMembers.addedByID',
            'tblSmallGroupPlaces.hostUserID', 'tblSmallGroupPlaces.placeEnteredByID',
            'tblSmallGroups.createdByID', 'tblSmallGroups.hostUserID', 'tblSmallGroups.placeEnteredByID',
            'tblSmsMessage.recipientUserID', 'tblSongs.createdByID', 'tblTasks.assignedToID',
            'tblTasks.createdByID', 'tblUserDepts.addedByID', 'tblUserGroups.addedByID',
            'tblUserMilestone.userID', 'tblUserRoles.grantedByID', 'tblVenueAgreementFiles.uploadedByID',
            'tblVenueAgreements.createdByID', 'tblVenueBookingGroups.createdByID',
            'tblVenueBookings.createdByID', 'tblVenueBookings.updatedByID', 'tblVenueImportBatches.createdByID',
            'tblVenueInvoicePayments.recordedByID', 'tblVenueInvoices.createdByID',
            'tblVenueUsageTypeWindows.createdByID', 'tblVenues.createdByID', 'tblVisitor.assignedToID',
            'tblVisitor.convertedUserID', 'tblVisitor.createdByID', 'tblVisitorContact.contactedByID',
            'tblWebhooks.createdByID', 'tblWorkflowActions.actedByID', 'tblWorkflowInstances.startedByID',
            'tblZoomMeeting.createdByID',
        ],

        // 🤝 One-per-person rows whose duplicate copy costs nothing to lose.
        //    ⚠️ Known limit for whoever builds the move: on
        //    `tblUserOutsideServicePref` keeping the kept account's choice can
        //    keep a LOOSER choice than the duplicate made (D-058: the strictest
        //    should win). Raised on #1064 rather than decided here.
        self::KEEP_KEPT_ON_CLASH => [
            'tblEventAttendance.userID', 'tblEventCoordinators.userID', 'tblEventRSVPs.userID',
            'tblExternalApprovalNotices.userID', 'tblNewsletterSubscription.userID',
            'tblPathwayEnrolments.userID', 'tblPathwayProgress.userID', 'tblReadingPlanEnrollment.userID',
            'tblSmallGroupMeetingAttendance.userID', 'tblSmallGroupMembers.userID',
            'tblUserDepts.userID', 'tblUserDeptsUnplaced.userID', 'tblUserGroups.userID',
            'tblUserGroupsUnplaced.userID', 'tblUserOutsideServicePref.userID', 'tblUserRoles.userID',
            'tblUserRolesUnplaced.userID', 'tblUserSites.userID', 'tblUserSmsPreference.userID',
            'tblUserTours.userID', 'tblUserTranslationPref.userID',
        ],

        // 💷 One-per-person rows about money or a record somebody relies on.
        self::REFUSE_ON_CLASH => [
            'tblGivingKeptDonors.donorID', 'tblGivingStatementLog.donorID', 'tblPledges.userID',
        ],

        // 📜 History and logs (D-116: not rewritten). Who did something, when,
        //    to what — recorded under the number they had at the time.
        self::KEEP => [
            'tblActivityLogs.userID', 'tblAiUsage.userID', 'tblAssetAudit.actorUserID',
            'tblAssetScanLog.actorUserID', 'tblAuditTrail.userID', 'tblCapabilityMap.decidedByID',
            'tblCareAccessLog.viewerID', 'tblConsentLog.userID', 'tblErasureRequest.processedByID',
            'tblErasureReviewDecisions.decidedByID', 'tblErrors.resolvedByID', 'tblErrors.userID',
            'tblExternalFeedRuns.triggeredByID', 'tblMigrations.executedByID',
            'tblNewsletterRecipient.userID', 'tblOffboarding.offboardedByID', 'tblOffboarding.rehiredByID',
            'tblRecordingPlay.userID',
        ],

        // 🔑 Owner question 1 on #1064.
        self::CREDENTIAL => [
            'tblAssetKioskPins.userID', 'tblLinkedAccounts.userID', 'tblLocalAccounts.userID',
            'tblPasswordResets.userID', 'tblPaymentMethod.userID', 'tblPushSubscriptions.userID',
            'tblTotpBackupCodes.userID', 'tblTrustedDevices.userID', 'tblWebAuthnCredentials.userID',
            'tblZoomAccount.userID',
        ],

        // ⛔ A merge is for a duplicate. An account somebody has asked to have
        //    erased, that has been offboarded, or that a restore holds rows back
        //    for, is a different situation that a merge must not paper over.
        self::REFUSE_IF_ANY => [
            'tblErasureRequest.userID', 'tblOffboarding.userID', 'tblRestoreHeldRows.userID',
        ],
    ];

    /**
     * Columns that hold an account number on SOME rows only, which no schema
     * link can find. Each is listed with the fixed condition that picks those
     * rows. The hard gate cannot discover a new column of this kind — it can
     * only check that each one listed still exists — and says so.
     *
     * @var array<string, array{treatment: string, when: string}>
     */
    public const POLYMORPHIC = [
        'tblWorkflowSteps.assigneeValue'   => ['treatment' => self::MOVE, 'when' => "`assigneeType` = 'user'"],
        // A person may be on one outside calendar's audience list once
        // (`uq_extaud_owner_member`), so both accounts on the same list is a
        // clash, and the kept account's entry is the one that stays. Found by
        // section A of the self-test, which a first version listed as "move".
        'tblExternalAudienceMembers.refID' => ['treatment' => self::KEEP_KEPT_ON_CLASH, 'when' => "`kind` = 'person'"],
        'tblAuditTrail.recordID'           => ['treatment' => self::KEEP, 'when' => "`tableName` = 'tblUsers'"],
    ];

    /**
     * Columns whose NAME looks like an account number and which are not one.
     * Fails both ways in the hard gate, so an entry is removed when the column is.
     *
     * @var array<string, string>
     */
    public const NOT_AN_ACCOUNT = [
        'tblVenueAgreements.supersededByID' => 'The agreement that replaced this one, not a person.',
    ];

    // =========================================================================
    // 🚫 Refusals — the words are here and nowhere else
    // =========================================================================

    public const R_NO_PERMISSION   = 'no_permission';
    public const R_SAME_ACCOUNT    = 'same_account';
    public const R_NOT_FOUND       = 'not_found';
    public const R_ERASED          = 'erased';
    public const R_NEEDS_GLOBAL    = 'needs_global';
    public const R_IS_YOU          = 'is_you';
    public const R_ADMINISTRATOR   = 'administrator';
    public const R_CLOSED          = 'closed';
    public const R_REFUSE_IF_ANY   = 'held_elsewhere';
    public const R_CLASH           = 'clash';
    public const R_UNKNOWN_CLASH   = 'unknown_clash';

    /** The sentence an administrator reads for each refusal. */
    private const REFUSAL_WORDS = [
        self::R_NO_PERMISSION => 'You cannot merge accounts here. It needs the "Merge two accounts" permission in '
            . 'every organisation either account belongs to.',
        self::R_SAME_ACCOUNT => 'Those are the same account. Choose two different accounts.',
        // ⚠️ One sentence for "does not exist" AND "belongs to another
        //    organisation": AccountGuard's row 3 exists so that an administrator
        //    cannot probe whether an account exists somewhere else.
        self::R_NOT_FOUND => 'No account with that number was found here.',
        self::R_ERASED => 'One of those accounts has been erased on request. An erased account cannot be merged.',
        self::R_NEEDS_GLOBAL => 'One of those accounts also belongs to another organisation, so only a global '
            . 'administrator can merge it.',
        self::R_IS_YOU => 'One of those accounts is your own. Somebody else must merge it.',
        self::R_ADMINISTRATOR => 'One of those accounts is an administrator\'s. Remove its administrator access '
            . 'first, then merge it.',
        self::R_CLOSED => 'One of those accounts is switched off. Switch it back on first if it really is a duplicate.',
        self::R_REFUSE_IF_ANY => 'One of those accounts has an erasure request, an offboarding record, or rows a '
            . 'restore is holding back. Deal with that first; a merge must not hide it.',
        self::R_CLASH => 'Both accounts hold a record that a person can have only one of, and it is about money or '
            . 'a record somebody relies on (a pledge, a Gift Aid record, a statement sent). Sort that out by hand first.',
        self::R_UNKNOWN_CLASH => 'The portal does not know how to merge one of the tables these accounts use. '
            . 'Nothing has been changed. Please report this.',
    ];

    /**
     * The words for a refusal code. An unknown code throws: a mistyped code is
     * a fault where it is written, never a blank message on a page.
     */
    public static function refusalWords(string $code): string
    {
        if (array_key_exists($code, self::REFUSAL_WORDS) === false) {
            throw new \InvalidArgumentException('AccountMerge: unknown refusal "' . $code . '"');
        }

        return self::REFUSAL_WORDS[$code];
    }

    // =========================================================================
    // 📋 The list, flattened
    // =========================================================================

    /**
     * Every listed column with its treatment, polymorphic ones included.
     *
     * @return array<string, array{treatment: string, when: string}>
     *
     * @throws \LogicException When a column is listed twice — two treatments for
     *                         one column would mean the preview and the move
     *                         could disagree.
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

    // =========================================================================
    // ⚖️ The rule — FACTS in, a refusal code (or '') out. No database here.
    // =========================================================================

    /**
     * May these two accounts be merged by this person? '' means yes.
     *
     * Takes facts rather than a connection, so the page and whatever builds the
     * move later ask exactly the same rule — the second time on facts read under
     * the lock (#647). The order is the order the checks are asked, and it
     * matters in one place: "not found" comes before everything that would tell
     * an administrator something about an account they cannot see.
     *
     * Expected facts (see `factsFor()`):
     *   mayMergeHere        bool  the actor passes the gate in the organisation that is open
     *   mayMergeEverywhere  bool  ...and in EVERY organisation either account belongs to
     *   actorId    int
     *   keptId, duplicateId  int
     *   accounts   array<int, array{guard: string, isActive: bool, adminAnywhere: bool, heldElsewhere: int}>
     *              keyed by account number; a missing key means the account does not exist
     *
     * ⚠️ THE PERMISSION IS ASKED TWICE, AND THE SECOND ASK MUST STAY AFTER
     *    "NOT FOUND". "Everywhere" depends on which organisations an account
     *    belongs to, so refusing on it first would tell an administrator that an
     *    account they cannot see belongs to some other organisation — the very
     *    probe AccountGuard's row 3 exists to stop.
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
        }
        foreach ([$kept, $dup] as $id) {
            if ($accounts[$id]['guard'] !== AccountGuard::ALLOW) {
                return self::R_NEEDS_GLOBAL;
            }
        }
        if (($facts['mayMergeEverywhere'] ?? false) !== true) {
            return self::R_NO_PERMISSION;
        }
        if ($kept === (int) ($facts['actorId'] ?? 0) || $dup === (int) ($facts['actorId'] ?? 0)) {
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
     * The organisation boundary is AccountGuard's (reach "account"), never a
     * second membership test. The permission is asked in every organisation
     * either account belongs to, plus the one that is open, because a merge
     * moves rows of all of them; the capability does NOT fall open for the
     * installation's administrator (owner question 2 on #1064).
     *
     * @param bool $actorIsGlobal AccountGuard::actorIsGlobal() for the actor.
     * @param bool $actorIsRoot   The actor's tblUsers.isRootAdmin, for Capabilities.
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
            $row     = self::one(
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
                'isActive'      => self::flag($row['isActive'] ?? 0),
                'adminAnywhere' => self::flag($row['isAdmin'] ?? 0) || self::flag($row['isRootAdmin'] ?? 0)
                    || (int) ($row['siteAdminRows'] ?? 0) > 0,
                'heldElsewhere' => self::refuseIfAnyRows($db, $id),
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
            'actorId'     => $actorId,
            'keptId'      => $keptId,
            'duplicateId' => $duplicateId,
            'accounts'    => $accounts,
        ];
    }

    /**
     * Is this person an administrator of that organisation — the same four
     * grounds `App::isAdmin()` reads, asked of a named organisation rather than
     * the open one, because a merge reaches every organisation either account
     * is in and `App::isAdmin()` can only speak for the one in the session.
     *
     * ⚠️ The `us.isActive = 1` term below CANNOT CHANGE ANY ANSWER TODAY, and
     *    that is said rather than implied: it is always asked together with the
     *    permission, and holding the permission's role already requires a
     *    switched-on membership of that organisation (`Roles::hasAnyOf()` joins
     *    `us.isActive = 1`). Planted out, the self-test stayed green, measured
     *    15 November 2026. It is kept so this method means what its name says
     *    if it is ever asked on its own.
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

    /** How many rows in the refuse-if-any tables name this account. */
    private static function refuseIfAnyRows(mysqli $db, int $userId): int
    {
        $total = 0;
        foreach (self::BY_TREATMENT[self::REFUSE_IF_ANY] as $name) {
            [$table, $column] = self::split($name);
            $sql = 'SELECT COUNT(*) AS `n` FROM `' . $table . '` WHERE `' . $column . '` = ?';
            // A cancelled erasure request is over: it is no reason to refuse.
            if ($table === 'tblErasureRequest') {
                $sql .= " AND `status` <> 'cancelled'";
            }
            $total += (int) (self::one($db, $sql, $userId)['n'] ?? 0);
        }

        return $total;
    }

    // =========================================================================
    // 🧮 The preview — counts only, changes nothing
    // =========================================================================

    /**
     * What merging the duplicate into the kept account would do, as COUNTS.
     *
     * Ask `whyNotAllowed()` first; this does not repeat it. Returns one line per
     * listed column that names the duplicate in at least one row, and the
     * refusal the counts themselves cause ('' when none):
     *   - R_CLASH when a refuse-on-clash column clashes;
     *   - R_UNKNOWN_CLASH when a column the list calls plain "move" sits in a
     *     unique key — the list is then wrong, and moving would fail or merge
     *     two rows. Structural, so it is reported whether or not rows clash
     *     today: a migration that adds a key must make somebody look here.
     *
     * Never returns a value from any row — only counts.
     *
     * @return array{lines: list<array{table: string, column: string, treatment: string, rows: int, clashes: int}>, refusal: string, unknown: list<string>}
     */
    public static function preview(mysqli $db, int $keptId, int $duplicateId): array
    {
        $keys    = self::uniqueKeysOf($db);
        $lines   = [];
        $refusal = '';
        $unknown = [];

        foreach (self::columns() as $name => $entry) {
            [$table, $column] = self::split($name);
            $when  = $entry['when'] === '' ? '' : ' AND ' . $entry['when'];
            $rows  = (int) (self::one(
                $db,
                'SELECT COUNT(*) AS `n` FROM `' . $table . '` WHERE `' . $column . '` = ?' . $when,
                $duplicateId
            )['n'] ?? 0);

            $clashes  = 0;
            $inKeys   = $keys[$table][$column] ?? [];
            $treatment = $entry['treatment'];
            if ($inKeys !== [] && $treatment === self::MOVE) {
                $unknown[] = $name;
            }
            if ($rows > 0 && $inKeys !== [] && in_array($treatment, [self::KEEP_KEPT_ON_CLASH, self::REFUSE_ON_CLASH], true) === true) {
                $clashes = self::clashCount($db, $table, $column, $entry['when'], $inKeys, $keptId, $duplicateId);
            }
            if ($treatment === self::REFUSE_ON_CLASH && $clashes > 0) {
                $refusal = self::R_CLASH;
            }
            if ($rows > 0) {
                $lines[] = ['table' => $table, 'column' => $column, 'treatment' => $treatment,
                    'rows' => $rows, 'clashes' => $clashes];
            }
        }
        if ($unknown !== []) {
            $refusal = self::R_UNKNOWN_CLASH;
        }

        return ['lines' => $lines, 'refusal' => $refusal, 'unknown' => $unknown];
    }

    /**
     * How many of the duplicate's rows would collide with one the kept account
     * already holds, under ANY unique key that includes the column.
     *
     * Compares the other key columns with `=`, never `<=>`: a unique key does
     * not bind a row where any part is empty (#487), so two rows with an empty
     * part do not clash and must not be counted as clashing.
     *
     * A polymorphic column's condition (`$when`) is applied on BOTH sides: it is
     * written with bare column names, which resolve to `d` in the outer WHERE and
     * to `k` inside the EXISTS, so only rows that really name an account count.
     *
     * @param list<list<string>> $otherColumnsPerKey For each unique key, its columns other than the account part.
     */
    private static function clashCount(mysqli $db, string $table, string $column, string $when, array $otherColumnsPerKey, int $keptId, int $duplicateId): int
    {
        $also  = $when === '' ? '' : ' AND (' . $when . ')';
        $parts = [];
        foreach ($otherColumnsPerKey as $others) {
            $match = '';
            foreach ($others as $other) {
                $match .= ' AND k.`' . $other . '` = d.`' . $other . '`';
            }
            $parts[] = 'EXISTS (SELECT 1 FROM `' . $table . '` k WHERE k.`' . $column . '` = ?' . $also . $match . ')';
        }
        $sql = 'SELECT COUNT(*) AS `n` FROM `' . $table . '` d WHERE d.`' . $column . '` = ?' . $also
            . ' AND (' . implode(' OR ', $parts) . ')';

        return (int) (self::one($db, $sql, $duplicateId, ...array_fill(0, count($parts), $keptId))['n'] ?? 0);
    }

    /**
     * Every unique key (PRIMARY included) in this database, as
     * table => account-ish column => list of "the key's other columns".
     *
     * A column counts as in the key when the key names it OR names a generated
     * column worked out from it (`tblEventAttendance.attendeeRef` is built from
     * `userID`, and that key is what makes two attendance marks clash).
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

        $out     = [];
        $listed  = self::columns();
        foreach ($listed as $name => $entry) {
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
    // 🧰 Small helpers
    // =========================================================================

    /**
     * Split "tblX.column" and refuse anything that is not plainly a name, since
     * both halves are written into SQL (they come from the constants above, so
     * this is a guard against a bad edit, not against a visitor).
     *
     * @return array{0: string, 1: string}
     */
    private static function split(string $name): array
    {
        if (preg_match('/^(tbl[A-Za-z0-9_]+)\.([A-Za-z0-9_]+)$/', $name, $m) !== 1) {
            throw new \LogicException('AccountMerge: "' . $name . '" is not table.column');
        }

        return [$m[1], $m[2]];
    }

    private static function flag(mixed $value): bool
    {
        return $value === 1 || $value === '1';
    }

    /** @return array<string, mixed>|null */
    private static function one(mysqli $db, string $sql, int ...$params): ?array
    {
        $rows = self::all($db, $sql, ...$params);

        return $rows[0] ?? null;
    }

    /**
     * Run a statement whose only values are whole numbers, bound as parameters.
     *
     * @return list<array<string, mixed>>
     */
    private static function all(mysqli $db, string $sql, int ...$params): array
    {
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('AccountMerge: a statement could not be prepared');
        }
        if ($params !== []) {
            $stmt->bind_param(str_repeat('i', count($params)), ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows   = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }
}
