<?php
// Path: _core/GdprEraser.php
/**
 * -----------------------------------------------------------------------------
 * GDPR Article 17 erasure engine 🗑️🔐
 * -----------------------------------------------------------------------------
 * Right-to-erasure orchestrator. Walks the catalogue of PII-bearing tables,
 * deletes rows where lawful, anonymises rows that must be retained for a
 * legitimate-interest reason (financial records, for example).
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
 * IT DOES NOT END AN OPEN SESSION. It deletes everything a person could sign
 * in WITH (password, linked accounts, passkeys, trusted devices, reset
 * tokens, the calendar-feed key), so nobody can sign in as them again. But if
 * they are signed in on some device when the erasure runs, that session goes
 * on working until it ends by itself. The portal keeps sessions as PHP's own
 * files, not in a table this class can reach, and Portal\Core\Auth::check()
 * only asks whether a session holds an account number - not whether that
 * account is still active. Such a session belongs to no organisation and has
 * no administrator rights, because those rows and flags ARE cleared. Ending
 * it needs a change to Auth that runs on every page, which was deliberately
 * not made here (found by the independent check of 12 October 2026).
 *
 * OTHER PEOPLE'S RECORDS ARE NEVER DELETED (roll-out package A2a, Salem874's
 * decision of 11 October 2026)
 * -------------------------------------------------------------------
 * A record is deleted only when it is ABOUT the person being erased. A record
 * they merely worked on - a visitor they followed up, a task or a rota slot
 * given to them, a note they wrote, a payment they entered - is somebody
 * else's, or the organisation's. It is kept, and only their account number is
 * taken off it. Until that decision, erasing a follow-up worker deleted every
 * visitor, salvation card, task and rota slot assigned to them.
 *
 * The rule is enforced in LINK_COLUMNS and ACTOR_COLUMNS below, not left to
 * each instruction: a "delete" matched on a column that says the person acted
 * on a record is refused by construction.
 *
 * GIVING RECORDS KEEP THE DONOR'S NAME FOR A SET TIME (roll-out package A2b,
 * Salem874's decisions of 11 October 2026)
 * -------------------------------------------------------------------
 * Tax and accounting law makes most organisations keep some giving records
 * WITH the donor's name for a number of years. So an erasure keeps the name
 * and home address, in `tblGivingKeptDonors`, for the gifts the person's
 * organisation has chosen to keep identified, and takes the name off every
 * other gift at once. The account itself is still emptied. When the
 * organisation's "keep until" date has passed, the retention job calls
 * clearKeptGivingIdentity(), which removes the name, the address, the link
 * and the kept statement files together, and adds a line to this erasure's
 * audit trail. See keepGivingRecords() and Portal\Core\GivingRetention.
 *
 * NOTHING IS CLEARED before an administrator has confirmed the number of
 * years, and nothing while a hold is on. The numbers of years are the
 * portal's SUGGESTIONS from research, never legal fact; no line written here
 * says otherwise.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.3.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/235
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/510
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/535
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/574
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
     *    'nullCols' => string[]?, 'reason' => string?, 'sqlSets' => string[]?]
     *
     * `sqlSets` (anonymise only) is a list of extra `column = expression`
     * pieces added to the UPDATE, for a change that is not "empty a column":
     * cancelling a rota swap request that was still waiting, for example. Each
     * piece is FIXED TEXT WRITTEN IN THIS FILE. It is put into the statement
     * as it stands, so it must never be built from anything a person typed or
     * anything read from the database.
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
            // 🧾 KEPT, since roll-out package A2b (Salem874, 11 October 2026).
            //    This line said 'anonymise' until then: every gift lost its
            //    donor at once. A gift given before the erasure but not yet
            //    claimed then dropped out of the organisation's next tax
            //    claim, because the claim matches a gift to its declaration
            //    by account number (Giving::buildHmrcCsv()), and nothing
            //    reported it.
            //
            //    By the time the walk reaches this line, keepGivingRecords()
            //    has already run: the gifts that do NOT keep the donor's name
            //    have lost their account number, so the only gifts this line
            //    can still find are the kept ones. It changes nothing; it
            //    writes down that they are kept. The retention job removes
            //    the number later (clearKeptGivingIdentity()).
            //
            //    It also said "UK HMRC requires 6-year retention", which was
            //    one country's rule stated as fact about every customer.
            ['table' => 'tblGivingEntry',       'userCol' => 'donorID',      'action' => 'retain', 'nullCols' => [], 'reason' => 'these gifts keep the donor\'s name and address for tax reasons until the organisation\'s keep-until date; see the "GivingRecords" line of this report for the date and how it was worked out. Every other gift of this person\'s has already lost their name'],
            // ✉️ A counted envelope. KEPT in the walk, like a gift, because the
            //    giving-records step has already dealt with every envelope by
            //    the time the walk gets here (unnameCountEnvelopes()): it
            //    takes the giver off all of them EXCEPT one in a count that
            //    is not yet closed whose gift, when the count is closed, will
            //    be a gift that keeps the donor's name.
            //
            //    This line first said 'anonymise' for every envelope. The
            //    independent check of 12 October 2026 pointed out what that
            //    did: closing the count afterwards made a gift with NO donor
            //    from it, so that gift dropped out of the organisation's tax
            //    claim although the same donor's other gifts were kept.
            //
            //    What it cannot do: find an envelope that names the person
            //    ONLY by a typed name (`giverName`), with no account number.
            ['table' => 'tblCountEnvelopes',    'userCol' => 'giverID',      'action' => 'retain', 'nullCols' => [], 'reason' => 'an envelope in an offering count that is not yet closed, whose gift will keep the donor\'s name for tax reasons when the count is closed; see the "CountEnvelopes" line of this report. Every other envelope of this person\'s has already lost its giver'],
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
            // column, on purpose: an instruction matches rows on ONE column,
            // so with a single one a choice somebody else created and this
            // person last changed would keep their number. Each entry here
            // empties its own column wherever it names this person. (When
            // this was written the derived route built one instruction per
            // table and had exactly that fault, #492; since 11 October 2026
            // it builds one per column too.)
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
            //    Not one column of a declaration changes at erasure. HOW LONG
            //    it is kept is the organisation's own setting (roll-out
            //    package A2b): this comment and the reason below used to say
            //    "six years" and name the United Kingdom's tax office, as if
            //    that were true of every customer, and noted that there was
            //    no timer and that the donor's NAME was kept nowhere. Both
            //    are put right: the name and address are kept in
            //    `tblGivingKeptDonors`, and the retention job empties the
            //    address, postcode, signature and notes here when the
            //    keep-until date has passed (clearKeptGivingIdentity()).
            //
            //    What that later step does NOT do: empty `donorID`. The
            //    database does not allow it to be empty on this table, so the
            //    row goes on pointing at an account that names nobody.
            ['table' => 'tblGiftAidDeclaration','userCol' => 'donorID', 'action' => 'retain', 'nullCols' => [], 'reason' => 'a tax declaration is the organisation\'s evidence for tax relief claimed on a donor\'s gifts. Kept unchanged, with the donor\'s giving records, until the organisation\'s keep-until date; see the "GivingRecords" line of this report'],
            // The person's own connection to Zoom: their Zoom account's number
            // and the keys that let the portal act as them. Deleted.
            //
            // THE MEETINGS MADE THROUGH IT GO TOO, and that is on purpose
            // (Salem874, 11 October 2026). The database deletes every
            // `tblZoomMeeting` row that used this connection
            // (`fk_zm_account`, ON DELETE CASCADE). Such a meeting is a room
            // in THEIR Zoom account, which the portal can no longer open once
            // the keys are gone. Until this was written down, the written
            // list said meetings were always kept, which was untrue.
            // A meeting they merely created through SOMEBODY ELSE'S
            // connection is kept, and only their number is taken off it.
            ['table' => 'tblZoomAccount',      'userCol' => 'userID', 'action' => 'delete', 'reason' => 'the person\'s own connection to Zoom. The meetings made through it are rooms in their Zoom account and are deleted with it; a meeting they made through somebody else\'s connection is kept'],
            ['table' => 'tblUserTranslationPref','userCol' => 'userID', 'action' => 'delete'],
            // #322 Web Push — endpoint URL + p256dh/auth keys are device
            // credentials tied to a specific browser install, not history
            // worth retaining once their owner is erased. Hard delete
            // mirrors the tblTrustedDevices/tblLocalAccounts precedent
            // immediately above, not the anonymise-and-keep pattern used
            // for content/authorship tables.
            ['table' => 'tblPushSubscriptions','userCol' => 'userID', 'action' => 'delete'],
            // #303 Phase 2 — discipleship per-user tables: the person's own
            // enrolments and progress, deleted.
            // (This used to say the "who enrolled / marked / revoked"
            // numbers on OTHER people's rows "self-heal" through the
            // database's ON DELETE SET NULL. They never did: that rule only
            // acts when an account ROW is deleted, and an erasure empties
            // the account and keeps the row. Those three columns now have
            // instructions of their own, in the #492 block further down.)
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
            // A child's check-in names TWO helpers: who checked the child in
            // and who checked them out. The check-in is the child's record
            // and stays. One instruction per column (the tblAssetStocktakes
            // shape), so a check-in made by somebody else and checked OUT by
            // this person is still found.
            //
            // The note above ("no separate catalogue entry is needed") was
            // written when the child's profile was deleted and its check-ins
            // went with it. The profile is kept now, so the check-ins are
            // too, and until 11 October 2026 nothing took a helper's number
            // off them. Neither column has a link to the account table, so
            // nothing but these two lines ever would.
            // What this does NOT do: `pickupName` is a name typed by hand and
            // may spell this person's name. Nothing ties it to an account.
            ['table' => 'tblKidCheckins',      'userCol' => 'checkedInByID',  'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the check-in is the child\'s record and stays; who checked them in is detached'],
            ['table' => 'tblKidCheckins',      'userCol' => 'checkedOutByID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the check-in is the child\'s record and stays; who checked them out is detached'],

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
            // tblCareVisit: a pastoral visit names who MADE it (`visitedByID`)
            // and who its follow-up was given to (`followUpAssignedToID`).
            // The visit and its notes describe the person being cared for,
            // not the visitor, so they stay with the case. Both numbers are
            // taken off, one instruction per column.
            //
            // 🛑 WHAT THIS USED TO DO, so nobody puts it back.
            //    Until 11 October 2026 `visitedByID` could not be empty, and
            //    a "retain" instruction left the number in place. That was
            //    the worst of both: the number stayed, yet it pointed at an
            //    emptied account, so it told a safeguarding review nothing.
            //    And a visit somebody ELSE made, with the follow-up given to
            //    this person, was matched by nothing at all. Migration 208
            //    made the column emptiable (Salem874's decision).
            //    Before that again, the table had no instruction, and every
            //    erasure's sealed audit trail said "no column ties a row of
            //    this table to an account", which was untrue.
            //
            //    NOT DECIDED HERE: whether the NAME of whoever made a visit
            //    should be kept on safeguarding grounds. That is a question
            //    for the organisation's safeguarding lead. As built, it is
            //    not kept.
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
            ['table' => 'tblCareVisit',      'userCol' => 'visitedByID',          'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the pastoral visit and its notes are kept with the case; who made the visit is detached'],
            ['table' => 'tblCareVisit',      'userCol' => 'followUpAssignedToID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the pastoral visit and its notes are kept with the case; who its follow-up was given to is detached'],
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
            // tblAnnouncements/tblEvents/tblRecording above. (`updatedByID`
            // used to "ride along" in this instruction's own list; see the
            // note just above the two lines below for why it no longer does.)
            // RESIDUAL RISK (documented, not mechanically solvable): an admin
            // may have typed personal data — a name, an email address — into
            // a FILTER VALUE inside the JSON definition (e.g. `donorName eq
            // "Jane Smith"`). That is admin-authored free text of the same
            // class as tblTasks.description, which existing erasure doesn't
            // scrub either. Report OUTPUT itself is never stored anywhere, so
            // there is no separate "results" table to catalogue.
            //
            // Two instructions since 11 October 2026 (#492). The single
            // one this replaced matched on `createdByID` and emptied
            // `updatedByID` only on those rows, so a report somebody else
            // created and this person last changed kept their number.
            ['table' => 'tblReportDefinitions', 'userCol' => 'createdByID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'saved report definitions retained (metadata, not personal data); authorship attribution detached'],
            ['table' => 'tblReportDefinitions', 'userCol' => 'updatedByID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'saved report definitions retained (metadata, not personal data); who last changed it is detached'],
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

            // 🔁 THE SECOND "WHO DID IT" COLUMN (#492; built 11 October 2026
            //    on Salem874's decision for every such column: keep the
            //    record, clear who did it).
            //
            //    An instruction matches rows on ONE column. Each table below
            //    already had an instruction, on a different column, so this
            //    one was only ever reached when the same row happened to
            //    match there too. A gift somebody else gave and this person
            //    RECORDED kept their number; so did an event they last
            //    edited, a prayer request given to them, a booking they
            //    entered. One instruction per column puts that right. (For
            //    tables with no hand-written instruction the same is done by
            //    fromPersonalDataCatalogue(), from the columns the written
            //    list names.)
            //
            //    Every one is a column that says what the person DID to a
            //    record that is not theirs, and every one may be empty.
            //
            //    Six columns were left out when this block was written, and
            //    pinned by name in the test, because nothing was to be
            //    guessed. Salem874 decided all six on 12 October 2026:
            //      - five could not be emptied because the database forbade
            //        it. Migration 208 lifted that, and they are cleared like
            //        the rest: who gave somebody a licence seat, who asked
            //        for an asset loan, who processed somebody's leaving, who
            //        made a room booking, who asked for a rota swap;
            //      - tblInvitation.acceptedByID is the invited person's own
            //        account, so an accepted invitation is DELETED with them
            //        (the written list says "erase"; see LINK_COLUMNS).
            //
            //    What is still NOT reached, and pinned in the test:
            //    `tblWorkflowSteps.assigneeValue`. It is text, and it holds
            //    an account number only when a step of an approval route is
            //    given to one named person. It does not say who DID
            //    something; it says who MUST. Emptying it would leave a step
            //    nobody can approve, so what the step should become is a
            //    decision, and none has been taken.
            ['table' => 'tblAnnouncements',           'userCol' => 'updatedByID',      'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the announcement is the organisation\'s and stays; who last changed it is detached'],
            ['table' => 'tblEvents',                  'userCol' => 'updatedByID',      'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the event is the organisation\'s and stays; who last changed it is detached'],
            ['table' => 'tblEvents',                  'userCol' => 'moderatedByID',    'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the event is the organisation\'s and stays; who approved or declined it is detached'],
            // Two the database does not link to the account table, so nothing
            // found them until the independent check of 12 October 2026. An
            // event somebody sent in while signed in carries no typed name or
            // address (those are only stored for somebody NOT signed in), so
            // the number is all there is to clear. Nothing writes
            // `statusChangedByID` today; it is cleared for the day something
            // does.
            ['table' => 'tblEvents',                  'userCol' => 'submittedByID',    'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the event is kept; who sent it in for the calendar is detached'],
            ['table' => 'tblEvents',                  'userCol' => 'statusChangedByID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the event is the organisation\'s and stays; who last changed its status is detached'],
            ['table' => 'tblEventAttendance',         'userCol' => 'markedByID',       'action' => 'anonymise', 'nullCols' => [], 'reason' => 'somebody else\'s attendance record stays; who marked it is detached'],
            ['table' => 'tblGivingEntry',             'userCol' => 'recordedByID',     'action' => 'anonymise', 'nullCols' => [], 'reason' => 'somebody else\'s gift is a financial record and stays, with its donor, amount and date; who entered it is detached'],
            ['table' => 'tblPrayerRequests',          'userCol' => 'moderatorID',      'action' => 'anonymise', 'nullCols' => [], 'reason' => 'somebody else\'s prayer request stays; who moderated it is detached'],
            ['table' => 'tblPrayerRequests',          'userCol' => 'assignedToUserID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'somebody else\'s prayer request stays; who it was given to is detached, so it is unassigned'],
            ['table' => 'tblPathwayEnrolments',       'userCol' => 'enrolledByID',     'action' => 'anonymise', 'nullCols' => [], 'reason' => 'somebody else\'s enrolment stays; who enrolled them is detached'],
            ['table' => 'tblPathwayProgress',         'userCol' => 'markedByID',       'action' => 'anonymise', 'nullCols' => [], 'reason' => 'somebody else\'s progress stays; who marked the step is detached'],
            ['table' => 'tblPathwayProgress',         'userCol' => 'revokedByID',      'action' => 'anonymise', 'nullCols' => [], 'reason' => 'somebody else\'s progress stays; who revoked the step is detached'],
            ['table' => 'tblAssetLoans',              'userCol' => 'approvedByID',     'action' => 'anonymise', 'nullCols' => [], 'reason' => 'loan history retained for the asset\'s custody chain; who approved the loan is detached'],
            ['table' => 'tblAssetLoans',              'userCol' => 'requestedByID',    'action' => 'anonymise', 'nullCols' => [], 'reason' => 'loan history retained for the asset\'s custody chain; who asked for the loan is detached'],
            ['table' => 'tblAssetLicenseAssignments', 'userCol' => 'releasedByID',     'action' => 'anonymise', 'nullCols' => [], 'reason' => 'seat-assignment history retained for licence compliance; who released the seat is detached'],
            ['table' => 'tblAssetLicenseAssignments', 'userCol' => 'linkedByID',       'action' => 'anonymise', 'nullCols' => [], 'reason' => 'seat-assignment history retained for licence compliance; who gave out the seat is detached'],
            ['table' => 'tblVenueBookings',           'userCol' => 'createdByID',      'action' => 'anonymise', 'nullCols' => [], 'reason' => 'booking history retained for the venue hire schedule; who entered the booking is detached'],
            // A child's event registration. The first line is what the
            // derived route already did (the registration holds a child's
            // medical details and is deleted when whoever submitted it is
            // erased; see DELETE_ON_ACTOR_COLUMN). It is written out by hand
            // now for one reason: the table has a SECOND person column, who
            // reviewed the registration, and the derived route picks the
            // column to delete on by its place in LINK_COLUMNS. With both
            // listed it would have picked `reviewedByID`, and deleted a
            // child's registration because of who REVIEWED it. Hand-written,
            // each column can only do what its own line says.
            ['table' => 'tblEventRegistrations',      'userCol' => 'submittedByUserID', 'action' => 'delete', 'reason' => 'a child\'s registration, with their medical details, submitted by this person'],
            ['table' => 'tblEventRegistrations',      'userCol' => 'reviewedByID',     'action' => 'anonymise', 'nullCols' => [], 'reason' => 'somebody else\'s registration stays; who reviewed it is detached'],

            // 🤝 OTHER PEOPLE'S RECORDS, AND THE ORGANISATION'S WORK
            //    (roll-out package A2a; Salem874's decision, 11 October 2026).
            //
            //    Until then all five of these tables were DELETED, on the
            //    column naming the worker. Erasing a follow-up worker removed
            //    every visitor and every salvation card they had been given,
            //    every task and rota slot assigned to them, and every swap
            //    request sent to them. None of those is theirs.
            //
            //    One instruction per column, because an instruction matches
            //    rows on ONE column: a task somebody else created and this
            //    person was given would otherwise be missed.
            //
            // A visitor record is about the VISITOR. Three columns can name
            // the erased person, and they mean three different things:
            //   convertedUserID  the visitor later became a member, and this
            //                    IS that member. The record is their own
            //                    (name, email, telephone, notes): deleted.
            //                    Until now nothing found it at all.
            //   assignedToID     who follows the visitor up: emptied.
            //   createdByID      who typed the record in: emptied.
            // The delete comes first, so the other two never look at a row
            // that is about to go. What this cannot do: a visitor record
            // about the erased person that was never tied to their account
            // cannot be found.
            ['table' => 'tblVisitor',          'userCol' => 'convertedUserID', 'action' => 'delete', 'reason' => 'this person\'s own visitor record, from before they had an account'],
            ['table' => 'tblVisitor',          'userCol' => 'assignedToID',    'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the visitor\'s record is about the visitor and stays; who was following them up is detached, so the visitor is unassigned'],
            ['table' => 'tblVisitor',          'userCol' => 'createdByID',     'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the visitor\'s record is about the visitor and stays; who entered it is detached'],
            ['table' => 'tblTasks',            'userCol' => 'assignedToID',    'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the task is the organisation\'s work and stays; who it was given to is detached, so it is unassigned'],
            ['table' => 'tblTasks',            'userCol' => 'createdByID',     'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the task is the organisation\'s work and stays; who created it is detached'],
            ['table' => 'tblRotaSlot',         'userCol' => 'assignedToID',    'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the rota slot is the organisation\'s and stays; who was filling it is detached, so the slot is unfilled'],
            ['table' => 'tblRotaSlot',         'userCol' => 'createdByID',     'action' => 'anonymise', 'nullCols' => [], 'reason' => 'the rota slot is the organisation\'s and stays; who created it is detached'],
            // A swap request belongs to whoever ASKED for the swap. One
            // aimed at the erased person is kept, with their number taken
            // off it. If it was still waiting for their answer it is
            // CANCELLED, because nobody is left to answer it and a request
            // left waiting would hold the asker's slot in limbo. One already
            // accepted or declined keeps its status: that is history.
            //
            // 🛑 THE CANCEL IS NOT A NICETY. The page that answers a swap
            //    (web/_apps/rota/swap-respond.php) treats a waiting request
            //    with NO target as an OPEN swap that anybody may accept. A
            //    request aimed at one person, left waiting with its target
            //    emptied, would become exactly that. Cancelling it is what
            //    stops an erasure quietly turning "could YOU cover me?" into
            //    "could anybody?".
            //
            // A request the erased person MADE (`requestedByID`) is handled
            // the same way since 12 October 2026 (Salem874's decision;
            // migration 208 lets the column be empty): the request is kept,
            // who asked is detached, and one still waiting is cancelled,
            // because nobody is left who wanted the swap.
            // What this does NOT do: `requestMessage` is the asker's own
            // words and is kept as written.
            ['table' => 'tblRotaSwapRequest',  'userCol' => 'targetUserID',    'action' => 'anonymise', 'nullCols' => [],
             'sqlSets' => ["`status` = IF(`status` = 'pending', 'cancelled', `status`)"],
             'reason' => 'the swap request belongs to whoever asked for it and stays; the person it was aimed at is detached, and a request still waiting for their answer is cancelled'],
            ['table' => 'tblRotaSwapRequest',  'userCol' => 'requestedByID',   'action' => 'anonymise', 'nullCols' => [],
             'sqlSets' => ["`status` = IF(`status` = 'pending', 'cancelled', `status`)"],
             'reason' => 'the record that a swap was asked for stays; who asked is detached, and a request still waiting is cancelled because nobody is left who wanted it'],

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
            // to gate, so leaving its value untouched here is harmless.
            // (This used to add that "Delete my account" reset it to
            // 'private' through a list of its own. That page no longer
            // changes the account at all; see fileRequest().)
            //
            // 🖼️ `displayBio` added on 11 October 2026 (roll-out package A2a):
            //    the biography a member writes about themselves for the
            //    directory was left on the emptied account. `avatarPath` and
            //    `displayPhoto` are NOT in this list on purpose: each may
            //    name an image file, and eraseProfileImages() empties them
            //    only once that file is dealt with. Emptying them here would
            //    lose the only record of where the file is.
            //
            // 🔑 `isAdmin`, `isRootAdmin` and `totpEnabled` are switched off
            //    since the same day. "Delete my account" used to empty the
            //    account by its own short list, which did switch them off;
            //    that page now files an ordinary erasure request, so this is
            //    the only place left that can. An account that names nobody
            //    should not still be counted as an administrator.
            //
            // 📅 `calendarToken` and `notifyPrefs` are cleared since the same
            //    day. The token is the key inside the person's private
            //    calendar-feed address, and the feed looks an account up by
            //    it without asking whether the account is still active
            //    (Portal\Core\Ical). Left in place, that address went on
            //    answering after the erasure. `notifyPrefs` is the person's
            //    own choices about what they are sent.
            ['table' => 'tblUsers', 'userCol' => 'userID', 'action' => 'anonymise',
             'nullCols' => ['emailAddress','phoneNumber','displayAddress','displayPhone','displayBio','latitude','longitude','what3words','locale','totpSecret','calendarToken','notifyPrefs'],
             'overrides' => ['fullName' => self::TOMBSTONE_NAME, 'isActive' => 0, 'isAdmin' => 0, 'isRootAdmin' => 0, 'totpEnabled' => 0],
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
     * THE ORDER MATTERS. The first seven names mean "this record is ABOUT the
     * person". Every name after them means "the person ACTED on this record,
     * or is merely named on it" (those are repeated in ACTOR_COLUMNS). When a
     * table has both kinds, the derived route matches on the first name it
     * finds, so the person the record concerns wins. Add an "about" name
     * after `leaderID`, and an "acted on it" name at the end.
     *
     * 🛑 `assignedToID` AND `targetUserID` USED TO BE IN THE FIRST GROUP, and
     *    that is how an erasure came to delete other people's records. A
     *    visitor "assigned to" a follow-up worker is not about the worker; nor
     *    is a task, a rota slot, a salvation card, or a swap request aimed at
     *    them. Both names moved to the second group on 11 October 2026
     *    (Salem874's decision), so a "delete" matched on either is now refused
     *    by construction. Do not move them back.
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
        'convertedUserID', 'leaderID',
        // `acceptedByID` moved UP here on 12 October 2026 (Salem874's
        // decision). It is on one table, tblInvitation, and it is not "who
        // did something to somebody else's record": it is the account of the
        // person the invitation was FOR, and that row holds their own email
        // address. An accepted invitation is deleted with its person.
        'acceptedByID',
        // `giverID` (roll-out package A2b): who a counted envelope was FROM.
        // The envelope is about the giver, not something they did to
        // somebody else's record. It is here, in the first group, so that it
        // is emptied only where it names this person.
        'giverID',
        // The person ACTED on this, or is merely named on it.
        'assignedToID', 'targetUserID',
        'uploadedByUserID', 'approverID', 'reviewedByID', 'startedByID',
        'submittedByUserID', 'createdByID', 'updatedByID',
        'parentUserID', 'counterpartyUserID', 'recordedByID',
        'approvedByID', 'assignedByID',
        'addedByID', 'markedByID', 'openedByID', 'closedByID',
        'moderatedByID', 'moderatorID', 'presenterID', 'scannedByID',
        'bookedByID', 'requestedByID', 'revokedByID',
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
        // Eleven more (roll-out package A2a, 11 October 2026). Each is on a
        // table that had no instruction at all, so the person's number stayed
        // on it for ever: who acted on an approval step, wrote a follow-up
        // note, prepared a service plan, posted a project update, sent a
        // message to an event's people, operated the song-licence log,
        // imported a bank statement, matched a bank line, entered an expense
        // payment, and checked a child in or out.
        'actedByID', 'contactedByID', 'preparedByID', 'postedByID', 'sentByID',
        'operatorID', 'importedByID', 'matchedByID', 'paidByID',
        'checkedInByID', 'checkedOutByID',
        // Two more, found by the test that puts the person's number in every
        // column the database links to an account: who carried out a piece
        // of maintenance, and who a prayer request was given to.
        'performedByUserID', 'assignedToUserID',
        // Four more, found by the independent check of 12 October 2026. The
        // database does not link any of them to the account table, and none
        // was on this list, so neither the eraser nor its test could see
        // them: who sent an event in for the calendar, who last changed an
        // event's status, who marked an error as dealt with, and who ran a
        // database upgrade. The real-database test now also looks for person
        // columns by the SHAPE OF THEIR NAME, so a column the database does
        // not link can no longer hide.
        'submittedByID', 'statusChangedByID', 'resolvedByID', 'executedByID',
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
        // Being GIVEN a record to work on, or being the person a request is
        // aimed at, is not the record being about you. These two were classed
        // as "about the person" until 11 October 2026; see LINK_COLUMNS.
        'assignedToID', 'targetUserID',
        'uploadedByUserID', 'approverID', 'reviewedByID', 'startedByID',
        'submittedByUserID', 'createdByID', 'updatedByID',
        'parentUserID', 'counterpartyUserID', 'recordedByID',
        'approvedByID', 'assignedByID',
        // A further twenty-one found by Codex on 11 September 2026. Every one
        // of them names somebody who DID something to a record rather than
        // somebody the record is about.
        'addedByID', 'markedByID', 'openedByID', 'closedByID',
        'moderatedByID', 'moderatorID', 'presenterID', 'scannedByID',
        'bookedByID', 'requestedByID', 'revokedByID',
        'counter1ID', 'counter2ID', 'offboardedByID', 'rehiredByID',
        'grantedByID', 'enrolledByID', 'linkedByID', 'releasedByID',
        'processedByID',
        // The five #535 added to LINK_COLUMNS. All five say what somebody DID.
        'viewerID', 'actorUserID', 'uploadedByID', 'triggeredByID', 'decidedByID',
        // And the two pastoral-visit names: making a visit, and being given
        // its follow-up, are things a person DID or was asked to do.
        'visitedByID', 'followUpAssignedToID',
        // The eleven added by roll-out package A2a. Every one says what
        // somebody DID to a record that is not theirs.
        'actedByID', 'contactedByID', 'preparedByID', 'postedByID', 'sentByID',
        'operatorID', 'importedByID', 'matchedByID', 'paidByID',
        'checkedInByID', 'checkedOutByID',
        'performedByUserID', 'assignedToUserID',
        'submittedByID', 'statusChangedByID', 'resolvedByID', 'executedByID',
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
     * The same goes for `processedByID` on OTHER people's requests, which
     * names the erased person when they were the administrator who handled
     * one. closeRequest() empties that too, in the same last step, rather
     * than an instruction of its own: an instruction on this table would
     * break the rule above, and would show the person a second, confusing
     * line for this table under "what we hold about you".
     *
     * @var string[]
     */
    public const FINAL_STEP_TABLES = ['tblErasureRequest'];

    /**
     * ⏱️ How long a request must have shown no sign of a run before it may be
     *    put back from "processing" (putBackStuckRequest()).
     *
     * A quarter of an hour. A run refreshes its sign of life at least once a
     * minute (stillGoing()), and a web host kills a page long before fifteen
     * minutes, so a request this quiet has no run behind it.
     */
    public const STUCK_AFTER_MINUTES = 15;

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
     * ONE INSTRUCTION PER PERSON COLUMN (#492, 11 October 2026). An
     * instruction matches rows on one column. The derived route used to build
     * ONE instruction per table, matched on the first person column the list
     * named, and emptied the others only on the rows that matched there. So a
     * room-hire poster somebody else created and this person last changed
     * kept their number. Now:
     *
     *   unlink   one "empty it" instruction for EVERY person column listed;
     *   erase    delete on the first, then "empty it" for every OTHER listed
     *            column that says the person acted on the record (their own
     *            rows go; rows they merely worked on stay);
     *   retain   kept on the first, then "empty it" for every other listed
     *            "acted on it" column. The record that must be kept is not
     *            touched: only the number of whoever HANDLED it goes.
     *
     * So for a table handled here, naming a person column in the written
     * list is all it takes for that column to be dealt with.
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

            // Which columns tie a row to a person? Every name on the one
            // shared list that this table has, in that list's order. The
            // FIRST is the one a delete or a "keep" matches on: "about the
            // person" names come first on the list, so they win over "acted
            // on it" names.
            $links = array_values(array_intersect(self::LINK_COLUMNS, $columns));
            $link  = $links[0] ?? '';

            // The "acted on it" columns beyond the first: each gets its own
            // "empty it" instruction after a delete or a keep (see this
            // method's docblock). Built here once, used by both branches.
            $alsoActedOn = [];
            foreach (array_slice($links, 1) as $other) {
                if (in_array($other, self::ACTOR_COLUMNS, true) === true) {
                    $alsoActedOn[] = [
                        'table'    => $table,
                        'userCol'  => $other,
                        'action'   => 'anonymise',
                        'nullCols' => [],
                        'reason'   => 'somebody else\'s record of this kind is kept as it is; only who handled it (`'
                            . $other . '`) is detached',
                    ];
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
                foreach ($alsoActedOn as $entry) {
                    $entries[] = $entry;
                }
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
                foreach ($alsoActedOn as $entry) {
                    $entries[] = $entry;
                }
                continue;
            }

            // 'unlink' - keep what was made, remove who made it. One
            //  instruction for EVERY person column the list names, each
            //  matching on its own column.
            //
            //  (This used to be one instruction, matched on the first column
            //  and emptying the rest "only on those rows". The comment here
            //  said that stopped a second column still naming the person. It
            //  did the opposite for any row that matched the second column
            //  and not the first: #492.)
            foreach ($links as $column) {
                $entries[] = [
                    'table'    => $table,
                    'userCol'  => $column,
                    'action'   => 'anonymise',
                    'nullCols' => [],
                    'reason'   => $reason,
                ];
            }
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
     *   2b. The giving-records step (keepGivingRecords()). It copies the
     *      donor's name and address to where they are kept for tax reasons,
     *      so it MUST run before the walk empties the account, and it too
     *      stops everything if it fails.
     *   3. Every instruction in catalogue(), then the dedicated steps
     *      (giving statements, the email send log, the person's photo). A
     *      step that fails is written down and the rest still run.
     *   4. LAST, the request itself: closed if no step failed, put back to
     *      `pending_review` untouched if any did.
     *
     * While it works it refreshes the request's "a run is going" moment about
     * once a minute (stillGoing()), which is what lets a request whose run
     * was killed by the web host be told apart from one still running.
     *
     * It used to return true or false for "did anything change", and its only
     * caller ignored the answer and reported success whatever had happened.
     *
     * @param int      $requestId     The erasure request being carried out.
     * @param int      $userId        The account to erase.
     * @param int|null $processedByID The administrator who pressed the button.
     *   Not recorded on the request when it is the person being erased.
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

        // 🧾 The giving records an organisation keeps WITH the donor's name
        //    for tax reasons (roll-out package A2b). BEFORE the catalogue,
        //    because it reads the name and address and the catalogue's last
        //    instruction empties them. Outside the try below for the same
        //    reason as the Demo Data step: if it fails it puts the request
        //    back and throws its own message, and NOTHING after it runs. A
        //    failed step normally lets a run carry on; here that would empty
        //    the account and destroy the very name this step exists to keep.
        if (self::keepGivingRecords($requestId, $userId) === true) {
            $changed = true;
        }

        $steps  = 2; // the Demo Data step and the giving-records step; each succeeded or we would not be here
        $failed = 0;
        $status = '';

        try {
            // Each step runs inside its own small transaction (see
            // asOneStep()), so what a step changed and the audit line that
            // says so are saved together or not at all.
            $outcomes = [];
            $lastBeat = time();
            foreach (self::catalogue() as $entry) {
                $outcomes[] = self::asOneStep(
                    $db,
                    static fn (): string => self::processEntry($db, $requestId, $userId, $entry)
                );
                $lastBeat = self::stillGoing($db, $requestId, $lastBeat);
            }
            // 🧾 Giving statements (#440 Q5): the rendered PDFs, then the
            //    address each was mailed to. Two steps, two audit lines.
            $giving = self::asOneStep($db, static fn (): array => self::eraseGivingStatementFiles($requestId, $userId));
            foreach ($giving as $outcome) {
                $outcomes[] = $outcome;
            }
            // 📧 #234 — the address in the email send log.
            $outcomes[] = self::asOneStep($db, static fn (): string => self::eraseEmailLogRecipients($requestId, $erasedEmail));
            // 🖼️ The person's photo: the image file, and the two columns on
            //    the account that say where it is.
            $outcomes[] = self::asOneStep($db, static fn (): string => self::eraseProfileImages($requestId, $userId));
            // ⏳ Giving records that were ALREADY past their keep-until date
            //    when this run met them. Cleared here, after the walk,
            //    because the routine that clears refuses until the account
            //    has really been emptied, and one instruction of the walk is
            //    what empties it. (They were first cleared inside the
            //    giving-records step, before the account was emptied. That
            //    is exactly the order the guard now forbids.)
            foreach (self::$dueAtErasure as $keptDonorId) {
                $outcomes[] = self::clearGivingRecordDueAtErasure($db, $requestId, $keptDonorId);
            }
            self::$dueAtErasure = [];

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
     * still open (somebody pressed the button twice) is closed with this one,
     * with a line in its own audit trail saying what did the work: left open
     * it would point at nobody and could never be run.
     *
     * "Still open" includes one stuck at `processing`. It did not until
     * 11 October 2026: such a request had its account number cleared and was
     * then left at "processing" for ever, with no line to say why.
     *
     * A request from the same account at ANOTHER organisation is closed too
     * (the account is one account, emptied everywhere), but its line is
     * worded differently. It used to send that organisation's administrator
     * to "that request's report", which they cannot open: each organisation
     * sees only its own requests.
     *
     * Last, wherever the erased person was the ADMINISTRATOR who handled
     * somebody else's request, their number is taken off it
     * (`processedByID`). The request, its outcome and its dates all stay.
     * For the same reason the administrator is not recorded on this request
     * when they are the person being erased.
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
            // Which organisation this request belongs to, to word the lines
            // on other requests correctly.
            $stmt = $db->prepare('SELECT siteID FROM tblErasureRequest WHERE requestID = ? LIMIT 1');
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $ownSite = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
            $stmt->close();

            // Other requests from this account that are still open.
            $others = [];
            $stmt = $db->prepare(
                "SELECT requestID, siteID FROM tblErasureRequest WHERE userID = ? AND requestID <> ? "
                . "AND status IN ('pending_confirmation', 'pending_review', 'processing') ORDER BY requestID"
            );
            $stmt->bind_param('ii', $userId, $requestId);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_row()) {
                $others[(int) $r[0]] = (int) $r[1];
            }
            $stmt->close();

            // Requests this person HANDLED as an administrator. Done before
            // the status update below, which writes the administrator of
            // THIS run onto this request.
            $stmt = $db->prepare('UPDATE tblErasureRequest SET processedByID = NULL WHERE processedByID = ?');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $handled = (int) $stmt->affected_rows;
            $stmt->close();

            self::logAudit(
                $db,
                $requestId,
                'completed',
                'tblErasureRequest',
                null,
                'All ' . $steps . ' steps ran and none failed. The request is closed, and the account number, '
                . 'name, email address and notes are cleared from it'
                . ($others === [] ? '.' : ' and from ' . count($others) . ' other open request(s) from the same account.')
                . ($handled === 0 ? '' : ' On ' . $handled . ' other request(s) that this person handled as an '
                    . 'administrator, who handled it is cleared; those requests are otherwise unchanged.')
            );
            foreach ($others as $otherId => $otherSite) {
                self::logAudit(
                    $db,
                    $otherId,
                    'completed',
                    'tblErasureRequest',
                    null,
                    $otherSite === $ownSite
                        ? 'Closed together with erasure request #' . $requestId . ', which erased the same account. '
                            . 'The steps are listed on that request\'s report.'
                        // Another organisation's administrator reads this
                        // line. They cannot open the report of a request
                        // that is not their organisation's, so they are not
                        // sent to it, and are not given its number.
                        : 'Closed: this account was erased under a request handled by another organisation on this '
                            . 'installation. All ' . $steps . ' steps ran and none failed, so nothing more needs doing '
                            . 'here. The list of steps is kept by the organisation that handled it.'
                );
            }

            // Status first, while `userID` can still find the open ones.
            // The administrator is not written down when they ARE the person
            // being erased: that would put their number straight back.
            $recordedAdmin = $processedByID === $userId ? null : $processedByID;
            $stmt = $db->prepare(
                "UPDATE tblErasureRequest SET status = 'completed', processedAt = NOW(), processedByID = ? "
                . "WHERE requestID = ? OR (userID = ? AND status IN ('pending_confirmation', 'pending_review', 'processing'))"
            );
            $stmt->bind_param('iii', $recordedAdmin, $requestId, $userId);
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

    /**
     * 💓 Show that this run is still going, about once a minute.
     *
     * Refreshes `tblErasureRequest.claimedAt`. That moment is what
     * putBackStuckRequest() reads to tell a run the web host killed from one
     * that is still working.
     *
     * Counted in exact seconds (PHP's time()), and written as a moment in UTC
     * (UTC_TIMESTAMP()), so the two nights a year the clocks change cannot
     * move it. A time on the server's own clock would jump an hour on the
     * spring night and make a live run look fifteen minutes dead.
     *
     * A failure here is swallowed on purpose. This is only a sign of life: if
     * it cannot be written, the worst outcome is that the request can be put
     * back a little sooner, and every step is safe to repeat. Stopping an
     * erasure half way because its heartbeat failed would be far worse. (A
     * database not yet upgraded to migration 208 has no such column, and the
     * erasure page refuses to start a run there in the first place.)
     *
     * @param int $lastBeat When the last sign of life was written (time()).
     *
     * @return int When the most recent one was written.
     */
    private static function stillGoing(\mysqli $db, int $requestId, int $lastBeat): int
    {
        if (time() - $lastBeat < 60) {
            return $lastBeat;
        }
        try {
            $stmt = $db->prepare("UPDATE tblErasureRequest SET claimedAt = UTC_TIMESTAMP() WHERE requestID = ? AND status = 'processing'");
            if ($stmt !== false) {
                $stmt->bind_param('i', $requestId);
                $stmt->execute();
                $stmt->close();
            }
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] GdprEraser: could not refresh the sign of life for erasure #' . $requestId . ': ' . $e->getMessage());
        }

        return time();
    }

    /**
     * ↩️ Put back a request that is stuck at "processing" because its run
     *    stopped without finishing (the web host killed the page, say).
     *
     * Until 11 October 2026 nothing in the portal could do this. The erasure
     * page only runs a request that is at "pending review", so a stuck one
     * stayed stuck until somebody edited the database by hand.
     *
     * It is put back ONLY when all of this is true, checked by the database
     * in one statement so that two administrators cannot both succeed:
     *   - it belongs to the organisation given (another organisation's
     *     request is "not found", never "not yours");
     *   - it is at "processing";
     *   - it has shown no sign of a run for STUCK_AFTER_MINUTES. An empty
     *     `claimedAt` counts as "no run": that is every request that was
     *     already stuck before this column existed.
     *
     * Both sides of that comparison are moments in UTC; see stillGoing() for
     * why not the server's clock.
     *
     * Putting it back and the audit line that says so are saved together or
     * not at all. Nothing is erased or un-erased here: every step of a run is
     * safe to repeat, so the next run simply does whatever is left.
     *
     * What this cannot do: prove that no run is going. A run that went a
     * full quarter of an hour without finishing one step would look dead. If
     * that ever happened and the request were run again, two runs would do
     * the same repeatable steps; nothing would be lost, but two closing lines
     * would be written.
     *
     * @return string 'put-back' when it was put back; otherwise why not:
     *   'not-found', 'not-processing' or 'still-going'.
     *
     * @throws \Throwable If the database refuses. Nothing is then changed.
     */
    public static function putBackStuckRequest(int $requestId, int $siteId): string
    {
        $db = App::db();

        $moved = self::asOneStep($db, static function () use ($db, $requestId, $siteId): bool {
            $stmt = $db->prepare(
                "UPDATE tblErasureRequest SET status = 'pending_review', claimedAt = NULL "
                . "WHERE requestID = ? AND siteID = ? AND status = 'processing' "
                . 'AND (claimedAt IS NULL OR claimedAt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL '
                . self::STUCK_AFTER_MINUTES . ' MINUTE))'
            );
            if ($stmt === false) {
                throw new \RuntimeException('the request could not be put back: ' . $db->error);
            }
            $stmt->bind_param('ii', $requestId, $siteId);
            $stmt->execute();
            $moved = (int) $stmt->affected_rows === 1;
            $stmt->close();

            if ($moved === true) {
                self::logAudit(
                    $db,
                    $requestId,
                    'put-back',
                    'tblErasureRequest',
                    null,
                    'Put back to pending review by an administrator. It had been at "processing" with no sign of a '
                    . 'run for more than ' . self::STUCK_AFTER_MINUTES . ' minutes, which means its run stopped before '
                    . 'it finished. Nothing was erased or undone by this. Every step is safe to repeat, so running the '
                    . 'request again does whatever is left.'
                );
            }

            return $moved;
        });
        if ($moved === true) {
            return 'put-back';
        }

        // It was not put back. Say why, from the request as it really is.
        $stmt = $db->prepare('SELECT status FROM tblErasureRequest WHERE requestID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            throw new \RuntimeException('the request could not be read: ' . $db->error);
        }
        $stmt->bind_param('ii', $requestId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        if ($row === null) {
            return 'not-found';
        }

        return (string) $row[0] === 'processing' ? 'still-going' : 'not-processing';
    }

    /**
     * 📨 File an erasure request for a signed-in person.
     *
     * THE ONE WAY A REQUEST IS FILED. Two pages offer it - "Request data
     * erasure" (/account/erasure-request) and "Delete my account"
     * (/account/delete/confirm) - and both call this, so they cannot drift
     * apart.
     *
     * 🛑 "Delete my account" used to be a SECOND way to empty an account. It
     *    ran its own short list of statements and never touched this class,
     *    so it was reviewed by nobody, wrote no audit trail, and left behind
     *    everything its list did not name. Salem874 decided on 11 October
     *    2026 that there is one route only: that page now files a request
     *    here, like any other, and an administrator's run of the request
     *    (execute()) is the only thing that ever empties an account.
     *
     * HOW A REQUEST GETS CONFIRMED. Before an administrator can run a
     * request, somebody has to have confirmed that it really is the
     * person's. There are three ways, and only three:
     *
     *   1. $alreadyConfirmed = false (the "Request data erasure" page). The
     *      request starts at "pending confirmation" and the person is
     *      emailed a link; clicking it (/account/erasure-confirm) moves the
     *      request to "pending review".
     *   2. $alreadyConfirmed = true ("Delete my account"). On that page the
     *      person is signed in and has just typed DELETE MY ACCOUNT, and
     *      that IS the confirmation (Salem874, 11 October 2026). The request
     *      starts at "pending review", with the moment it was confirmed, and
     *      NO email is sent: there is nothing left to confirm. Pass true
     *      only from a page that has itself checked such a typed phrase.
     *   3. confirmOnBehalf(): an administrator, for a request stuck at way 1
     *      because the email never arrived.
     *
     * What this cannot do: with way 1, if the email cannot be sent the
     * request waits until an administrator confirms it (way 3); `mailed`
     * says which happened so the page can tell the person the truth. It
     * also does not stop somebody filing twice; closeRequest() closes any
     * extra ones when the first is carried out.
     *
     * @param bool $alreadyConfirmed See "HOW A REQUEST GETS CONFIRMED".
     *
     * @return array{requestID: int, email: string, mailed: bool, confirmed: bool}
     *   `email` is the address on the account now. `mailed`: the
     *   confirmation link was sent to it (always false when `confirmed`).
     *
     * @throws \RuntimeException When the account cannot be found or the
     *   request cannot be saved. Nothing is then filed.
     */
    public static function fileRequest(int $siteId, int $userId, bool $alreadyConfirmed = false): array
    {
        $db = App::db();

        $stmt = $db->prepare('SELECT fullName, emailAddress FROM tblUsers WHERE userID = ? LIMIT 1');
        if ($stmt === false) {
            throw new \RuntimeException('the account could not be read: ' . $db->error);
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($user === null) {
            throw new \RuntimeException('there is no account #' . $userId . ' to file an erasure request for');
        }
        $email = (string) $user['emailAddress'];
        $name  = (string) ($user['fullName'] ?? '');
        // A confirmed request carries no token: there is no link to click,
        // and a token left on it would be a second, unneeded way in.
        $token  = $alreadyConfirmed === true ? null : bin2hex(random_bytes(32));
        $status = $alreadyConfirmed === true ? 'pending_review' : 'pending_confirmation';

        $stmt = $db->prepare(
            'INSERT INTO tblErasureRequest (siteID, userID, subjectEmail, subjectName, confirmToken, status, confirmedAt, dueBy) '
            . 'VALUES (?, ?, ?, ?, ?, ?, IF(? = 1, NOW(), NULL), DATE_ADD(NOW(), INTERVAL 1 MONTH))'
        );
        if ($stmt === false) {
            throw new \RuntimeException('the erasure request could not be saved: ' . $db->error);
        }
        $confirmedFlag = $alreadyConfirmed === true ? 1 : 0;
        $stmt->bind_param('iissssi', $siteId, $userId, $email, $name, $token, $status, $confirmedFlag);
        $stmt->execute();
        $requestId = (int) $stmt->insert_id;
        $stmt->close();

        if ($alreadyConfirmed === true) {
            return ['requestID' => $requestId, 'email' => $email, 'mailed' => false, 'confirmed' => true];
        }

        // The link goes back to the address the person is using right now.
        // No address is built in: with no host to go by, no link is sent.
        $mailed = false;
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host !== '') {
            $https      = (string) ($_SERVER['HTTPS'] ?? '');
            $scheme     = ($https !== '' && $https !== 'off') ? 'https' : 'http';
            $confirmUrl = $scheme . '://' . $host . '/account/erasure-confirm?token=' . $token;
            $body = '<p>Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
                . '<p>We received a request to erase your personal data on the portal.</p>'
                . '<p>If this was you, click below to confirm. The link expires in 24 hours.</p>'
                . '<p><a href="' . htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8') . '">Confirm erasure request</a></p>'
                . '<p>Nothing is erased until you confirm and an administrator has reviewed the request. '
                . 'Until then your account carries on working as it does now.</p>'
                . '<p>If you didn\'t make this request you can safely ignore this email.</p>';
            try {
                $mailed = Mailer::send($email, 'Confirm your data erasure request', $body) === true;
            } catch (\Throwable $e) {
                // The request is saved either way; the caller tells the
                // person that the email did not go.
                error_log('[WebMS-Intra] GdprEraser: the confirmation email for erasure #' . $requestId . ' could not be sent: ' . $e->getMessage());
            }
        }

        return ['requestID' => $requestId, 'email' => $email, 'mailed' => $mailed, 'confirmed' => false];
    }

    /**
     * 🔎 The person's own request at this organisation that is already on its
     *    way to an administrator, if there is one.
     *
     * "On its way" means confirmed and not yet finished: at "pending review"
     * or "processing". One still waiting for its emailed link does not count;
     * it may never be confirmed.
     *
     * For "Delete my account". A second press of its button (a double-click,
     * or the Back button) used to file a second request, or, worse, to answer
     * "Nothing was changed. Please try again." about a press that HAD filed
     * one: the form token is used up by the first press, so the second fails
     * its token test. The page asks this first and says "already recorded".
     *
     * Reads only. It is given the signed-in person's own number by the page.
     *
     * @return int|null The newest such request's number, or null.
     */
    public static function openRequestFor(int $siteId, int $userId): ?int
    {
        $stmt = App::db()->prepare(
            "SELECT requestID FROM tblErasureRequest WHERE siteID = ? AND userID = ? "
            . "AND status IN ('pending_review', 'processing') ORDER BY requestID DESC LIMIT 1"
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $siteId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();

        return $row === null ? null : (int) $row[0];
    }

    /**
     * ✅ Confirm a waiting request on the person's behalf (an administrator).
     *
     * A request filed from "Request data erasure" waits at "pending
     * confirmation" until the person clicks the link emailed to them. If that
     * email could not be sent, or never arrived, nobody could move the
     * request on: the page told the person to ask an administrator, and an
     * administrator had no way to help. This is that way.
     *
     * The email step exists to show the request really is the person's. An
     * administrator who confirms in its place is saying THEY have checked
     * that, and the audit trail records exactly that claim and who made it,
     * by account number. It records the claim; it cannot test it.
     *
     * Done only when, in one statement:
     *   - the request belongs to the organisation given (another
     *     organisation's is "not found", never "not yours");
     *   - it is still at "pending confirmation".
     * The change and its audit line are saved together or not at all. The
     * emailed link stops working, because the token is cleared.
     *
     * What this cannot do: carry the request out. It moves it to "pending
     * review", where an administrator still has to press Execute. And the
     * administrator's account number stays in this audit line even if that
     * administrator is later erased: the trail is sealed, so no line in it
     * is ever rewritten.
     *
     * @return string 'confirmed'; otherwise why not: 'not-found' or
     *   'not-waiting'.
     *
     * @throws \Throwable If the database refuses. Nothing is then changed.
     */
    public static function confirmOnBehalf(int $requestId, int $siteId, int $adminId): string
    {
        $db = App::db();

        $confirmed = self::asOneStep($db, static function () use ($db, $requestId, $siteId, $adminId): bool {
            $stmt = $db->prepare(
                "UPDATE tblErasureRequest SET status = 'pending_review', confirmedAt = NOW(), confirmToken = NULL "
                . "WHERE requestID = ? AND siteID = ? AND status = 'pending_confirmation'"
            );
            if ($stmt === false) {
                throw new \RuntimeException('the request could not be confirmed: ' . $db->error);
            }
            $stmt->bind_param('ii', $requestId, $siteId);
            $stmt->execute();
            $confirmed = (int) $stmt->affected_rows === 1;
            $stmt->close();

            if ($confirmed === true) {
                self::logAudit(
                    $db,
                    $requestId,
                    'confirmed-on-behalf',
                    'tblErasureRequest',
                    null,
                    'Confirmed on the person\'s behalf by the administrator with account #' . $adminId . ', who states that '
                    . 'they checked the person\'s identity and that the request is the person\'s own. The person did not '
                    . 'confirm it from the link emailed to them. Nothing has been erased by this: the request is now at '
                    . 'pending review, and an administrator still has to run it.'
                );
            }

            return $confirmed;
        });
        if ($confirmed === true) {
            return 'confirmed';
        }

        $stmt = $db->prepare('SELECT status FROM tblErasureRequest WHERE requestID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            throw new \RuntimeException('the request could not be read: ' . $db->error);
        }
        $stmt->bind_param('ii', $requestId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();

        return $row === null ? 'not-found' : 'not-waiting';
    }

    /**
     * 💬 What to tell somebody straight after their request has been filed.
     *
     * One place for the words, for both pages that file a request. Each of
     * the three messages says what has happened, what (if anything) the
     * person has to do, and that NOTHING IS ERASED YET - the old "Delete my
     * account" page emptied the account on the spot and signed the person
     * out, and somebody used to that must not think it has just happened
     * again.
     *
     * @param array{requestID: int, email: string, mailed: bool, confirmed: bool} $filed
     *   What fileRequest() returned.
     *
     * @return array{message: string, type: string} `type` is the colour of
     *   the message box: 'info' or 'warning'.
     */
    public static function whatHappensNext(array $filed): array
    {
        if ($filed['confirmed'] === true) {
            // "Delete my account": confirmed by the typed phrase, so there
            // is no email and nothing more for the person to do.
            return [
                'type'    => 'info',
                'message' => 'Your request is recorded, and nothing has been erased yet. You confirmed it by typing '
                    . 'the words, so there is no email to wait for and nothing more for you to do. An administrator '
                    . 'now reviews the request and carries it out, within one month. Until that happens your account '
                    . 'works exactly as it does now.',
            ];
        }
        if ($filed['mailed'] === true) {
            return [
                'type'    => 'info',
                'message' => 'Your request is recorded, and nothing has been erased yet. We have sent an email to '
                    . $filed['email'] . ': open it and click the link within 24 hours to confirm that the request is '
                    . 'yours. An administrator then reviews it and carries it out, within one month. Until that '
                    . 'happens your account works exactly as it does now.',
            ];
        }

        return [
            'type'    => 'warning',
            'message' => 'Your request is recorded, but the email asking you to confirm it could not be sent to '
                . $filed['email'] . ', so it is waiting. Nothing has been erased and your account works exactly as it '
                . 'does now. Please ask an administrator to confirm it for you: they can do that from their list of '
                . 'erasure requests, once they have checked that the request is yours. Tell them it is erasure '
                . 'request #' . $filed['requestID'] . '.',
        ];
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
            // Extra fixed pieces written in catalogue() (see the `sqlSets`
            // note there). Added exactly as written: never built from input.
            foreach ((array) ($entry['sqlSets'] ?? []) as $piece) {
                $sets[] = (string) $piece;
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
     * 🧍 THE ONE DEFINITION of "this account has been emptied by an erasure",
     *    as a piece of SQL about a row of `tblUsers`.
     *
     * Three things, all of them: the account is switched off, its name is the
     * made-up one, and its address is the made-up one. One instruction of
     * the table walk sets all three in one statement, so an account can never
     * show only some of them because a run stopped.
     *
     * THAT INSTRUCTION IS NOT THE LAST. It comes about half way through the
     * walk, and more of the walk and four further steps follow it. So an
     * account that shows all three HAS been emptied, but its request may
     * still be unfinished (a later step failed, or the run was stopped).
     * This comment used to say "the last instruction" and "run to the end".
     * That mistaken belief is where the fault described at "1b" in
     * keepGivingRecordsAtSite() came from.
     *
     * Everything that treats a donor as ERASED asks this, and nothing else:
     * the guard that clears a kept name, the tax claim file, the statements
     * list, and Portal\Core\Giving::isErasedDonor(). "A kept row exists" is
     * NOT the same question: the kept row is written near the start of an
     * erasure and the account is emptied about half way through it, and a
     * run can stop in between.
     *
     * FIXED TEXT: `$alias` is the name a query gives `tblUsers`, always
     * written in code.
     */
    public static function emptiedAccountSql(string $alias): string
    {
        return '(' . $alias . '.isActive = 0 AND ' . $alias . ".fullName = '" . self::TOMBSTONE_NAME . "' AND "
            . $alias . ".emailAddress LIKE '" . str_replace('{rid}', '%', self::TOMBSTONE_EMAIL) . "')";
    }

    /** Has this account been emptied by an erasure? See emptiedAccountSql(). False when it cannot be read. */
    public static function accountIsEmptied(\mysqli $db, int $userId): bool
    {
        try {
            $stmt = $db->prepare('SELECT 1 FROM tblUsers u WHERE u.userID = ? AND ' . self::emptiedAccountSql('u') . ' LIMIT 1');
            if ($stmt === false) {
                return false;
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $emptied = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();

            return $emptied;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Has any run of this request already CHANGED something: deleted a row,
     * emptied a column, deleted a file, or kept a donor's name?
     *
     * The erasure page asks before it CANCELS a request. Once this is true a
     * cancel cannot be made safe, because nothing a run does can be put
     * back: rows are deleted, and the gifts that lost their donor are not
     * listed anywhere. A run that stops part way leaves the person with
     * some of their records gone, and about half way through the walk the
     * account itself is emptied. The request has to be run again to its end
     * instead.
     *
     * It reads the request's own audit trail. Every step that changes
     * something writes its line in the same transaction as the change, so a
     * change with no line cannot exist (deleted FILES are the one exception:
     * see asOneStep()).
     *
     * WHAT WAS WRONG (found by the narrow check of 12 October 2026): the
     * first version of this, givingStepHasRun() below, asked only about the
     * giving records. A person with no giving records whose run had stopped
     * after deleting their organisation membership could be cancelled, and
     * was then a member no longer with a request that said nothing happened.
     *
     * @return bool True as well when the trail cannot be read: the cautious
     *   answer, which refuses the cancel.
     */
    public static function runHasChangedAnything(int $requestId): bool
    {
        return self::givingStepHasRun($requestId) === true
            || self::trailHasLine($requestId, "action IN ('delete', 'anonymise', 'partial')") === true;
    }

    /**
     * Has a run of this request already dealt with the person's giving
     * records (kept some, or taken the donor off some)? One part of
     * runHasChangedAnything(), kept apart because "retain" means "a name was
     * written to the kept-names table" only for these two labels; for every
     * other table it means nothing was touched.
     */
    public static function givingStepHasRun(int $requestId): bool
    {
        return self::trailHasLine($requestId, "tableName IN ('GivingRecords', 'CountEnvelopes') AND action IN ('retain', 'anonymise')");
    }

    /**
     * Does a request's audit trail hold a line that matches? True as well
     * when the trail cannot be read.
     *
     * FIXED TEXT: `$where` is written in code by the two callers above.
     */
    private static function trailHasLine(int $requestId, string $where): bool
    {
        try {
            $stmt = App::db()->prepare('SELECT 1 FROM tblErasureAudit WHERE requestID = ? AND ' . $where . ' LIMIT 1');
            if ($stmt === false) {
                // Cannot tell: say it has, so that the cancel is refused.
                return true;
            }
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $found = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();

            return $found;
        } catch (\Throwable $e) {
            // Cannot tell, for the same reason and with the same answer.
            error_log('[WebMS-Intra] GdprEraser: could not read the audit trail of erasure #' . $requestId . ': ' . $e->getMessage());

            return true;
        }
    }

    /**
     * The dates of the gifts THIS run took the donor off, organisation by
     * organisation, for the statement-file step that follows in the same run.
     * Null for an organisation means "an earlier run had already sorted the
     * gifts there, so it is not known". Emptied at the start of every run.
     *
     * @var array<int, string[]|null>
     */
    private static array $unkeptGiftDates = [];

    /**
     * The organisations whose gifts THIS run sorted by "every gift keeps the
     * donor's name", for the envelope step that follows in the same run.
     * Emptied at the start of every run.
     *
     * @var int[]
     */
    private static array $everyGiftSites = [];

    /**
     * 🧾 Keep what the organisation must keep of a donor's giving records, and
     *    take the donor's name off the rest (roll-out package A2b).
     *
     * For each organisation where the person has gifts, a tax declaration,
     * a giving statement or a named envelope in a count, in ONE transaction
     * with its audit line:
     *
     *   1. read that organisation's rule (Portal\Core\GivingRetention);
     *   2. work out which gifts keep the donor's name, and the last of them;
     *   3. if anything is kept, write the name and home address to
     *      `tblGivingKeptDonors` (a row an earlier run left is worked out
     *      again: see "A LATER RUN" below);
     *   4. take the account number and any typed name off every OTHER gift.
     *
     * Then, once for all organisations, the giver comes off every counted
     * envelope except one in a count that is not yet closed whose gift will
     * be a kept gift (unnameCountEnvelopes()).
     *
     * The kept gifts, the declarations and the statement records are not
     * touched: they keep the account number, and the name now lives in the
     * kept row. The account is emptied later in the run, as it always was.
     *
     * A LATER RUN WORKS THE KEPT ROW OUT AGAIN, AND NEVER WRITES A MADE-UP
     * NAME OVER A REAL ONE. A request can be run more than once (a step
     * failed, or the host killed the page, and it was run again). What the
     * account looks like by then depends on WHERE the earlier run stopped:
     *
     *   - It stopped AFTER the account was emptied (the walk empties it about
     *     half way through; a later step failed, or the run was stopped).
     *     The account now says "[Deleted User]", the donor IS erased, and
     *     the kept row holds the only copy of the name. The name and address
     *     on the row are left exactly as they are; the gifts and envelopes
     *     are sorted by the WIDER of the rule stored on the row and today's
     *     setting, and the stored rule is not changed; and the row is never
     *     emptied here, only by the retention job under its guard.
     *   - It stopped BEFORE the account was emptied (inside this step, or
     *     earlier in the walk). The account still holds the real name, and
     *     the person may have gone on giving in between. This run is the
     *     erasure: the name and address are read again from the account,
     *     and today's rule sorts the gifts, as on a first run.
     *
     *   In both cases the counts are worked out again: which gifts are kept,
     *   the last of them, how many, and any gift recorded since the earlier
     *   run that is not kept loses its donor. The date is never moved
     *   EARLIER than the one already recorded.
     *
     * This comment used to say that on a second run "the account has already
     * been emptied" and that a row that exists "is left exactly as it is".
     * The first is not always true, and the second meant a gift recorded
     * between two runs was never counted, so its donor's name was due to be
     * cleared a year early (found by the independent check, 12 October 2026).
     *
     * IF THIS STEP FAILS THE WHOLE ERASURE STOPS HERE. The request goes back
     * to "pending review" and the caller is told. The walk that follows
     * empties the account; letting it run after this step failed would
     * destroy the name with nowhere holding it.
     *
     * A record whose keep-until date has ALREADY passed is cleared at the END
     * of the same run, after the account has been emptied, by the same
     * routine the retention job uses, so it is held to the same conditions
     * (the account emptied, no hold, a confirmed number of years, the date
     * passed). Without that, an organisation that never switched the
     * scheduled job on would keep such a name for ever. (It used to be
     * cleared inside this step. The clearing now refuses while the account
     * still names the person, and this step runs before that.)
     *
     * What this cannot do:
     *   - Keep a name that is already gone. An account emptied before this
     *     feature existed has only "[Deleted User]" left; the kept row is
     *     then written with an empty name and the line says so.
     *   - Find a gift that names the donor only by a typed name with no
     *     account number (`donorName` on a row with no `donorID`, #492).
     *   - Know which gifts were really claimed for tax. Nothing records that,
     *     so a gift is treated as kept when a declaration or a statement of
     *     the donor's covers its date.
     *   - Run on a database that has not had migration 209. It checks for the
     *     kept-names table before anything else and stops the run if it is
     *     missing, for EVERYBODY, with the advice to run the upgrade. (It
     *     first met the missing table only when the person had giving
     *     records; anybody else's erasure carried on and emptied the account
     *     before a later step failed.)
     *
     * @return bool Whether anything was changed: a gift or an envelope lost
     *   its donor.
     *
     * @throws \RuntimeException When the step could not be completed. The
     *   message says what state the request is in.
     */
    private static function keepGivingRecords(int $requestId, int $userId): bool
    {
        $db      = App::db();
        $changed = false;
        $doing   = 'checking that this database has been upgraded for this version';
        self::$unkeptGiftDates = [];
        self::$everyGiftSites  = [];
        self::$dueAtErasure    = [];

        try {
            // The table the kept names go in. Asked for BEFORE anything else
            // and for everybody, donor or not: a database without it has not
            // had migration 209, and no erasure should start on one.
            $probe = $db->query('SELECT 1 FROM tblGivingKeptDonors LIMIT 0');
            if ($probe instanceof \mysqli_result) {
                $probe->free();
            }

            $doing = 'finding which organisations hold giving records for this person';
            $sites = [];
            $stmt  = $db->prepare(
                'SELECT siteID FROM tblGivingEntry WHERE donorID = ? '
                . 'UNION SELECT siteID FROM tblGiftAidDeclaration WHERE donorID = ? '
                . 'UNION SELECT siteID FROM tblGivingStatementLog WHERE donorID = ? '
                // An organisation where the person is named only on a counted
                // envelope: the gift made from it when the count is closed
                // may be one that keeps the donor's name.
                . 'UNION SELECT cs.siteID FROM tblCountEnvelopes ce INNER JOIN tblCountSessions cs ON cs.countSessionID = ce.countSessionID '
                . 'WHERE ce.giverID = ? ORDER BY siteID'
            );
            if ($stmt === false) {
                throw new \RuntimeException((string) $db->error);
            }
            $stmt->bind_param('iiii', $userId, $userId, $userId, $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_row()) {
                $sites[] = (int) $row[0];
            }
            $stmt->close();

            if ($sites === []) {
                // Written down, like every other step: without a line nothing
                // could show that this step ran at all.
                self::asOneStep($db, static function () use ($db, $requestId): bool {
                    self::logAudit($db, $requestId, 'none', 'GivingRecords', null, 'no giving records are held for this person');

                    return true;
                });
            }

            $dueNow = [];
            foreach ($sites as $siteId) {
                $doing   = 'keeping the giving records at organisation #' . $siteId;
                $outcome = self::asOneStep(
                    $db,
                    static fn (): array => self::keepGivingRecordsAtSite($db, $requestId, $userId, $siteId)
                );
                if ($outcome['changed'] === true) {
                    $changed = true;
                }
                if ($outcome['due'] !== null) {
                    $dueNow[] = $outcome['due'];
                }
            }

            // ✉️ Counted envelopes, AFTER every organisation's kept row is
            //    written, because which envelopes keep their giver depends on
            //    those rows.
            $doing = 'taking the giver off counted envelopes';
            if (self::asOneStep($db, static fn (): bool => self::unnameCountEnvelopes($db, $requestId, $userId)) === true) {
                $changed = true;
            }

            // A record whose keep-until date has ALREADY passed is cleared at
            // the END of the run, by clearGivingRecordDueAtErasure(): the routine
            // that clears refuses until the account has really been emptied,
            // which the walk that follows this step does. The numbers are
            // kept on the class for it.
            self::$dueAtErasure = $dueNow;
        } catch (\Throwable $e) {
            // Abandon anything half done, so the two writes below are not
            // part of a transaction that is about to be thrown away.
            try {
                $db->rollback();
            } catch (\Throwable $ignored) {
                // Nothing was open, or the connection has gone.
            }

            $problem = ($e instanceof \mysqli_sql_exception ? 'Error ' . (int) $e->getCode() . ': ' : '') . $e->getMessage();
            // A database that has not had migration 209 has no table to keep
            // the name in. Say what to do, not just what went wrong.
            $advice = $e instanceof \mysqli_sql_exception && (int) $e->getCode() === 1146
                ? ' The database has not been upgraded for this version: run the database upgrade, then run this request again.'
                : '';

            try {
                self::logAudit(
                    $db,
                    $requestId,
                    'failed',
                    'GivingRecords',
                    null,
                    mb_substr('Erasure stopped while ' . $doing . '; nothing after this step was done in this run, '
                        . 'so the account was not touched by it. ' . $problem . $advice, 0, 600)
                );
            } catch (\Throwable $auditProblem) {
                error_log('[WebMS-Intra] GdprEraser: could not record the stopped erasure #' . $requestId . ': ' . $auditProblem->getMessage());
            }

            $status = self::putBackToPendingReview($db, $requestId);
            error_log('[WebMS-Intra] GdprEraser: erasure #' . $requestId . ' stopped while ' . $doing . ': ' . $problem);

            throw new \RuntimeException(
                'Erasure request #' . $requestId . ' stopped before it finished, while ' . $doing . ' (' . $problem . '). Nothing after this '
                . 'step was done in this run, so the account was not touched by it. Every step is safe to repeat.'
                . $advice . ' ' . self::statusSentence($status),
                0,
                $e
            );
        }

        return $changed;
    }

    /**
     * The kept records that were ALREADY past their keep-until date when this
     * run met them. execute() clears them as its last act before closing the
     * request, once the account has been emptied.
     *
     * @var int[]
     */
    private static array $dueAtErasure = [];

    /**
     * 🏢 The giving-records step for ONE organisation. Runs inside the
     *    transaction keepGivingRecords() opened, and writes exactly one audit
     *    line. Anything it throws undoes what it did here and stops the run.
     *
     * It does the same work whether or not an earlier run has been here:
     * count what is kept, write or bring up to date the kept row, take the
     * donor off every gift that is not kept. See keepGivingRecords() for what
     * differs on a later run, and why.
     *
     * No line it writes holds the donor's name.
     *
     * @return array{changed: bool, due: ?int} `due`: the kept row's number
     *   when its keep-until date has already passed and nothing but the
     *   account not being emptied yet holds the clearing back; otherwise null.
     */
    private static function keepGivingRecordsAtSite(\mysqli $db, int $requestId, int $userId, int $siteId): array
    {
        $rule  = GivingRetention::ruleForSite($siteId);
        $where = 'organisation #' . $siteId;
        $one   = static function (string $sql, string $types, array $values) use ($db): ?array {
            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                throw new \RuntimeException((string) $db->error);
            }
            $stmt->bind_param($types, ...$values);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return $row;
        };
        $plural = static fn (int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);

        // 1. The row an earlier run left, if any. Locked, so two runs cannot
        //    both decide there is no row and both write one.
        $existing = $one(
            'SELECT keptDonorID, scope, lastGiftDate, clearedAt FROM tblGivingKeptDonors WHERE siteID = ? AND donorID = ? FOR UPDATE',
            'ii',
            [$siteId, $userId]
        );
        if ($existing !== null && $existing['clearedAt'] !== null) {
            self::$unkeptGiftDates[$siteId] = null;
            self::logAudit(
                $db,
                $requestId,
                'retain',
                'GivingRecords',
                $where,
                'Giving records at ' . $where . ' were already dealt with by an earlier run, and the name and address '
                . 'kept then have since been cleared. Nothing was changed.'
            );

            return ['changed' => false, 'due' => null];
        }
        $earlierRun = $existing !== null;

        // 1b. The account, as it is now. "Already emptied" is deliberately
        //     WIDER here than emptiedAccountSql(): either the made-up name or
        //     the made-up address is enough, because the mistakes to avoid
        //     at this point are writing "[Deleted User]" over a real name and
        //     re-deciding what an already-erased donor's record keeps.
        $account = $one('SELECT fullName, emailAddress, displayAddress FROM tblUsers WHERE userID = ?', 'i', [$userId]);
        $emptiedAlready = $account === null
            || (string) $account['fullName'] === self::TOMBSTONE_NAME
            || self::isTombstoneAddress((string) $account['emailAddress']) === true;

        // 🛑 AN EARLIER RUN KEPT A NAME HERE, AND THE ACCOUNT IS ALREADY
        //    EMPTIED. The walk empties the account about half way through a
        //    run, so a run that stops after that leaves exactly this. The
        //    donor IS erased; the kept row is the only copy of their name,
        //    and what it keeps was decided on the day the account was
        //    emptied. So this run:
        //      - sorts the gifts (and the envelopes) by the WIDER of the rule
        //        stored on the kept row and today's setting: if either says
        //        "every gift", every gift is kept. Never by a narrower rule
        //        than either;
        //      - never changes that stored rule;
        //      - never takes the "nothing to keep" branch below. If nothing
        //        is linked any more the row is left for the retention job,
        //        which clears it only under its guard (no hold, a confirmed
        //        figure, the date passed).
        //    WHAT WAS WRONG (found by the narrow check of 12 October 2026):
        //    the first version of "work the row out again" sorted by today's
        //    setting in every case. An organisation that kept every gift,
        //    with a hold on, had a donor's run stop after the account was
        //    emptied; "which gifts" was then narrowed; and running the
        //    request again emptied the kept name at once, hold or no hold.
        //    The first fix then sorted by the STORED rule alone, which was
        //    the same fault the other way round: stored "tax gifts only",
        //    widened to "every gift", and a gift matched to the erased donor
        //    afterwards lost its donor on the later run, though the same gift
        //    kept it for a donor whose erasure had finished (found by the
        //    re-check of 12 October 2026). A donor taken off a gift cannot
        //    be put back, so the wider rule wins.
        //    When the account is NOT yet emptied, today's rule is the right
        //    one: this run is the erasure, as a first run would be.
        $keptAlready = $earlierRun === true && $emptiedAlready === true;

        // 2. Which gifts keep the name, and the last of them. The same piece
        //    of SQL decides here and in the update further down.
        //    `$scope` is the rule that SORTS. Beside an emptied account it is
        //    the wider of the two (1b); it is only ever WRITTEN to the row
        //    when the account is not yet emptied, where it is today's.
        $scope = (string) $rule['scope'];
        if ($keptAlready === true && (string) $existing['scope'] === GivingRetention::SCOPE_ALL) {
            $scope = GivingRetention::SCOPE_ALL;
        }
        if ($scope === GivingRetention::SCOPE_ALL) {
            // For the envelope step that follows all the organisations.
            self::$everyGiftSites[] = $siteId;
        }
        $kept  = GivingRetention::keptGiftSql($scope, 'e');
        $gifts = $one(
            'SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN ' . $kept . ' THEN 1 ELSE 0 END), 0) AS kept, '
            . 'MAX(CASE WHEN ' . $kept . ' THEN e.donatedAt END) AS lastKept '
            . 'FROM tblGivingEntry e WHERE e.siteID = ? AND e.donorID = ?',
            'ii',
            [$siteId, $userId]
        );
        $declarations = $one('SELECT COUNT(*) AS n, MAX(validFrom) AS newest FROM tblGiftAidDeclaration WHERE siteID = ? AND donorID = ?', 'ii', [$siteId, $userId]);
        $statements   = $one('SELECT COUNT(*) AS n, MAX(toDate) AS newest FROM tblGivingStatementLog WHERE siteID = ? AND donorID = ?', 'ii', [$siteId, $userId]);
        // Named envelopes in a count that is not closed yet, whose gift will
        // be a kept gift when it is (see unnameCountEnvelopes()).
        $envelopes = $one(
            'SELECT COUNT(*) AS n, MAX(cs.serviceDate) AS newest FROM tblCountEnvelopes ce '
            . 'INNER JOIN tblCountSessions cs ON cs.countSessionID = ce.countSessionID '
            . "WHERE cs.siteID = ? AND ce.giverID = ? AND cs.status <> 'closed' AND "
            . GivingRetention::keptWhenSql($scope, 'cs.siteID', 'ce.giverID', 'cs.serviceDate'),
            'ii',
            [$siteId, $userId]
        );

        $keptGifts        = (int) $gifts['kept'];
        $otherGifts       = (int) $gifts['total'] - $keptGifts;
        $declarationCount = (int) $declarations['n'];
        $statementCount   = (int) $statements['n'];
        $envelopeCount    = (int) $envelopes['n'];
        $scopeWords       = $scope === GivingRetention::SCOPE_ALL
            ? 'this organisation keeps the donor\'s name on every gift'
            : 'this organisation keeps the donor\'s name only on gifts that have a tax declaration, receipt or statement';

        // The dates of the gifts about to lose their donor, for the
        // statement-file step later in this run: a statement the donor made
        // for themselves is kept only when none of these falls inside it. On
        // a later run the gifts the EARLIER run took the donor off cannot be
        // listed any more, so "not known" is recorded instead.
        if ($earlierRun === true) {
            self::$unkeptGiftDates[$siteId] = null;
        } else {
            $dates = [];
            $stmt  = $db->prepare('SELECT DISTINCT e.donatedAt FROM tblGivingEntry e WHERE e.siteID = ? AND e.donorID = ? AND NOT ' . $kept);
            if ($stmt === false) {
                throw new \RuntimeException((string) $db->error);
            }
            $stmt->bind_param('ii', $siteId, $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_row()) {
                $dates[] = (string) $row[0];
            }
            $stmt->close();
            self::$unkeptGiftDates[$siteId] = $dates;
        }

        // 3. Every gift that is NOT kept loses the donor at once.
        $stmt = $db->prepare(
            'UPDATE tblGivingEntry e SET e.donorID = NULL, e.donorName = NULL WHERE e.siteID = ? AND e.donorID = ? AND NOT ' . $kept
        );
        if ($stmt === false) {
            throw new \RuntimeException((string) $db->error);
        }
        $stmt->bind_param('ii', $siteId, $userId);
        $stmt->execute();
        $emptied = (int) $stmt->affected_rows;
        $stmt->close();
        if ($emptied !== $otherGifts) {
            // The count and the update use one rule, so they can only differ
            // if a gift was recorded between the two. Stop and undo, rather
            // than write a line that does not match what was done.
            throw new \RuntimeException('the gifts at ' . $where . ' changed while they were being sorted (' . $otherGifts
                . ' counted, ' . $emptied . ' changed); nothing was kept or changed there');
        }
        $otherWords = $otherGifts === 0
            ? 'No other gift of theirs is held there.'
            : $plural($otherGifts, 'other gift keeps', 'other gifts keep') . ' the amount, date and method and no longer '
                . ($otherGifts === 1 ? 'names' : 'name') . ' the donor.';

        // 4. Nothing to keep. (Never for a record already kept beside an
        //    emptied account: see 1b.)
        if ($keptAlready === false && $keptGifts === 0 && $declarationCount === 0 && $statementCount === 0 && $envelopeCount === 0) {
            if ($earlierRun === true) {
                // An earlier run kept a name here and nothing needs it any
                // more. The name and address go; the row stays as the record.
                $stmt = $db->prepare(
                    'UPDATE tblGivingKeptDonors SET fullName = NULL, address = NULL, postcode = NULL, keptGiftCount = 0, '
                    . 'clearedAt = UTC_TIMESTAMP() WHERE keptDonorID = ?'
                );
                if ($stmt === false) {
                    throw new \RuntimeException((string) $db->error);
                }
                $keptDonorId = (int) $existing['keptDonorID'];
                $stmt->bind_param('i', $keptDonorId);
                $stmt->execute();
                $stmt->close();
            }
            self::logAudit(
                $db,
                $requestId,
                'anonymise',
                'GivingRecords',
                $where,
                'Giving records at ' . $where . ': nothing has to be kept with the donor\'s name. '
                . $plural($emptied, 'gift keeps', 'gifts keep') . ' the amount, date and method and no longer '
                . ($emptied === 1 ? 'names' : 'name') . ' the donor. Why: ' . $scopeWords . ', and this donor has none of those there.'
                . ($earlierRun === true ? ' An earlier run had kept a name and address here; nothing needs them any more, so they were emptied.' : '')
            );

            return ['changed' => $emptied > 0 || $earlierRun === true, 'due' => null];
        }

        // 5. The date everything is counted from: the last kept gift (or the
        //    service date of a kept envelope in an open count, if later), or,
        //    with neither, the newest statement or declaration. On a later
        //    run it is never moved EARLIER than the date already recorded.
        $lastGift = max($gifts['lastKept'] !== null ? (string) $gifts['lastKept'] : '', $envelopes['newest'] !== null ? (string) $envelopes['newest'] : '');
        $from     = 'last such gift ' . GivingRetention::dateInWords($lastGift);
        if ($lastGift === '') {
            $lastGift = max((string) ($statements['newest'] ?? ''), (string) ($declarations['newest'] ?? ''));
            $from     = 'no gift is kept, so it is counted from the newest statement or declaration, ' . GivingRetention::dateInWords($lastGift);
        }
        if ($earlierRun === true && (string) $existing['lastGiftDate'] > $lastGift) {
            $lastGift = (string) $existing['lastGiftDate'];
            $from     = 'the date recorded by the earlier run, ' . GivingRetention::dateInWords($lastGift);
        }

        // 6. The name and home address, as they are now (the account was
        //    read at 1b).
        $name     = $emptiedAlready === true ? null : (string) $account['fullName'];
        $declared = $one(
            "SELECT address, postcode FROM tblGiftAidDeclaration WHERE siteID = ? AND donorID = ? AND address IS NOT NULL AND address <> '' "
            . 'ORDER BY validFrom DESC, declarationID DESC LIMIT 1',
            'ii',
            [$siteId, $userId]
        );
        $address  = $declared !== null ? (string) $declared['address'] : ($emptiedAlready === true ? null : ($account['displayAddress'] ?? null));
        $postcode = $declared !== null && $declared['postcode'] !== null ? (string) $declared['postcode'] : null;

        if ($earlierRun === false) {
            $stmt = $db->prepare(
                'INSERT INTO tblGivingKeptDonors (siteID, donorID, erasureRequestID, fullName, address, postcode, scope, lastGiftDate, keptGiftCount, createdAt) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
            );
            if ($stmt === false) {
                throw new \RuntimeException((string) $db->error);
            }
            $stmt->bind_param('iiisssssi', $siteId, $userId, $requestId, $name, $address, $postcode, $scope, $lastGift, $keptGifts);
            $stmt->execute();
            $keptDonorId = (int) $stmt->insert_id;
            $stmt->close();
        } else {
            // 🛑 NEVER a made-up name over a real one. When the account has
            //    already been emptied, the three columns that hold the
            //    donor's details are not in the statement at all.
            $keptDonorId = (int) $existing['keptDonorID'];
            if ($emptiedAlready === true) {
                // Nor is the stored rule: "which gifts kept the name on the
                // day" must stay what it was on the day.
                $stmt = $db->prepare('UPDATE tblGivingKeptDonors SET lastGiftDate = ?, keptGiftCount = ? WHERE keptDonorID = ?');
                if ($stmt === false) {
                    throw new \RuntimeException((string) $db->error);
                }
                $stmt->bind_param('sii', $lastGift, $keptGifts, $keptDonorId);
            } else {
                // The earlier run stopped before the account was emptied.
                // THIS request is the one
                // that is erasing the person now, so the row is tied to it:
                // the line written when the name is cleared goes on the trail
                // of the request that really did the erasing. (The earlier
                // one may be a different request, since cancelled, or gone.)
                $stmt = $db->prepare(
                    'UPDATE tblGivingKeptDonors SET erasureRequestID = ?, fullName = ?, address = ?, postcode = ?, scope = ?, '
                    . 'lastGiftDate = ?, keptGiftCount = ? WHERE keptDonorID = ?'
                );
                if ($stmt === false) {
                    throw new \RuntimeException((string) $db->error);
                }
                $stmt->bind_param('isssssii', $requestId, $name, $address, $postcode, $scope, $lastGift, $keptGifts, $keptDonorId);
            }
            $stmt->execute();
            $stmt->close();
        }

        // 7. The line. Dates in words; never the donor's name.
        $state = GivingRetention::stateFor($rule, $lastGift);
        $what  = [];
        if ($keptGifts > 0) {
            $what[] = $plural($keptGifts, 'gift', 'gifts');
        }
        if ($declarationCount > 0) {
            $what[] = $plural($declarationCount, 'tax declaration', 'tax declarations');
        }
        if ($statementCount > 0) {
            $what[] = $plural($statementCount, 'giving statement', 'giving statements');
        }
        if ($envelopeCount > 0) {
            $what[] = $plural($envelopeCount, 'envelope in a count that is not yet closed', 'envelopes in a count that is not yet closed');
        }
        $total = $keptGifts + $declarationCount + $statementCount + $envelopeCount;
        $list  = $what === [] ? '' : (count($what) > 1 ? implode(', ', array_slice($what, 0, -1)) . ' and ' . end($what) : $what[0]);

        $line = $earlierRun === true
            ? 'Giving records at ' . $where . ' were already dealt with by an earlier run, and were worked out again by this one. '
            : 'Giving records at ' . $where . ': ';
        if ($what === []) {
            // Only reachable for a record already kept beside an emptied
            // account (1b): everything else with nothing to keep left at 4.
            $line .= 'Nothing there now keeps this donor\'s name. The name and address kept by the earlier run are left '
                . 'in place all the same: only the retention job removes them, '
                . ($state['state'] === 'unreadable'
                    ? 'and THE KEEP-UNTIL DATE COULD NOT BE WORKED OUT from this organisation\'s settings, so nothing will be '
                        . 'cleared until that is put right.'
                    : 'after ' . GivingRetention::dateInWords($state['keepUntil']) . '.');
        } elseif ($state['state'] === 'unreadable') {
            $line .= ucfirst($list) . ' ' . ($total === 1 ? 'keeps' : 'keep')
                . ' this donor\'s name and address. THE KEEP-UNTIL DATE COULD NOT BE WORKED OUT from this '
                . 'organisation\'s settings, so nothing will be cleared until that is put right.';
        } else {
            $line .= ($earlierRun === true ? ucfirst($list) : $list) . ' ' . ($total === 1 ? 'keeps' : 'keep')
                . ' this donor\'s name and address until ' . GivingRetention::dateInWords($state['keepUntil'])
                . '. After that date the retention job removes them.';
        }
        $line .= ' ' . $otherWords . ' Why these: ' . $scopeWords . '.';
        if ($keptAlready === true && $scope !== (string) $rule['scope']) {
            $line .= ' (That is the rule that was in force when the name was kept. The organisation has changed its '
                . 'setting since, which does not change what an already-erased donor\'s record keeps.)';
        } elseif ($keptAlready === true && $scope !== (string) $existing['scope']) {
            $line .= ' (The name was first kept under the narrower rule. The organisation keeps the name on every gift '
                . 'now, and for an already-erased donor the wider of the two is used. The rule recorded with the name '
                . 'is not changed.)';
        }
        if ($state['state'] !== 'unreadable') {
            $years = (int) $rule['years'];
            $line .= ' How the date was worked out: ' . $from . '; '
                . ($state['usedFallback'] === true
                    ? 'no financial year end is set, so one year after that was used (' . GivingRetention::dateInWords($state['countedFrom'])
                        . '), which may keep the record up to a year longer than needed'
                    : 'financial year ends ' . GivingRetention::yearEndInWords((string) $rule['yearEnd'])
                        . ' (' . GivingRetention::dateInWords($state['countedFrom']) . ')')
                . '; plus ' . $years . ' ' . ($years === 1 ? 'year' : 'years') . '.';
        }
        $line .= ' ' . GivingRetention::figureSentence($rule);
        if ($rule['hold'] !== false) {
            $line .= ' A hold is switched on (an audit or enquiry is open), so nothing will be cleared until it is switched off.';
        }
        if ($earlierRun === true) {
            $line .= $emptiedAlready === true
                ? ' The account had already been emptied, so the name and address kept by the earlier run were left as they are: what was kept was not overwritten.'
                : ' The earlier run stopped before the account was emptied, so the name and address held were read again from the account.';
        } elseif ($emptiedAlready === true) {
            $line .= ' This account had ALREADY been emptied before this run, so no name could be recorded: the record kept holds '
                . ($address !== null ? 'the address from the tax declaration and no name.' : 'no name and no address.');
        }

        self::logAudit($db, $requestId, 'retain', 'GivingRecords', $where, $line);

        return ['changed' => $emptied > 0, 'due' => $state['state'] === 'due' ? $keptDonorId : null];
    }

    /**
     * ✉️ Take the giver off the person's counted envelopes, except the ones
     *    whose gift will be a kept gift. One audit line. Runs inside the
     *    transaction keepGivingRecords() opened, after every organisation's
     *    kept row has been written.
     *
     * An envelope is the counting sheet, not the financial record: the
     * gift-log line made from it when the count is CLOSED is that
     * (giving/count/close.php, which copies the envelope's giver onto the
     * gift). So:
     *
     *   - an envelope in a CLOSED count loses its giver. Its gift is already
     *     in the gift log, where it was sorted like any other gift;
     *   - an envelope in a count that is NOT yet closed loses its giver too,
     *     UNLESS the gift it will become is one that keeps the donor's name:
     *     that organisation is keeping this donor's name, and it keeps every
     *     gift, or a tax declaration or statement of the donor's covers the
     *     count's service date. Then the giver stays, so that closing the
     *     count makes a gift the tax claim can still match.
     *
     * A kept envelope's giver is taken off later, with everything else, by
     * clearKeptGivingIdentity(); and its service date counts as a gift date
     * when the keep-until date is worked out
     * (Portal\Core\GivingRetention::stateOfKeptRow()).
     *
     * What this cannot do: an envelope that names the person only by a typed
     * name, with no account number, cannot be found.
     *
     * @return bool Whether any envelope lost its giver.
     */
    private static function unnameCountEnvelopes(\mysqli $db, int $requestId, int $userId): bool
    {
        // FIXED TEXT: the same question the gift-sorting asks, put to the
        // envelope's organisation, giver and service date.
        //
        // "Every gift" is true of an organisation when the rule stored on
        // the kept row says so, OR when this run sorted that organisation's
        // gifts by "every gift" (today's rule, or the wider of the two
        // beside an emptied account: keepGivingRecordsAtSite(), 1b). The
        // numbers in the list are organisation numbers read from the
        // database by this run, never anything typed.
        $everyGiftHere = self::$everyGiftSites === []
            ? ''
            : ' OR cs.siteID IN (' . implode(', ', array_map('intval', self::$everyGiftSites)) . ')';
        $keptEnvelope = "(cs.countSessionID IS NOT NULL AND cs.status <> 'closed' AND k.keptDonorID IS NOT NULL AND (k.scope = '"
            . GivingRetention::SCOPE_ALL . "'" . $everyGiftHere . ' OR '
            . GivingRetention::keptWhenSql(GivingRetention::SCOPE_RECEIPTED, 'cs.siteID', 'ce.giverID', 'cs.serviceDate') . '))';
        $joins = 'tblCountEnvelopes ce '
            . 'LEFT JOIN tblCountSessions cs ON cs.countSessionID = ce.countSessionID '
            . 'LEFT JOIN tblGivingKeptDonors k ON k.siteID = cs.siteID AND k.donorID = ce.giverID AND k.clearedAt IS NULL ';

        $kept = 0;
        $stmt = $db->prepare('SELECT COUNT(*) FROM ' . $joins . 'WHERE ce.giverID = ? AND ' . $keptEnvelope);
        if ($stmt === false) {
            throw new \RuntimeException((string) $db->error);
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->bind_result($kept);
        $stmt->fetch();
        $stmt->close();

        $stmt = $db->prepare('UPDATE ' . $joins . 'SET ce.giverID = NULL, ce.giverName = NULL WHERE ce.giverID = ? AND NOT ' . $keptEnvelope);
        if ($stmt === false) {
            throw new \RuntimeException((string) $db->error);
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $unnamed = (int) $stmt->affected_rows;
        $stmt->close();

        $keptWords = (int) $kept . ' envelope(s) in a count that is not yet closed keep the giver, because the gift made from each '
            . 'when the count is closed is one that keeps the donor\'s name; the retention job takes the giver off with the rest';
        if ($unnamed > 0) {
            self::logAudit($db, $requestId, 'anonymise', 'CountEnvelopes', $unnamed . ' rows',
                'counted envelopes no longer name the giver; each keeps its amount' . ((int) $kept > 0 ? '. ' . $keptWords : ''));
        } elseif ((int) $kept > 0) {
            self::logAudit($db, $requestId, 'retain', 'CountEnvelopes', (int) $kept . ' rows', $keptWords);
        } else {
            self::logAudit($db, $requestId, 'none', 'CountEnvelopes', null, 'no counted envelope names this person');
        }

        return $unnamed > 0;
    }

    /**
     * One record that was already past its keep-until date at erasure, as a
     * step of the run. clearKeptGivingIdentity() writes its own line when it
     * clears. When it cannot (a kept file would not delete), that is a FAILED
     * step, written down here, so the request is not closed and the next run
     * tries again. Any other answer (a hold switched on a moment ago, say)
     * simply leaves the record for the retention job.
     *
     * @return string One of the STEP_ constants.
     */
    private static function clearGivingRecordDueAtErasure(\mysqli $db, int $requestId, int $keptDonorId): string
    {
        $answer = self::clearKeptGivingIdentity($keptDonorId);
        if ($answer === 'cleared') {
            return self::STEP_CHANGED;
        }
        if ($answer !== 'failed') {
            return self::STEP_UNCHANGED;
        }

        return self::asOneStep($db, static function () use ($db, $requestId): string {
            self::logAudit($db, $requestId, 'failed', 'GivingRecords', null,
                'a giving record that was already past its keep-until date could not be cleared (the reason is in the '
                . 'server\'s error log). Nothing of it was changed; running the request again tries once more.');

            return self::STEP_FAILED;
        });
    }

    /**
     * ⏳ Remove a donor's name, address and link from the giving records one
     *    organisation was keeping, because the keep-until date has passed.
     *
     * Called by the retention job (web/_apps/cron/_retention-sweep.php), and
     * at the end of an erasure run, by clearGivingRecordDueAtErasure(), for a
     * record already past its date.
     *
     * 🛑 THE GUARD IS IN HERE, NOT IN THE CALLERS. Nothing is cleared unless
     *    Portal\Core\GivingRetention::stateOfKeptRow() answers "due", which
     *    needs all FOUR: the donor's account has really been emptied; no
     *    hold; a number of years an administrator has confirmed; and a
     *    keep-until date that has passed where the organisation is, counted
     *    from the LATER of the date on the kept row and the newest gift still
     *    linked to the donor. A caller cannot clear early by forgetting a
     *    test, because there is no test for it to forget.
     *
     *    The first of the four was missing until the independent check of
     *    12 October 2026: a kept row is written near the start of an erasure
     *    and the account is emptied about half way through it, and a run
     *    that stopped in between left a kept row beside a live member, which
     *    this routine would then have cleared, gifts and declaration
     *    included.
     *
     * 🔒 THE DECISION IS MADE INSIDE THE TRANSACTION, WITH THE ROWS LOCKED.
     *    It used to be made before the transaction began, and the files
     *    were deleted before it too. A gift recorded for the donor in that
     *    gap lost its donor, and the name was cleared, although the gift
     *    made the record no longer due (found by the narrow check of 12
     *    October 2026, with a second connection holding the gap open). Now
     *    the kept row and the donor's gifts at that organisation are locked
     *    first, the rule and the state are read again, and nothing is
     *    deleted or changed unless the answer is still "due".
     *
     * What goes, together:
     *   - the kept statement PDFs for that donor at that organisation;
     *   - the account number and any typed name on their gifts there;
     *   - the giver on any counted envelope of theirs there;
     *   - the address, postcode, signature, sender address and notes on
     *     their tax declarations there;
     *   - the file path on their statement records there;
     *   - the name, address and postcode on the kept row, which is stamped
     *     with the moment and stays as the record that this happened.
     * NO ROW IS DELETED, and no amount, date or method changes.
     *
     * The line is added to the ORIGINAL erasure request's audit trail, so one
     * report tells the whole story of that person's erasure.
     *
     * THE FILES GO FIRST, the rows second, both after the decision. A file
     * cannot be un-deleted, so the order is chosen for the failure it leaves
     * behind: if the rows then
     * cannot be changed, files that were due anyway are gone and the next run
     * finishes the job. The other order could leave the rows saying
     * "cleared" with a PDF naming the donor still on disk, and nothing would
     * ever look at it again. A file that cannot be deleted stops this record
     * being cleared at all; the next run tries again.
     *
     * What this cannot do:
     *   - Empty `donorID` on a declaration or a statement record. The
     *     database does not allow it there. The account it points at names
     *     nobody, and the line says so.
     *   - Bring a name back.
     *   - Notice a hold switched on, or a figure un-confirmed, in the
     *     instant after the rule is read again: the settings are not locked.
     *   - Hold a new gift back on a database server set to "read committed".
     *     The lock on the gap a new gift would go into exists at MySQL's
     *     default setting ("repeatable read"), which is what the portal
     *     runs on.
     *   - Lock the counted envelopes. A count being closed makes a gift,
     *     and that gift is held back by the lock on the gifts.
     *   - Tell the organisation that owns the records, when the erasure
     *     request was filed at a different organisation: the line is on that
     *     request's trail. The job also writes one activity-log line per
     *     organisation for that reason.
     *
     * @param int         $keptDonorId The row of `tblGivingKeptDonors`.
     * @param string|null $today       `YYYY-MM-DD` where the organisation is. Null means now. For tests.
     *
     * @return string 'cleared', or why not: 'not-erased' (the account has not
     *   been emptied: no run of the erasure got that far), 'on-hold', 'not-confirmed',
     *   'not-due', 'unreadable' (the date could not be worked out),
     *   'already-cleared', 'gone' (no such row), or 'failed' (a file or a
     *   write failed; the reason is in the error log and nothing was cleared
     *   in the database).
     */
    public static function clearKeptGivingIdentity(int $keptDonorId, ?string $today = null): string
    {
        $db = App::db();

        try {
            $stmt = $db->prepare('SELECT siteID, donorID, erasureRequestID, lastGiftDate, clearedAt FROM tblGivingKeptDonors WHERE keptDonorID = ?');
            if ($stmt === false) {
                throw new \RuntimeException((string) $db->error);
            }
            $stmt->bind_param('i', $keptDonorId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] GdprEraser: kept giving record #' . $keptDonorId . ' could not be read: ' . $e->getMessage());

            return 'failed';
        }
        if ($row === null) {
            return 'gone';
        }
        if ($row['clearedAt'] !== null) {
            return 'already-cleared';
        }

        $siteId  = (int) $row['siteID'];
        $donorId = (int) $row['donorID'];
        $rule    = GivingRetention::ruleForSite($siteId);
        $state   = GivingRetention::stateOfKeptRow($db, $rule, $siteId, $donorId, (string) $row['lastGiftDate'], $today);

        // A first look, with nothing locked. It only saves work: most
        // records are not due, and this answers for them at once. It is NOT
        // the decision. That is made again inside the transaction below.
        if ($state['state'] !== 'due') {
            return $state['state'];
        }

        try {
            return self::asOneStep($db, static function () use ($db, $keptDonorId, $siteId, $donorId, $today): string {
                // 🔒 Lock the kept row, then the donor's gifts at this
                //    organisation, and only THEN decide.
                $stmt = $db->prepare('SELECT erasureRequestID, lastGiftDate, clearedAt FROM tblGivingKeptDonors WHERE keptDonorID = ? FOR UPDATE');
                $stmt->bind_param('i', $keptDonorId);
                $stmt->execute();
                $now = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($now === null || $now['clearedAt'] !== null) {
                    return 'already-cleared';
                }
                // The lock covers the rows that are there AND the gap a new
                // gift for this donor would go into, so nobody can record one
                // between the decision below and the update that follows it.
                $stmt = $db->prepare('SELECT entryID FROM tblGivingEntry WHERE siteID = ? AND donorID = ? FOR UPDATE');
                $stmt->bind_param('ii', $siteId, $donorId);
                $stmt->execute();
                $stmt->get_result()->free();
                $stmt->close();

                // 🛑 THE GUARD. The rule and the state are read again, now
                //    that nothing can change under them.
                $rule  = GivingRetention::ruleForSite($siteId);
                $state = GivingRetention::stateOfKeptRow($db, $rule, $siteId, $donorId, (string) $now['lastGiftDate'], $today);
                if ($state['state'] !== 'due') {
                    return $state['state'];
                }

                // Never clear with nowhere to write that it was done.
                if ($now['erasureRequestID'] === null) {
                    error_log('[WebMS-Intra] GdprEraser: kept giving record #' . $keptDonorId . ' is due, but the erasure request it '
                        . 'belongs to no longer exists, so there is no audit trail to record the clearing on. Nothing was cleared.');

                    return 'failed';
                }
                $requestId = (int) $now['erasureRequestID'];

                // The files, only now that the decision is final.
                $gone = 0;
                foreach (self::statementFilesOf($donorId, $siteId) as $path) {
                    if (@unlink($path) === false) {
                        error_log('[WebMS-Intra] GdprEraser: kept giving record #' . $keptDonorId . ' is due, but a kept statement '
                            . 'file could not be deleted (' . basename($path) . '). Nothing was cleared; the next run tries again.');

                        return 'failed';
                    }
                    $gone++;
                }

                $count = static function (string $table) use ($db, $siteId, $donorId): int {
                    $n    = 0;
                    $stmt = $db->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE siteID = ? AND donorID = ?');
                    $stmt->bind_param('ii', $siteId, $donorId);
                    $stmt->execute();
                    $stmt->bind_result($n);
                    $stmt->fetch();
                    $stmt->close();

                    return (int) $n;
                };
                $gifts        = $count('tblGivingEntry');
                $declarations = $count('tblGiftAidDeclaration');

                foreach ([
                    'UPDATE tblGivingEntry SET donorID = NULL, donorName = NULL WHERE siteID = ? AND donorID = ?',
                    'UPDATE tblGiftAidDeclaration SET address = NULL, postcode = NULL, acceptedIP = NULL, signaturePath = NULL, '
                        . 'notes = NULL WHERE siteID = ? AND donorID = ?',
                    'UPDATE tblGivingStatementLog SET pdfPath = NULL, emailedTo = NULL WHERE siteID = ? AND donorID = ?',
                    // An envelope kept in a count that was still open at the
                    // erasure. Its gift, if the count has been closed since,
                    // is one of the gifts above.
                    'UPDATE tblCountEnvelopes ce INNER JOIN tblCountSessions cs ON cs.countSessionID = ce.countSessionID '
                        . 'SET ce.giverID = NULL, ce.giverName = NULL WHERE cs.siteID = ? AND ce.giverID = ?',
                ] as $sql) {
                    $stmt = $db->prepare($sql);
                    $stmt->bind_param('ii', $siteId, $donorId);
                    $stmt->execute();
                    $stmt->close();
                }
                $stmt = $db->prepare(
                    'UPDATE tblGivingKeptDonors SET fullName = NULL, address = NULL, postcode = NULL, clearedAt = UTC_TIMESTAMP() '
                    . 'WHERE keptDonorID = ? AND clearedAt IS NULL'
                );
                $stmt->bind_param('i', $keptDonorId);
                $stmt->execute();
                $stmt->close();

                $years = (int) $rule['years'];
                self::logAudit(
                    $db,
                    $requestId,
                    'anonymise',
                    'GivingRecords',
                    'organisation #' . $siteId,
                    'The keep-until date for this donor\'s giving records at organisation #' . $siteId . ' was '
                    . GivingRetention::dateInWords($state['keepUntil']) . ' and has passed. The name and address were removed from '
                    . $gifts . ' ' . ($gifts === 1 ? 'gift' : 'gifts') . ', ' . $declarations . ' tax '
                    . ($declarations === 1 ? 'declaration' : 'declarations') . ' and the record that held them, and '
                    . $gone . ' kept statement ' . ($gone === 1 ? 'file was' : 'files were') . ' deleted. Amounts, dates and '
                    . 'methods are kept, and no record was deleted. The tax declarations and statement records still carry '
                    . 'the account number, because the database does not allow it to be empty there; the account it '
                    . 'points at names nobody. Worked out from: last gift ' . GivingRetention::dateInWords((string) $state['countedFromGift']) . '; '
                    . ($state['usedFallback'] === true
                        ? 'no financial year end set, so one year after it was used'
                        : 'financial year ends ' . GivingRetention::yearEndInWords((string) $rule['yearEnd']))
                    . '; ' . $years . ' ' . ($years === 1 ? 'year' : 'years') . ', confirmed'
                    . ((string) $rule['confirmedOn'] !== '' ? ' on ' . GivingRetention::dateInWords((string) $rule['confirmedOn']) : '') . '.'
                );

                return 'cleared';
            });
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] GdprEraser: kept giving record #' . $keptDonorId . ' could not be cleared: ' . $e->getMessage());

            return 'failed';
        }
    }

    /**
     * 📄 Is one rendered statement PDF kept with the tax evidence?
     *
     * Only asked about a file at an organisation that IS keeping the donor's
     * name. See eraseGivingStatementFiles() for the rule and its reasons.
     *
     * @param string $scope    The rule that organisation applied: 'receipted' or 'all'.
     * @param string $fileName `statement-{donor}-{from}_{to}.pdf`.
     */
    private static function statementFileIsKept(\mysqli $db, int $siteId, int $donorId, string $scope, string $fileName): bool
    {
        if ($scope === GivingRetention::SCOPE_ALL) {
            return true;
        }
        $prefix = 'statement-' . $donorId . '-';
        $period = substr($fileName, strlen($prefix), -4);
        if (str_starts_with($fileName, $prefix) === false
            || preg_match('/^(\d{4}-\d{2}-\d{2})_(\d{4}-\d{2}-\d{2})$/', $period, $m) !== 1
        ) {
            // The name does not give a period, so it cannot be matched to a
            // statement record. Kept: see eraseGivingStatementFiles().
            return true;
        }

        $stmt = $db->prepare('SELECT 1 FROM tblGivingStatementLog WHERE siteID = ? AND donorID = ? AND periodKey = ? LIMIT 1');
        if ($stmt === false) {
            throw new \RuntimeException((string) $db->error);
        }
        $stmt->bind_param('iis', $siteId, $donorId, $period);
        $stmt->execute();
        $issued = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        if ($issued === true) {
            return true;
        }

        // A statement the donor made for themselves ("my statement"), with no
        // statement record behind it. Kept when EVERY gift on it is a kept
        // gift: at least one gift in its period still carries the donor, and
        // none of the gifts this run took the donor off falls inside it.
        [, $from, $to] = $m;
        $linked = 0;
        $stmt   = $db->prepare('SELECT COUNT(*) FROM tblGivingEntry WHERE siteID = ? AND donorID = ? AND donatedAt BETWEEN ? AND ?');
        if ($stmt === false) {
            throw new \RuntimeException((string) $db->error);
        }
        $stmt->bind_param('iiss', $siteId, $donorId, $from, $to);
        $stmt->execute();
        $stmt->bind_result($linked);
        $stmt->fetch();
        $stmt->close();
        if ((int) $linked === 0) {
            return false;
        }
        foreach (self::$unkeptGiftDates[$siteId] ?? [] as $date) {
            // Dates written YYYY-MM-DD compare as text in calendar order.
            if ($date >= $from && $date <= $to) {
                return false;
            }
        }

        // Either no gift in its period lost the donor in this run, or an
        // EARLIER run sorted the gifts and which ones it took the donor off
        // is no longer known. Kept in both cases: keeping can be put right.
        return true;
    }

    /**
     * 📁 The rendered statement PDFs on disk for one donor: at one
     *    organisation, or at every one when `$siteId` is null.
     *
     * The organisation is the FOLDER's name and the donor's account number is
     * in the file's (see Portal\Core\Giving::renderStatementPdf()). Both are
     * whole numbers here, so nothing typed by anybody reaches the pattern.
     *
     * @return string[] Full paths.
     */
    private static function statementFilesOf(int $donorId, ?int $siteId = null): array
    {
        $base = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'giving'
              . DIRECTORY_SEPARATOR . 'statements';
        if (is_dir($base) === false) {
            return [];
        }
        $found = glob($base . DIRECTORY_SEPARATOR . ($siteId === null ? '*' : (string) $siteId) . DIRECTORY_SEPARATOR
            . 'statement-' . $donorId . '-*.pdf');

        return $found === false ? [] : $found;
    }

    /**
     * 🧾 Giving statements: the rendered PDFs for this person, across every
     *    organisation (the organisation is in the folder name, not the file
     *    name - see Portal\Core\Giving::renderStatementPdf()), then the
     *    address each statement was mailed to
     *    (`tblGivingStatementLog.emailedTo`, SEC-02).
     *
     * A STATEMENT FILE FOR KEPT GIFTS IS KEPT (Salem874, 11 October 2026). It
     * is a copy of what the donor was given, and a copy cannot be made again
     * once the account is emptied. So a file is kept, until the retention job
     * removes it with the names, when BOTH are true:
     *
     *   - that organisation is keeping this donor's name (keepGivingRecords()
     *     wrote a kept row there earlier in this run, or on an earlier one);
     *   - EVERY gift on the file is a kept gift. That is so when the
     *     organisation keeps the name on every gift; when its statement log
     *     has a row for this donor and exactly this period (under the "tax
     *     gifts only" rule a statement record is what makes its period's
     *     gifts kept gifts); and, for a statement the donor made for
     *     themselves from "my statement", when at least one gift in its
     *     period still carries the donor and none of the gifts this run took
     *     the donor off falls inside it.
     *
     * Every other file is deleted, as all of them used to be: a file at an
     * organisation that keeps nothing of this donor's, and a self-made
     * statement that lists a gift which has just lost the donor's name (it
     * would name them against that gift). A file whose name does not give a
     * period cannot be sorted either way; it is KEPT, because keeping is the
     * mistake that can be put right.
     *
     * (A self-made statement was first ALWAYS deleted. The owner's reason
     * for keeping statement files, that a copy cannot be made again, applies
     * to it too when everything on it is tax evidence; the independent check
     * of 12 October 2026 said so.)
     *
     * What this cannot do: on a LATER run of a request whose first run had
     * already sorted an organisation's gifts, it is no longer known which
     * gifts lost the donor. A self-made statement there is kept if any gift
     * in its period still carries the donor, even if another gift on it did
     * not keep the name.
     *
     * The statement-log ROW stays: its amounts and period are kept like
     * `tblGivingEntry`'s. Not a catalogue() entry, because
     * `tblGivingStatementLog.donorID` may not be empty, so the generic
     * instructions do not fit.
     *
     * Two steps, each with exactly one audit line, including "nothing held".
     * A file that could not be deleted, or an update that failed, is a FAILED
     * step. Both used to be passed over in silence: the PDF with the person's
     * name on it stayed on disk and the erasure was reported complete. If it
     * cannot be found out which files to keep, NO file is deleted and the
     * step fails.
     *
     * @return string[] Two STEP_ constants: the files, then the addresses.
     */
    private static function eraseGivingStatementFiles(int $requestId, int $userId): array
    {
        $db = App::db();

        $removed = 0;
        $stuck   = 0;
        $kept    = 0;
        $problem = null;
        try {
            // Where this donor's name is being kept, and under which rule.
            $keptAt = [];
            $stmt   = $db->prepare('SELECT siteID, scope FROM tblGivingKeptDonors WHERE donorID = ? AND clearedAt IS NULL');
            if ($stmt === false) {
                throw new \RuntimeException((string) $db->error);
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $keptAt[(int) $row['siteID']] = (string) $row['scope'];
            }
            $stmt->close();

            // Sort every file BEFORE deleting any, so a failure part way
            // through the sorting cannot leave some deleted on a guess.
            $delete = [];
            foreach (self::statementFilesOf($userId) as $path) {
                $folder = basename(dirname($path));
                $siteId = ctype_digit($folder) === true ? (int) $folder : 0;
                if (isset($keptAt[$siteId]) === true
                    && self::statementFileIsKept($db, $siteId, $userId, $keptAt[$siteId], basename($path)) === true
                ) {
                    $kept++;
                    continue;
                }
                $delete[] = $path;
            }
            foreach ($delete as $path) {
                if (@unlink($path) === true) {
                    $removed++;
                } else {
                    $stuck++;
                }
            }
        } catch (\Throwable $e) {
            $problem = $e->getMessage();
        }
        $keptWords = $kept . ' rendered statement PDF(s) kept with the donor\'s giving records as tax evidence, until the '
            . 'keep-until date on the "GivingRecords" line of this report, when the retention job removes them';
        // Not a real table name — deliberately NOT `tblXxx`-shaped so
        // check_php_table_refs.py doesn't need an allowlist entry for
        // this filesystem-only sweep's audit label.
        if ($problem !== null) {
            self::logAudit($db, $requestId, 'failed', 'GivingStatementPdfFiles', null, mb_substr('it could not be found out which rendered statement PDFs must be kept, so none was deleted: ' . $problem, 0, 250));
            $files = self::STEP_FAILED;
        } elseif ($stuck > 0) {
            self::logAudit($db, $requestId, 'failed', 'GivingStatementPdfFiles', (string) $removed . ' file(s)', $stuck . ' rendered statement PDF(s) could not be deleted and are still on disk');
            $files = self::STEP_FAILED;
        } elseif ($removed > 0) {
            self::logAudit($db, $requestId, 'delete', 'GivingStatementPdfFiles', (string) $removed . ' file(s)', 'rendered statement PDF(s) deleted: no giving record that keeps this donor\'s name needs them'
                . ($kept > 0 ? '. ' . $keptWords : ''));
            $files = self::STEP_CHANGED;
        } elseif ($kept > 0) {
            self::logAudit($db, $requestId, 'retain', 'GivingStatementPdfFiles', (string) $kept . ' file(s) kept', $keptWords);
            $files = self::STEP_UNCHANGED;
        } else {
            self::logAudit($db, $requestId, 'none', 'GivingStatementPdfFiles', null, 'no rendered statement PDF found for this person');
            $files = self::STEP_UNCHANGED;
        }

        // 🔐 SEC-02 — whatever happened to the files above, the raw mailed-to
        // address is not tax evidence and must not survive an erasure in
        // `tblGivingStatementLog.emailedTo`. Null it (prepared, donor-scoped) while keeping
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
            self::logAudit($db, $requestId, 'anonymise', 'tblGivingStatementLog', (string) $nulled . ' rows', 'the address each statement was mailed to is emptied; the statement record, its totals and its period are kept');
            $addresses = self::STEP_CHANGED;
        } else {
            self::logAudit($db, $requestId, 'none', 'tblGivingStatementLog', null, 'no mailed-to address held for this person');
            $addresses = self::STEP_UNCHANGED;
        }

        return [$files, $addresses];
    }

    /**
     * 📧 #234 — take the erased person's address out of the email send log
     *    (`tblEmailLog.toRecipients`).
     *
     * Not a catalogue() entry: that column is a list of addresses separated
     * by commas (one send can have many recipients, see Mailer), not a single
     * account number the generic instructions can match. The row and its
     * other columns (provider, status, subject, HTTP code) are kept.
     *
     * ONLY AN ENTRY THAT IS EXACTLY THIS ADDRESS IS REPLACED.
     *
     * 🛑 It used to run one statement over the whole table:
     *    `REPLACE(toRecipients, address, '[erased]') WHERE toRecipients LIKE
     *    '%address%'`. That replaced the text wherever it appeared, including
     *    inside a longer address: erasing `ann@x.org` turned somebody else's
     *    `joann@x.org` into `jo[erased]`. And because `_` and `%` mean "any
     *    character" in a LIKE, an address containing one also matched
     *    look-alikes. A third person's address was being damaged on a request
     *    that was never theirs.
     *
     * Now the LIKE is only a first filter (with those characters escaped) to
     * avoid reading the whole log. Each candidate list is split on commas
     * here, and an entry is replaced only when, with the spaces around it
     * removed, it equals the address. Capital letters are ignored, as they
     * are when mail is delivered. Each changed row is written back by its
     * own key.
     *
     * The address comes from execute(), which reads it before the table walk
     * empties it (see addressToScrub()).
     *
     * Exactly one audit line, including when there was nothing to do. A
     * failed read or update is a FAILED step, not a crash.
     *
     * What this cannot do: an entry written as `Name <address>` is not
     * matched. Mailer writes plain addresses only, so none is expected.
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

        // `\`, `%` and `_` are escaped so the filter looks for the address as
        // written. (Backslash is the LIKE escape character unless a server
        // has NO_BACKSLASH_ESCAPES switched on; the exact comparison below is
        // what decides, so a filter that is too wide costs time, not
        // correctness.)
        $like        = '%' . addcslashes($email, '\\%_') . '%';
        $wanted      = mb_strtolower($email);
        $replacement = '[erased]';

        $affected = 0;
        $problem  = null;
        try {
            $read = $db->prepare('SELECT emailLogID, toRecipients FROM tblEmailLog WHERE toRecipients LIKE ?');
            if ($read === false) {
                $problem = (string) $db->error;
            } else {
                $read->bind_param('s', $like);
                $read->execute();
                $candidates = $read->get_result()->fetch_all(MYSQLI_ASSOC);
                $read->close();

                $write = null;
                foreach ($candidates as $row) {
                    $entries = explode(',', (string) $row['toRecipients']);
                    $changed = false;
                    foreach ($entries as $i => $entry) {
                        $bare = trim($entry);
                        if ($bare !== '' && mb_strtolower($bare) === $wanted) {
                            // Keeps whatever spaces were around the entry, so
                            // the rest of the list reads exactly as before.
                            $entries[$i] = str_replace($bare, $replacement, $entry);
                            $changed     = true;
                        }
                    }
                    if ($changed === false) {
                        continue; // a longer address that merely contains it
                    }
                    if ($write === null) {
                        $write = $db->prepare('UPDATE tblEmailLog SET toRecipients = ? WHERE emailLogID = ?');
                        if ($write === false) {
                            $problem = (string) $db->error;
                            break;
                        }
                    }
                    $list = implode(',', $entries);
                    $id   = (int) $row['emailLogID'];
                    $write->bind_param('si', $list, $id);
                    $write->execute();
                    $affected++;
                }
                if ($write instanceof \mysqli_stmt) {
                    $write->close();
                }
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
     * 🖼️ The person's photo: delete the image file, then empty the two columns
     *    on their account that say where it is (`avatarPath`, `displayPhoto`).
     *
     * Until 11 October 2026 an erasure left both columns, and any file they
     * named, exactly as they were, on an account that otherwise named nobody.
     *
     * WHY THIS IS A STEP OF ITS OWN and not two more columns on the account
     * instruction. Emptying a column is the easy half. If the column were
     * emptied first and the file then could not be deleted, the photo would
     * still be on disk with nothing left pointing at it, and the next run
     * could not find it. So here the file goes first, and a column is
     * emptied only once its file is dealt with. A file that cannot be dealt
     * with is a FAILED step: both columns stay, the request is not closed,
     * and the next run finds the file again.
     *
     * WHAT IT WILL DELETE, AND WHAT IT REFUSES TO. A path stored in a
     * database column is not to be trusted with the right to delete files.
     * So a file is deleted only when ALL of this is true:
     *   - it is a real file inside this portal's own `_uploads` folder, after
     *     every `..` and shortcut in the path has been followed;
     *   - its name ends like an image (PROFILE_IMAGE_TYPES);
     *   - no OTHER account names the same file. Then it is somebody else's
     *     photo as well: it is kept, and only this person's link goes.
     * A file that exists anywhere else (the portal's code, the web folder,
     * another person's giving statement) is never deleted here. It is
     * reported as a failed step so that a person looks at it.
     *
     * An address on another service (the picture a Microsoft or Google
     * sign-in supplies) is not a file on this server. The link is emptied
     * and nothing is deleted.
     *
     * What this cannot do: delete a photo held by that other service; find a
     * copy of the file that some other page made; or delete a file that
     * lives outside `_uploads`. It notices another account using the same
     * file only when that account's column holds exactly the same text; the
     * same file written two ways (`a.jpg` and `_uploads/a.jpg`) would not be
     * noticed. And today NO PAGE IN THE PORTAL STORES A
     * PHOTO FILE AT ALL: `avatarPath` is only ever filled with an address by
     * single sign-on, and nothing writes `displayPhoto`. This step is ready
     * for the day one does, and for a path put there by hand or by an import.
     *
     * Exactly one audit line. It never contains the path, which may itself
     * spell the person's name.
     *
     * @return string One of the STEP_ constants.
     */
    private static function eraseProfileImages(int $requestId, int $userId): string
    {
        $db = App::db();

        $deleted = 0; // files removed from disk
        $shared  = 0; // files kept because another account uses them too
        $links   = 0; // columns that named something and are now emptied
        $problem = null;

        try {
            $stmt = $db->prepare('SELECT avatarPath, displayPhoto FROM tblUsers WHERE userID = ? LIMIT 1');
            if ($stmt === false) {
                throw new \RuntimeException((string) $db->error);
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?? [];
            $stmt->close();

            // Each stored value once, even when both columns hold the same.
            $values = array_values(array_unique(array_filter(
                [trim((string) ($row['avatarPath'] ?? '')), trim((string) ($row['displayPhoto'] ?? ''))],
                static fn (string $value): bool => $value !== ''
            )));
            $links = count(array_filter(
                [$row['avatarPath'] ?? null, $row['displayPhoto'] ?? null],
                static fn ($value): bool => $value !== null
            ));

            foreach ($values as $value) {
                $found = self::locateProfileImage($value);
                if ($found['where'] === 'elsewhere') {
                    throw new \RuntimeException(
                        'this account\'s photo points at a file that exists but is not an image in the portal\'s '
                        . 'uploads folder, so it was not deleted. Somebody needs to look at it: remove the file by '
                        . 'hand if it is the person\'s photo, then run the request again'
                    );
                }
                if ($found['where'] !== 'uploads') {
                    continue; // an address on another service, or no file at all
                }

                // Is it somebody else's photo too?
                $stmt = $db->prepare('SELECT COUNT(*) FROM tblUsers WHERE userID <> ? AND (avatarPath = ? OR displayPhoto = ?)');
                if ($stmt === false) {
                    throw new \RuntimeException((string) $db->error);
                }
                $stmt->bind_param('iss', $userId, $value, $value);
                $stmt->execute();
                $alsoUsed = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
                $stmt->close();
                if ($alsoUsed > 0) {
                    $shared++;
                    continue;
                }

                if (@unlink($found['path']) === false) {
                    throw new \RuntimeException('this account\'s photo file could not be deleted and is still on disk');
                }
                $deleted++;
            }

            if ($links > 0) {
                $stmt = $db->prepare('UPDATE tblUsers SET avatarPath = NULL, displayPhoto = NULL WHERE userID = ?');
                if ($stmt === false) {
                    throw new \RuntimeException((string) $db->error);
                }
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $stmt->close();
            }
        } catch (\Throwable $e) {
            $problem = $e->getMessage();
        }

        // Not a real table name, like 'GivingStatementPdfFiles' above.
        if ($problem !== null) {
            self::logAudit($db, $requestId, 'failed', 'ProfileImageFiles', (string) $deleted . ' file(s)', mb_substr($problem . '. The links to it are left on the account so the next run can find it.', 0, 400));

            return self::STEP_FAILED;
        }
        if ($links === 0) {
            self::logAudit($db, $requestId, 'none', 'ProfileImageFiles', null, 'the account names no photo');

            return self::STEP_UNCHANGED;
        }
        self::logAudit(
            $db,
            $requestId,
            $deleted > 0 ? 'delete' : 'anonymise',
            'ProfileImageFiles',
            (string) $deleted . ' file(s)',
            'the account\'s photo: ' . $deleted . ' image file(s) deleted from the uploads folder; the links to a photo '
            . 'are emptied on the account'
            . ($shared === 0 ? '' : '; ' . $shared . ' file(s) kept because another account uses the same one')
            . ($deleted === 0 && $shared === 0
                ? '. No file was found in the uploads folder (the photo was an address on another service, or its file '
                    . 'was already gone)'
                : '')
        );

        return self::STEP_CHANGED;
    }

    /**
     * 📂 The endings a profile photo's file name may have. A file with any
     *    other ending is never deleted by eraseProfileImages(), whatever a
     *    column says, so a path that wanders onto a document or a statement
     *    cannot take it with it.
     *
     * @var string[]
     */
    private const PROFILE_IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'heic', 'svg'];

    /**
     * 🔎 Work out what a stored photo value points at. Deletes nothing.
     *
     * @return array{where: string, path: string} `where` is one of:
     *   'remote'    an address on another service (anything with a scheme,
     *               or starting `//`);
     *   'uploads'   an image file inside this portal's `_uploads` folder:
     *               `path` is its real location, and it may be deleted;
     *   'elsewhere' a file that exists but is not that. Never deleted;
     *   'missing'   nothing was found.
     */
    private static function locateProfileImage(string $value): array
    {
        if (preg_match('#^(?:[a-z][a-z0-9+.\-]*:|//)#i', $value) === 1) {
            return ['where' => 'remote', 'path' => ''];
        }

        $relative = ltrim(str_replace('\\', '/', $value), '/');
        $uploads  = realpath(PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads');
        if ($relative === '' || str_contains($relative, "\0") === true) {
            return ['where' => 'missing', 'path' => ''];
        }
        $relative = str_replace('/', DIRECTORY_SEPARATOR, $relative);

        // Where the value could mean. `displayPhoto` is a path under
        // `_uploads`; `avatarPath` is a path under the web folder (Avatar).
        // The web folder is looked in ONLY to notice a file that is there:
        // nothing in it is ever deleted.
        $candidates = [
            PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . $relative,
            PORTAL_ROOT . DIRECTORY_SEPARATOR . $relative,
        ];
        $webFolder = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($webFolder !== '') {
            $candidates[] = rtrim($webFolder, '/\\') . DIRECTORY_SEPARATOR . $relative;
        }

        $elsewhere = false;
        foreach ($candidates as $candidate) {
            // realpath() follows every `..` and shortcut, so what is tested
            // below is where the file REALLY is, not what the text says.
            $real = realpath($candidate);
            if ($real === false || is_file($real) === false) {
                continue;
            }
            $isImage   = in_array(strtolower(pathinfo($real, PATHINFO_EXTENSION)), self::PROFILE_IMAGE_TYPES, true);
            $inUploads = $uploads !== false && str_starts_with($real, $uploads . DIRECTORY_SEPARATOR) === true;
            if ($isImage === true && $inUploads === true) {
                return ['where' => 'uploads', 'path' => $real];
            }
            $elsewhere = true;
        }

        return ['where' => $elsewhere === true ? 'elsewhere' : 'missing', 'path' => ''];
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
