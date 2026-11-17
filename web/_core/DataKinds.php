<?php
// Path: web/_core/DataKinds.php
/**
 * -----------------------------------------------------------------------------
 * DataKinds — what a kind of record is called, in words a member can read 🗣️
 * -----------------------------------------------------------------------------
 * THE ONE PLACE a database table becomes plain words for a member (#1138 item 9).
 * "What we hold about you" (account/my-data), the erasure request page and the
 * full data copy (account/data-export) used to name each kind of record by its
 * table name (`tblGivingEntry`), which means nothing to the person it is about.
 * They print `DataKinds::words($table)` now.
 *
 * WHICH TABLES. Exactly the tables `GdprEraser::catalogue()` can COUNT for a
 * person (an account column, or a parent it is matched through), plus
 * `tblErasureRequest`, which `GdprEraser::inventory()` adds by hand. Section 28
 * of tools/gdpr-coverage-selftest.php fails BOTH ways: a countable table with
 * no phrase here (a member would be shown a raw table name, or nothing), and a
 * phrase here for a table nothing can count (a stale entry that reads like
 * coverage). `tblErasureAudit` is deliberately NOT here: it is not in the
 * catalogue (#510 rule 7d) and the inventory never lists it.
 *
 * TWO PHRASES FOR A TABLE THAT HAS TWO MEANINGS. Some tables hold records that
 * are ABOUT the person (a gift given, an attendance mark) AND records the
 * person MADE as a worker (a gift they typed in for somebody else, an
 * attendance cell they ticked). WORDS holds the phrase for the first; MADE holds
 * the phrase for the second, used only when `words()` is given a column that is
 * an actor column. A table with only one meaning has only a WORDS entry.
 *
 * WHAT IT DOES NOT DO. It says what KIND of record it is. What will happen to
 * the record on an erasure, and why, is still `GdprEraser::inventoryWords()` and
 * the instruction's own `reason`; the two are never folded together, because a
 * kind is the same for every row of the table while the outcome can differ
 * row by row (gifts that keep the name beside gifts that lose it).
 *
 * WHY NOT A `label` KEY ON EACH personal-data-catalogue.php ENTRY. Considered
 * and rejected for now: several audit scripts read that file by POSITION (for
 * instance, "`decision` stays on the line straight after the opening one"), and
 * about 245 edits there risk breaking them silently. A separate list, guarded
 * both ways by section 28, costs one file and no risk to those readers.
 *
 * A phrase never contains a table name or a technical word, and is at least
 * eight characters (section 28 checks both).
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1135
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class DataKinds
{
    /**
     * Table => the plain phrase for a record of that kind. For a table with two
     * meanings this is the phrase for the records ABOUT the person.
     *
     * @var array<string, string>
     */
    public const WORDS = [
        'tblAccountMergeRequest'         => 'Requests to merge accounts that you made',
        'tblActivityLogs'                => 'The record of what you did in the portal',
        'tblAiUsage'                     => 'What you asked the AI assistant, and its answers',
        'tblAnnouncements'               => 'Announcements you wrote or changed',
        'tblApiKeys'                     => 'Access keys for outside programs that you created or withdrew',
        'tblAssetAudit'                  => 'Changes to equipment records that you made',
        'tblAssetEventAssignments'       => 'Equipment you assigned to events',
        'tblAssetIdentifiers'            => 'Equipment tags and barcodes you added',
        'tblAssetKioskPins'              => 'Your equipment kiosk PIN (never the PIN itself)',
        'tblAssetKioskTokens'            => 'Equipment kiosk links you created',
        'tblAssetLicenseAssignments'     => 'Software seats assigned to you',
        'tblAssetLoans'                  => 'Equipment lent to you or borrowed from you',
        'tblAssetMaintenance'            => 'Equipment maintenance you recorded or carried out',
        'tblAssetOwners'                 => 'Equipment you own or look after',
        'tblAssetResources'              => 'Equipment files you uploaded',
        'tblAssetScanLog'                => 'Equipment scans you made',
        'tblAssetStocktakeItems'         => 'Stocktake items you scanned',
        'tblAssetStocktakes'             => 'Stocktakes you started or closed',
        'tblAssetValueHistory'           => 'Equipment values you recorded',
        'tblAssets'                      => 'Equipment records you created',
        'tblAttendanceSessions'          => 'Attendance sessions you created or changed',
        'tblAuditTrail'                  => 'The history of changes you made to records',
        'tblBankImports'                 => 'Bank statement files you imported',
        'tblBankTxns'                    => 'Bank transactions you matched to records',
        'tblCapabilityMap'               => 'Decisions you made about who can open which parts of the portal',
        'tblCareAccessLog'               => 'The times you opened a pastoral care file',
        'tblCareCase'                    => 'Pastoral care cases about you',
        'tblCareVisit'                   => 'Pastoral care visits about you',
        'tblCcliUsage'                   => 'Song-use reports you recorded',
        'tblConsentLog'                  => 'Your consent choices',
        'tblCountEnvelopes'              => 'Offering envelopes named for you',
        'tblCountSessions'               => 'Offering counts you started, closed or counted',
        'tblDbsChecks'                   => 'Safeguarding (DBS) checks recorded for you',
        'tblDecisionMoments'             => 'Decision moments you recorded',
        'tblDocuments'                   => 'Documents you uploaded',
        'tblErasureRequest'              => 'Your requests to be erased',
        'tblErasureReviewDecisions'      => 'Erasure review decisions you made',
        'tblErrors'                      => 'Errors recorded while you were using the portal',
        'tblEventAttendance'             => 'Your attendance at events',
        'tblEventBroadcasts'             => 'Messages you sent to people at an event',
        'tblEventCoordinators'           => 'Events you coordinate',
        'tblEventCrewMembers'            => 'Event crews you are on',
        'tblEventHubResources'           => 'Event hub files and links you added',
        'tblEventHubVideos'              => 'Event hub videos you added',
        'tblEventJobAssignments'         => 'Event jobs you have been given',
        'tblEventOccurrenceOverrides'    => 'Changes to single dates of repeating events that you made',
        'tblEventPeople'                 => 'Events you are listed at, for example as a speaker or host',
        'tblEventRSVPInvites'            => 'Event invitations you created',
        'tblEventRSVPs'                  => 'Your answers to events',
        'tblEventRegistrations'          => 'Event registrations made for you',
        'tblEvents'                      => 'Events you created, changed or suggested',
        'tblExpenseClaimApprovals'       => 'Expense claim decisions you made',
        'tblExpenseClaimPayments'        => 'Expense claim payments you recorded',
        'tblExpenseClaims'               => 'Your expense claims',
        'tblExternalApprovalNotices'     => 'Notices that imported events were waiting for your approval',
        'tblExternalAudienceMembers'     => 'Outside calendars that list you as someone who may see them',
        'tblExternalEventApprovals'      => 'Imported event approvals you decided',
        'tblExternalEventChoices'        => 'Choices you made about imported events',
        'tblExternalFeedRules'           => 'Rules for outside calendars that you set up',
        'tblExternalFeedRuns'            => 'Outside calendar updates you started',
        'tblExternalFeeds'               => 'Outside calendars you added or changed',
        'tblFormResponses'               => 'Your answers to forms',
        'tblForms'                       => 'Forms you created',
        'tblGiftAidDeclaration'          => 'Your Gift Aid (tax relief) declarations',
        'tblGivingEntry'                 => 'Gifts recorded for you',
        'tblGivingKeptDonors'            => 'Your name and address kept with your gift records',
        'tblGivingStatementLog'          => 'Giving statements made for you',
        'tblInvitation'                  => 'The invitation you joined by',
        'tblKidCheckins'                 => 'Children\'s check-ins and check-outs you carried out',
        'tblKidProfiles'                 => 'Children\'s profiles linked to you as a parent or carer',
        'tblLeadershipAssignments'       => 'Leadership roles you hold or held',
        'tblLinkedAccounts'              => 'Outside accounts linked to your sign-in',
        'tblLiveChatMessages'            => 'Live chat messages you moderated',
        'tblLivePrompts'                 => 'Live prompts you created',
        'tblLocalAccounts'               => 'Your password sign-in (never the password itself)',
        'tblMigrations'                  => 'Portal upgrades you carried out',
        'tblNewsletter'                  => 'Newsletters you created',
        'tblNewsletterRecipient'         => 'Newsletters sent to you',
        'tblNewsletterSubscription'      => 'Whether you receive each newsletter',
        'tblNoticeboardPosters'          => 'Noticeboard posters you created or changed',
        'tblNoticeboardUploads'          => 'Noticeboard files you uploaded',
        'tblOffboarding'                 => 'Your offboarding record',
        'tblPasswordResets'              => 'Your password reset requests',
        'tblPathwayEnrolments'           => 'Pathways you are enrolled on',
        'tblPathwayProgress'             => 'Pathway steps you have completed',
        'tblPathways'                    => 'Pathways you created',
        'tblPayment'                     => 'Payments you made',
        'tblPaymentMethod'               => 'Ways to pay that you saved',
        'tblPhoto'                       => 'Photos you uploaded or approved',
        'tblPhotoAlbum'                  => 'Photo albums you created',
        'tblPledgeCampaigns'             => 'Giving campaigns you created',
        'tblPledges'                     => 'Your promises to give',
        'tblPrayerRequests'              => 'Your prayer requests',
        'tblProject'                     => 'Projects you created',
        'tblProjectPledge'               => 'Your promises to give to projects',
        'tblProjectUpdate'               => 'Project updates you posted',
        'tblPublicHosts'                 => 'Public website addresses you approved',
        'tblPushSubscriptions'           => 'Devices you allowed to send you notifications',
        'tblReadingPlan'                 => 'Reading plans you created',
        'tblReadingPlanEnrollment'       => 'Reading plans you started',
        'tblRecording'                   => 'Recordings you uploaded or presented',
        'tblRecordingNote'               => 'Notes on recordings that you wrote',
        'tblRecordingPlay'               => 'Recordings you played',
        'tblReportDefinitions'           => 'Reports you created or changed',
        'tblResourceBooking'             => 'Room and resource bookings you made',
        'tblRestoreHeldRows'             => 'Sign-in codes and settings kept aside during a backup restore',
        'tblRotaSlot'                    => 'Rota slots you were given or created',
        'tblRotaSwapRequest'             => 'Rota swap requests you made or received',
        'tblSalvationCards'              => 'Decision cards given to you to follow up',
        'tblServicePlan'                 => 'Service plans you prepared',
        'tblServicePlanItem'             => 'Service plan items you present',
        'tblServicePlanMessages'         => 'Service plan messages you wrote',
        'tblServicePlanState'            => 'Service plan changes you made',
        'tblServicePlans'                => 'Service plans you created',
        'tblSmallGroupMeetingAttendance' => 'Your attendance at small group meetings',
        'tblSmallGroupMeetings'          => 'Small group meetings you recorded',
        'tblSmallGroupMembers'           => 'Small groups you belong to',
        'tblSmallGroupPlaces'            => 'Small group meeting places at your home',
        'tblSmallGroups'                 => 'Small groups you created or host',
        'tblSmsMessage'                  => 'Text messages sent to you',
        'tblSongs'                       => 'Songs you added',
        'tblTasks'                       => 'Tasks you were given or created',
        'tblTotpBackupCodes'             => 'Your two-step sign-in backup codes',
        'tblTrustedDevices'              => 'Devices you chose to trust at sign-in',
        'tblUserDepts'                   => 'Departments you belong to',
        'tblUserDeptsUnplaced'           => 'Department memberships waiting to be placed in an organisation',
        'tblUserGroups'                  => 'User groups you belong to',
        'tblUserGroupsUnplaced'          => 'Group memberships waiting to be placed in an organisation',
        'tblUserMilestone'               => 'Dates you added to your profile, such as a birthday',
        'tblUserOutsideServicePref'      => 'Your choices about outside services',
        'tblUserRoles'                   => 'Roles you hold',
        'tblUserRolesUnplaced'           => 'Roles waiting to be placed in an organisation',
        'tblUserSites'                   => 'The organisations you belong to',
        'tblUserSmsPreference'           => 'Your text message settings',
        'tblUserTours'                   => 'Guided tours you have finished',
        'tblUserTranslationPref'         => 'Your choice about translating content',
        'tblUsers'                       => 'Your account and profile',
        'tblVenueAgreementFiles'         => 'Venue agreement files you uploaded',
        'tblVenueAgreements'             => 'Venue agreements you created',
        'tblVenueBookingGroups'          => 'Venue booking groups you created',
        'tblVenueBookings'               => 'Venue bookings you created or last changed',
        'tblVenueImportBatches'          => 'Venue booking files you imported',
        'tblVenueInvoicePayments'        => 'Venue invoice payments you recorded',
        'tblVenueInvoices'               => 'Venue invoices you created',
        'tblVenueUsageTypeWindows'       => 'Default hours for venue uses that you created',
        'tblVenues'                      => 'Venues you added',
        'tblVisitor'                     => 'Your visitor record, from before you had an account',
        'tblVisitorContact'              => 'Visitor follow-up contacts you recorded',
        'tblWebAuthnCredentials'         => 'Passkeys you registered',
        'tblWebhooks'                    => 'Outgoing notification links you set up',
        'tblWorkflowActions'             => 'Approval steps you acted on',
        'tblWorkflowInstances'           => 'Approvals you started',
        'tblZoomAccount'                 => 'A Zoom account you connected',
        'tblZoomMeeting'                 => 'Zoom meetings you created',
    ];

    /**
     * Table => the phrase for the records the person MADE as a worker, for the
     * tables WORDS describes as records ABOUT the person. Used only when
     * `words()` is given a column that names who did something (an actor column).
     *
     * @var array<string, string>
     */
    public const MADE = [
        'tblAssetLicenseAssignments' => 'Software seats you assigned or released for other people',
        'tblAssetLoans'              => 'Equipment loans you requested or approved',
        'tblCareCase'                => 'Pastoral care cases you opened',
        'tblCareVisit'               => 'Pastoral care visits you made or were asked to follow up',
        'tblDbsChecks'               => 'Safeguarding (DBS) checks you recorded for other people',
        'tblEventAttendance'         => 'Event attendance you marked for other people',
        'tblEventCoordinators'       => 'Event coordinators you appointed',
        'tblEventRegistrations'      => 'Event registrations you submitted or reviewed for other people',
        'tblExternalAudienceMembers' => 'People you added to the lists of who may see outside calendars',
        'tblGivingEntry'             => 'Gifts you recorded for other people',
        'tblGivingStatementLog'      => 'Giving statements you created for other people',
        'tblInvitation'              => 'Invitations you sent or withdrew',
        'tblLeadershipAssignments'   => 'Leadership roles you recorded or changed for other people',
        'tblOffboarding'             => 'Offboarding you carried out for other people',
        'tblPathwayEnrolments'       => 'Pathway enrolments you made for other people',
        'tblPathwayProgress'         => 'Pathway steps you marked or took back for other people',
        'tblPrayerRequests'          => 'Prayer requests you moderated or were given',
        'tblResourceBooking'         => 'Room and resource bookings you approved',
        'tblSmallGroupMeetingAttendance' => 'Small group attendance you marked for other people',
        'tblSmallGroupMembers'       => 'Small group members you added',
        'tblSmallGroupPlaces'        => 'Small group meeting places you entered',
        'tblSmallGroups'             => 'Small groups you created, or whose meeting place you entered',
        'tblUserDepts'               => 'Department members you added',
        'tblUserGroups'              => 'Group members you added',
        'tblUserRoles'               => 'Roles you gave to other people',
        'tblVisitor'                 => 'Visitor records you created or were given to follow up',
    ];

    /**
     * The plain phrase for a kind of record.
     *
     * @param string $table  The table name `GdprEraser::inventory()` row carries.
     * @param string $column The instruction's person column, when known: an
     *                       actor column (who made, approved or marked the
     *                       record) selects the phrase for what the person MADE.
     */
    public static function words(string $table, string $column = ''): string
    {
        if ($column !== '' && isset(self::MADE[$table]) === true
            && in_array($column, GdprEraser::ACTOR_COLUMNS, true) === true) {
            return self::MADE[$table];
        }
        if (isset(self::WORDS[$table]) === true) {
            return self::WORDS[$table];
        }

        // A table with no phrase (section 28 of the coverage test fails on
        // that): a plain fallback that names no table, never the raw name.
        return 'Another kind of record we hold';
    }
}
