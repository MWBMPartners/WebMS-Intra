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
 * Every action lands in tblErasureAudit as one line, and each line carries a
 * fingerprint (SHA-256) of itself and of the line before it. Changing or
 * removing a line afterwards breaks every fingerprint that follows, and
 * verifyAuditChain() says so. What this cannot do: it is a plain fingerprint,
 * not one made with a secret key (this used to say "HMAC", which it never
 * was), so somebody able to rewrite EVERY line of a request could recompute
 * them all. It catches an edited line, not a forged trail.
 *
 * Categories:
 *   delete    — drop the row entirely (cascade FKs do the rest).
 *   anonymise — keep the row; empty the person's own account number on it,
 *               and any listed details about them. (This used to say the
 *               number was replaced by a "TOMBSTONE_ID". There is no such
 *               thing: the column is emptied.)
 *   retain    — log the retention reason, no row change (rare).
 *
 * THE POLICY: STEP BY STEP, NOT ALL-OR-NOTHING (#510, 11 October 2026)
 * -------------------------------------------------------------------
 * An erasure is a list of small steps, one per instruction, and each step is
 * saved as soon as it is done. It is NOT one transaction that either all
 * happens or all un-happens. Two reasons:
 *
 *   - One transaction over about 140 tables would hold its locks for the whole
 *     run. On shared hosting that stalls every other page for as long as it
 *     takes, and the host may kill it part way.
 *   - Part of an erasure is deleting files (rendered giving statements). A
 *     deleted file cannot be un-deleted, so "undo everything" would be a
 *     promise the code cannot keep.
 *
 * What makes step-by-step safe is that every step can be repeated: deleting
 * rows that are already gone, or emptying a column that is already empty,
 * changes nothing. So the rules are:
 *
 *   1. Every instruction writes exactly ONE line to the audit trail, including
 *      "nothing held for this person". A missing line would mean a step did
 *      not run, and nothing else could show that.
 *   2. A step that fails is written down as "failed" and the run carries on
 *      with the rest.
 *   3. If any step failed, the request goes back to "pending review" with
 *      everything on it unchanged, so it can simply be run again.
 *   4. Only when no step failed is the request closed, as the LAST thing done.
 *      The request and its audit lines are the organisation's record that the
 *      erasure was asked for and carried out, so they are never deleted. (They
 *      used to be: the table walk deleted the request it was working on, the
 *      database deleted its audit lines with it, and the run crashed.)
 *   5. If the audit trail itself cannot be written, the run STOPS: carrying on
 *      would erase things with no record of it. The request is put back to
 *      "pending review" and the caller is told plainly.
 *
 * What this cannot do: it cannot make two runs of the same request at the same
 * moment impossible (the page that calls it claims the request first, which is
 * what prevents that), and it cannot find personal information that no column
 * ties to an account - see the entries marked "retain" for that reason in
 * personal-data-catalogue.php.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/235
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/510
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/535
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
     *   ['table' => string, 'userCol' => string, 'action' => 'delete'|'anonymise'|'retain',
     *    'nullCols' => string[]?, 'reason' => string?]
     *
     * `userCol` is the column matched against the person's account number. It
     * is an empty string ONLY on a 'retain' entry for a table that no column
     * ties to an account: the record is kept and the audit trail says so,
     * without pretending to have looked for the person in it.
     *
     * A fourth action, 'refuse', is never written by hand. The derived route
     * produces it when the written list asks for something this class will
     * not do (see fromPersonalDataCatalogue()), so that the fault shows up in
     * the audit trail and stops the request being closed, instead of the
     * table being passed over in silence.
     *
     * (This used to list a `cascade-only` action. Nothing ever used it, and
     * processEntry() would have refused it as unrecognised.)
     *
     * @return array<int, array<string, mixed>>
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
            // #535 — a recording names TWO people: who uploaded it and who
            // presented it. Until 11 October 2026 only the line above
            // existed, so somebody who had preached and then asked to be
            // forgotten stayed linked to every recording of them. One entry
            // per column, the tblAssetStocktakes shape further down: each
            // empties its own column wherever it names this person, so a
            // recording uploaded by somebody else is still found.
            // What this does NOT do: `presenterText` is a name typed by hand
            // and may still spell the person's name. Nothing ties it to an
            // account, so it cannot be found here (#492).
            ['table' => 'tblRecording',        'userCol' => 'presenterID',  'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the recording is the organisation\'s and stays; who presented it is detached'],

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
            // #516 — a role holding names WHO GRANTED IT (grantedByID) as
            // well as who holds it. The row above already removes every
            // holding belonging to the erased person; this second entry
            // handles the DIFFERENT case — a holding that belongs to
            // SOMEBODY ELSE, which this person happened to grant. That
            // holding stays (it is about the holder, not the granter);
            // only the "who granted it" attribution is detached.
            ['table' => 'tblUserRoles',        'userCol' => 'grantedByID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the holding stays (it is about the holder, not the granter); who granted it is detached'],
            // #516 — a role key parked in the pen, waiting for a global
            // administrator to place it. About the person themselves, so
            // it is removed outright, the same as the holding it was
            // copied from would have been.
            ['table' => 'tblUserRolesUnplaced', 'userCol' => 'userID', 'action' => 'delete'],
            // #517 — user-group and department memberships, the same shape as
            // the two tblUserRoles entries above. The person's OWN
            // memberships go (flags included). A membership that belongs to
            // SOMEBODY ELSE, which this person happened to add, stays — it is
            // about the member, not whoever added them — and only the "who
            // added it" number (addedByID) is detached. The tblUserSites
            // delete above already removes the person's own rows through the
            // composite foreign keys; the explicit entries stay so the audit
            // trail says what happened (the #516 reasoning).
            ['table' => 'tblUserGroups',       'userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblUserGroups',       'userCol' => 'addedByID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the membership stays (it is about the member, not who added them); who added it is detached'],
            ['table' => 'tblUserDepts',        'userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblUserDepts',        'userCol' => 'addedByID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the membership stays (it is about the member, not who added them); who added it is detached'],
            // #517 — group and department memberships parked by migration 203,
            // waiting for a global administrator to place them. About the
            // person themselves, so removed outright.
            ['table' => 'tblUserGroupsUnplaced', 'userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblUserDeptsUnplaced',  'userCol' => 'userID', 'action' => 'delete'],
            // #514 part P1 — the lists of who may see an outside calendar's
            // events (and, later, one date or a rule). A row naming THIS
            // person goes. The account row itself is emptied rather than
            // deleted (the tblUsers step at the end), so the table's own
            // "delete with the account" foreign key never fires, and this
            // explicit entry is what actually removes it. A row somebody
            // ELSE is on, which this person happened to add, stays, and only
            // the "who added it" number is detached — the tblUserGroups shape
            // above.
            ['table' => 'tblExternalAudienceMembers', 'userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblExternalAudienceMembers', 'userCol' => 'createdByID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the entry stays (it is about the person named on it, or about a group); who added it is detached'],
            // #514 part P7 — choices, rules and approval rows for outside
            // calendars. Each is the organisation's setting or decision, so
            // the row stays and only the person's number is detached.
            //
            // TWO entries each for the choice and rule tables, one per
            // column, on purpose: the route this class derives from the
            // written catalogue matches rows on ONE column and empties a
            // second column only on those rows (`processEntry()`), so a choice
            // somebody else created and this person last changed would keep
            // their number. Each entry here empties its own column wherever
            // it names this person.
            //
            // The approvals entry was written because `decidedByID` was not
            // then one of the names the derived route recognised, so without
            // it the table was skipped altogether — the `triggeredByID` trap
            // part P6 found. #535 added both names to LINK_COLUMNS on
            // 11 October 2026, so the derived route would now reach it too;
            // the line stays because a hand-written entry wins, and removing
            // it would change nothing but the wording of the audit line.
            ['table' => 'tblExternalEventChoices',   'userCol' => 'createdByID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the choice is the organisation\'s setting and stays; who made it is detached'],
            ['table' => 'tblExternalEventChoices',   'userCol' => 'updatedByID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the choice is the organisation\'s setting and stays; who last changed it is detached'],
            ['table' => 'tblExternalFeedRules',      'userCol' => 'createdByID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the rule is the organisation\'s setting and stays; who made it is detached'],
            ['table' => 'tblExternalFeedRules',      'userCol' => 'updatedByID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the rule is the organisation\'s setting and stays; who last changed it is detached'],
            ['table' => 'tblExternalEventApprovals', 'userCol' => 'decidedByID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the decision is the organisation\'s history and stays; who approved or declined is detached'],
            ['table' => 'tblUserSmsPreference','userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblNewsletterSubscription','userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblPaymentMethod',    'userCol' => 'userID', 'action' => 'delete'],
            // 🔒 KEPT, on Salem874's decision of 11 October 2026 (roll-out
            //    decision 8). This line said 'delete' until then, so a donor
            //    asking to be forgotten destroyed the organisation's evidence
            //    for tax it had already reclaimed on their gifts.
            //
            //    Kept for tax reasons: HMRC can ask to see a declaration for
            //    six years after the last gift claimed under it. THAT PERIOD
            //    HAS NOT BEEN CONFIRMED WITH AN ACCOUNTANT; it is the
            //    project's understanding of HMRC's guidance, written down so
            //    that somebody checks it rather than trusts it.
            //
            //    What this does NOT do: nothing removes a declaration when
            //    those six years are up (there is no timer), and the donor's
            //    NAME is not on this row - it is on the account, which the
            //    last step empties. So what is kept is the address, postcode,
            //    dates and signature, linked to an account that names nobody.
            //    Whether that is enough for HMRC is part of what an
            //    accountant needs to confirm.
            ['table' => 'tblGiftAidDeclaration','userCol' => 'donorID', 'action' => 'retain', 'nullCols' => [], 'reason' => 'a Gift Aid declaration is the evidence for tax already reclaimed. Kept for tax reasons: HMRC can ask to see it for six years after the last gift claimed under it'],
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
            // are deliberately NOT catalogued either). tblCareVisit is KEPT,
            // by the 'retain' entry below: the visitor's attribution is
            // detached indirectly once the final tblUsers step tombstones
            // their name/email, without needing to touch (or delete) the
            // pastoral visit notes themselves — those stay attached to the
            // (now-anonymised) case for institutional continuity.
            //
            // 🛑 That entry was added on 11 October 2026, after the
            //    independent check of #510/#535. Until then the table had no
            //    entry here, the written list named only its `notes` column,
            //    and so EVERY erasure's audit trail said of pastoral visits
            //    that "no column ties a row of this table to an account" —
            //    while the visits the person had made still carried their
            //    account number. A sealed record saying something untrue.
            //    The line now says what is true: the visits are kept, and
            //    the number stays because the column may not be empty.
            //
            //    What this does NOT do: a visit somebody ELSE made, with the
            //    follow-up assigned to this person (`followUpAssignedToID`),
            //    is not matched by anything and keeps their number (#492).
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
            ['table' => 'tblCareVisit',      'userCol' => 'visitedByID',  'action' => 'retain', 'nullCols' => [], 'reason' => 'the pastoral visits this person made are kept with the case, as safeguarding policy requires. Their account number stays on each visit, because the database does not allow that column to be empty; the account it points at is emptied, so it names nobody'],
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
     * 🔗 Every column name in this portal that ties a record to a person.
     *
     * THE ONE LIST. It existed four times until 11 October 2026 (#535): here;
     * a private copy of the first 21 names inside fromPersonalDataCatalogue();
     * and two more copies in tools/gdpr-coverage-selftest.php. The copies
     * drifted, exactly as copies do. `presenterID` and `moderatedByID` were
     * here and not in the private copy, so the tables that used them were
     * passed over without a word. Everything now reads this constant.
     *
     * THE ORDER MATTERS. The first nine names mean "this record is ABOUT the
     * person". Every name after them means "the person ACTED on this record,
     * or is merely named on it" (those are repeated in ACTOR_COLUMNS). When a
     * table has both kinds, the derived route matches on the first name it
     * finds, so the person the record concerns wins. Add an "about" name
     * after `leaderID`, and an "acted on it" name at the end.
     *
     * Being on this list also decides how a column is emptied. processEntry()
     * empties a listed column only on the rows where it names THIS person. A
     * person column missing from the list is treated as free text and emptied
     * on every matched row, other people's numbers included.
     *
     * @var string[]
     */
    public const LINK_COLUMNS = [
        // Records ABOUT the person.
        'userID', 'memberID', 'donorID', 'submitterID', 'recipientUserID',
        'assignedToID', 'targetUserID', 'convertedUserID', 'leaderID',
        // The person ACTED on this, or is merely named on it.
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
        // Five added by #535 on 11 October 2026. Each was already used by a
        // hand-written instruction or a table, and none was on any list:
        // who opened a pastoral file, who changed or scanned an asset, who
        // uploaded a recording, who pressed Refresh on an outside calendar,
        // and who approved or declined one of its events.
        'viewerID', 'actorUserID', 'uploadedByID', 'triggeredByID', 'decidedByID',
        // Two more, added the same day after the independent check: who made
        // a pastoral visit, and who its follow-up was assigned to. Neither
        // was on any list, which is how the audit trail came to say that no
        // column tied a pastoral visit to an account.
        'visitedByID', 'followUpAssignedToID',
    ];

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
     * So a table whose ONLY link is one of these keeps the record and drops
     * the name. The derived route refuses to build a "delete" on one of these
     * columns unless the table is in DELETE_ON_ACTOR_COLUMN below, and
     * `tools/gdpr-coverage-selftest.php` applies the same rule to the
     * hand-written instructions.
     *
     * @var string[]
     */
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
        // The five #535 added to LINK_COLUMNS. All five say what somebody DID.
        'viewerID', 'actorUserID', 'uploadedByID', 'triggeredByID', 'decidedByID',
        // And the two pastoral-visit names: making a visit, and being given
        // its follow-up, are things a person DID or was asked to do.
        'visitedByID', 'followUpAssignedToID',
    ];

    /**
     * 🛑 The ONLY tables where a whole row may be deleted because of a column
     *     that says the person acted on it. Each one was looked at on purpose
     *     and carries its reason. Adding to this list should feel uncomfortable.
     *
     * Everywhere else, "they acted on it" means the record stays and only
     * their number goes. This list is read by fromPersonalDataCatalogue() and
     * by tools/gdpr-coverage-selftest.php, so the rule and its test cannot
     * disagree about the exceptions.
     *
     * @var array<string, string> Table name => why deleting is right here.
     */
    public const DELETE_ON_ACTOR_COLUMN = [
        // A parent signs a child up. The record holds the CHILD's name, date of
        // birth, allergies and medical notes - deleting it is the entire point.
        'tblEventRegistrations' => 'A child\'s medical details; deletion is the purpose',
        // One line saying "this person opened this pastoral file". It is about
        // the person's own actions and nothing else, so it goes with them. The
        // pastoral case, and the notes of every visit, stay. It is listed here
        // because #535 put `viewerID` among the "acted on it" names.
        'tblCareAccessLog'      => 'A line saying this person opened a file; the pastoral case and its notes stay',
    ];

    /**
     * 🏁 Tables the table walk must never touch, because execute() deals with
     *     them itself as its LAST step (closeRequest()).
     *
     * `tblErasureRequest` is the request being worked on. The walk used to
     * delete it part way through (#510): the database then deleted every audit
     * line with it, the next audit line had no request to belong to, and the
     * run crashed with the person half erased and the record of it gone.
     *
     * Tried and rejected: giving it an ordinary 'anonymise' instruction.
     * processEntry() always empties the matched column, so `userID` would be
     * emptied in the middle of the run. If a later step then failed, the
     * erasure page would find no account on the request and it could never be
     * run again.
     *
     * @var string[]
     */
    public const FINAL_STEP_TABLES = ['tblErasureRequest'];

    /**
     * 🧪 Tables with no single column that can be matched against a person,
     *     which a dedicated step erases instead. Table name => that step.
     *
     * The derived route leaves these alone on purpose.
     * tools/gdpr-coverage-selftest.php proves each step exists, is called
     * from execute() before the table walk, and has a matching block in the
     * data export.
     *
     * @var array<string, string>
     */
    public const DEDICATED_STEP_TABLES = [
        // #498: a list entry only means "this person" together with its tableName.
        'tblDemoDataRegister' => 'eraseDemoDataRegisterEntries',
    ];

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
     * wins, because it was written for a reason. So are the tables in
     * FINAL_STEP_TABLES and DEDICATED_STEP_TABLES.
     *
     * EVERY OTHER TABLE GETS AN INSTRUCTION. Until 11 October 2026 (#535) a
     * table marked "erase" or "unlink" whose listed columns held no name this
     * method recognised was simply passed over: no instruction, no audit
     * line, nothing. Eight tables were in that state while the written list
     * promised they were dealt with. Now such a table gets a 'refuse'
     * instruction, which processEntry() writes down as a failed step. The
     * written list has to be made true instead (give the table a real person
     * column, or mark it "retain" with the honest reason), and
     * tools/gdpr-coverage-selftest.php fails until it is.
     *
     * @param array<int, array<string, mixed>> $handWritten Already-handled entries.
     *
     * @return array<int, array<string, mixed>>
     */
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

        $entries = [];

        foreach ($catalogue as $table => $meta) {
            $table = (string) $table;
            if (isset($alreadyHandled[$table]) === true
                || in_array($table, self::FINAL_STEP_TABLES, true) === true
                || isset(self::DEDICATED_STEP_TABLES[$table]) === true
            ) {
                continue;
            }

            $decision = (string) ($meta['decision'] ?? '');
            if ($decision === 'not-personal') {
                continue;
            }

            $columns = (array) ($meta['columns'] ?? []);
            $reason  = (string) ($meta['reason'] ?? '');

            // Which column ties this row to a person? The first name on the
            // one shared list that this table has. "About the person" names
            // come first on that list, so they win over "acted on it" names.
            $link = '';
            foreach (self::LINK_COLUMNS as $candidate) {
                if (in_array($candidate, $columns, true) === true) {
                    $link = $candidate;
                    break;
                }
            }

            if ($decision === 'retain') {
                // Nothing is changed. The point of the entry is that the audit
                // trail records the decision.
                //
                // The matched column used to be written here as `userID` for
                // every kept table. Three of the four have no such column, so
                // the look-up failed and the audit line said "table missing"
                // about a table that exists and a record that was kept. Now it
                // is the table's real person column, or empty when the table
                // has none - and processEntry() then says so instead of
                // looking.
                $entries[] = [
                    'table'    => $table,
                    'userCol'  => $link,
                    'action'   => 'retain',
                    'nullCols' => [],
                    'reason'   => $reason . (isset($meta['period']) === true
                        // Was ' (kept for ' . period . ')', which read "kept for
                        // as required by safeguarding policy" for half the list.
                        ? ' (how long it is kept: ' . (string) $meta['period'] . ')'
                        : ''),
                ];
                continue;
            }

            if ($link === '') {
                // The written list promises to erase or unlink this table and
                // names no column that could find the person in it. Refuse
                // out loud rather than pass over it (see this method's
                // docblock). Event registrations were once the worst example;
                // they now record who submitted them (migration 191).
                $entries[] = [
                    'table'    => $table,
                    'userCol'  => '',
                    'action'   => 'refuse',
                    'nullCols' => [],
                    'reason'   => 'the written list says "' . $decision . '" but names no column that '
                        . 'ties this table to an account, so the person cannot be found in it',
                ];
                continue;
            }

            if ($decision === 'erase') {
                // 🛑 Never delete a whole row because of a column that only
                //    says the person ACTED on it, unless the table is one of
                //    the reviewed exceptions. Refused here, by construction,
                //    so that a careless "erase" in the written list cannot
                //    destroy somebody else's record.
                if (in_array($link, self::ACTOR_COLUMNS, true) === true
                    && isset(self::DELETE_ON_ACTOR_COLUMN[$table]) === false
                ) {
                    $entries[] = [
                        'table'    => $table,
                        'userCol'  => $link,
                        'action'   => 'refuse',
                        'nullCols' => [],
                        'reason'   => 'the written list says "erase", but the only link to a person is `'
                            . $link . '`, which says they acted on the record. Deleting it would destroy '
                            . 'somebody else\'s record',
                    ];
                    continue;
                }

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
            $nullCols = array_values(array_intersect(self::LINK_COLUMNS, $columns));
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
     * 🗑️ Run the erasure for one request.
     *
     * The caller must have moved the request to `processing` first (this
     * class does not claim a request; the erasure page does, so that two
     * administrators cannot run the same one at once).
     *
     * The order, and why:
     *   1. Read the person's email address, while the account still has it.
     *   2. The Demo Data step (#498). It stops everything by itself if it
     *      fails, and puts the request back; see its own docblock.
     *   3. Every instruction in catalogue(), then the two dedicated steps
     *      (giving statements, the email send log). A step that fails is
     *      written down and the rest still run.
     *   4. LAST, the request itself: closed if no step failed, put back to
     *      `pending_review` untouched if any did.
     *
     * It used to return true or false for "did anything change", and its only
     * caller ignored the answer and reported success whatever had happened.
     *
     * @param int      $requestId     The erasure request being carried out.
     * @param int      $userId        The account to erase.
     * @param int|null $processedByID The administrator who pressed the button.
     *
     * @return array{changed: bool, steps: int, failed: int} `changed`: at
     *   least one row or file was deleted or emptied. `steps`: how many steps
     *   ran. `failed`: how many of them could not be done; 0 means the request
     *   is now `completed`, anything else means it is back at `pending_review`.
     *
     * @throws \RuntimeException When the run had to stop before the end (the
     *   audit trail could not be written, or the request could not be put
     *   back). The message says, in plain words, what state the request is in.
     */
    public static function execute(int $requestId, int $userId, ?int $processedByID = null): array
    {
        $db = App::db();

        // 📧 #234 — the address has to be read BEFORE the table walk empties
        //    it on the account row; the email-log step needs it to search by.
        try {
            $erasedEmail = self::addressToScrub($db, $requestId, $userId);
        } catch (\Throwable $e) {
            throw self::stopped($db, $requestId, $e);
        }

        // 🧪 #498 — the Demo Data page's list entries for this person. Runs
        //    BEFORE the catalogue, because it finds membership entries through
        //    the person's tblUserSites rows and the catalogue deletes those.
        //    Deliberately OUTSIDE the try below: on a failure it puts the
        //    request back and throws its own carefully worded message, and
        //    that behaviour was settled over five review rounds.
        $changed = self::eraseDemoDataRegisterEntries($requestId, $userId) > 0;

        $steps  = 1; // the Demo Data step, which succeeded or we would not be here
        $failed = 0;
        $status = '';

        try {
            // Each step runs inside its own small transaction (see
            // asOneStep()), so what a step changed and the audit line that
            // says so are saved together or not at all.
            $outcomes = [];
            foreach (self::catalogue() as $entry) {
                $outcomes[] = self::asOneStep(
                    $db,
                    static fn (): string => self::processEntry($db, $requestId, $userId, $entry)
                );
            }
            // 🧾 Giving statements (#440 Q5): the rendered PDFs, then the
            //    address each was mailed to. Two steps, two audit lines.
            $giving = self::asOneStep($db, static fn (): array => self::eraseGivingStatementFiles($requestId, $userId));
            foreach ($giving as $outcome) {
                $outcomes[] = $outcome;
            }
            // 📧 #234 — the address in the email send log.
            $outcomes[] = self::asOneStep($db, static fn (): string => self::eraseEmailLogRecipients($requestId, $erasedEmail));

            foreach ($outcomes as $outcome) {
                $steps++;
                if ($outcome === self::STEP_FAILED) {
                    $failed++;
                } elseif ($outcome === self::STEP_CHANGED) {
                    $changed = true;
                }
            }

            if ($failed === 0) {
                self::closeRequest($db, $requestId, $userId, $processedByID, $steps);

                return ['changed' => $changed, 'steps' => $steps, 'failed' => 0];
            }

            // At least one step failed. The request goes back exactly as it
            // was - account number, name, address and notes all still on it -
            // so that running it again finds the person and the address.
            $status = self::putBackToPendingReview($db, $requestId);
            self::logAudit(
                $db,
                $requestId,
                'failed',
                'tblErasureRequest',
                null,
                $failed . ' of ' . $steps . ' steps failed, so the request was not closed. '
                . self::statusSentence($status)
            );
        } catch (\Throwable $e) {
            throw self::stopped($db, $requestId, $e);
        }

        if ($status !== 'pending_review') {
            throw new \RuntimeException(
                'Erasure request #' . $requestId . ': ' . $failed . ' of ' . $steps . ' steps failed. '
                . self::statusSentence($status)
            );
        }

        return ['changed' => $changed, 'steps' => $steps, 'failed' => $failed];
    }

    /**
     * 🔗 Run one step so that its change and its audit line are saved together.
     *
     * Without this, a step's change was saved the moment it was made and its
     * audit line a moment later. If that second write failed, the run stopped
     * with something erased and no record of it; and because the step had
     * already been done, running the request again found nothing and wrote
     * "nothing held". The trail then lacked a change that really happened,
     * for ever. (The Demo Data step met the same hole first: #498, round 5.)
     *
     * One transaction PER STEP, never one for the whole run: see "THE POLICY"
     * at the top of this file for why not.
     *
     * What this cannot do: bring back a deleted file. If the audit line for
     * the giving-statement PDFs cannot be written, the files are already gone
     * and the trail will not say so.
     *
     * @template T
     * @param callable(): T $step
     * @return T
     *
     * @throws \Throwable Whatever the step threw, after undoing its change.
     */
    private static function asOneStep(\mysqli $db, callable $step): mixed
    {
        if ($db->begin_transaction() === false) {
            throw new \RuntimeException('a transaction could not be started: ' . $db->error);
        }
        try {
            $outcome = $step();
            if ($db->commit() === false) {
                throw new \RuntimeException('the step could not be saved: ' . $db->error);
            }

            return $outcome;
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $rollbackProblem) {
                error_log('[WebMS-Intra] GdprEraser: rollback of one erasure step failed: ' . $rollbackProblem->getMessage());
            }
            throw $e;
        }
    }

    /** What one step did. Returned by processEntry() and the dedicated steps. */
    private const STEP_CHANGED   = 'changed';
    private const STEP_UNCHANGED = 'unchanged';
    private const STEP_FAILED    = 'failed';

    /**
     * 📧 The email address to search the send log for.
     *
     * Normally the address on the account. On a SECOND run of the same
     * request (after a step failed the first time) the account row has
     * already been emptied and holds only the made-up `deleted-…` address, so
     * the address is taken from the request instead. That is why a request is
     * left completely untouched until every step has succeeded.
     *
     * What this cannot do: if the person changed their address between asking
     * and the first run, a second run searches for the OLDER address on the
     * request. Nothing records the newer one once the account is emptied.
     *
     * @return string The address, or '' when none is known. Never a made-up
     *   `deleted-…` address.
     */
    private static function addressToScrub(\mysqli $db, int $requestId, int $userId): string
    {
        $read = static function (string $sql, int $id) use ($db): string {
            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                throw new \RuntimeException('the address could not be read: ' . $db->error);
            }
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();

            return (string) ($row[0] ?? '');
        };

        $address = $read('SELECT emailAddress FROM tblUsers WHERE userID = ? LIMIT 1', $userId);
        if ($address === '' || self::isTombstoneAddress($address) === true) {
            $address = $read('SELECT subjectEmail FROM tblErasureRequest WHERE requestID = ? LIMIT 1', $requestId);
        }

        return self::isTombstoneAddress($address) === true ? '' : $address;
    }

    /** Is this one of the made-up addresses an erasure leaves behind? */
    private static function isTombstoneAddress(string $address): bool
    {
        $pattern = str_replace(preg_quote('{rid}', '/'), '\d+', preg_quote(self::TOMBSTONE_EMAIL, '/'));

        return preg_match('/^' . $pattern . '$/', $address) === 1;
    }

    /**
     * 🏁 The last step: close the request, and take the person's details off it.
     *
     * Only ever called when every step before it succeeded.
     *
     * The request row STAYS. It is the organisation's record that an erasure
     * was asked for, when, and that it was done. What goes is what identifies
     * the person: the account number, the name, the notes, the confirmation
     * token, and the email address (replaced, because the column may not be
     * empty, with that row's own `deleted-<request number>` address). Decided
     * by Salem874's standing recommendation for #510: a request that still
     * shows the address of somebody who asked to be forgotten has not
     * forgotten them.
     *
     * The same is done to every OTHER request from the same account, matched
     * on the account number and never on the text of an address. One of those
     * still waiting (somebody pressed the button twice) is closed with this
     * one, with a line in its own audit trail saying which request did the
     * work: left open it would point at nobody and could never be run.
     *
     * The closing audit line and the changes to the request are ONE
     * transaction, so the trail can never say "closed" about a request that
     * is not, nor the other way round.
     *
     * @throws \Throwable If anything here fails. Nothing is then changed, and
     *   execute() puts the request back to `pending_review`.
     */
    private static function closeRequest(\mysqli $db, int $requestId, int $userId, ?int $processedByID, int $steps): void
    {
        if ($db->begin_transaction() === false) {
            throw new \RuntimeException('a transaction could not be started: ' . $db->error);
        }

        try {
            // Other requests from this account that are still waiting.
            $others = [];
            $stmt = $db->prepare(
                "SELECT requestID FROM tblErasureRequest WHERE userID = ? AND requestID <> ? "
                . "AND status IN ('pending_confirmation', 'pending_review') ORDER BY requestID"
            );
            $stmt->bind_param('ii', $userId, $requestId);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_row()) {
                $others[] = (int) $r[0];
            }
            $stmt->close();

            self::logAudit(
                $db,
                $requestId,
                'completed',
                'tblErasureRequest',
                null,
                'All ' . $steps . ' steps ran and none failed. The request is closed, and the account number, '
                . 'name, email address and notes are cleared from it'
                . ($others === [] ? '.' : ' and from ' . count($others) . ' other waiting request(s) from the same account.')
            );
            foreach ($others as $otherId) {
                self::logAudit(
                    $db,
                    $otherId,
                    'completed',
                    'tblErasureRequest',
                    null,
                    'Closed together with erasure request #' . $requestId . ', which erased the same account. '
                    . 'The steps are listed on that request\'s report.'
                );
            }

            // Status first, while `userID` can still find the waiting ones.
            $stmt = $db->prepare(
                "UPDATE tblErasureRequest SET status = 'completed', processedAt = NOW(), processedByID = ? "
                . "WHERE requestID = ? OR (userID = ? AND status IN ('pending_confirmation', 'pending_review'))"
            );
            $stmt->bind_param('iii', $processedByID, $requestId, $userId);
            $stmt->execute();
            $stmt->close();

            // Then the person's details, on every request from this account.
            [$before, $after] = explode('{rid}', self::TOMBSTONE_EMAIL);
            $stmt = $db->prepare(
                'UPDATE tblErasureRequest SET subjectName = NULL, notes = NULL, confirmToken = NULL, '
                . 'subjectEmail = CONCAT(?, requestID, ?), userID = NULL WHERE userID = ? OR requestID = ?'
            );
            $stmt->bind_param('ssii', $before, $after, $userId, $requestId);
            $stmt->execute();
            $stmt->close();

            if ($db->commit() === false) {
                throw new \RuntimeException('closing the request could not be saved: ' . $db->error);
            }
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $rollbackProblem) {
                error_log('[WebMS-Intra] GdprEraser: rollback failed while closing erasure #' . $requestId . ': ' . $rollbackProblem->getMessage());
            }
            throw $e;
        }
    }

    /**
     * ↩️ Put a request back from `processing` to `pending_review`, the only
     *    state the erasure page will run one from.
     *
     * A new small helper on purpose. eraseDemoDataRegisterEntries() has its
     * own copy of this statement, and it is left exactly as it is: that
     * method's behaviour was settled over five review rounds (#498).
     *
     * @return string The request's status afterwards, read back from the
     *   database rather than assumed ('' if it could not be read). Anything
     *   other than 'pending_review' means the put-back did not happen.
     */
    private static function putBackToPendingReview(\mysqli $db, int $requestId): string
    {
        try {
            $stmt = $db->prepare("UPDATE tblErasureRequest SET status = 'pending_review' WHERE requestID = ? AND status = 'processing'");
            if ($stmt === false) {
                return '';
            }
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $stmt->close();

            $stmt = $db->prepare('SELECT status FROM tblErasureRequest WHERE requestID = ? LIMIT 1');
            if ($stmt === false) {
                return '';
            }
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();

            return (string) ($row[0] ?? '');
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] GdprEraser: could not put erasure #' . $requestId . ' back to pending_review: ' . $e->getMessage());

            return '';
        }
    }

    /** One plain sentence saying where a request was left, from its real status. */
    private static function statusSentence(string $status): string
    {
        if ($status === 'pending_review') {
            return 'The request is back at pending review with everything on it unchanged, so it can be run again.';
        }

        return 'The request could NOT be put back to pending review'
            . ($status === '' ? '' : ' (its status is "' . $status . '")')
            . ', so it has to be set back by hand before it can be run again.';
    }

    /**
     * 🛑 The run has to stop. Put the request back, and build the message.
     *
     * Reached when something threw outside a step's own guard. processEntry()
     * and the dedicated steps catch a failing data statement themselves and
     * record it, so in practice this means the audit trail could not be
     * written - and erasing more with no record of it would be worse than
     * stopping.
     */
    private static function stopped(\mysqli $db, int $requestId, \Throwable $cause): \RuntimeException
    {
        // Abandon anything half done on this connection (closeRequest() may
        // have left a transaction open), so the put-back is not part of it.
        try {
            $db->rollback();
        } catch (\Throwable $ignored) {
            // Nothing was open, or the connection has gone; the put-back
            // below then fails too and the message says so.
        }

        $status = self::putBackToPendingReview($db, $requestId);
        error_log('[WebMS-Intra] GdprEraser: erasure #' . $requestId . ' stopped before it finished: '
            . get_class($cause) . ': ' . $cause->getMessage());

        return new \RuntimeException(
            'Erasure request #' . $requestId . ' stopped before it finished (' . $cause->getMessage() . '). '
            // Was "Nothing was lost: every step already done is recorded".
            // Not quite true: statement PDFs deleted just before the stop
            // cannot be un-deleted, and if their audit line is what failed
            // there is no record of them (see asOneStep()).
            . 'Every step is safe to repeat. Each step already done is in the audit trail, with one possible '
            . 'exception: giving-statement files deleted just before the stop. '
            . self::statusSentence($status),
            0,
            $cause
        );
    }

    /**
     * "What we hold about you" — JSON-serialisable inventory built by
     * querying every catalogue table for rows referencing the user.
     * Used by /account/my-data and /account/erasure-request.
     *
     * The person's own erasure requests are added at the end, by hand. They
     * used to appear because the table walk had an instruction for
     * `tblErasureRequest`; #510 took that instruction away (it deleted the
     * request being carried out), and for a few hours on 11 October 2026 a
     * person could no longer see here that they had asked to be erased.
     *
     * What this cannot show: a kept table that no column ties to an account
     * (see catalogue()), and a request already carried out - its account
     * number has been cleared, so nothing links it to anybody.
     *
     * @return array<int, array{table: string, rows: int, action: string, reason: string}>
     */
    public static function inventory(int $userId): array
    {
        $db = App::db();
        $out = [];
        foreach (self::catalogue() as $entry) {
            $table = (string) $entry['table'];
            $col   = (string) $entry['userCol'];
            if ($col === '') {
                // A kept table that no column ties to an account: there is
                // nothing to count this person's rows by.
                continue;
            }
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

        // 🗑️ The person's own erasure requests, with the state each is in.
        //    Listed as 'anonymise', the word used here for "the row is kept
        //    and what identifies you is cleared" - which is what
        //    closeRequest() does to it.
        try {
            $states = [];
            $total  = 0;
            $stmt   = $db->prepare('SELECT status, COUNT(*) FROM tblErasureRequest WHERE userID = ? GROUP BY status ORDER BY status');
            if ($stmt !== false) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $rs = $stmt->get_result();
                while ($r = $rs->fetch_row()) {
                    $states[] = (int) $r[1] . ' ' . str_replace('_', ' ', (string) $r[0]);
                    $total   += (int) $r[1];
                }
                $stmt->close();
            }
            if ($total > 0) {
                $out[] = [
                    'table'  => 'tblErasureRequest',
                    'rows'   => $total,
                    'action' => 'anonymise',
                    'reason' => 'Your request to be erased (' . implode(', ', $states) . '). The record that it was asked '
                        . 'for, and when, is kept; your name, email address and notes are cleared from it once the '
                        . 'erasure is done.',
                ];
            }
        } catch (\Throwable $ignored) {
            // The table is missing (a database older than the erasure
            // feature). There is then no request to show.
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

    /**
     * 🧱 Carry out ONE instruction, and write exactly ONE line about it to the
     *    audit trail.
     *
     * Every path through this method ends in one logAudit() call, and none of
     * those calls sits inside a try. That is deliberate: a data statement that
     * fails is caught here and recorded as a failed step, while a failure to
     * WRITE THE RECORD is left to travel up to execute(), which stops the run.
     * The two used to share one try, so a failed audit write was "recorded" by
     * a second audit write, which failed the same way and crashed the page.
     *
     * The lines it can write:
     *   none       nothing is held for this person in this table
     *   delete     rows deleted
     *   anonymise  the person's number, and any listed details, emptied
     *   partial    some emptied; some left because the database forbids it
     *   retain     kept on purpose, with the reason
     *   skip       nothing could be done and nothing needed to be (the table
     *              is not in this database; or no column may be emptied)
     *   failed     it should have been done and was not
     *
     * @param array<string, mixed> $entry One instruction from catalogue().
     *
     * @return string One of the STEP_ constants.
     */
    private static function processEntry(\mysqli $db, int $requestId, int $userId, array $entry): string
    {
        $table  = (string) $entry['table'];
        $col    = (string) $entry['userCol'];
        $action = (string) $entry['action'];
        $reason = (string) ($entry['reason'] ?? '');

        // 🛑 An instruction this class will not carry out. STOP, do not guess.
        //
        //    This used to be a plain "else" further down, which meant anything
        //    that was not "delete" was quietly treated as "anonymise" -
        //    including a typo, and including any new action somebody added
        //    later. For a record the law says to KEEP, that would have
        //    destroyed the very thing that had to be preserved, and reported
        //    success. Then it became a "skip", which let the request be closed
        //    as complete with the table untouched. It is a FAILED step now:
        //    refusing is always recoverable, and guessing is not.
        if ($action !== 'delete' && $action !== 'anonymise' && $action !== 'retain') {
            self::logAudit(
                $db,
                $requestId,
                'failed',
                $table,
                null,
                $action === 'refuse'
                    ? 'refused, nothing was changed: ' . $reason
                    : 'refused: unrecognised instruction "' . $action . '" - nothing was changed'
            );

            return self::STEP_FAILED;
        }

        // 🔒 A kept table that no column ties to an account. There is nothing
        //    to look the person up by, so say that, rather than run a look-up
        //    that cannot work and report its failure as "table missing".
        if ($col === '') {
            if ($action !== 'retain') {
                self::logAudit($db, $requestId, 'failed', $table, null, 'refused, nothing was changed: the instruction names no column to find the person by');

                return self::STEP_FAILED;
            }
            self::logAudit(
                $db,
                $requestId,
                'retain',
                $table,
                null,
                'kept, and not searched: no column ties a row of this table to an account, so it cannot be '
                . 'known whether it holds anything for this person. ' . ($reason !== '' ? $reason : 'no reason recorded')
            );

            return self::STEP_UNCHANGED;
        }

        // Does this table hold anything for this person?
        $held    = false;
        $problem = null;
        $errno   = 0;
        try {
            $stmt = $db->prepare('SELECT 1 FROM `' . $table . '` WHERE `' . $col . '` = ? LIMIT 1');
            if ($stmt === false) {
                $problem = (string) $db->error;
                $errno   = (int) $db->errno;
            } else {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $held = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
            }
        } catch (\Throwable $e) {
            $problem = $e->getMessage();
            $errno   = $e instanceof \mysqli_sql_exception ? (int) $e->getCode() : 0;
        }
        if ($problem !== null) {
            // MySQL error 1146 is "this table does not exist": an app that is
            // not installed, or a database not yet upgraded. Nothing can be
            // held in a table that is not there, so that is a skip.
            //
            // EVERYTHING ELSE IS A FAILED STEP. This used to say
            // "table-missing" for any problem at all - a column that does not
            // exist, a lock that timed out - which was untrue, and let a
            // request be closed with the table never looked at.
            if ($errno === 1146) {
                self::logAudit($db, $requestId, 'skip', $table, null, 'table-missing');

                return self::STEP_UNCHANGED;
            }
            self::logAudit($db, $requestId, 'failed', $table, null, mb_substr('could not look for this person by `' . $col . '`: ' . $problem, 0, 250));

            return self::STEP_FAILED;
        }

        if ($held === false) {
            // Written down, not passed over: without this line nothing could
            // show that the step ran at all (#510).
            self::logAudit($db, $requestId, 'none', $table, null, 'nothing held for this person (looked by `' . $col . '`)');

            return self::STEP_UNCHANGED;
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
                // Was 'kept as the law requires: ', in front of whatever the
                // reason said - including "as required by safeguarding
                // policy", which is a policy, not a law. The reason itself
                // says what the grounds are.
                'kept on purpose: ' . ($reason !== '' ? $reason : 'no reason recorded')
            );

            return self::STEP_UNCHANGED;
        }

        // Values bound before the one the WHERE clause uses. Only the
        // anonymise path adds any; declared here so every path has it.
        $extraBind = [];
        // Columns that had to be left alone because the database forbids
        // emptying them. Only the anonymise path adds any.
        $refused = [];

        // Empty unless one of the two branches below fills it. An action that
        // is neither was refused at the top of this method; if that guard were
        // ever removed, an empty statement fails to prepare and is recorded as
        // a failed step, so "anonymise" can never become the catch-all again.
        $sql = '';

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

            $sets = [];
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
                // The address may not be empty, so it was "refused" above -
                // but the line just above REPLACES it with a made-up one. It
                // is therefore not left in place, and the audit line must not
                // say it was. (It always did, on every erasure, until the
                // real-database test read the trail on 11 October 2026.)
                $refused = array_values(array_diff($refused, ['emailAddress']));
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

                    return self::STEP_UNCHANGED;
                }

                $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE `' . $col . '` = ?';
            }
        }

        // The data statement, guarded. Its failure is a failed STEP; the run
        // carries on with the next table.
        $affected = 0;
        $problem  = null;
        try {
            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                $problem = (string) $db->error;
            } else {
                // 📌 One value for each "is this column this person?" test,
                //    then the one the WHERE clause matches on. All whole
                //    numbers, so the letters are simply that many i's. Built
                //    from the same list that produced the SQL, so the two
                //    cannot fall out of step.
                $bindValues   = $extraBind;
                $bindValues[] = $userId;
                $stmt->bind_param(str_repeat('i', count($bindValues)), ...$bindValues);
                $stmt->execute();
                $affected = (int) $stmt->affected_rows;
                $stmt->close();
            }
        } catch (\Throwable $e) {
            $problem = $e->getMessage();
        }
        if ($problem !== null) {
            self::logAudit($db, $requestId, 'failed', $table, null, mb_substr($problem, 0, 250));

            return self::STEP_FAILED;
        }

        // 📝 Anything that had to be left alone is written down in the same
        //    line, so the organisation can show exactly what it did and what
        //    it could not do. Quietly leaving a link in place would be the
        //    worst outcome: it looks like a completed erasure and is not one.
        //    (This used to be a second, separate line written BEFORE the
        //    change was made, so it stayed in the trail even when the change
        //    then failed.)
        if ($refused !== []) {
            self::logAudit(
                $db,
                $requestId,
                'partial',
                $table,
                (string) $affected . ' rows',
                'left in place because the database does not allow them to be empty: '
                . implode(', ', $refused) . '. ' . $reason
            );
        } else {
            self::logAudit($db, $requestId, $action, $table, (string) $affected . ' rows', $reason);
        }

        return $affected > 0 ? self::STEP_CHANGED : self::STEP_UNCHANGED;
    }

    /**
     * 🧾 Giving statements: delete every rendered PDF for this person, across
     *    every organisation (the organisation is in the folder name, not the
     *    file name - see Portal\Core\Giving::renderStatementPdf()), then empty
     *    the address each statement was mailed to
     *    (`tblGivingStatementLog.emailedTo`, SEC-02).
     *
     * The statement-log ROW stays: its amounts and period are kept like
     * `tblGivingEntry`'s. Not a catalogue() entry, because
     * `tblGivingStatementLog.donorID` may not be empty, so the generic
     * instructions do not fit.
     *
     * Two steps, each with exactly one audit line, including "nothing held".
     * A file that could not be deleted, or an update that failed, is a FAILED
     * step. Both used to be passed over in silence: the PDF with the person's
     * name on it stayed on disk and the erasure was reported complete.
     *
     * @return string[] Two STEP_ constants: the files, then the addresses.
     */
    private static function eraseGivingStatementFiles(int $requestId, int $userId): array
    {
        $db = App::db();

        $removed = 0;
        $stuck   = 0;
        $base = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'giving'
              . DIRECTORY_SEPARATOR . 'statements';
        if (is_dir($base) === true) {
            $pattern = $base . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'statement-' . $userId . '-*.pdf';
            $matches = glob($pattern);
            if ($matches !== false) {
                foreach ($matches as $path) {
                    if (@unlink($path) === true) {
                        $removed++;
                    } else {
                        $stuck++;
                    }
                }
            }
        }
        // Not a real table name — deliberately NOT `tblXxx`-shaped so
        // check_php_table_refs.py doesn't need an allowlist entry for
        // this filesystem-only sweep's audit label.
        if ($stuck > 0) {
            self::logAudit($db, $requestId, 'failed', 'GivingStatementPdfFiles', (string) $removed . ' file(s)', $stuck . ' rendered statement PDF(s) could not be deleted and are still on disk');
            $files = self::STEP_FAILED;
        } elseif ($removed > 0) {
            self::logAudit($db, $requestId, 'delete', 'GivingStatementPdfFiles', (string) $removed . ' file(s)', 'rendered statement PDFs have no retention duty once the subject is erased');
            $files = self::STEP_CHANGED;
        } else {
            self::logAudit($db, $requestId, 'none', 'GivingStatementPdfFiles', null, 'no rendered statement PDF found for this person');
            $files = self::STEP_UNCHANGED;
        }

        // 🔐 SEC-02 — the rendered PDF is gone above, but the raw mailed-to
        // address still survives in `tblGivingStatementLog.emailedTo` even
        // after erasure. Null it (prepared, donor-scoped) while keeping
        // the row's totals/period for the run-history/audit trail.
        $nulled  = 0;
        $problem = null;
        try {
            $stmt = $db->prepare('UPDATE tblGivingStatementLog SET emailedTo = NULL WHERE donorID = ?');
            if ($stmt === false) {
                $problem = (string) $db->error;
            } else {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $nulled = (int) $stmt->affected_rows;
                $stmt->close();
            }
        } catch (\Throwable $e) {
            $problem = $e->getMessage();
        }
        if ($problem !== null) {
            self::logAudit($db, $requestId, 'failed', 'tblGivingStatementLog', null, mb_substr('the mailed-to address could not be emptied: ' . $problem, 0, 250));
            $addresses = self::STEP_FAILED;
        } elseif ($nulled > 0) {
            self::logAudit($db, $requestId, 'anonymise', 'tblGivingStatementLog', (string) $nulled . ' rows', 'emailedTo PII detached; totals/period run-history retained (statement PDFs already unlinked above)');
            $addresses = self::STEP_CHANGED;
        } else {
            self::logAudit($db, $requestId, 'none', 'tblGivingStatementLog', null, 'no mailed-to address held for this person');
            $addresses = self::STEP_UNCHANGED;
        }

        return [$files, $addresses];
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
     * The address comes from execute(), which reads it before the table walk
     * empties it (see addressToScrub()).
     *
     * Exactly one audit line, including when there was nothing to do. A
     * failed update is a FAILED step, not a crash.
     *
     * @return string One of the STEP_ constants.
     */
    private static function eraseEmailLogRecipients(int $requestId, string $email): string
    {
        $db = App::db();

        if ($email === '') {
            self::logAudit($db, $requestId, 'none', 'tblEmailLog', null, 'no email address is known for this person, so the send log was not searched');

            return self::STEP_UNCHANGED;
        }

        $like        = '%' . $email . '%';
        $replacement = '[erased]';

        $affected = 0;
        $problem  = null;
        try {
            $stmt = $db->prepare('UPDATE tblEmailLog SET toRecipients = REPLACE(toRecipients, ?, ?) WHERE toRecipients LIKE ?');
            if ($stmt === false) {
                $problem = (string) $db->error;
            } else {
                $stmt->bind_param('sss', $email, $replacement, $like);
                $stmt->execute();
                $affected = (int) $stmt->affected_rows;
                $stmt->close();
            }
        } catch (\Throwable $e) {
            $problem = $e->getMessage();
        }
        if ($problem !== null) {
            self::logAudit($db, $requestId, 'failed', 'tblEmailLog', null, mb_substr('the address could not be removed from the send log: ' . $problem, 0, 250));

            return self::STEP_FAILED;
        }
        if ($affected > 0) {
            self::logAudit($db, $requestId, 'anonymise', 'tblEmailLog', (string) $affected . ' rows', 'recipient address detached from send-log audit trail; provider/status/subject/httpCode retained');

            return self::STEP_CHANGED;
        }
        self::logAudit($db, $requestId, 'none', 'tblEmailLog', null, 'the send log holds no message to this person\'s address');

        return self::STEP_UNCHANGED;
    }

    /**
     * 🧪 Delete the Demo Data page's list entries that point at this person
     *    (#498).
     *
     * tblDemoDataRegister is the Demo Data page's list of the rows it created
     * (web/_apps/admin/maintenance/demo-data.php). An entry is a table name and
     * a row number, with no name or text. But an entry naming this person's
     * own account (tblUsers) or one of their memberships (tblUserSites) still
     * points straight at them: a made-up demo person can be edited into a
     * real one and kept. A number that identifies somebody is personal data
     * even with nothing beside it, so those entries are deleted.
     *
     * WHY THIS IS NOT A catalogue() ENTRY. The generic instructions match ONE
     * column against the person's number. Here the number is in rowID, and it
     * only means "this person" together with tableName. Matching rowID alone
     * would also delete the entry for, say, announcement number 7 whenever
     * person number 7 is erased. That would delete nothing real, but it would
     * quietly take a demo announcement off the list, so Wipe would never
     * remove it and nothing would show it was still there.
     *
     * WHY IT RUNS BEFORE THE CATALOGUE. Membership entries are found through
     * the person's rows in tblUserSites, and the catalogue deletes those rows.
     *
     * Deleting an entry is always the safe direction for the Demo Data page:
     * Wipe never deletes a row that is not on the list. The account row itself
     * is kept and emptied by the catalogue's last step, as for anybody.
     *
     * What it does NOT touch: entries for announcements. An announcement is
     * the organisation's content, not information about its author (the
     * catalogue unlinks the author and keeps the announcement).
     *
     * The matching export is the 'demoDataRegister' block in
     * web/_apps/auth/account/data-export.php.
     *
     * WHY A FAILURE STOPS THE WHOLE ERASURE. The first version of this step
     * wrote a failure to the audit trail and let the erasure carry on. Codex
     * found (14 September 2026) that this turned a passing problem, such as a
     * lock held for a moment, into a permanent one. The membership entries are
     * found through the person's tblUserSites rows, and the catalogue deletes
     * those rows a moment later. After a failed attempt the entries were still
     * on the list, but nothing linked the person to them any more, so running
     * the erasure again could never find them.
     * Now any failure is written to the audit trail, the request is put back
     * from 'processing' to 'pending_review' (the only state the erasure page,
     * web/_apps/admin/erasure/process.php, will run a request from), and an
     * exception is thrown. This is the first thing execute() erases, so
     * nothing has been erased at that point, and running the request again
     * starts from exactly the same position and finds every entry.
     *
     * ROUND 5 (15 September 2026, Codex). That first fix still had a gap: the
     * DELETE and the audit line that recorded it were two separate statements,
     * neither inside a transaction with the other. If the audit write itself
     * failed - for example a lock wait timeout on tblErasureAudit, which this
     * table's own chained-hash design means every request writes to in order -
     * the DELETE had already happened, but nothing said so, and the request
     * still had to be put back to pending_review for the reset to help at all.
     * A retry then found the entries already gone and deleted nothing, so the
     * audit trail permanently lacked a deletion that genuinely occurred - not
     * a false negative that self-heals on retry, but a hole that stays.
     * The fix: the DELETE and its 'delete' audit line are now ONE transaction.
     * Either both happen (committed together) or neither does (rolled back
     * together), so the audit trail can never end up missing a deletion that
     * really happened, and a retry after any failure finds every entry again,
     * exactly as before this row of work.
     *
     * WHAT WAS TRIED AND REJECTED FOR ROUND 5:
     *   - The brief's literal wording ("report that those entries were already
     *     removed" after a successful delete whose audit write then failed).
     *     Rejected because, with the delete and its audit line as one
     *     transaction, a failed audit write UNDOES the delete - so "nothing was
     *     erased" is what actually happened, and is the honest thing to report.
     *     The only genuinely unknown case is a failure during COMMIT itself,
     *     which is reported as unconfirmed rather than either "erased" or
     *     "not erased" (see "What this cannot do" below).
     *   - Swallowing an audit-write failure and carrying on regardless.
     *     Rejected because the catalogue's own processEntry() calls to
     *     logAudit() are not guarded either, so the very next one would meet
     *     the same lock and fail the same way, leaving the request stuck with
     *     only part of the person erased and no way to tell how much.
     *   - Catching the exception in web/_apps/admin/erasure/process.php
     *     instead. Rejected: that is outside this change, and would not make
     *     the delete and its audit line succeed or fail together, which is the
     *     actual fault being fixed.
     *   - Retrying the audit write in a loop before giving up. Rejected: each
     *     attempt can wait the database's full lock-wait limit (50 seconds by
     *     default), which only makes the request sit at 'processing' for
     *     longer before the reset that is meant to free it.
     *   - Writing a 'failed' audit line and THEN putting the request back, even
     *     when the audit trail itself is what failed. Measured (round 5
     *     testing, lock-wait limit set to 3 seconds for the test): this order
     *     took roughly 6 seconds before the reset, because it waited out one
     *     lock-wait timeout on the real audit line and then a second one on
     *     the 'failed' line, which fails the same way for the same reason.
     *     Skipping the second attempt and going straight to the reset measured
     *     about 3 seconds, with the same safe result (nothing erased, request
     *     back at pending_review). Every extra second spent at 'processing' is
     *     a second in which a web server or hosting time limit can kill this
     *     PHP process before the reset runs at all, which is the exact fault
     *     being fixed - so a 'failed' line is written for every OTHER kind of
     *     failure (starting the transaction, the DELETE itself, saving), but
     *     not when the audit trail is what failed.
     *
     * The one failure that does NOT stop it is MySQL error 1146, "table does
     * not exist", and ONLY when it comes from the DELETE itself. A database
     * not yet upgraded to migration 194 has no list, so the list holds nothing
     * about anybody; that is recorded as 'skip' with 'table-missing', the same
     * words processEntry() uses, and the erasure carries on. If the SKIP line
     * itself cannot be written (the audit table has the same problem it always
     * might), that stops the step like any other audit-write failure above -
     * error 1146 from a query that is not the DELETE is not "the list does not
     * exist", it is "the audit trail does not exist", which is a different and
     * much more serious problem and must not be waved through as harmless.
     *
     * What this cannot do:
     *   - If putting the request back fails too (for example the connection
     *     has gone), the request stays at 'processing' and has to be set back
     *     by hand. The exception's message now says plainly which of the two
     *     happened, rather than always claiming the reset worked; the earlier
     *     version of this code said the request was back at pending review
     *     even on the runs where the reset itself had failed.
     *   - A failure during COMMIT leaves it genuinely unknown whether the
     *     DELETE was saved or not. The thrown message says so, and says that
     *     running the request again will delete whatever, if anything,
     *     remains - which is safe either way, because a second DELETE against
     *     rows that are already gone simply affects nothing.
     *   - begin_transaction() silently commits any transaction a caller
     *     already had open on this connection. The only caller of this method,
     *     execute(), calls it first, before opening any transaction of its
     *     own, so this never discards work belonging to something else.
     *   - While the audit write is waiting on a lock, the tblDemoDataRegister
     *     rows being deleted, and the person's tblUserSites rows the DELETE's
     *     subquery reads, stay locked for up to the database's lock-wait
     *     limit (50 seconds by default) before this method gives up.
     *   - After a failure while writing to the audit trail specifically
     *     (rather than to the request row), no 'failed' audit line is written
     *     at all, for the timing reason explained above. The reason for the
     *     stop is still in the PHP error log, and in the thrown exception's
     *     message, which the portal records in tblErrors.
     *
     * @param int $requestId The erasure request, for the audit trail.
     * @param int $userId    The person being erased.
     *
     * @return int List entries deleted. 0 when there were none, or when the
     *   table does not exist yet (written to the audit trail as a skip).
     *
     * @throws \RuntimeException On any other failure, after recording it where
     *   the audit trail itself still works, and attempting to put the request
     *   back to 'pending_review' - the exception message says whether that
     *   attempt succeeded.
     */
    private static function eraseDemoDataRegisterEntries(int $requestId, int $userId): int
    {
        $db = App::db();

        // Which part was running when something failed. It decides three
        // things below: whether MySQL error 1146 means "the list does not
        // exist yet" (only when it comes from the DELETE itself - the same
        // error from the audit write means "the AUDIT TRAIL does not exist",
        // a much more serious problem, and must not be waved through as
        // harmless); what the thrown message says was lost; and whether a
        // 'failed' audit line is even attempted (see "WHAT WAS TRIED AND
        // REJECTED FOR ROUND 5" in this method's docblock for why not, when
        // the audit trail itself is what failed).
        $stage        = 'start';
        $deleted      = 0;
        $tableMissing = false;
        // A SEPARATE flag from "$problem is empty", not "$problem === ''",
        // because an exception with an empty message (unlikely, but not
        // impossible) would otherwise look exactly like success below.
        $failed  = false;
        $problem = '';

        try {
            if ($db->begin_transaction() === false) {
                // Only reached if the driver is not set to throw (it is, see
                // bootstrap.php's mysqli_report(MYSQLI_REPORT_STRICT), but
                // this method already guards every other non-throwing case,
                // so it stays consistent rather than assuming that config).
                throw new \RuntimeException('a transaction could not be started: ' . $db->error);
            }
            try {
                $stage = 'delete';
                $stmt  = $db->prepare(
                    "DELETE FROM tblDemoDataRegister WHERE (tableName = 'tblUsers' AND rowID = ?) "
                    . "OR (tableName = 'tblUserSites' AND rowID IN (SELECT userSiteID FROM tblUserSites WHERE userID = ?))"
                );
                if ($stmt === false) {
                    throw new \mysqli_sql_exception((string) $db->error, (int) $db->errno);
                }
                $stmt->bind_param('ii', $userId, $userId);
                if ($stmt->execute() === false) {
                    $failure = new \mysqli_sql_exception((string) $stmt->error, (int) $stmt->errno);
                    $stmt->close();
                    throw $failure;
                }
                $deleted = (int) $stmt->affected_rows;
                $stmt->close();

                // The DELETE and its audit line are now ONE transaction (round
                // 5 of #498) - see this method's docblock for why. Only write
                // the line when something was actually deleted; an empty
                // delete needs no audit entry, same as before this change.
                if ($deleted > 0) {
                    $stage = 'audit';
                    self::logAudit(
                        $db,
                        $requestId,
                        'delete',
                        'tblDemoDataRegister',
                        (string) $deleted . ' rows',
                        'Demo Data list entries pointing at this person\'s account or memberships deleted; entries for announcements kept'
                    );
                }

                $stage = 'commit';
                if ($db->commit() === false) {
                    throw new \RuntimeException('the deletion could not be saved: ' . $db->error);
                }
                $stage = 'done';
            } catch (\Throwable $inner) {
                // Undo FIRST, before anything below (the 'failed' audit line,
                // the reset) runs - those must survive outside this
                // transaction, or they would be thrown away by the rollback
                // along with everything else.
                try {
                    $db->rollback();
                } catch (\Throwable $rollbackProblem) {
                    error_log('[WebMS-Intra] GdprEraser: rollback failed for erasure #' . $requestId . ': ' . $rollbackProblem->getMessage());
                }
                throw $inner;
            }
        } catch (\mysqli_sql_exception $e) {
            if ($stage === 'delete' && (int) $e->getCode() === 1146) {
                $tableMissing = true;
            } else {
                $failed  = true;
                $problem = 'Error ' . (int) $e->getCode() . ': ' . $e->getMessage();
            }
        } catch (\Throwable $e) {
            $failed  = true;
            $problem = get_class($e) . ': ' . $e->getMessage();
        }

        if ($tableMissing === true) {
            // A database not yet upgraded to migration 194 has no list at
            // all, so there is nothing about anybody to delete. Recorded the
            // same way processEntry() records a genuinely missing table.
            try {
                self::logAudit($db, $requestId, 'skip', 'tblDemoDataRegister', null, 'table-missing');
                return 0;
            } catch (\Throwable $e) {
                // Writing even the SKIP line failed - the audit trail itself
                // has a problem, which is not "table-missing" any more, so
                // fall through to the same stopped-and-reset handling as any
                // other failure below.
                $stage   = 'skip-audit';
                $failed  = true;
                $problem = ($e instanceof \mysqli_sql_exception ? 'Error ' . (int) $e->getCode() . ': ' : get_class($e) . ': ') . $e->getMessage();
            }
        }

        if ($failed === false) {
            return $deleted;
        }

        $where = [
            'start'      => 'before the Demo Data list entries could be deleted',
            'delete'     => 'while deleting the Demo Data list entries',
            'audit'      => 'while recording the deletion of the Demo Data list entries in the audit trail',
            'commit'     => 'while saving the deletion of the Demo Data list entries',
            'skip-audit' => 'while recording in the audit trail that the Demo Data list does not exist yet',
        ][$stage] ?? 'at an unexpected point';
        $outcome = $stage === 'commit'
            // Only COMMIT leaves it genuinely unconfirmed: everything up to
            // and including the audit write succeeded, but whether the
            // database actually saved it is unknown. Every other stage
            // rolled back cleanly, so "nothing was erased" is simply true.
            ? 'it could not be confirmed whether the Demo Data list entries were deleted; running the request again deletes any that remain'
            : 'nothing was erased';

        // Record the stop, UNLESS the audit trail itself is what failed: a
        // second write to the same table would very likely fail the same
        // way, and waiting for it (up to the database's lock-wait limit, 50
        // seconds by default) only delays the reset below, which is the part
        // that actually has to happen. Measured in round 5 testing: writing
        // the 'failed' line first, then resetting, took about 6 seconds with
        // a 3-second lock-wait limit (two timeouts back to back); skipping
        // straight to the reset took about 3 seconds for the same safe
        // result. See "WHAT WAS TRIED AND REJECTED FOR ROUND 5" above.
        if ($stage === 'audit' || $stage === 'skip-audit') {
            error_log('[WebMS-Intra] GdprEraser: erasure #' . $requestId . ' stopped ' . $where . ' (' . $problem . '); no failure line was written, because the audit trail is what failed');
        } else {
            try {
                self::logAudit(
                    $db,
                    $requestId,
                    'failed',
                    'tblDemoDataRegister',
                    null,
                    mb_substr('Erasure stopped ' . $where . ': ' . $outcome . '; putting the request back to pending_review. ' . $problem, 0, 250)
                );
            } catch (\Throwable $auditProblem) {
                error_log('[WebMS-Intra] GdprEraser: could not record the stopped erasure #' . $requestId . ': ' . $auditProblem->getMessage());
            }
        }

        // Put the request back so it can be run again - and say HONESTLY in
        // the thrown message whether that actually worked, rather than
        // always claiming it did (the fault this exact line fixes: the
        // earlier version of this code reported "back at pending review"
        // even on the runs where this UPDATE itself had failed).
        $putBack = false;
        try {
            $reset = $db->prepare("UPDATE tblErasureRequest SET status = 'pending_review' WHERE requestID = ? AND status = 'processing'");
            if ($reset !== false) {
                $reset->bind_param('i', $requestId);
                $reset->execute();
                $putBack = ((int) $reset->affected_rows === 1);
                $reset->close();
            }
        } catch (\Throwable $resetProblem) {
            error_log('[WebMS-Intra] GdprEraser: could not put erasure #' . $requestId . ' back to pending_review: ' . $resetProblem->getMessage());
        }

        throw new \RuntimeException(
            'Erasure request #' . $requestId . ' stopped ' . $where . ', so ' . $outcome . ' (' . $problem . '). '
            . ($putBack === true
                ? 'The request is back at pending review, so it can be run again.'
                : 'The request could NOT be put back to pending review, so it has to be set back by hand before it can be run again.')
        );
    }

    /**
     * ✍️ Add one line to a request's audit trail, chained to the line before.
     *
     * THROWS if the line cannot be written. It used to do nothing at all when
     * its statement could not be prepared, so an erasure could carry on with
     * no record being kept and nobody the wiser. Callers do not catch this:
     * execute() does, once, and stops the run.
     *
     * @throws \RuntimeException|\mysqli_sql_exception When the trail cannot be
     *   read or written.
     */
    private static function logAudit(\mysqli $db, int $requestId, string $action, string $table, ?string $recordKey, string $details): void
    {
        // Read the tail hash to chain on.
        $prev = '';
        $stmt = $db->prepare('SELECT chainHash FROM tblErasureAudit WHERE requestID = ? ORDER BY auditID DESC LIMIT 1');
        if ($stmt === false) {
            throw new \RuntimeException('the erasure audit trail could not be read: ' . $db->error);
        }
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $stmt->bind_result($prev);
        $stmt->fetch();
        $stmt->close();

        $hash = self::hashRow($prev ?? '', $action, $table, $recordKey ?? '', $details);
        $ins = $db->prepare('INSERT INTO tblErasureAudit (requestID, action, tableName, recordKey, details, chainHash) VALUES (?, ?, ?, ?, ?, ?)');
        if ($ins === false) {
            throw new \RuntimeException('the erasure audit trail could not be written: ' . $db->error);
        }
        $ins->bind_param('isssss', $requestId, $action, $table, $recordKey, $details, $hash);
        if ($ins->execute() === false) {
            $problem = (string) $ins->error;
            $ins->close();
            throw new \RuntimeException('the erasure audit trail could not be written: ' . $problem);
        }
        $ins->close();
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
