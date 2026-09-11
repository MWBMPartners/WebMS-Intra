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
 *   tblEventRegistrations (children you registered for an event while signed
 *                          in — name, date of birth, allergies, medical
 *                          notes, parent contact details. Registrations made
 *                          without an account record nobody to match against,
 *                          and are removed on a time limit instead — #479)
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
use Portal\Core\Logger;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$user   = App::user();
$userId = (int) ($user['userID'] ?? 0);
if ($userId <= 0) {
    header('Location: /account', true, 302);
    exit();
}

/**
 * 🛠️ Helper — run a SELECT * style query bound to the current user and
 * return rows with sensitive columns removed.
 *
 * @param string $sql           Prepared SQL with one '?' (the user ID)
 * @param string[] $stripFields Column names to drop from each row
 */
$fetchUserRows = static function (string $sql, array $stripFields = []) use ($mysqli, $userId): array {
    $stmt = $mysqli->prepare($sql);
    if ($stmt === false) {
        return [];
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
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
        'activityLogs' => $fetchUserRows(
            'SELECT logID, activityType, activityDescription, visitorIP, timestamp FROM tblActivityLogs WHERE userID = ? ORDER BY timestamp DESC LIMIT 5000'
        ),
        'expenseClaims' => $fetchUserRows(
            // tblExpenseClaims uses `userID` not `submittedByID`
            'SELECT * FROM tblExpenseClaims WHERE userID = ?'
        ),
        'prayerRequests' => $fetchUserRows(
            'SELECT * FROM tblPrayerRequests WHERE submitterID = ?'
        ),
        'consentLog' => $fetchUserRows(
            'SELECT consentID, consentType, decision, policyHash, ipAddress, createdAt FROM tblConsentLog WHERE userID = ? ORDER BY createdAt DESC'
        ),
        'trustedDevices' => $fetchUserRows(
            'SELECT deviceID, label, createdIP, lastSeenAt, expiresAt, revokedAt, createdAt FROM tblTrustedDevices WHERE userID = ?'
        ),
        // 🏛️ Venue Bookings (#429) — export↔erasure parity with the three
        // GdprEraser::catalogue() entries added alongside this block.
        'venueBookingsEdited' => $fetchUserRows(
            // tblVenueBookings.updatedByID — bookings this user last edited
            'SELECT bookingID, siteID, venueID, roomID, bookingDate, startTime, endTime, statusID, notes, updatedAt '
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
        // for the declaring donor. GdprEraser::catalogue() already
        // hard-DELETEs this table on erasure (donorID match) — this export
        // block is the missing access/portability half of that pair.
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
        // Only registrations submitted while signed in appear here, because
        // only those record whose account made them. Anybody may register a
        // child WITHOUT an account, and those carry nothing to match a person
        // against - the same reasoning as the public form responses above.
        // Those are covered by a time limit instead: they are deleted a set
        // number of days after the event, whether anybody asks or not. See
        // migration 191 and the clear-out on the Retention page.
        //
        // This is the matching half of the erasure entry: the portal must be
        // able to both HAND OVER and REMOVE the same information, and until
        // now it could do neither for this table.
        'eventRegistrations' => $fetchUserRows(
            'SELECT r.registrationID, r.eventID, e.eventName, e.startDateTime, '
            . 'r.fullName, r.dateOfBirth, r.grade, r.gender, r.shirtSize, '
            . 'r.allergies, r.medicalNotes, r.parentName, r.parentPhone, '
            . 'r.parentEmail, r.photoConsent, r.emergencyContactName, '
            . 'r.emergencyContactPhone, r.status, r.source, r.createdAt '
            . 'FROM tblEventRegistrations AS r '
            . 'INNER JOIN tblEvents AS e ON e.eventID = r.eventID '
            . 'WHERE r.submittedByUserID = ?'
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

// 📝 Audit log — the act of exporting one's own data is itself an event
Logger::activity('DataExport', 'User downloaded their data export');

// 📥 Stream as JSON download
$filename = 'webms-intra-data-export-' . $userId . '-' . date('Ymd-His') . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
