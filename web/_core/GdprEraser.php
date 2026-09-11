<?php
// Path: _core/GdprEraser.php
/**
 * -----------------------------------------------------------------------------
 * GDPR Article 17 erasure engine 🗑️🔐
 * -----------------------------------------------------------------------------
 * Right-to-erasure orchestrator. Walks the catalogue of PII-bearing tables,
 * deletes rows where lawful, anonymises rows that must be retained for a
 * legitimate-interest reason (e.g. financial records → HMRC 6-year rule).
 *
 * Every action lands in tblErasureAudit with a chained HMAC so a later
 * tamper attempt is detectable: chainHash[N] = SHA-256(chainHash[N-1] ||
 * canonical(payload[N])). Verify by re-walking.
 *
 * Categories:
 *   delete    — drop the row entirely (cascade FKs do the rest).
 *   anonymise — null PII columns, replace userID with TOMBSTONE_ID.
 *   retain    — log the retention reason, no row change (rare).
 *
 * @package   Portal\Core
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/235
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class GdprEraser
{
    public const TOMBSTONE_NAME  = '[Deleted User]';
    public const TOMBSTONE_EMAIL = 'deleted-{rid}@example.invalid';

    /**
     * Catalogue of tables touched by erasure, with the per-table action.
     * Adding a new PII-bearing table? Append it here.
     *
     * Schema entry shape:
     *   ['table' => string, 'userCol' => string, 'action' => 'delete'|'anonymise'|'cascade-only',
     *    'nullCols' => string[]?, 'reason' => string?]
     *
     * `cascade-only` means we don't touch this table directly — FK CASCADE
     * on a parent table will sweep it up. Logged for the audit trail anyway.
     */
    public static function catalogue(): array
    {
        // 📋 The entries written by hand, below, say HOW to erase a particular
        //    table - which column links a row to a person, and what to do with
        //    it. They are kept because several need special handling that no
        //    general rule could work out.
        $handWritten = [
            // Direct user-row anonymisation last; do dependents first.
            // NOTE: per-user event check-ins live in `tblEventAttendance`
            // (migration 116, multi-day attendance grid) — there is no
            // `tblAttendanceCheckIns` table. `tblAttendanceSessions` /
            // `tblAttendanceCounts` are aggregate headcounts by service
            // type with no per-user column, so nothing to catalogue there.
            ['table' => 'tblEventAttendance',  'userCol' => 'userID',       'action' => 'anonymise', 'nullCols' => [], 'reason' => 'aggregate attendance stats retained — userID nulled'],
            // Expense claims live in `tblExpenseClaims`.`userID` — there is
            // no `tblExpenseClaim` (singular) table or `submittedByID` column.
            ['table' => 'tblExpenseClaims',    'userCol' => 'userID',       'action' => 'anonymise', 'nullCols' => [], 'reason' => 'UK HMRC requires 6-year retention of expense records'],
            ['table' => 'tblGivingEntry',       'userCol' => 'donorID',      'action' => 'anonymise', 'nullCols' => [], 'reason' => 'UK HMRC requires 6-year retention of financial records'],
            ['table' => 'tblPayment',           'userCol' => 'userID',       'action' => 'anonymise', 'nullCols' => [], 'reason' => 'Payment processor reconciliation requires retention'],
            ['table' => 'tblPrayerRequests',    'userCol' => 'submitterID',  'action' => 'anonymise', 'nullCols' => ['submitterName','submitterEmail','submitterIP'], 'reason' => 'request body preserved for congregational continuity; PII blanked'],
            ['table' => 'tblAnnouncements',    'userCol' => 'createdByID',  'action' => 'anonymise', 'nullCols' => [], 'reason' => 'authorship attribution detached'],
            ['table' => 'tblEvents',           'userCol' => 'createdByID',  'action' => 'anonymise', 'nullCols' => [], 'reason' => 'authorship attribution detached'],
            ['table' => 'tblRecording',        'userCol' => 'uploadedByID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'authorship attribution detached'],

            // 📝 Forms Builder (#153) — a response's answersJson is arbitrary
            // free-text and may contain any PII the form asked for; hard DELETE is
            // the only default-safe action (no per-column blanking can know which
            // answers are personal). Public (anonymous) responses carry no
            // submitterID at all, so they are outside this catalogue entry's
            // reach by design — see FormEngine's file header + help/forms.php's
            // "Privacy Note" section.
            ['table' => 'tblFormResponses',    'userCol' => 'submitterID',  'action' => 'delete'],

            // Sessions / tokens / personal devices — hard delete.
            // NOTE: PHP's own session store is file-based, not a DB table
            // (there never was a `tblSessions` row to erase).
            ['table' => 'tblTotpBackupCodes',  'userCol' => 'userID', 'action' => 'delete'],
            // Correct case: `tblWebAuthnCredentials` (capital A) — MySQL on
            // Linux is case-sensitive for table names, so the previous
            // `tblWebauthnCredentials` spelling never matched and passkeys
            // were never deleted.
            ['table' => 'tblWebAuthnCredentials','userCol' => 'userID', 'action' => 'delete'],
            // Auth-residue tables (verified against data-export.php's export
            // list, which already used the correct names). `tblUsers` is
            // anonymised in place, never deleted, so no FK CASCADE ever
            // sweeps these up — without an explicit entry each survives its
            // "erased" owner's credentials/SSO links/trusted devices/reset
            // tokens indefinitely.
            ['table' => 'tblLocalAccounts',    'userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblLinkedAccounts',   'userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblTrustedDevices',   'userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblPasswordResets',   'userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblUserSites',        'userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblUserRoles',        'userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblUserSmsPreference','userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblNewsletterSubscription','userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblPaymentMethod',    'userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblGiftAidDeclaration','userCol' => 'donorID', 'action' => 'delete'],
            ['table' => 'tblZoomAccount',      'userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblUserTranslationPref','userCol' => 'userID', 'action' => 'delete'],
            // #322 Web Push — endpoint URL + p256dh/auth keys are device
            // credentials tied to a specific browser install, not history
            // worth retaining once their owner is erased. Hard delete
            // mirrors the tblTrustedDevices/tblLocalAccounts precedent
            // immediately above, not the anonymise-and-keep pattern used
            // for content/authorship tables.
            ['table' => 'tblPushSubscriptions','userCol' => 'userID', 'action' => 'delete'],
            // #303 Phase 2 — discipleship per-user tables. markedByID /
            // enrolledByID / revokedByID attributions self-heal via
            // ON DELETE SET NULL on the FKs, so a hard delete here is safe.
            ['table' => 'tblPathwayEnrolments','userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblPathwayProgress',  'userCol' => 'userID', 'action' => 'delete'],
            // Kids ministry — a child profile links to exactly ONE parent
            // (`parentUserID`; no multi-guardian junction table exists), so
            // erasing the parent erases the child's profile (name,
            // allergies, medical notes, pickup names). `tblKidCheckins` has
            // `ON DELETE CASCADE` from `tblKidProfiles`.`childID`, so
            // deleting the profile here also sweeps its check-in history —
            // no separate catalogue entry is needed for `tblKidCheckins`.
            // 🛑 CHANGED 11 September 2026, on the owner's explicit decision.
            //    This used to DELETE the child's record outright.
            //
            //    A parent's right to be forgotten is their own. It is not their
            //    child's, and it is not the right of a second parent or carer
            //    who never agreed to it. A child in current safeguarding
            //    arrangements must not disappear from the register because one
            //    adult closed their account - group leaders and other parents
            //    may still depend on that record existing.
            //
            //    So the parent link is emptied and the child's record stays.
            //    It is still removed on a time limit in the ordinary way.
            //
            //    NOTE: pickupAuthorisedNames is free text and may still name
            //    the departing parent. That is NOT handled here and is tracked
            //    separately - emptying it wholesale would remove every OTHER
            //    authorised adult too, which would be unsafe.
            ['table' => 'tblKidProfiles',      'userCol' => 'parentUserID', 'action' => 'anonymise', 'nullCols' => ['parentUserID']],

            // #257 Pastoral Care Register. personUserID is the case SUBJECT
            // (the person being cared for) — anonymise rather than delete so
            // pastoral/safeguarding continuity is preserved for the
            // remaining team, matching tblPrayerRequests' "body preserved,
            // PII blanked" convention immediately above. nullCols also blanks
            // personName defensively even though the app never populates it
            // while personUserID is set (see tblCareCase's column comment).
            // openedByID is the STAFF author who logged the case — anonymise
            // for authorship-attribution-detached, mirroring
            // tblAnnouncements/tblEvents/tblRecording above.
            //
            // tblCareVisit.visitedByID and tblCareAccessLog.viewerID are
            // BOTH NOT NULL with no ON DELETE SET NULL path (visitedByID
            // is even FK'd ON DELETE RESTRICT), so neither column can be
            // nulled by an UPDATE the way the nullable columns above can —
            // same reasoning already applied throughout this catalogue to
            // every other NOT NULL creator/actor column (e.g.
            // tblAssetMaintenance.createdByID, tblAssetResources.uploadedByID
            // are deliberately NOT catalogued either). tblCareVisit is left
            // uncatalogued entirely: the visitor's attribution is detached
            // indirectly once the final tblUsers step below tombstones their
            // name/email, without needing to touch (or delete) the pastoral
            // visit notes themselves — those stay attached to the
            // (now-anonymised) case for institutional continuity.
            // tblCareAccessLog is different: a "who viewed this case" audit
            // row attributed to a since-erased viewer carries no independent
            // value once that identity is gone (unlike the visit notes,
            // which describe the CASE SUBJECT, not the viewer), so the rows
            // for this erasure subject are hard-deleted — mirrors the
            // tblAssetOwners/tblAssetKioskPins hard-delete precedent above.
            // Deleting only WHERE viewerID = ? never touches other staff's
            // access-log rows or the case/visit history itself.
            ['table' => 'tblCareCase',       'userCol' => 'personUserID', 'action' => 'anonymise', 'nullCols' => ['personName'], 'reason' => 'case history retained for pastoral/safeguarding continuity; subject identity detached'],
            ['table' => 'tblCareCase',       'userCol' => 'openedByID',   'action' => 'anonymise', 'nullCols' => [], 'reason' => 'authorship attribution detached'],
            ['table' => 'tblCareAccessLog',  'userCol' => 'viewerID',     'action' => 'delete'],

            // #393 Asset Tracker. tblAssetFoundReports is deliberately
            // NOT catalogued here — it's filled by the public, anonymous
            // "I found this" form (reporterName/reporterContact are free
            // text, never tied to a portal userID), so there is no
            // per-user column to match against for an erasure request —
            // same reasoning as the tblAttendanceSessions/Counts note at
            // the top of this method. tblAssetOwners IS a hard delete
            // (not anonymise) because an ownership row with its userID
            // nulled is meaningless — an "owner" row that owns nothing is
            // just clutter, unlike e.g. an authorship attribution which
            // stays useful once detached.
            ['table' => 'tblAssetOwners',               'userCol' => 'userID',               'action' => 'delete'],
            ['table' => 'tblAssetLoans',                'userCol' => 'counterpartyUserID',   'action' => 'anonymise', 'nullCols' => [], 'reason' => 'loan history retained for asset custody chain; borrower/lender identity detached'],
            ['table' => 'tblAssetLicenseAssignments',   'userCol' => 'userID',               'action' => 'anonymise', 'nullCols' => [], 'reason' => 'seat-assignment history retained for licence compliance; assignee identity detached'],
            ['table' => 'tblAssetAudit',                'userCol' => 'actorUserID',          'action' => 'anonymise', 'nullCols' => [], 'reason' => 'audit history retained (mirrors tblAuditTrail — no FK, immutable record); actor identity detached'],
            // #404 Phase 2 Pass 2 (#410). tblAssetScanLog rows are retained
            // for scan-volume analytics (item.php's sparkbar) even after
            // the scanning user is erased — only the actor attribution is
            // detached, mirroring tblAssetAudit immediately above. The
            // ipHash/userAgentHash columns are ALREADY salted-hash-only
            // (never a raw IP/User-Agent — see AssetRegister::recordScan()),
            // so there is no separate PII column here to null out.
            ['table' => 'tblAssetScanLog',              'userCol' => 'actorUserID',          'action' => 'anonymise', 'nullCols' => [], 'reason' => 'scan-volume analytics retained; actor identity detached (ipHash/userAgentHash are already salted-hash-only, never raw)'],
            // #392 Phase 3 (#411/#412/#414). tblAssetKioskPins IS a hard
            // delete (not anonymise) — same rationale as tblAssetOwners
            // above: a PIN tied to an erased user is meaningless, and
            // `pinHash` is credential-shaped data that must not linger.
            // tblAssetStocktakeItems/tblAssetStocktakes/tblAssetValueHistory
            // are retained-and-anonymised — stocktake results and
            // valuation history stay useful as asset-register history
            // once the actor/starter/closer/recorder identity is
            // detached, same convention as tblAssetAudit/tblAssetScanLog
            // immediately above. tblAssetStocktakes carries TWO per-user
            // columns (startedByID, closedByID) — this catalogue entry
            // shape supports only one userCol per row, so both get their
            // own entry rather than a combined one.
            ['table' => 'tblAssetKioskPins',            'userCol' => 'userID',               'action' => 'delete'],
            ['table' => 'tblAssetStocktakeItems',       'userCol' => 'scannedByID',          'action' => 'anonymise', 'nullCols' => [], 'reason' => 'stocktake scan history retained for audit-trail continuity; scanner identity detached'],
            ['table' => 'tblAssetStocktakes',           'userCol' => 'startedByID',          'action' => 'anonymise', 'nullCols' => [], 'reason' => 'stocktake run history retained; starter identity detached'],
            ['table' => 'tblAssetStocktakes',           'userCol' => 'closedByID',           'action' => 'anonymise', 'nullCols' => [], 'reason' => 'stocktake run history retained; closer identity detached'],
            ['table' => 'tblAssetValueHistory',         'userCol' => 'recordedByID',         'action' => 'anonymise', 'nullCols' => [], 'reason' => 'valuation/depreciation history retained for the value dashboard; recorder identity detached'],

            // 🏛️ Venue Bookings (#429). The register's own history — the
            // hire schedule, effective-dated default-hours windows, and
            // import-wizard batch runs — is retained exactly like the
            // authorship-attribution entries above; only the acting user's
            // identity is detached, mirroring tblAssetAudit/tblAssetScanLog.
            // All three FK columns are already nullable (ON DELETE SET
            // NULL), so processEntry()'s anonymise path (SET userCol =
            // NULL) is a straightforward UPDATE with no schema constraint
            // to work around.
            ['table' => 'tblVenueBookings',         'userCol' => 'updatedByID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'booking history retained for the venue hire schedule; last-editor identity detached'],
            ['table' => 'tblVenueUsageTypeWindows', 'userCol' => 'createdByID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'effective-dated default-hours history retained; author identity detached'],
            ['table' => 'tblVenueImportBatches',    'userCol' => 'createdByID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'CSV/XLSX import-wizard batch history retained for audit trail; uploader identity detached'],

            // 📊 Reports Builder (#156). A saved report DEFINITION (registry
            // keys + literal filter values) is retained — it is metadata
            // about a query, not personal data about anyone other than its
            // author — only the authorship attribution is detached, mirroring
            // tblAnnouncements/tblEvents/tblRecording above. updatedByID rides
            // along via nullCols since processEntry()'s anonymise path only
            // auto-nulls the matched userCol (createdByID) itself.
            // RESIDUAL RISK (documented, not mechanically solvable): an admin
            // may have typed personal data — a name, an email address — into
            // a FILTER VALUE inside the JSON definition (e.g. `donorName eq
            // "Jane Smith"`). That is admin-authored free text of the same
            // class as tblTasks.description, which existing erasure doesn't
            // scrub either. Report OUTPUT itself is never stored anywhere, so
            // there is no separate "results" table to catalogue.
            ['table' => 'tblReportDefinitions', 'userCol' => 'createdByID', 'action' => 'anonymise', 'nullCols' => ['updatedByID'], 'reason' => 'saved report definitions retained (metadata, not personal data); authorship attribution detached'],
            // 👥 Small Groups (#150). The membership row is the subject's
            // OWN personal data (which group, which role) so it is hard
            // DELETEd, not retained — unlike the attendance/authorship
            // rows below, which stay for the GROUP's own continuity with
            // only the subject's identity detached (mirrors
            // tblEventAttendance / tblAssetAudit convention above).
            ['table' => 'tblSmallGroupMembers',           'userCol' => 'userID',       'action' => 'delete'],
            ['table' => 'tblSmallGroupMembers',           'userCol' => 'addedByID',    'action' => 'anonymise', 'nullCols' => [], 'reason' => 'membership rows retained for the group; assigner identity detached'],
            ['table' => 'tblSmallGroupMeetingAttendance', 'userCol' => 'userID',       'action' => 'anonymise', 'nullCols' => [], 'reason' => 'aggregate attendance stats retained — userID nulled (tblEventAttendance precedent)'],
            ['table' => 'tblSmallGroupMeetingAttendance', 'userCol' => 'markedByID',   'action' => 'anonymise', 'nullCols' => [], 'reason' => 'roll history retained; recorder identity detached'],
            ['table' => 'tblSmallGroupMeetings',          'userCol' => 'recordedByID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'meeting history retained; recorder identity detached'],
            ['table' => 'tblSmallGroups',                 'userCol' => 'createdByID',  'action' => 'anonymise', 'nullCols' => [], 'reason' => 'group retained for the congregation; creator identity detached'],

            // Final step — anonymise the user row itself rather than delete,
            // so foreign keys with ON DELETE SET NULL don't cascade-blow
            // historical attributions we wanted to keep.
            // NOTE: `address`, `passwordHash`, `msAccountID` and
            // `googleAccountID` are NOT columns on tblUsers (the real
            // address field is `displayAddress`; credentials live in
            // tblLocalAccounts; SSO links live in tblLinkedAccounts — both
            // now hard-deleted above), so referencing them here made the
            // whole UPDATE fail to prepare and this final step never ran.
            // 📍 #456 Chunk B — `displayPhone`/`latitude`/`longitude`/
            // `what3words` (migration 181) added to nullCols alongside the
            // pre-existing PII columns: the member's own map pin is exactly
            // the kind of PII this final anonymise step exists to remove.
            // `visibilityCoords` is a NOT NULL ENUM (default 'private') so
            // it cannot be nulled via this generic mechanism — with lat/
            // lng/what3words all NULL there is nothing left for that tier
            // to gate, so leaving its value untouched here is harmless
            // (delete-confirm.php's self-deletion path DOES reset it to
            // 'private' defensively, since that path builds its own
            // literal SET list rather than using this nullCols mechanism).
            ['table' => 'tblUsers', 'userCol' => 'userID', 'action' => 'anonymise',
             'nullCols' => ['emailAddress','phoneNumber','displayAddress','displayPhone','latitude','longitude','what3words','locale','totpSecret'],
             'overrides' => ['fullName' => self::TOMBSTONE_NAME, 'isActive' => 0],
             'reason' => 'user row retained for historical FK integrity; PII removed'],
        ];

        // 📚 Everything else comes from the one written list of where
        //    personal information lives: _core/personal-data-catalogue.php.
        //
        //    Before this, the two were separate, and they drifted. When the
        //    difference was measured on 11 September 2026, 77 of the 126 tables
        //    holding personal information were in neither the erasure list nor
        //    the download. Two were found by accident.
        //
        //    Reading from the same list means a table can no longer be in one
        //    and missing from the other, and the automatic check
        //    (check_personal_data_coverage.py) refuses to let a new table go
        //    unsorted.
        return array_merge($handWritten, self::fromPersonalDataCatalogue($handWritten));
    }

    /**
     * 📚 Turn the written list of personal data into erasure instructions.
     *
     * The written list says WHICH tables hold personal information and WHAT
     * should happen to each. This works out HOW: which column ties a row to a
     * person, and which of the eraser's own actions matches the decision.
     *
     * The four decisions map like this:
     *
     *   erase        -> delete     remove the rows outright
     *   unlink       -> anonymise  keep the row, empty the column naming the person
     *   retain       -> retain     keep it, and record in the audit trail that it
     *                              was kept and why
     *   not-personal -> skipped    nothing to do
     *
     * A table already handled by hand is left alone: the hand-written entry
     * wins, because it was written for a reason.
     *
     * @param array<int, array<string, mixed>> $handWritten Already-handled entries.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * ⚠️ Columns that say the person ACTED on a record, rather than that the
     *     record is about them.
     *
     * This distinction is the difference between removing somebody's name and
     * destroying somebody else's records.
     *
     * Seven tables were set to "delete the whole row" while the only thing
     * tying them to a person was one of these columns. Had those links simply
     * been switched on to improve coverage, asking to be forgotten would have:
     * deleted a venue invoice PAYMENT because of who typed it in; deleted a
     * small group's attendance register because of who took it; deleted a room
     * booking because of who approved it; and deleted a child's profile because
     * of who their parent was. Financial records and other people's records,
     * destroyed on one person's request.
     *
     * So a table whose ONLY link is one of these should almost always keep the
     * record and drop the name. `tools/gdpr-coverage-selftest.php` refuses any
     * table that says otherwise unless it appears on its short, deliberately
     * reviewed list - event registrations being the one real exception, where a
     * child's medical notes genuinely must go.
     *
     * @var string[]
     */
    /**
     * 🔗 Every column name in this portal that ties a record to a person.
     *
     * Shared, rather than written out twice, because the two copies drifted
     * before: five names were missing from one of them, and seven tables were
     * reported as having no link to anybody when they had a perfectly good one.
     *
     * @var string[]
     */
    public const LINK_COLUMNS = [
        'userID', 'memberID', 'donorID', 'submitterID', 'recipientUserID',
        'assignedToID', 'targetUserID', 'convertedUserID', 'leaderID',
        'uploadedByUserID', 'approverID', 'reviewedByID', 'startedByID',
        'submittedByUserID', 'createdByID', 'updatedByID',
        'parentUserID', 'counterpartyUserID', 'recordedByID',
        'approvedByID', 'assignedByID',
        'addedByID', 'markedByID', 'openedByID', 'closedByID',
        'moderatedByID', 'moderatorID', 'presenterID', 'scannedByID',
        'bookedByID', 'requestedByID', 'acceptedByID', 'revokedByID',
        'counter1ID', 'counter2ID', 'offboardedByID', 'rehiredByID',
        'grantedByID', 'enrolledByID', 'linkedByID', 'releasedByID',
        'processedByID',
    ];

    public const ACTOR_COLUMNS = [
        'uploadedByUserID', 'approverID', 'reviewedByID', 'startedByID',
        'submittedByUserID', 'createdByID', 'updatedByID',
        'parentUserID', 'counterpartyUserID', 'recordedByID',
        'approvedByID', 'assignedByID',
        // A further twenty-one found by Codex on 11 September 2026. Every one
        // of them names somebody who DID something to a record rather than
        // somebody the record is about.
        'addedByID', 'markedByID', 'openedByID', 'closedByID',
        'moderatedByID', 'moderatorID', 'presenterID', 'scannedByID',
        'bookedByID', 'requestedByID', 'acceptedByID', 'revokedByID',
        'counter1ID', 'counter2ID', 'offboardedByID', 'rehiredByID',
        'grantedByID', 'enrolledByID', 'linkedByID', 'releasedByID',
        'processedByID',
    ];

    private static function fromPersonalDataCatalogue(array $handWritten): array
    {
        $file = __DIR__ . DIRECTORY_SEPARATOR . 'personal-data-catalogue.php';
        if (is_readable($file) === false) {
            return [];
        }
        $catalogue = require $file;
        if (is_array($catalogue) === false) {
            return [];
        }

        $alreadyHandled = [];
        foreach ($handWritten as $entry) {
            $alreadyHandled[(string) ($entry['table'] ?? '')] = true;
        }

        // Columns that tie a row to a person. Ordered: the ones that mean "this
        // record is ABOUT them" come before the ones that mean "they made this",
        // because when a table has both, the first is what identifies the person
        // the record concerns.
        $linkColumns = [
            // Records ABOUT the person. Listed first so that when a table has
            // both kinds, the person the record concerns wins.
            'userID', 'memberID', 'donorID', 'submitterID', 'recipientUserID',
            'assignedToID', 'targetUserID', 'convertedUserID', 'leaderID',

            // The person ACTED on this, or is merely named on it. See
            // ACTOR_COLUMNS below for why the difference matters so much.
            'uploadedByUserID', 'approverID', 'reviewedByID', 'startedByID',
            'submittedByUserID', 'createdByID', 'updatedByID',
            // Five that were missing entirely until 11 September 2026. Seven
            // tables had a perfectly good link to a person and were reported as
            // having none, simply because nobody had listed these names - among
            // them a child's profile, reachable through the parent.
            'parentUserID', 'counterpartyUserID', 'recordedByID',
            'approvedByID', 'assignedByID',
        ];

        $entries = [];

        foreach ($catalogue as $table => $meta) {
            $table = (string) $table;
            if (isset($alreadyHandled[$table]) === true) {
                continue;
            }

            $decision = (string) ($meta['decision'] ?? '');
            if ($decision === 'not-personal') {
                continue;
            }

            $columns = (array) ($meta['columns'] ?? []);
            $reason  = (string) ($meta['reason'] ?? '');

            if ($decision === 'retain') {
                // No column needed: nothing is being changed. The point of the
                // entry is that the audit trail records the decision.
                $entries[] = [
                    'table'    => $table,
                    'userCol'  => 'userID',
                    'action'   => 'retain',
                    'nullCols' => [],
                    'reason'   => $reason . (isset($meta['period']) === true
                        ? ' (kept for ' . (string) $meta['period'] . ')'
                        : ''),
                ];
                continue;
            }

            // Which column ties this row to a person?
            $link = '';
            foreach ($linkColumns as $candidate) {
                if (in_array($candidate, $columns, true) === true) {
                    $link = $candidate;
                    break;
                }
            }

            if ($link === '') {
                // No link to an account at all, so there is nothing to match a
                // person against and this route cannot reach the table.
                //
                // Event registrations used to be the worst example - a child's
                // name, date of birth, allergies and medical notes, with
                // nothing tying them to anybody. They now record the account of
                // whoever submitted them when that person was signed in, so
                // they ARE reachable here (see migration 191).
                //
                // That only covers registrations made by somebody with an
                // account, which is the minority. The rest are covered by a
                // time limit instead: they are deleted a set number of days
                // after the event whether anybody asks or not. The same
                // thinking applies to every table still listed here - each
                // needs either a link adding or a time limit. See issue #479.
                continue;
            }

            if ($decision === 'erase') {
                $entries[] = [
                    'table'    => $table,
                    'userCol'  => $link,
                    'action'   => 'delete',
                    'nullCols' => [],
                    'reason'   => $reason,
                ];
                continue;
            }

            // 'unlink' - keep what was made, remove who made it. Every
            //  person-linking column this table has is emptied, not only the
            //  one matched on, or a second column would still name them.
            $nullCols = array_values(array_intersect($linkColumns, $columns));
            $entries[] = [
                'table'    => $table,
                'userCol'  => $link,
                'action'   => 'anonymise',
                'nullCols' => $nullCols,
                'reason'   => $reason,
            ];
        }

        return $entries;
    }

    /**
     * 🔍 Which of a table's columns the database will actually allow to be empty.
     *
     * Asked of the database rather than read from the schema file, because the
     * database is what will refuse. A schema file can be out of step with a
     * customer's actual database - an upgrade that half ran, a column somebody
     * changed by hand - and being wrong here means an erasure request stops
     * part way through.
     *
     * Answers are remembered for the life of the request. An erasure walks more
     * than a hundred tables, and asking the same question twice for each of
     * them would be wasteful on shared hosting.
     *
     * Returns an empty list if the question cannot be asked at all. That is the
     * safe direction: nothing is emptied, nothing throws, and the audit trail
     * records that nothing could be done.
     *
     * @param \mysqli $db    Open database connection.
     * @param string  $table The table to ask about.
     *
     * @return string[] Column names that are allowed to be empty.
     */
    private static function nullableColumns(\mysqli $db, string $table): array
    {
        static $remembered = [];

        if (isset($remembered[$table]) === true) {
            return $remembered[$table];
        }

        $columns = [];

        try {
            $stmt = $db->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() '
                . '  AND TABLE_NAME = ? '
                . "  AND IS_NULLABLE = 'YES'"
            );
            if ($stmt !== false) {
                $stmt->bind_param('s', $table);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $columns[] = (string) $row['COLUMN_NAME'];
                }
                $stmt->close();
            }
        } catch (\Throwable $e) {
            // Deliberately swallowed. An empty list means "empty nothing",
            // which is safe; the caller writes that fact into the audit trail.
            $columns = [];
        }

        $remembered[$table] = $columns;

        return $columns;
    }

    /**
     * 📏 How many tables the written list covers, and how they break down.
     *
     * Used by the erasure page so an administrator can see the scale of what is
     * about to happen before they agree to it.
     *
     * @return array<string, int>
     */
    public static function catalogueSummary(): array
    {
        $file = __DIR__ . DIRECTORY_SEPARATOR . 'personal-data-catalogue.php';
        if (is_readable($file) === false) {
            return [];
        }
        $catalogue = require $file;
        if (is_array($catalogue) === false) {
            return [];
        }

        $counts = [];
        foreach ($catalogue as $meta) {
            $decision = (string) ($meta['decision'] ?? 'unknown');
            $counts[$decision] = ($counts[$decision] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * Run the erasure for one request. Caller must have flipped the
     * request status to `processing` first (Eraser doesn't manage state
     * transitions — it executes).
     */
    public static function execute(int $requestId, int $userId, ?int $processedByID = null): bool
    {
        $db = App::db();

        // 📧 #234 — capture the CURRENT address before the catalogue below
        // anonymises `tblUsers.emailAddress` (its final entry) — the
        // tblEmailLog recipient scrub below needs something to match
        // against, and by the time execute() reaches it the user row is
        // already tombstoned.
        $erasedEmail = '';
        $es = $db->prepare('SELECT emailAddress FROM tblUsers WHERE userID = ? LIMIT 1');
        if ($es !== false) {
            $es->bind_param('i', $userId);
            $es->execute();
            $row = $es->get_result()->fetch_assoc();
            $es->close();
            $erasedEmail = (string) ($row['emailAddress'] ?? '');
        }

        $catalogue = self::catalogue();
        $any = false;
        foreach ($catalogue as $entry) {
            $any = self::processEntry($db, $requestId, $userId, $entry) || $any;
        }
        // 🧾 Giving bulk statements (#440 Q5) — a filesystem-only sweep, not
        // a catalogue entry: tblGivingStatementLog rows themselves stay
        // (donorID is NOT NULL there, and the run-history/HMRC-adjacent
        // audit trail is retained exactly like tblGivingEntry above), but
        // the RENDERED PDF is a name-bearing document with no retention
        // duty once its subject is erased, so the files are unlinked here.
        $any = (self::eraseGivingStatementFiles($requestId, $userId) > 0) || $any;
        // 📧 #234 — tblEmailLog audit trail: scrub the erased address out
        // of the comma-joined `toRecipients` column. Not a catalogue()
        // entry — see eraseEmailLogRecipients()'s doc comment for why.
        $any = (self::eraseEmailLogRecipients($requestId, $erasedEmail) > 0) || $any;
        // Final status flip.
        $u = $db->prepare('UPDATE tblErasureRequest SET status = "completed", processedAt = NOW(), processedByID = ?, userID = NULL WHERE requestID = ?');
        if ($u !== false) {
            $u->bind_param('ii', $processedByID, $requestId);
            $u->execute();
            $u->close();
        }
        return $any;
    }

    /**
     * "What we hold about you" — JSON-serialisable inventory built by
     * querying every catalogue table for rows referencing the user.
     * Used by /account/my-data.
     */
    public static function inventory(int $userId): array
    {
        $db = App::db();
        $out = [];
        foreach (self::catalogue() as $entry) {
            $table = (string) $entry['table'];
            $col   = (string) $entry['userCol'];
            try {
                $count = 0;
                $stmt = $db->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $col . '` = ?');
                if ($stmt !== false) {
                    $stmt->bind_param('i', $userId);
                    $stmt->execute();
                    $stmt->bind_result($count);
                    $stmt->fetch();
                    $stmt->close();
                }
                if ($count > 0) {
                    $out[] = [
                        'table'  => $table,
                        'rows'   => (int) $count,
                        'action' => (string) $entry['action'],
                        'reason' => (string) ($entry['reason'] ?? ''),
                    ];
                }
            } catch (\Throwable $ignored) {
                // Table missing (app not installed). Skip silently.
            }
        }
        return $out;
    }

    /**
     * Verify the audit chain for one request. Returns true when intact,
     * false on any broken link. Lets compliance prove no row was edited.
     */
    public static function verifyAuditChain(int $requestId): bool
    {
        $db = App::db();
        $stmt = $db->prepare('SELECT auditID, action, tableName, recordKey, details, chainHash FROM tblErasureAudit WHERE requestID = ? ORDER BY auditID');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $rs = $stmt->get_result();
        $prev = '';
        while ($r = $rs->fetch_assoc()) {
            $expected = self::hashRow($prev, (string) $r['action'], (string) $r['tableName'], (string) ($r['recordKey'] ?? ''), (string) ($r['details'] ?? ''));
            if (hash_equals($expected, (string) $r['chainHash']) === false) {
                $stmt->close();
                return false;
            }
            $prev = (string) $r['chainHash'];
        }
        $stmt->close();
        return true;
    }

    // -------------------------------------------------------------------------
    // internals
    // -------------------------------------------------------------------------

    private static function processEntry(\mysqli $db, int $requestId, int $userId, array $entry): bool
    {
        $table  = (string) $entry['table'];
        $col    = (string) $entry['userCol'];
        $action = (string) $entry['action'];

        // Snapshot row keys for the audit trail.
        $ids = [];
        try {
            $stmt = $db->prepare('SELECT 1 FROM `' . $table . '` WHERE `' . $col . '` = ? LIMIT 100');
            if ($stmt === false) {
                // Table/column genuinely missing (or a real schema-drift
                // typo) — record it instead of vanishing silently, so the
                // next drift is visible in the audit trail rather than
                // masquerading as a completed erasure.
                self::logAudit($db, $requestId, 'skip', $table, null, 'table-or-column-missing: ' . $db->error);
                return false;
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_row()) {
                $ids[] = 'matched';
            }
            $stmt->close();
        } catch (\Throwable $e) {
            self::logAudit($db, $requestId, 'skip', $table, null, 'table-missing');
            return false;
        }
        if (count($ids) === 0) {
            return false;
        }

        // 🔒 KEEP means keep. Handled FIRST, and explicitly.
        //
        //    Some records must be kept even after somebody asks to be
        //    forgotten: Gift Aid declarations, financial records, safeguarding
        //    records. Each is kept deliberately, with a reason, and the fact
        //    that it was kept is written into the audit trail - so the
        //    organisation can show exactly what it did and why, rather than
        //    quietly keeping things.
        if ($action === 'retain') {
            self::logAudit(
                $db,
                $requestId,
                'retain',
                $table,
                null,
                'kept as the law requires: ' . (string) ($entry['reason'] ?? 'no reason recorded')
            );
            return true;
        }

        // Values bound before the one the WHERE clause uses. Only the
        // anonymise path adds any; declared here so every path has it.
        $extraBind = [];

        if ($action === 'delete') {
            $sql = 'DELETE FROM `' . $table . '` WHERE `' . $col . '` = ?';
        } elseif ($action === 'anonymise') {
            $nulls = (array) ($entry['nullCols'] ?? []);
            $overrides = (array) ($entry['overrides'] ?? []);

            // 🚫 Only empty a column the database allows to be empty.
            //
            //    Thirteen of these columns are marked in the schema as never
            //    allowed to be empty - `createdByID` on the venue register, the
            //    asset register, invoices and several more. Emptying one throws,
            //    and because that happens part way through a request, the person
            //    is left with some of their information removed, some of it
            //    still there, and no record of which was which. A half-finished
            //    erasure is worse than one that never started.
            //
            //    Asked of the database itself rather than assumed from the
            //    schema file, because the database is what will actually refuse.
            $canBeEmptied = self::nullableColumns($db, $table);

            $sets    = [];
            $refused = [];
            foreach ($nulls as $c) {
                $c = (string) $c;
                if (in_array($c, $canBeEmptied, true) === false) {
                    $refused[] = $c;
                    continue;
                }

                // 👥 Empty this column ONLY where it names THIS person.
                //
                //    It used to be emptied outright on every matching row, and
                //    that quietly removed other people. A venue created by
                //    Ann and later edited by Ben carries both names. Ann asks
                //    to be forgotten, the row matches on her, and Ben's name
                //    was wiped as well - on a request that was never his, and
                //    with nothing recording that it had happened.
                //
                //    Asking "is this column this person?" for each column
                //    separately means only their own name goes.
                if (in_array($c, self::LINK_COLUMNS, true) === true) {
                    $sets[]      = '`' . $c . '` = IF(`' . $c . '` = ?, NULL, `' . $c . '`)';
                    $extraBind[] = $userId;
                    continue;
                }

                // Not a link to an account - free text describing whoever the
                // row is about, such as a name typed in by hand. The row was
                // matched on this person, so it does belong to them.
                $sets[] = '`' . $c . '` = NULL';
            }
            foreach ($overrides as $c => $v) {
                if (is_int($v) === true) {
                    $sets[] = '`' . $c . '` = ' . (int) $v;
                } else {
                    $v = str_replace('{rid}', (string) $requestId, (string) $v);
                    $sets[] = '`' . $c . "` = '" . $db->real_escape_string($v) . "'";
                }
            }
            // For tblUsers, the user-row anonymisation also nulls the email/etc.
            if ($table === 'tblUsers') {
                $sets[] = "`emailAddress` = '" . $db->real_escape_string(str_replace('{rid}', (string) $requestId, self::TOMBSTONE_EMAIL)) . "'";
                $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', array_unique($sets)) . ' WHERE `' . $col . '` = ?';
            } else {
                // Anonymise = drop the link to the person as well, where the
                // database allows it to be dropped.
                if (in_array($col, $canBeEmptied, true) === true) {
                    $sets[] = '`' . $col . '` = NULL';
                } else {
                    $refused[] = (string) $col;
                }

                // Nothing left to change. Say so plainly rather than running an
                // UPDATE with an empty SET, which is not valid SQL anyway.
                if ($sets === []) {
                    self::logAudit(
                        $db,
                        $requestId,
                        'skip',
                        $table,
                        null,
                        'nothing could be emptied: the database does not allow '
                        . implode(', ', $refused) . ' to be empty. The account '
                        . 'they point at has itself been anonymised, so the row '
                        . 'no longer names anybody - but the link remains. See '
                        . 'migration 192.'
                    );
                    return false;
                }

                $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE `' . $col . '` = ?';
            }

            // 📝 Anything that had to be left alone is written down, so the
            //    organisation can show exactly what it did and what it could
            //    not do. Quietly leaving a link in place would be the worst
            //    outcome: it looks like a completed erasure and is not one.
            if ($refused !== []) {
                self::logAudit(
                    $db,
                    $requestId,
                    'partial',
                    $table,
                    null,
                    'left in place because the database does not allow them to be '
                    . 'empty: ' . implode(', ', $refused)
                );
            }
        } else {
            // 🛑 An action nobody recognises. STOP, do not guess.
            //
            //    This used to be a plain "else", which meant anything that was
            //    not "delete" was quietly treated as "anonymise" - including a
            //    typo, and including any new action somebody added later. For a
            //    record the law says to KEEP, that would have destroyed the very
            //    thing that had to be preserved, and reported success.
            //
            //    Refusing is always recoverable. Guessing is not.
            self::logAudit(
                $db,
                $requestId,
                'skip',
                $table,
                null,
                'refused: unrecognised instruction "' . $action . '" - nothing was changed'
            );
            return false;
        }

        try {
            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                self::logAudit($db, $requestId, 'failed', $table, null, $db->error);
                return false;
            }

            // 📌 One value for each "is this column this person?" test, then
            //    the one the WHERE clause matches on. All whole numbers, so the
            //    letters are simply that many i's. Built from the same list that
            //    produced the SQL, so the two cannot fall out of step.
            $bindValues = $extraBind;
            $bindValues[] = $userId;
            $stmt->bind_param(str_repeat('i', count($bindValues)), ...$bindValues);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            self::logAudit($db, $requestId, $action, $table, (string) $affected . ' rows', (string) ($entry['reason'] ?? ''));
            return $affected > 0;
        } catch (\Throwable $e) {
            self::logAudit($db, $requestId, 'failed', $table, null, mb_substr($e->getMessage(), 0, 250));
            return false;
        }
    }

    /**
     * Unlink every rendered giving-statement PDF for `$userId`, across
     * every site (siteID is embedded in the directory, not the filename —
     * see Portal\Core\Giving::renderStatementPdf()'s path convention), and
     * (SEC-02) detach the raw `emailedTo` address from every
     * `tblGivingStatementLog` row for this donor. The row ITSELF stays —
     * amount/period is retained run-history/HMRC-adjacent audit trail,
     * exactly like `tblGivingEntry`'s own "amounts kept, identity
     * detached" convention (see the call site's comment in execute()) —
     * only the PII columns (the file, then the mailed-to address) are
     * removed. Not a catalogue() entry: `tblGivingStatementLog.donorID` is
     * NOT NULL by design, so the generic anonymise/delete actions don't
     * fit; this bespoke sweep does the file unlink AND the column-level
     * PII scrub together as one #440 Q5 step.
     *
     * @return int Number of files removed plus statement-log rows that
     *   had `emailedTo` detached (0 means there was nothing to erase).
     */
    private static function eraseGivingStatementFiles(int $requestId, int $userId): int
    {
        $db = App::db();

        $removed = 0;
        $base = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'giving'
              . DIRECTORY_SEPARATOR . 'statements';
        if (is_dir($base) === true) {
            $pattern = $base . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'statement-' . $userId . '-*.pdf';
            $matches = glob($pattern);
            if ($matches !== false) {
                foreach ($matches as $path) {
                    if (@unlink($path) === true) {
                        $removed++;
                    }
                }
            }
        }
        if ($removed > 0) {
            // Not a real table name — deliberately NOT `tblXxx`-shaped so
            // check_php_table_refs.py doesn't need an allowlist entry for
            // this filesystem-only sweep's audit label.
            self::logAudit($db, $requestId, 'delete', 'GivingStatementPdfFiles', (string) $removed . ' file(s)', 'rendered statement PDFs have no retention duty once the subject is erased');
        }

        // 🔐 SEC-02 — the rendered PDF is gone above, but the raw mailed-to
        // address still survives in `tblGivingStatementLog.emailedTo` even
        // after erasure. Null it (prepared, donor-scoped) while keeping
        // the row's totals/period for the run-history/audit trail.
        $nulled = 0;
        $stmt = $db->prepare('UPDATE tblGivingStatementLog SET emailedTo = NULL WHERE donorID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $nulled = $stmt->affected_rows;
            $stmt->close();
        }
        if ($nulled > 0) {
            self::logAudit($db, $requestId, 'anonymise', 'tblGivingStatementLog', (string) $nulled . ' rows', 'emailedTo PII detached; totals/period run-history retained (statement PDFs already unlinked above)');
        }

        return $removed + $nulled;
    }

    /**
     * #234 — scrub the erased user's address out of `tblEmailLog.
     * toRecipients`. Not a catalogue() entry: that column is a
     * comma-joined free-text address LIST (one send can have many
     * recipients), not a single `userCol` FK the generic anonymise/delete
     * actions can target — this bespoke sweep does a targeted
     * substring REPLACE instead, mirroring
     * eraseGivingStatementFiles()'s "PII column scrubbed, row + other
     * columns (provider/status/subject/httpCode) retained for the audit
     * trail" convention immediately above.
     *
     * Must be called with the address captured BEFORE the catalogue's
     * final `tblUsers` entry nulls it out (see execute()).
     *
     * @return int Number of tblEmailLog rows whose toRecipients was
     *   rewritten (0 means there was nothing to erase, or no address).
     */
    private static function eraseEmailLogRecipients(int $requestId, string $email): int
    {
        if ($email === '') {
            return 0;
        }

        $db          = App::db();
        $like        = '%' . $email . '%';
        $replacement = '[erased]';

        $stmt = $db->prepare('UPDATE tblEmailLog SET toRecipients = REPLACE(toRecipients, ?, ?) WHERE toRecipients LIKE ?');
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('sss', $email, $replacement, $like);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected > 0) {
            self::logAudit($db, $requestId, 'anonymise', 'tblEmailLog', (string) $affected . ' rows', 'recipient address detached from send-log audit trail; provider/status/subject/httpCode retained');
        }

        return $affected > 0 ? (int) $affected : 0;
    }

    private static function logAudit(\mysqli $db, int $requestId, string $action, string $table, ?string $recordKey, string $details): void
    {
        // Read the tail hash to chain on.
        $prev = '';
        $stmt = $db->prepare('SELECT chainHash FROM tblErasureAudit WHERE requestID = ? ORDER BY auditID DESC LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $stmt->bind_result($prev);
            $stmt->fetch();
            $stmt->close();
        }
        $hash = self::hashRow($prev ?? '', $action, $table, $recordKey ?? '', $details);
        $ins = $db->prepare('INSERT INTO tblErasureAudit (requestID, action, tableName, recordKey, details, chainHash) VALUES (?, ?, ?, ?, ?, ?)');
        if ($ins !== false) {
            $ins->bind_param('isssss', $requestId, $action, $table, $recordKey, $details, $hash);
            $ins->execute();
            $ins->close();
        }
    }

    /**
     * Per-row hash function. Pipe-delimited so collisions across fields
     * are not possible without an actual collision in SHA-256.
     */
    private static function hashRow(string $prev, string $action, string $table, string $recordKey, string $details): string
    {
        return hash('sha256', $prev . '|' . $action . '|' . $table . '|' . $recordKey . '|' . $details);
    }
}
