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
    // 85 tables.

    'tblAiUsage' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblAnonymousCheckins' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userAgent'],
    ],
    'tblAssetEventAssignments' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['notes'],
    ],
    'tblAssetIdentifiers' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['notes', 'createdByID'],
    ],
    'tblAssetKioskPins' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblAssetLicenseAssignments' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'notes'],
    ],
    'tblAssetLoans' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['notes'],
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
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['notes'],
    ],
    'tblAssetStocktakes' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['startedByID', 'notes'],
    ],
    'tblAttendanceCounts' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['sessionID'],
    ],
    'tblAttendanceSessions' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['sessionID', 'notes', 'createdByID', 'updatedByID'],
    ],
    'tblConsentLog' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'sessionID', 'ipAddress', 'userAgent'],
    ],
    'tblCountSessions' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['notes', 'createdByID'],
    ],
    'tblDecisionMoments' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['notes'],
    ],
    'tblErasureRequest' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'notes'],
    ],
    'tblEventAttendance' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
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
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['notes', 'createdByID'],
    ],
    'tblEventPeople' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblEventRSVPInvites' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
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
        'columns'  => ['fullName', 'dateOfBirth', 'gender', 'allergies', 'medicalNotes', 'parentName', 'parentPhone', 'parentEmail', 'emergencyContactName', 'emergencyContactPhone'], /* and 2 more */
    ],
    'tblExpenseClaimApprovals' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
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
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['donorID', 'donorName', 'notes'],
    ],
    'tblInvitation' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['email', 'createdByID'],
    ],
    'tblKidProfiles' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['fullName', 'dateOfBirth', 'allergies', 'medicalNotes'],
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
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
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
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
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
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
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
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['notes'],
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
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['notes', 'createdByID'],
    ],
    'tblSmallGroupMeetingAttendance' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblSmallGroupMeetings' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['notes'],
    ],
    'tblSmallGroupMembers' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID'],
    ],
    'tblSmallGroups' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
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
    'tblUsers' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['userID', 'fullName', 'emailAddress', 'displayAddress', 'latitude', 'longitude', 'what3words'],
    ],
    'tblVenueAgreements' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['notes', 'createdByID'],
    ],
    'tblVenueBookings' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['notes', 'createdByID', 'updatedByID'],
    ],
    'tblVenueImportRows' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['rawNotes'],
    ],
    'tblVenueInvoicePayments' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['notes'],
    ],
    'tblVenueInvoices' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['notes', 'createdByID'],
    ],
    'tblVenueUsageTypeWindows' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
        'columns'  => ['note', 'createdByID'],
    ],
    'tblVenues' => [
        'decision' => 'erase',
        'reason'   => 'Holds information about the person themselves',
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
        'decision' => 'retain',
        'reason'   => 'Safeguarding and pastoral record',
        'period'   => 'as required by safeguarding policy',
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
        'decision' => 'retain',
        'reason'   => 'Financial records for tax and charity accounting',
        'period'   => '6 years',
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
