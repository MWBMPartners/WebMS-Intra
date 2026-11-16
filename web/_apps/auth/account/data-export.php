<?php
// Path: public_html/auth/account/data-export.php
/**
 * -----------------------------------------------------------------------------
 * Account — Data Export (GDPR portability) 📦
 * -----------------------------------------------------------------------------
 * Bundles every record the portal holds about the current user into a
 * single JSON document and offers it as a download.
 *
 * Closes part of #47 (Right to Access / Portability under GDPR Art.15+20).
 *
 * Tables included:
 *   tblUsers              (your profile)
 *   tblLocalAccounts      (username, NOT the password hash)
 *   tblLinkedAccounts     (SSO links)
 *   tblWebAuthnCredentials (passkey labels, NOT public-key material)
 *   tblPasswordResets     (history, NOT the token hashes)
 *   tblActivityLogs       (your activity history)
 *   tblExpenseClaims      (your claims, line items, attachments metadata)
 *   tblPrayerRequests     (your requests if any)
 *   tblConsentLog         (your consent history)
 *   tblTrustedDevices     (active + revoked trust cookies)
 *   tblNotificationPreferences (when present)
 *   tblVenueBookings      (bookings you last edited — #429)
 *   tblVenueUsageTypeWindows (default-hours windows you created — #429)
 *   tblVenueImportBatches (CSV/XLSX import batches you uploaded — #429)
 *   tblGiftAidDeclaration (your Gift Aid declarations — address/postcode/
 *                          status/dates — #456 Chunk B)
 *   tblFormResponses      (your Forms Builder responses — internal channel
 *                          only, public/anonymous responses have no
 *                          submitterID to match — #153)
 *   tblEventRegistrations (event registrations that are yours, and ones you
 *                          typed in for somebody else while signed in — name,
 *                          date of birth, allergies, medical notes, parent
 *                          contact details. Registrations made without an
 *                          account record nobody to match against, and are
 *                          removed on a time limit instead — #479, #492)
 *   tblDemoDataRegister   (Demo Data list entries pointing at your account or
 *                          your memberships — only possible if a made-up demo
 *                          person was edited into your real account — #498)
 *   tblUserRoles          (roles you hold, and in which organisation — #516)
 *   tblUserRolesUnplaced  (a role key waiting for a global administrator to
 *                          place, if a hand-edited database left you one — #516)
 *   tblUserGroups         (user groups you are in, and in which organisation — #517)
 *   tblUserDepts          (departments you are in, with your flags such as
 *                          lead or approver, and in which organisation — #517)
 *   tblUserGroupsUnplaced (a group membership waiting for a global
 *                          administrator to place — #517)
 *   tblUserDeptsUnplaced  (a department membership waiting for a global
 *                          administrator to place — #517)
 *   tblExternalAudienceMembers (outside calendars whose "who may see these
 *                          events" list names you — #514)
 *   tblExternalApprovalNotices (if you are an administrator: when you were
 *                          last told that imported events were waiting for
 *                          approval, and in which organisation — #514 part 9)
 *   tblMailQueue, tblMailQueueFiles (e-mail waiting to be sent to your
 *                          address: its subject, when it was written, what
 *                          state it is in and the names of any attached
 *                          files. NOT its text: that is stored encrypted and
 *                          may be for other people too — #592)
 *   tblEmailLog           (the portal's record of e-mail sent, or tried, to
 *                          your address: the subject, when, and what became
 *                          of it — #592)
 *   tblUserOutsideServicePref (your own choices about outside services — #778)
 *   tblUserSites          (the organisations you belong to, with your
 *                          administrator flags, whether active, and when you
 *                          joined — #813)
 *   tblUserTranslationPref (your choice to have content translated — #813)
 *   tblUserSmsPreference  (your text-message settings, without the one-time
 *                          verification code — #813)
 *   tblNewsletterSubscription (whether you receive each organisation's
 *                          newsletter, without the unsubscribe code — #813)
 *   tblZoomAccount        (a Zoom account you connected, without its sign-in
 *                          keys — #813)
 *   tblAssetKioskPins     (whether you have an equipment kiosk PIN, never the
 *                          PIN — #813)
 *   tblCareVisit, tblCareCase, tblDbsChecks, tblKidCheckins (only a LIST of
 *                          the records that carry your name as the worker who
 *                          made them: kind, day and organisation, never the
 *                          other person's details — #929, D-142)
 *   tblDbsChecks          (the DBS checks recorded FOR you: type, certificate
 *                          reference, dates, status and notes, never who
 *                          recorded them — #1012)
 *   tblCareCase, tblCareVisit (the pastoral care cases ABOUT you: organisation,
 *                          your name as typed, category, summary, status,
 *                          opened and closed; each visit's day and kind only.
 *                          Never who opened it, its number, the visiting
 *                          worker or the visit notes — #1015, D-184)
 *   #1063 — every other kind of record the erasure deletes with you:
 *   tblAiUsage            (what you asked the AI assistant, its answer, the cost)
 *   tblAssetOwners        (equipment you own or look after, without the notes)
 *   tblEventCoordinators  (events you coordinate, without who made you one)
 *   tblEventCrewMembers, tblEventJobAssignments, tblEventPeople (event crews,
 *                          jobs and listings you are on)
 *   tblEventRSVPs         (your answers to events)
 *   tblInvitation         (the invitation you joined by, without its code)
 *   tblLeadershipAssignments (leadership roles, without the notes)
 *   tblNewsletterRecipient (newsletters sent to you, without the unsubscribe code)
 *   tblOffboarding        (if you were offboarded: when, and what was chosen for
 *                          your data; without the reason typed, which is an
 *                          administrator's note)
 *   tblPathwayEnrolments, tblPathwayProgress (pathways and steps, without notes)
 *   tblPaymentMethod      (saved ways to pay, without the payment company's
 *                          references)
 *   tblPledges, tblProjectPledge (promises to give)
 *   tblPushSubscriptions  (notification devices, without their address or keys)
 *   tblReadingPlanEnrollment, tblUserMilestone, tblUserTours
 *   tblRecordingPlay      (recordings you played, with the scrambled address)
 *   tblSmsMessage         (texts sent to you; the code in a text that
 *                          confirmed your phone number is masked, and a
 *                          prayer-chain text's words are left out)
 *   tblCareAccessLog      (when you opened a pastoral care file, nothing else)
 *   tblVisitor            (your visitor record from before you had an account,
 *                          without the follow-up notes)
 *   Left out on purpose, each with its reason in the written list
 *   (personal-data-catalogue.php, `notInDataCopy`): tblTotpBackupCodes and
 *   tblRestoreHeldRows.
 *   A kind that can grow without limit is cut to its newest
 *   DATA_COPY_ROWS_PER_KIND rows, and `shortened` in the file says so.
 *
 * Sensitive fields (password hashes, TOTP secret, tokenHash etc.) are
 * EXCLUDED — exporting them would be a security regression, not a feature.
 *
 * @package   Portal\Auth
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\GdprEraser;
use Portal\Core\Logger;
use Portal\Core\MailQueue;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$user   = App::user();
$userId = (int) ($user['userID'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . Site::url('account'), true, 302);
    exit();
}

/**
 * 🛠️ Helper — run a SELECT * style query bound to the current user and
 * return rows with sensitive columns removed.
 *
 * Every '?' in the query is the SAME account number. It used to be exactly
 * one, and became "however many there are" on 3 November 2026 (#492 part 3),
 * because a table can name a person in more than one column and the download
 * has to cover each — an event registration can be one the person submitted OR
 * one that is about them, and the erasure now reaches both, so the download
 * must too. Counting the marks rather than taking a number as an argument
 * keeps every caller's query the only place that decides.
 *
 * @param string $sql           Prepared SQL whose every '?' is the user ID
 * @param string[] $stripFields Column names to drop from each row
 */
/**
 * 📏 How many rows of ONE kind the copy hands over, for the kinds that can
 * grow without limit (#1063): text messages, AI questions, recordings played,
 * newsletters, event answers, care files opened. Each such query asks for one
 * more than this, so the page can tell "exactly this many" from "more", and
 * `shortened` in the file says, in words, which kinds were cut. The activity
 * history is one of them: it was cut at 5,000 rows silently before #1063.
 */
const DATA_COPY_ROWS_PER_KIND = 5000;

// 🔀 #1064 (D-199): the account AND every account merged into it are the same
//    person, and a merge never reassigns history, so every query below runs for
//    each of those numbers and the rows are handed over together. The order is
//    this account first, then the merged-in ones.
$personIds = array_merge([$userId], \Portal\Core\AccountMerge::mergedInto($mysqli, $userId));

$fetchUserRows = static function (string $sql, array $stripFields = []) use ($mysqli, $personIds): array {
    $rows = [];
    foreach ($personIds as $personId) {
        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        // 🔢 One bound value per '?'. The SQL here is written by this file alone —
        //    never built from a request — so counting the marks in it is safe, and
        //    a query with none would be refused by prepare() long before this.
        $marks  = substr_count($sql, '?');
        $values = array_fill(0, max(1, $marks), $personId);
        $stmt->bind_param(str_repeat('i', count($values)), ...$values);
        $stmt->execute();
        $rows = array_merge($rows, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $stmt->close();
    }
    foreach ($rows as &$row) {
        foreach ($stripFields as $f) {
            unset($row[$f]);
        }
    }
    return $rows;
};

$payload = [
    'exportVersion' => '1',
    'exportedAt'    => gmdate('c'),
    'siteID'        => Site::id(),
    'subject'       => [
        'userID'       => $userId,
        'emailAddress' => $user['emailAddress'] ?? null,
    ],
    'data' => [
        // 📍 #456 Chunk B — latitude/longitude/what3words/visibilityCoords
        // ride along automatically via SELECT * (verified: they are NOT
        // added to the strip list below — visibilityCoords is the
        // subject's own datum, and the coordinates/W3W are exactly the
        // kind of PII this export exists to surface).
        //
        // 📅 `calendarToken` IS handed over, on purpose (#1063 check round 2):
        //    it holds only a SHA-256 fingerprint of your calendar feed's token
        //    (Ical::ensureUserToken()), which cannot be turned back into a
        //    working feed address. tools/data-copy-selftest.php pins that the
        //    token is stored only as a fingerprint; if that ever changed, it
        //    would have to be stripped here like `totpSecret`.
        'user' => $fetchUserRows(
            'SELECT * FROM tblUsers WHERE userID = ? LIMIT 1',
            ['totpSecret']
        ),
        'localAccount' => $fetchUserRows(
            'SELECT localID, userID, username, isVerified, createdAt FROM tblLocalAccounts WHERE userID = ? LIMIT 1'
        ),
        'linkedAccounts' => $fetchUserRows(
            // tblLinkedAccounts uses `linkedAt` not `createdAt`
            'SELECT linkID, userID, provider, providerEmail, linkedAt FROM tblLinkedAccounts WHERE userID = ?'
        ),
        'webauthnCredentials' => $fetchUserRows(
            // tblWebAuthnCredentials uses `friendlyName` not `label`
            'SELECT credentialID, userID, friendlyName, transports, createdAt, lastUsedAt FROM tblWebAuthnCredentials WHERE userID = ?'
        ),
        'passwordResets' => $fetchUserRows(
            'SELECT resetID, userID, expiresAt, usedAt, createdAt, createdIP FROM tblPasswordResets WHERE userID = ?'
        ),
        // 🙈 THE DESCRIPTION IS LEFT OUT ON PURPOSE (#626, the owner's decision,
        //    27 October 2026). This block is a person's own download of what
        //    the portal holds about them, and any signed-in member may ask for
        //    it.
        //
        //    `activityDescription` is free text written by some 550 different
        //    places, and about two dozen of them put ANOTHER PERSON into it —
        //    an invited guest's e-mail address, an outside crew member's name.
        //    Those records used to be invisible here because they carried no
        //    account number at all; since #626 gave every record the account
        //    that caused it, they are this person's records and would be
        //    handed over with the third party's details in them.
        //
        //    Nothing checks what goes into that column, so it cannot be made
        //    safe by reading it once. Everything else about the record stays:
        //    what kind of thing happened, from what address, and when.
        //
        //    Tried and rejected: stripping e-mail addresses and quoted names
        //    out of the text. It is pattern-matching against free text from
        //    550 call sites — it would miss cases while looking as though it
        //    had not, which is worse than leaving the field out.
        'activityLogs' => $fetchUserRows(
            'SELECT logID, activityType, visitorIP, timestamp FROM tblActivityLogs WHERE userID = ? ORDER BY timestamp DESC LIMIT ' . (DATA_COPY_ROWS_PER_KIND + 1)
        ),
        'expenseClaims' => $fetchUserRows(
            // tblExpenseClaims uses `userID` not `submittedByID`
            'SELECT * FROM tblExpenseClaims WHERE userID = ?'
        ),
        'prayerRequests' => $fetchUserRows(
            // 🙈 NAMED COLUMNS, not `SELECT *`, and two are deliberately left out.
            //
            //    `moderatorID` and `assignedToUserID` are OTHER PEOPLE — the
            //    personal-data list marks both as such. `SELECT *` handed a
            //    person the account numbers of whoever moderated their request
            //    and whoever it was given to, in their own download. Found by
            //    the Codex review of 27 October 2026; the owner chose to drop
            //    the third-party columns and keep everything that is the
            //    person's own.
            //
            //    The same reasoning as leaving the free-text description out of
            //    the activity records above: a download is for what the portal
            //    holds about YOU, and somebody else's identity is not that.
            //
            //    If a column is added to tblPrayerRequests, it must be added
            //    here on purpose — which is the point of naming them. Ask of
            //    each one: whose is it?
            'SELECT requestID, siteID, submitterID, assignedAt, partnerNote, partnerLastPrayedAt, '
            . 'submitterName, submitterEmail, submitterIP, subject, body, kind, visibility, status, '
            . 'isAnonymous, testimony, answeredAt, moderatedAt, createdAt, updatedAt '
            . 'FROM tblPrayerRequests WHERE submitterID = ?'
        ),
        'consentLog' => $fetchUserRows(
            'SELECT consentID, consentType, decision, policyHash, ipAddress, createdAt FROM tblConsentLog WHERE userID = ? ORDER BY createdAt DESC'
        ),
        'trustedDevices' => $fetchUserRows(
            'SELECT deviceID, label, createdIP, lastSeenAt, expiresAt, revokedAt, createdAt FROM tblTrustedDevices WHERE userID = ?'
        ),
        // 🔀 #1064 — requests to merge your accounts: which side each account
        //    was, when it agreed, and (on a merged duplicate) the address it held
        //    when it was merged. Export↔erasure parity with
        //    GdprEraser::eraseMergeRecords(), which empties that address.
        'accountMerges' => $fetchUserRows(
            'SELECT `requestID`, `userID`, `side`, `confirmedAtUtc`, `formerEmail`, `formerEarlierEmail` FROM tblAccountMergeSide WHERE `userID` = ?'
        ),
        // 🛂 #778 — your own choices about outside services (which outside
        //    companies may be sent something about you). Export↔erasure parity
        //    with the GdprEraser instruction added alongside it.
        'outsideServiceChoices' => $fetchUserRows(
            'SELECT `serviceKey`, `choice`, `updatedAt` FROM `tblUserOutsideServicePref` WHERE `userID` = ?'
        ),
        // 🏢 #813 check round 2 — the organisations you belong to: whether
        //    you are an administrator there, whether the membership is
        //    active, and when you joined. The erasure deletes these rows, and
        //    the copy only ever named the table inside another entry's
        //    sub-select, so they were handed over nowhere. Each row NAMES its
        //    organisation, whatever state that organisation is in: it is the
        //    member's OWN membership, as their roles, groups and departments
        //    above name theirs. (A draft withheld the name of a switched-off
        //    organisation, reading D-151 as "never name an organisation the
        //    person cannot open". D-151 is narrower: it is about a worker's
        //    records about OTHER people in an organisation they have left -
        //    those are counted, not listed. The coordinator's ruling, #1018
        //    check round 3.)
        'organisationMemberships' => $fetchUserRows(
            'SELECT us.`siteID`, s.`siteName`, us.`isSiteAdmin`, us.`isSiteRootAdmin`, us.`isActive`, us.`joinedAt` '
            . 'FROM `tblUserSites` us LEFT JOIN `tblSites` s ON s.`siteID` = us.`siteID` WHERE us.`userID` = ? ORDER BY us.`siteID`'
        ),
        // 🌐 #813 — your own choice to have the organisation's words
        //    translated for you (D-049, #485). It is a choice about you, kept
        //    until you are erased, and was missing from this copy. The next
        //    four are the same kind of record - one row per member (and per
        //    organisation), holding that member's own setting - found missing
        //    by the check #813 asked for (section 26 of
        //    tools/gdpr-coverage-selftest.php), which finds such tables from
        //    the install script's own keys rather than from a list typed here.
        'translationChoice' => $fetchUserRows(
            'SELECT `autoTranslate`, `updatedAt` FROM `tblUserTranslationPref` WHERE `userID` = ?'
        ),
        // 📱 Your text-message settings. The verification code and when it
        //    runs out are left out: they are a one-time secret, not a fact
        //    about you, and handing one over would let anybody holding this
        //    file finish a verification in your name.
        'textMessageSettings' => $fetchUserRows(
            'SELECT `siteID`, `phoneNumber`, `isVerified`, `categories`, `updatedAt` FROM `tblUserSmsPreference` WHERE `userID` = ?'
        ),
        // 📰 Whether you receive each organisation's newsletter. The
        //    unsubscribe code is left out: it is a key that works without
        //    signing in.
        'newsletterChoices' => $fetchUserRows(
            'SELECT `siteID`, `optedIn`, `updatedAt` FROM `tblNewsletterSubscription` WHERE `userID` = ?'
        ),
        // 🎥 A Zoom account you connected. The two sign-in keys Zoom gave the
        //    portal are left out (they are encrypted secrets, and a working
        //    key in a downloaded file is a key anybody holding it can use);
        //    what is handed over is which Zoom account it is and what it was
        //    allowed to do.
        'zoomAccounts' => $fetchUserRows(
            'SELECT `siteID`, `zoomUserId`, `zoomAccountEmail`, `scopes`, `createdAt`, `updatedAt` FROM `tblZoomAccount` WHERE `userID` = ?'
        ),
        // 🔢 Whether you have a PIN for the equipment kiosk, and when it was
        //    last used. The PIN itself is stored only scrambled and is never
        //    handed over.
        'equipmentKioskPins' => $fetchUserRows(
            'SELECT `siteID`, `isActive`, `createdAt`, `lastUsedAt` FROM `tblAssetKioskPins` WHERE `userID` = ?'
        ),
        // 🏷️ Roles (#516) — export↔erasure parity with the GdprEraser
        // catalogue entries added alongside this block. `grantedByID` is
        // deliberately left out (another person's account number, not
        // this subject's own data — the same reasoning already applied to
        // every other "who did this to me" column in this export).
        'roles' => $fetchUserRows(
            'SELECT ur.userRoleID, ur.siteID, s.siteName, r.roleKey, r.roleName, ur.grantedAt '
            . 'FROM tblUserRoles ur '
            . 'JOIN tblRoles r ON r.roleID = ur.roleID AND r.siteID = ur.siteID '
            . 'JOIN tblSites s ON s.siteID = ur.siteID '
            . 'WHERE ur.userID = ?'
        ),
        // 🖊️ A role key migration 202 could not place automatically on a
        // hand-edited database, still waiting for a global administrator
        // to place it at /admin/users/roles-unplaced (#516).
        'rolesAwaitingPlacement' => $fetchUserRows(
            'SELECT unplacedID, roleKey, roleName, createdAt FROM tblUserRolesUnplaced WHERE userID = ?'
        ),
        // 👥🏢 User groups and departments (#517) — export↔erasure parity
        // with the GdprEraser::catalogue() entries added alongside these
        // blocks. `addedByID` is deliberately left out of all four: it is
        // ANOTHER person's account number (who added you), not your own
        // data — the same reasoning as `grantedByID` on roles above. The
        // group query starts from tblGroups rather than tblUserGroups with a
        // short name, because tools/audit-checks/check_sql_columns.py
        // misreads that second shape (its own header, blind spot 15).
        'userGroups' => $fetchUserRows(
            'SELECT ug.userGroupID, ug.siteID, s.siteName, g.groupName, ug.addedAt '
            . 'FROM tblGroups g '
            . 'JOIN tblUserGroups ug ON ug.groupID = g.groupID AND ug.siteID = g.siteID '
            . 'JOIN tblSites s ON s.siteID = ug.siteID '
            . 'WHERE ug.userID = ?'
        ),
        'departments' => $fetchUserRows(
            'SELECT ud.userDeptID, ud.siteID, s.siteName, d.deptName, d.deptCode, ud.isDeptLead, ud.isDeptAssistant, '
            . 'ud.isDeptSecretary, ud.isApprover, ud.isMandatoryApprover, ud.addedAt '
            . 'FROM tblDepts d '
            . 'JOIN tblUserDepts ud ON ud.deptID = d.deptID AND ud.siteID = d.siteID '
            . 'JOIN tblSites s ON s.siteID = ud.siteID '
            . 'WHERE ud.userID = ?'
        ),
        // 🖊️ Memberships migration 203 could not place automatically on a
        // hand-edited database, still waiting for a global administrator at
        // /admin/users/memberships-unplaced (#517).
        'groupMembershipsAwaitingPlacement' => $fetchUserRows(
            'SELECT unplacedID, groupID, groupName, createdAt FROM tblUserGroupsUnplaced WHERE userID = ?'
        ),
        'departmentMembershipsAwaitingPlacement' => $fetchUserRows(
            'SELECT p.unplacedID, p.deptID, d.deptName, p.isDeptLead, p.isDeptAssistant, p.isDeptSecretary, '
            . 'p.isApprover, p.isMandatoryApprover, p.createdAt '
            . 'FROM tblUserDeptsUnplaced p '
            . 'JOIN tblDepts d ON d.deptID = p.deptID '
            . 'WHERE p.userID = ?'
        ),
        // 👁️ Outside calendars you have been named on (#514 part P1) —
        // export↔erasure parity with the two GdprEraser::catalogue() entries
        // added alongside this block. Each row says which calendar's list
        // names you and whether that list belongs to the calendar itself or
        // (in a later part) to one date or a rule. `createdByID` is
        // deliberately left out: it is ANOTHER person's account number (who
        // added you), the same reasoning as `addedByID` above. A plain JOIN
        // to the calendar loses nothing: every list row belongs to a
        // calendar that exists, because deleting a calendar deletes its
        // lists (the foreign key fk_extaud_feed).
        'outsideCalendarAudiences' => $fetchUserRows(
            'SELECT am.audienceMemberID, am.siteID, xf.name AS calendarName, am.ownerType, am.createdAt '
            . 'FROM tblExternalFeeds xf '
            . 'JOIN tblExternalAudienceMembers am ON am.feedID = xf.feedID '
            . 'WHERE am.userID = ?'
        ),
        // 🔔 When this person was told that imported events were waiting for
        // approval (#514 part 9, tblExternalApprovalNotices). There is a row
        // only for an administrator who has been sent the daily e-mail or the
        // push, one for each organisation. Handed over: the organisation, the
        // day of the last daily e-mail and what became of it, and the moment
        // of the last push.
        //
        // TWO KINDS OF TIME, and each key says which. `lastEmailDay` is a
        // date on THAT ORGANISATION'S OWN calendar, not a UTC date. The two
        // keys ending in "Utc" are moments in UTC.
        //
        // The matching erasure step is the catalogue's own: the entry says
        // "erase" and names `userID`, so the row is deleted with the person.
        //
        // Error 1146 means the table does not exist, because the database
        // has not been upgraded to include it (migration 218). Then nothing
        // is held, so the answer is an empty list. Any other database error
        // is not hidden.
        'importedEventNotices' => (static function () use ($fetchUserRows): array {
            try {
                return $fetchUserRows(
                    'SELECT n.siteID, s.siteName, n.emailLocalDate AS lastEmailDay, n.emailOutcome AS lastEmailOutcome, '
                    . 'n.emailAt AS lastEmailClaimedAtUtc, n.pushAt AS lastPushAtUtc '
                    . 'FROM tblSites s '
                    . 'JOIN tblExternalApprovalNotices n ON n.siteID = s.siteID '
                    . 'WHERE n.userID = ?'
                );
            } catch (\mysqli_sql_exception $problem) {
                if ($problem->getCode() === 1146) {
                    return [];
                }
                throw $problem;
            }
        })(),
        // 🏛️ Venue Bookings (#429) — export↔erasure parity with the three
        // GdprEraser::catalogue() entries added alongside this block.
        'venueBookingsEdited' => $fetchUserRows(
            // tblVenueBookings.updatedByID — bookings this user last edited.
            //
            // 🙈 `notes` is NOT included, and that is deliberate. These rows are
            //    here only because this person was the LAST TO EDIT the booking,
            //    which does not make the booking theirs. The notes are free text
            //    that anybody may have written and may hold somebody else's
            //    telephone number or name. Found by the Codex review of
            //    27 October 2026; the owner chose to drop it.
            //
            //    Stripping names out of the text was rejected for the activity
            //    log for the same reason it would be wrong here: pattern-matching
            //    free text misses cases while looking as though it has not.
            'SELECT bookingID, siteID, venueID, roomID, bookingDate, startTime, endTime, statusID, updatedAt '
            . 'FROM tblVenueBookings WHERE updatedByID = ?'
        ),
        'venueUsageWindowsCreated' => $fetchUserRows(
            // tblVenueUsageTypeWindows.createdByID — default-hours windows this user created
            'SELECT windowID, siteID, usageTypeID, effectiveFrom, defaultStartTime, defaultEndTime, note, createdAt '
            . 'FROM tblVenueUsageTypeWindows WHERE createdByID = ?'
        ),
        'venueImportBatches' => $fetchUserRows(
            // tblVenueImportBatches.createdByID — CSV/XLSX import batches this user uploaded
            'SELECT batchID, siteID, venueID, fileName, sourceKind, status, rowCount, importedCount, skippedCount, createdAt, committedAt '
            . 'FROM tblVenueImportBatches WHERE createdByID = ?'
        ),
        // 📍 #456 Chunk B — closes a pre-existing gap found while building
        // the location/GDPR lockstep: Gift Aid declarations carry a home
        // address + postcode (HMRC requires it) but were never exported
        // for the declaring donor. This export block is the access/
        // portability half; GdprEraser::catalogue() holds the erasure half.
        // (That half used to hard-DELETE the declaration. Since 11 October
        // 2026, on the owner's decision, it KEEPS it for tax reasons and
        // records that in the erasure's audit trail - so a person may see a
        // declaration here that an erasure will not remove.)
        'giftAidDeclarations' => $fetchUserRows(
            'SELECT declarationID, siteID, status, validFrom, validTo, address, postcode, acceptedAt, createdAt '
            . 'FROM tblGiftAidDeclaration WHERE donorID = ?'
        ),
        // 📊 Reports Builder (#156) — definitions the subject authored.
        // `definition` (the registry-keys-only JSON) is included too: it
        // is the report's structure (source/columns/filters), not a
        // results snapshot — report OUTPUT is never stored anywhere.
        'reportDefinitions' => $fetchUserRows(
            'SELECT reportID, siteID, reportName, description, sourceKey, definition, isShared, lastRunAt, runCount, createdAt '
            . 'FROM tblReportDefinitions WHERE createdByID = ?'
        ),
        // 🧾 Forms Builder (#153) — export↔erasure parity with the
        // GdprEraser::catalogue() 'tblFormResponses' entry added alongside
        // this block. Public (anonymous) responses carry no submitterID and
        // so are never included here — same reasoning as the salvation
        // decision-card note immediately below.
        'formResponses' => $fetchUserRows(
            'SELECT responseID, formID, siteID, channel, answersJson, status, createdAt '
            . 'FROM tblFormResponses WHERE submitterID = ?'
        ),
        // 🧒 Event registrations (#479) — somebody signing a child up for
        // an event. The most sensitive information the portal holds: a
        // child's name, date of birth, allergies and medical notes, beside a
        // parent's telephone number and email address.
        //
        // Only registrations with an account recorded against them appear
        // here, because only those record anybody to match. Anybody may
        // register a child WITHOUT an account, and those carry nothing to
        // match a person against - the same reasoning as the public form
        // responses above. Those are covered by a time limit instead: they are
        // deleted a set number of days after the event, whether anybody asks
        // or not. See migration 191 and the clear-out on the Retention page.
        //
        // This is the matching half of the erasure entry: the portal must be
        // able to both HAND OVER and REMOVE the same information, and until
        // #479 it could do neither for this table.
        //
        // 👤 TWO COLUMNS SINCE #492 PART 3 (3 November 2026, migration 246),
        //    and both are needed. `registeredForUserID` says whose record the
        //    registration IS, and that is the column an erasure DELETES on; it
        //    can be a registration somebody else typed in, so matching only on
        //    the submitter would hand back less than the erasure removes.
        //    `submittedByUserID` says who typed it, and those registrations
        //    stay here because the person really does hold them: they filled
        //    the form in, and they may need to see what they sent.
        'eventRegistrations' => $fetchUserRows(
            'SELECT r.registrationID, r.eventID, e.eventName, e.startDateTime, '
            . 'r.fullName, r.dateOfBirth, r.grade, r.gender, r.shirtSize, '
            . 'r.allergies, r.medicalNotes, r.parentName, r.parentPhone, '
            . 'r.parentEmail, r.photoConsent, r.emergencyContactName, '
            . 'r.emergencyContactPhone, r.status, r.source, r.createdAt '
            . 'FROM tblEventRegistrations AS r '
            . 'INNER JOIN tblEvents AS e ON e.eventID = r.eventID '
            . 'WHERE r.submittedByUserID = ? OR r.registeredForUserID = ?'
        ),
        // 👥 Small Groups (#150) — export↔erasure parity with the six
        // GdprEraser::catalogue() entries added alongside this block.
        // Neither block below aliases its OWN `tblSmallGroup*` table right
        // after its FROM — every such table name contains the bare
        // substring "Group", which trips a backtracking false-positive in
        // check_sql_columns.py's SELECT-column regex when a short alias
        // sits directly after `FROM tblSmallGroup*` (see SmallGroups.php's
        // matching comment for the full mechanics). The JOINed table can
        // still be aliased freely — the checker's FROM-anchored regex
        // never inspects text after JOIN.
        'smallGroupMemberships' => $fetchUserRows(
            'SELECT tblSmallGroupMembers.membershipID, tblSmallGroupMembers.siteID, tblSmallGroupMembers.groupID, '
            . 'g.groupName, tblSmallGroupMembers.memberRole, tblSmallGroupMembers.status, '
            . 'tblSmallGroupMembers.joinedAt, tblSmallGroupMembers.endedAt, tblSmallGroupMembers.requestNote, '
            . 'tblSmallGroupMembers.createdAt '
            . 'FROM tblSmallGroupMembers INNER JOIN tblSmallGroups g ON g.groupID = tblSmallGroupMembers.groupID '
            . 'WHERE tblSmallGroupMembers.userID = ?'
        ),
        'smallGroupAttendance' => $fetchUserRows(
            'SELECT tblSmallGroupMeetingAttendance.attendanceID, mt.groupID, mt.meetingDate, mt.topic, '
            . 'tblSmallGroupMeetingAttendance.markedAt '
            . 'FROM tblSmallGroupMeetingAttendance '
            . 'INNER JOIN tblSmallGroupMeetings mt ON mt.meetingID = tblSmallGroupMeetingAttendance.meetingID '
            . 'WHERE tblSmallGroupMeetingAttendance.userID = ?'
        ),
        'smallGroupsCreated' => $fetchUserRows(
            'SELECT groupID, siteID, groupName, createdAt FROM tblSmallGroups WHERE createdByID = ?'
        ),
        'smallGroupMembersAdded' => $fetchUserRows(
            'SELECT membershipID, groupID, memberRole, status, createdAt FROM tblSmallGroupMembers WHERE addedByID = ?'
        ),
        // 🏠 #809 (D-124): a small group's meeting place names its HOST, and it
        //    is that host's home. So the person's own home address, as a group
        //    holds it, is theirs to see — the group's usual place where they are
        //    its host, and every listed home they host. Erasure empties exactly
        //    these rows (GdprEraser's two `hostUserID` instructions), so the
        //    export shows what an erasure would remove. Who TYPED a place in is
        //    not exported for the typist: the home is somebody else's.
        'smallGroupHomeHosted' => $fetchUserRows(
            'SELECT groupID, siteID, groupName, addressLine1, addressLine2, city, region, postcode, countryCode, '
            . 'latitude, longitude, what3words, placeMapAgreed '
            . 'FROM tblSmallGroups WHERE `hostUserID` = ?'
        ),
        'smallGroupListedHomesHosted' => $fetchUserRows(
            'SELECT placeID, groupID, siteID, placeLabel, addressLine1, addressLine2, city, region, postcode, countryCode, '
            . 'latitude, longitude, what3words, placeMapAgreed, isActive, createdAt '
            . 'FROM tblSmallGroupPlaces WHERE `hostUserID` = ?'
        ),
        // 🧪 Demo Data list (#498) — entries on the Demo Data page's list of
        // rows it created (tblDemoDataRegister) that point at this person's
        // own account or at one of their memberships. Normally there are none.
        // It happens only when a made-up demo person was later edited into a
        // real account and kept. An entry holds a table name, a row number, an
        // organisation number, the names of the columns it fingerprinted and a
        // one-way fingerprint of the row as it was loaded: no name or text. It
        // still points at this person, so it is handed over. The matching
        // erasure step is GdprEraser::eraseDemoDataRegisterEntries().
        //
        // Entries for announcements are left out: an announcement is the
        // organisation's content, not information about its author.
        //
        // Matched through tblUsers so the one person number the helper binds
        // can be used twice (a row number only means "this person" together
        // with its table name, so rowID alone is never matched).
        //
        // Error 1146 means the table does not exist, because the database has
        // not been upgraded to include it (migration 194). Then nothing is
        // held, so the answer is an empty list. Web/_core/Maintenance.php only
        // compares version numbers and does not stop the portal while an
        // upgrade is waiting, so without this the whole export would fail on
        // such a database. Any other database error is not hidden.
        'demoDataRegister' => (static function () use ($fetchUserRows): array {
            try {
                return $fetchUserRows(
                    'SELECT r.registerID, r.tableName, r.rowID, r.siteID, r.fingerprintColumns, r.fingerprint, r.createdAt '
                    . 'FROM tblUsers AS u '
                    . "INNER JOIN tblDemoDataRegister AS r ON (r.tableName = 'tblUsers' AND r.rowID = u.userID) "
                    . "OR (r.tableName = 'tblUserSites' AND r.rowID IN (SELECT us.userSiteID FROM tblUserSites AS us WHERE us.userID = u.userID)) "
                    . 'WHERE u.userID = ?'
                );
            } catch (\mysqli_sql_exception $problem) {
                if ($problem->getCode() === 1146) {
                    return [];
                }
                throw $problem;
            }
        })(),
        // ✉️ E-mail WAITING to be sent to this person's address (#592): the
        // mail queue (tblMailQueue) and the files attached to that mail
        // (tblMailQueueFiles). Handed over: the subject, when the message
        // was written (a moment in UTC), what state it is in, and the names
        // of any attached files. NOT handed over: the text of the message,
        // which is stored encrypted and may be for other people too, and
        // the list of recipients, which can name other people.
        //
        // A person is in these tables only as an e-mail ADDRESS in a list
        // separated by commas, so they are found by address. The LIKE is
        // only a first filter; MailQueue::listHasAddress() then decides, and
        // counts an entry only when it IS the address (a longer address
        // that merely contains it is somebody else's). The matching erasure
        // step is GdprEraser::eraseMailQueueMessages().
        //
        // Error 1146 means the tables do not exist, because the database
        // has not been upgraded to include them (migration 216). Then
        // nothing is waiting, so the answer is an empty list.
        'mailWaitingToBeSent' => (static function () use ($mysqli, $user): array {
            $address = trim((string) ($user['emailAddress'] ?? ''));
            if ($address === '') {
                return [];
            }
            try {
                $like = '%' . addcslashes($address, '\\%_') . '%';
                $stmt = $mysqli->prepare(
                    'SELECT q.queueID, q.subject, q.createdAt, q.status, q.toRecipients, f.realName '
                    . 'FROM tblMailQueue AS q LEFT JOIN tblMailQueueFiles AS f ON f.queueID = q.queueID '
                    . 'WHERE q.toRecipients LIKE ? ORDER BY q.queueID, f.queueFileID'
                );
                $stmt->bind_param('s', $like);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            } catch (\mysqli_sql_exception $problem) {
                if ($problem->getCode() === 1146) {
                    return [];
                }
                throw $problem;
            }
            $waiting = [];
            foreach ($rows as $row) {
                if (MailQueue::listHasAddress((string) $row['toRecipients'], $address) === false) {
                    continue;
                }
                $id = (int) $row['queueID'];
                $waiting[$id] ??= [
                    'subject' => (string) $row['subject'], 'writtenAtUtc' => (string) $row['createdAt'],
                    'state' => (string) $row['status'], 'attachedFiles' => [],
                ];
                if ($row['realName'] !== null) {
                    $waiting[$id]['attachedFiles'][] = (string) $row['realName'];
                }
            }

            return array_values($waiting);
        })(),
        // 📧 The portal's record of e-mail sent, or tried, to this person's
        // address (tblEmailLog, #592): the subject, when, which mail route,
        // and what became of it. Found by address in the same way, and for
        // the same reason. The list of recipients is NOT handed over: it can
        // name other people. `recordedAtUtc` is empty for a row from before
        // migration 213, which has only the database server's own time. The
        // newest 2,000 lines that match the first filter are looked at. The
        // matching erasure step is GdprEraser::eraseEmailLogRecipients().
        'emailSendLog' => (static function () use ($mysqli, $user): array {
            $address = trim((string) ($user['emailAddress'] ?? ''));
            if ($address === '') {
                return [];
            }
            $like = '%' . addcslashes($address, '\\%_') . '%';
            try {
                $stmt = $mysqli->prepare(
                    'SELECT subject, provider, status, sentAtUtc, sentAt, toRecipients FROM tblEmailLog WHERE toRecipients LIKE ? ORDER BY emailLogID DESC LIMIT 2000'
                );
                $stmt->bind_param('s', $like);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            } catch (\mysqli_sql_exception $problem) {
                // 1054: the two columns of migration 213 are not there yet.
                if ($problem->getCode() === 1054 || $problem->getCode() === 1146) {
                    return [];
                }
                throw $problem;
            }
            $lines = [];
            foreach ($rows as $row) {
                if (MailQueue::listHasAddress((string) $row['toRecipients'], $address) === true) {
                    $lines[] = [
                        'subject' => (string) $row['subject'], 'route' => (string) $row['provider'], 'whatBecameOfIt' => (string) $row['status'],
                        'recordedAtUtc' => $row['sentAtUtc'], 'recordedAtServerTime' => (string) $row['sentAt'],
                    ];
                }
            }

            return $lines;
        })(),
        // 🧾 #929 (the owner's decision D-142): the pastoral visits, care
        //    cases, DBS checks and children's check-ins and check-outs that
        //    carry this person's name as the WORKER who made them. Each is
        //    usually about somebody else, so it is LISTED - kind, the day it
        //    was made, and the organisation - and nothing else from the record
        //    is handed over: no name, no notes, no time, no record number. Asked of
        //    GdprEraser::recordsCarryingYourName(), the ONE place they are
        //    found and worded, which the My data page asks too.
        'recordsCarryingYourName' => GdprEraser::recordsCarryingYourName($userId),
        // 🛡️ #1012: the DBS (safeguarding) checks ABOUT this person - the
        //    ones recorded FOR them, which an erasure keeps. My data counted
        //    them and this copy never handed them over. Asked of
        //    GdprEraser::dbsChecksAboutYou(), the ONE place they are read,
        //    which My data's count asks too, so the two cannot disagree. Which
        //    columns are handed over, and why the recorder's name and number
        //    are not, is set out on that method. Not to be confused with the
        //    list just above, which is the checks this person RECORDED for
        //    somebody else.
        'dbsChecksAboutYou' => GdprEraser::dbsChecksAboutYou($userId),
        // 💛 #1015 (the owner's decision D-184): the pastoral care cases ABOUT
        //    this person. My data counted them and this copy never handed them
        //    over. Asked of GdprEraser::careCasesAboutYou(), the ONE place
        //    they are read, which My data's counts ask too. Each case: the
        //    organisation, the person's name as typed, category, summary,
        //    status, opened and closed; each visit: its day and kind only.
        //    Never who opened the case, its number, the visiting worker or the
        //    visit notes (D-184 rules those out).
        'careCasesAboutYou' => GdprEraser::careCasesAboutYou($userId),
        // 📦 #1063: EVERY KIND OF RECORD THE ERASURE DELETES WITH YOU IS
        //    HANDED OVER HERE TOO, or is named in the written list
        //    (web/_core/personal-data-catalogue.php, `notInDataCopy`) with
        //    the reason it is not. Measured on 15 November 2026, 26 of the
        //    53 kinds the written list erases were in neither place; section
        //    27 of tools/gdpr-coverage-selftest.php now fails both ways on
        //    that list. Each block below matches on the column the erasure
        //    deletes by, and applies the rule this file has always applied:
        //    what is YOURS is handed over; another person's account number
        //    (who granted, added, marked or entered it), a secret that works
        //    without signing in, and notes a worker wrote (which can name
        //    other people - the reasoning D-184 applied to visit notes) are
        //    left out. Each says which.
        //
        // 🤖 What you asked the AI assistant and what it answered (both cut
        //    to the first 1,000 characters when stored), and what it cost.
        'aiAssistantUse' => $fetchUserRows(
            'SELECT `siteID`, `promptKind`, `provider`, `inputTokens`, `outputTokens`, `costPence`, `inputSample`, `outputSample`, `occurredAt` '
            . 'FROM `tblAiUsage` WHERE `userID` = ? ORDER BY `occurredAt` DESC LIMIT ' . (DATA_COPY_ROWS_PER_KIND + 1)
        ),
        // 🧰 Equipment you are named as an owner, custodian or stakeholder of.
        //    The notes are left out: the asset's administrator writes them.
        'equipmentYouOwn' => $fetchUserRows(
            'SELECT `siteID`, `assetID`, `roleKind`, `sharePercent`, `isLendingAuthority`, `isMaintenanceAuthority` '
            . 'FROM `tblAssetOwners` WHERE `userID` = ?'
        ),
        // 🗓️ Events you were made a coordinator of. Who made you one is left
        //    out (another person's account number).
        'eventsYouCoordinate' => $fetchUserRows(
            'SELECT `c`.`eventID`, `e`.`eventName`, `e`.`startDateTime`, `c`.`permissions`, `c`.`grantedAt`, `c`.`revokedAt` '
            . 'FROM `tblEventCoordinators` AS `c` INNER JOIN `tblEvents` AS `e` ON `e`.`eventID` = `c`.`eventID` '
            . 'WHERE `c`.`userID` = ?'
        ),
        // 👷 The event crews and jobs you are on, and the events you are
        //    listed as one of the people at (a speaker, a host).
        'eventCrewsYouAreOn' => $fetchUserRows(
            'SELECT `crewID`, `role`, `addedAt` FROM `tblEventCrewMembers` WHERE `userID` = ?'
        ),
        'eventJobsYouHave' => $fetchUserRows(
            'SELECT `jobID`, `assignedAt` FROM `tblEventJobAssignments` WHERE `userID` = ?'
        ),
        'eventsYouAreListedAt' => $fetchUserRows(
            'SELECT `p`.`eventID`, `e`.`eventName`, `e`.`startDateTime`, `p`.`role`, `p`.`isPrimary`, `p`.`createdAt` '
            . 'FROM `tblEventPeople` AS `p` INNER JOIN `tblEvents` AS `e` ON `e`.`eventID` = `p`.`eventID` '
            . 'WHERE `p`.`userID` = ?'
        ),
        // ✋ Your answers to events: going, maybe or not going, and any
        //    guests you said you would bring.
        'eventAnswers' => $fetchUserRows(
            'SELECT `r`.`siteID`, `r`.`eventID`, `e`.`eventName`, `e`.`startDateTime`, `r`.`response`, `r`.`guestCount`, `r`.`status`, '
            . '`r`.`waitlistedAt`, `r`.`createdAt`, `r`.`updatedAt` '
            . 'FROM `tblEventRSVPs` AS `r` INNER JOIN `tblEvents` AS `e` ON `e`.`eventID` = `r`.`eventID` '
            . 'WHERE `r`.`userID` = ? ORDER BY `r`.`createdAt` DESC LIMIT ' . (DATA_COPY_ROWS_PER_KIND + 1)
        ),
        // ✉️ The invitation you joined by: the address it was sent to, the
        //    role offered and the welcome written to you. The invitation's
        //    code is left out (a stored fingerprint of a link), and so are
        //    who sent or withdrew it.
        'invitationYouAccepted' => $fetchUserRows(
            'SELECT `siteID`, `email`, `intendedRole`, `welcomeMessage`, `expiresAt`, `acceptedAt`, `createdAt` '
            . 'FROM `tblInvitation` WHERE `acceptedByID` = ?'
        ),
        // 🧭 Leadership roles you hold or held. The notes are left out (an
        //    administrator writes them), and so are who recorded or changed
        //    the role.
        'leadershipRoles' => $fetchUserRows(
            'SELECT `siteID`, `roleID`, `personName`, `personEmail`, `startDate`, `endDate`, `isActive`, `createdAt`, `updatedAt` '
            . 'FROM `tblLeadershipAssignments` WHERE `userID` = ?'
        ),
        // 📰 Each newsletter sent to you: the address, and when it was
        //    delivered, opened and a link in it pressed. The unsubscribe code
        //    is left out: it works without signing in.
        'newslettersSentToYou' => $fetchUserRows(
            'SELECT `newsletterID`, `emailAddress`, `deliveredAt`, `openedAt`, `clickedAt`, `errorMsg` '
            . 'FROM `tblNewsletterRecipient` WHERE `userID` = ? ORDER BY `recipientID` DESC LIMIT ' . (DATA_COPY_ROWS_PER_KIND + 1)
        ),
        // 🚪 If you were offboarded: when, what was chosen for your data, and
        //    when you came back. Left out: who did it; the REASON, which is
        //    free text an administrator types on the offboarding page and can
        //    name other people (an administrator's note, like the notes left
        //    out above); and the list of steps the portal carried out (a
        //    technical record of the portal's own work, which can name other
        //    records).
        'offboarding' => $fetchUserRows(
            'SELECT `effectiveDate`, `dataDisposition`, `offboardedAt`, `rehiredAt` '
            . 'FROM `tblOffboarding` WHERE `userID` = ?'
        ),
        // 🪜 Pathways you are enrolled on, and the steps you have completed.
        //    Who enrolled you, and who marked or took back a step, are left
        //    out; so are a step's notes (whoever marked it wrote them).
        'pathwayEnrolments' => $fetchUserRows(
            'SELECT `siteID`, `pathwayID`, `status`, `enrolledAt`, `completedAt`, `updatedAt` FROM `tblPathwayEnrolments` WHERE `userID` = ?'
        ),
        'pathwayStepsCompleted' => $fetchUserRows(
            'SELECT `siteID`, `stepID`, `source`, `completedAt`, `revokedAt` FROM `tblPathwayProgress` WHERE `userID` = ?'
        ),
        // 💳 The ways to pay you saved: which company and the label you gave
        //    it. The company's own references for your account and card are
        //    left out: with the portal's key they can be used to take a
        //    payment, and your own Payment methods page does not show them.
        'paymentMethods' => $fetchUserRows(
            'SELECT `siteID`, `provider`, `label`, `isDefault`, `createdAt` FROM `tblPaymentMethod` WHERE `userID` = ?'
        ),
        // 🤝 Promises to give: to a campaign, and to a project.
        'pledges' => $fetchUserRows(
            'SELECT `siteID`, `campaignID`, `amountPence`, `currency`, `paymentSchedule`, `status`, `createdAt`, `updatedAt` '
            . 'FROM `tblPledges` WHERE `userID` = ?'
        ),
        'projectPledges' => $fetchUserRows(
            'SELECT `projectID`, `donorName`, `donorEmail`, `amountPence`, `currency`, `pledgedAt`, `fulfilledAt`, `isAnonymous`, `message` '
            . 'FROM `tblProjectPledge` WHERE `donorID` = ?'
        ),
        // 🔔 The devices you allowed to receive notifications: the browser
        //    and what it is sent. The device's address and its two keys are
        //    left out: with them anybody could send notifications to it.
        'notificationDevices' => $fetchUserRows(
            'SELECT `siteID`, `userAgent`, `channels`, `createdAt`, `lastUsedAt`, `isActive`, `failCount`, `lastFailureAt` '
            . 'FROM `tblPushSubscriptions` WHERE `userID` = ?'
        ),
        // 📖 Reading plans you started, and where you are in each.
        'readingPlans' => $fetchUserRows(
            'SELECT `planID`, `startedAt`, `completedAt`, `currentDay` FROM `tblReadingPlanEnrollment` WHERE `userID` = ?'
        ),
        // ▶️ Recordings you played, and when, with the scrambled network
        //    address each play was recorded from (a network address is
        //    personal data, D-178).
        'recordingsPlayed' => $fetchUserRows(
            'SELECT `recordingID`, `playedAt`, `ipHash` FROM `tblRecordingPlay` WHERE `userID` = ? ORDER BY `playedAt` DESC, `playID` DESC LIMIT ' . (DATA_COPY_ROWS_PER_KIND + 1)
        ),
        // 📱 Text messages sent to you: the number, the kind, the words, and
        //    what became of each. The text company's own reference is left out.
        //    TWO KINDS ARE NOT HANDED OVER WORD FOR WORD:
        //    - The code that confirmed your number: its record never held the
        //      code (Sms::VERIFICATION_TEXT_KEPT masks it as the text is
        //      recorded), because a file holding a working code would let
        //      anybody holding it finish a verification in your name - the
        //      same reason the code is left out of your text-message settings.
        //    - A prayer-chain text ('prayer_assignment'): its words are
        //      ANOTHER member's prayer request (its subject), which they asked
        //      about themselves and can have erased on their own (D-016). So
        //      only the day, the kind and what became of it are handed over;
        //      `body` is empty.
        'textMessagesSentToYou' => $fetchUserRows(
            "SELECT `siteID`, `recipientNumber`, CASE WHEN `category` = 'prayer_assignment' THEN NULL ELSE `body` END AS `body`, "
            . '`category`, `status`, `sentAt`, `createdAt` '
            . 'FROM `tblSmsMessage` WHERE `recipientUserID` = ? ORDER BY `createdAt` DESC LIMIT ' . (DATA_COPY_ROWS_PER_KIND + 1)
        ),
        // 🎂 The dates you have added to your profile (a birthday, an
        //    anniversary) and who may see each.
        'milestones' => $fetchUserRows(
            'SELECT `kind`, `label`, `monthDay`, `originYear`, `privacy`, `createdAt` FROM `tblUserMilestone` WHERE `userID` = ?'
        ),
        // 🧭 The guided tours you have finished.
        'toursCompleted' => $fetchUserRows(
            'SELECT `tourID`, `completedAt` FROM `tblUserTours` WHERE `userID` = ?'
        ),
        // 👁️ When you opened a pastoral care file. Only the moment: whose
        //    file it was is somebody else's (the reasoning of D-142).
        'careFilesYouOpened' => $fetchUserRows(
            'SELECT `viewedAt` FROM `tblCareAccessLog` WHERE `viewerID` = ? ORDER BY `viewedAt` DESC LIMIT ' . (DATA_COPY_ROWS_PER_KIND + 1)
        ),
        // 🚶 Your own visitor record, from before you had an account. The
        //    follow-up notes are left out (the people following you up wrote
        //    them, and they can name others - D-184's reasoning), and so are
        //    who entered it and who was following you up.
        'yourVisitorRecord' => $fetchUserRows(
            'SELECT `siteID`, `fullName`, `email`, `phone`, `firstVisitedAt`, `source`, `status`, `createdAt`, `updatedAt` '
            . 'FROM `tblVisitor` WHERE `convertedUserID` = ?'
        ),
        // 🙏 Salvation decision cards (tblSalvationCards) are DELIBERATELY
        // NOT exported here: the public decision-card form has no userID
        // FK at all (fullName/email/phone/address are free-text fields
        // filled by an anonymous submitter, logged-in or not — see
        // salvation/card-save.php), so there is no reliable donorID/userID
        // column to match this export's subject against. Matching on
        // email/name would be a false-positive risk (another person's card
        // sharing the same name/email) that this export must not take.
    ],
];

// ✂️ #1063: a kind cut to its newest DATA_COPY_ROWS_PER_KIND rows says so,
//    in words, rather than looking complete.
foreach (['textMessagesSentToYou', 'aiAssistantUse', 'eventAnswers', 'newslettersSentToYou', 'recordingsPlayed', 'careFilesYouOpened', 'activityLogs'] as $kind) {
    if (count($payload['data'][$kind]) > DATA_COPY_ROWS_PER_KIND) {
        $payload['data'][$kind]   = array_slice($payload['data'][$kind], 0, DATA_COPY_ROWS_PER_KIND);
        $payload['shortened'][$kind] = 'Only the newest ' . number_format(DATA_COPY_ROWS_PER_KIND)
            . ' of these are shown here. Ask the organisation\'s office for the rest.';
    }
}

// 📝 Audit log — the act of exporting one's own data is itself an event
Logger::activity('DataExport', 'User downloaded their data export');

// 📥 Stream as JSON download
$filename = 'webms-intra-data-export-' . $userId . '-' . date('Ymd-His') . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
