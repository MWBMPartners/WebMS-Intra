<?php
// Path: tools/gdpr-eraser-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Erasure self-test on a real database 🗑️🧪 (#510, #535)
 * -----------------------------------------------------------------------------
 * `tools/gdpr-coverage-selftest.php` proves the erasure INSTRUCTIONS are the
 * right ones. It cannot prove they work. This file runs the REAL
 * `GdprEraser::execute()` against a throwaway database and looks at the rows
 * afterwards.
 *
 * WHY IT EXISTS
 * -------------
 * Until 11 October 2026 nothing ran an erasure before a customer did. The
 * table walk deleted the request it was carrying out, the database deleted the
 * request's audit lines with it, and the run crashed with the person half
 * erased (#510). Eight tables the written list promised to deal with were
 * never touched (#535). Both were found by reading, and both would have been
 * found in seconds by a test like this one.
 *
 * THE MADE-UP WORLD, AND WHY IT IS BUILT BY A LOOP
 * ------------------------------------------------
 * For EVERY instruction `GdprEraser::catalogue()` produces, this file inserts
 * a row that the instruction should find, filling each column by its type from
 * what the database itself says the table looks like. Nothing here lists the
 * tables by hand, so a table added to the catalogue tomorrow is tested
 * tomorrow, without anybody remembering to add it.
 *
 *   P   the person being erased
 *   C   a control: has a row of their own in every table P has one in
 *   D   a second control: named on P's rows wherever a row can name two people
 *       ("created by D, last changed by P")
 *
 * Rows are inserted with foreign-key checks switched off (a generic row cannot
 * have real parents), and the checks are switched back ON before the erasure
 * runs, so a statement the database would refuse is refused here too.
 *
 * WHAT IT PROVES
 * --------------
 *  1. A run ends, the request survives, and it is closed with the person's
 *     details taken off it.
 *  2. Exactly one audit line per instruction, in order, and the chain verifies.
 *  3. Every delete deletes, every unlink empties the person's own number and
 *     nobody else's, and every "keep" keeps.
 *  4. NOTHING ELSE IN THE WHOLE DATABASE CHANGED. Every row of every table is
 *     fingerprinted before and after; only P's own rows may differ.
 *  5. The tables #535 was about, each by name; and the records that must
 *     never be lost, each by name.
 *  6. A step that fails is recorded, the rest still run, the request goes back
 *     to "pending review" untouched, and a second run finishes the job.
 *  7. If the audit trail cannot be written the run stops, nothing is left
 *     half-recorded, and the request can be run again.
 *  7b. A statement file that cannot be deleted, and a send log that cannot be
 *     updated, each count as a failed step and the request is not closed.
 *  7c. A database missing a COLUMN gives a failed step (the request is not
 *     closed); one missing a whole TABLE gives a skip, because nothing can be
 *     held in a table that is not there.
 *
 * ADDED BY ROLL-OUT PACKAGE A2a (11 October 2026)
 * -----------------------------------------------
 *  3b. OTHER PEOPLE'S RECORDS SURVIVE, proven with rows that really are
 *     linked to each other: a visitor, a salvation card, a task and a rota
 *     slot given to the erased person; a swap request aimed at them; a
 *     child's check-in, a pastoral visit, an expense payment and a Zoom
 *     meeting they worked on; another person's gifts, Gift Aid declaration
 *     and pastoral case. And the erased person's OWN visitor record, pledge
 *     and Zoom connection are gone.
 *  4c. Every column in the whole database that can hold an account number
 *     is given the erased person's number, and afterwards the ones that
 *     still hold it are EXACTLY a named list. This is the check that would
 *     have found the fourteen tables nobody had an instruction for.
 *  5c. The send log: only an entry that IS the address is replaced, never a
 *     longer address that contains it.
 *  7d. The person's photo: what is deleted, and the three things that are
 *     refused (a path outside the uploads folder, a file that is not an
 *     image, a file that cannot be deleted).
 *  9.  A request stuck at "processing" can be put back, a live one cannot,
 *     and the test of "live" does not depend on the server's clock or zone.
 *  10. Filing a request: unconfirmed from "Request data erasure", confirmed
 *     from "Delete my account". And an administrator confirming a waiting
 *     request on the person's behalf, with the audit line that says so.
 *
 * ADDED BY ROLL-OUT PACKAGE A2b (12 October 2026)
 * -----------------------------------------------
 *  11. GIVING RECORDS, with rows that really are linked, at two real
 *     organisations with different rules. A donor WITH a tax declaration:
 *     the declaration keeps their address, the kept row holds their real
 *     name, every claimed gift still leads to the declaration, the tax claim
 *     file still names them, and the statement issued to them is still on
 *     disk. A donor WITHOUT one: the gifts keep amount and date and lose the
 *     name at once. A statement file is NOT deleted at erasure when its
 *     gifts are kept. A failed write of the kept name STOPS the run with the
 *     account not emptied. A second run never overwrites the kept name.
 *  12. THE SCHEDULED CLEARING, through the function the job runs. Nothing is
 *     cleared before an administrator has confirmed the years; nothing while
 *     a hold is on; nothing a year early, nor on the keep-until date itself.
 *     The day after, the name, the address, the link and the kept files go
 *     together, with a line on the ORIGINAL request's trail, whose chain
 *     still verifies. Another organisation's records are untouched, no row
 *     is deleted anywhere, and a second run changes nothing.
 *  13. THE SETTINGS, saved through the routine the page uses: a value outside
 *     its list refuses the whole save; ticking "confirmed" fixes the figure;
 *     changing it un-ticks; a change that would clear kept records needs a
 *     second tick; one organisation's save never touches another's. And an
 *     organisation with NO COUNTRY SET gets the flagged placeholder even
 *     though the installation's own address says "GB". Changing the country
 *     un-ticks "confirmed" as changing the figure does.
 *
 * ADDED BY THE FIX ROUND AFTER THE INDEPENDENT CHECK (12 October 2026)
 * -------------------------------------------------------------------
 *  14. A RUN THAT STOPS PART WAY. The kept name is written near the start of
 *     an erasure and the account is emptied at its end. The checker's two
 *     scenarios are here: a run stops, a gift arrives, and the request is run
 *     again (the kept record must count from the new gift, and read the name
 *     again); and a run stops, the request is cancelled, and the person
 *     carries on as a member (nothing of theirs may be cleared, and the
 *     giving code must not treat them as erased). Also: the question the
 *     cancel page asks; a database without the kept-names table, for a
 *     person with no giving records; envelopes in an open count; a record
 *     with no request left to write its line on; the two holds; and the
 *     lock on the general settings editor.
 *     Section 11 also covers a statement the donor made for themselves.
 *
 * ADDED BY THE SECOND FIX ROUND, AFTER THE NARROW CHECK (12 October 2026)
 * ----------------------------------------------------------------------
 *  15. A RUN THAT STOPS AFTER THE ACCOUNT IS EMPTIED. The walk empties the
 *     account about half way through, not last. The checker's scenario E3:
 *     an "every gift" organisation with a hold on, a run that stops just
 *     after the account is emptied, "which gifts" narrowed, and the request
 *     run again: the kept name, the gifts and the rule stored on the record
 *     must all be as the earlier run left them. And the mirror of it (the
 *     checker's c3 attack): "tax gifts only" widened to "every gift" between
 *     the runs, where a gift and an envelope that today's rule keeps must
 *     keep the donor. A later run never moves the
 *     date earlier and never empties a kept name itself. The checker's race:
 *     a second connection holds the kept row while a gift of today is
 *     recorded, and the clearing must decide again under its lock, with the
 *     kept file untouched. And the wider cancel question: a run that held no
 *     giving records but deleted a membership has "changed something".
 *     Section 14 also proves each of the three parts of "an emptied account"
 *     on its own.
 *
 * WHAT IT CANNOT PROVE
 * --------------------
 * - Sections 11 to 16 use today's real date (they have to: the clearing reads
 *   the clock itself). The date it asks for is the one at the LAST place on
 *   Earth to reach a date, twelve hours behind UTC, whatever zone the
 *   organisation is in (#604), and that is the date this test works out for
 *   itself, in ge_now(). (It used to work out London's and New York's own
 *   dates, which is what the clearing asked before #604: from London's
 *   midnight until 12:00 UTC the test then failed on correct code, and at
 *   no hour could it see early clearing come back. Found by the second
 *   check, 13 October 2026.) So ONE run sees one hour of the day. To see
 *   another, the two clock readers (ge_now() here, and the one in
 *   GivingRetention::today()) are moved together in a COPY of the checkout;
 *   that was done at three other hours when this was changed. Section 16
 *   is the part that does not depend on the hour: Kiritimati's own date is
 *   ahead of the last place's at every hour of every day.
 *   The arithmetic on other dates, on the clock-change nights and at a
 *   financial-year boundary is in tools/giving-retention-selftest.php,
 *   which needs no database.
 * - The rows the LOOP makes are not linked to each other the way real data is
 *   (each has its own made-up numbers). Section 3b adds a small hand-built
 *   set that is linked, and section 4b checks the database's own cascade
 *   rules: no "delete" instruction may cascade into a table that is kept,
 *   apart from a short named list.
 * - Section 9 proves the put-back does not depend on the server's time ZONE.
 *   It does not sit through a real clock change.
 * - Section 15's race holds open the wait for the kept row. The instant
 *   AFTER the clearing's second decision is closed by a lock on the donor's
 *   gifts; no test here can hold that instant open, so that lock is checked
 *   only by reading the source (tools/gdpr-coverage-selftest.php). The race
 *   needs `performance_schema` to be readable (it is, for root, on MySQL
 *   8.0 and 8.4), to see that the other connection is waiting.
 * - It runs on whatever server it is pointed at. It has been run on MySQL
 *   8.0.36 and 8.4.11, and never on MariaDB.
 * - It does not drive the administrator's page; it calls the class.
 * - One place still keeps an erased person's number and is pinned by name
 *   (TEXT_THAT_CAN_HOLD_AN_ACCOUNT_NUMBER): a step of an approval route that
 *   is given to one named person. What that step should become is a decision
 *   nobody has taken. Everything else that keeps a number is kept on purpose
 *   and listed in KNOWN_STILL_NAMED.
 * - Section 4c finds a person column three ways: by GdprEraser's list of
 *   names, by the database's own links to the account table, and by the
 *   SHAPE of the column's name. A column that holds an account number under
 *   a name of some other shape, with no link, would still be missed; so
 *   would a TEXT column other than the one pinned.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=<port> SELFTEST_DB_PASS=<pw> \
 *           php tools/gdpr-eraser-selftest.php
 *         The database must be a throwaway one built from web/_sql/full_schema.sql
 *         and its name must start with selftest_. The account needs the right to
 *         create triggers (sections 6 and 7 plant a failure with one).
 *         Run it as an ordinary user, not as root: section 7b makes a folder
 *         read-only to stop a file being deleted, and nothing stops root.
 * Exit:   0 only when every section was reached, exactly EXPECTED_CHECKS
 *         checks ran, and none failed. 1 otherwise.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/510
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/535
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// 🧯 Anything thrown outside the checks becomes a plain FAIL line and exit 1,
//    not a stack trace that might happen to contain the word PASS.
set_exception_handler(static function (Throwable $e): void {
    echo 'FAIL — the self-test stopped early: ' . get_class($e) . ': ' . $e->getMessage()
        . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    exit(1);
});

// 🛑 Refuse anything but a throwaway database, before anything is loaded.
$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    echo "REFUSED — set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}').\n";
    echo "This test deletes rows, so it only ever runs on a throwaway database. Nothing was checked.\n";
    exit(1);
}

// 🔢 How many checks this file runs — EXACTLY. A check that never ran cannot
//    fail, so the count is part of the result. Change it only with a check.
const EXPECTED_CHECKS = 225;

// The headings that must all be reached for a pass.
const REQUIRED_SECTIONS = ['0', '1', '2', '3', '3b', '4', '4b', '4c', '5', '5b', '5c', '6', '7', '7b', '7c', '7d', '8', '9', '10', '11', '12', '13', '14', '15', '16'];

// 🗺️ The made-up people and requests. Every number is 920000 or higher, far
//    above anything the fresh-install script seeds.
const P_A   = 920001; // erased in sections 1-5
const C     = 920002; // control: their own rows
const D     = 920003; // control: named on P's rows
const ADMIN = 920004; // the administrator who presses the button
const P_B   = 920011; // erased in section 6 (a step fails, then a second run)
const P_C   = 920021; // erased in section 7 (the audit trail fails)
const P_E   = 920031; // erased in section 7b (a file, then the send log, cannot be dealt with)
const P_F   = 920041; // erased in section 7c (the database is missing a column and a table)
const P_G   = 920051; // erased in section 7d (a photo that must not, or cannot, be deleted)
const P_S   = 920061; // section 9 (a request stuck at "processing")
const P_T   = 920071; // section 10 (filing a request, and confirming one on the person's behalf)
// Roll-out package A2b: the giving-records people of sections 11 to 13.
const P_H   = 920081; // a donor WITH a tax declaration, at two organisations
const P_I   = 920082; // a donor WITHOUT one
const P_K   = 920083; // a control donor who is never erased
const P_L   = 920084; // a donor whose kept name cannot be written (the run must stop)
const P_M   = 920085; // a second erased donor, whose keep-until date is TODAY
const P_N   = 920086; // an erased donor whose kept statement file cannot be deleted
const P_O   = 920087; // a donor erased when their records are ALREADY past the keep-until date

const REQ_A     = 940001; // P_A's request, the one that is run
const REQ_A_DUP = 940002; // P_A pressed the button twice: still waiting
const REQ_A_OLD = 940003; // an older request of P_A's, cancelled
const REQ_C     = 940004; // C's own waiting request: must not be touched
const REQ_B     = 940011;
const REQ_PC    = 940021;
const REQ_E     = 940031;
const REQ_F     = 940041;
const REQ_A_STUCK = 940005; // another request of P_A's, left at "processing" by a run that died
const REQ_A_SITE2 = 940006; // P_A also asked at a SECOND organisation: still waiting there
const REQ_D_DONE  = 940007; // D's request, completed long ago; P_A was the administrator who ran it
const REQ_G       = 940051;
const REQ_S_OLD   = 940061; // "processing", last sign of a run 20 minutes ago
const REQ_S_LIVE  = 940062; // "processing", last sign of a run 1 minute ago
const REQ_S_NULL  = 940063; // "processing", with no moment recorded (stuck before migration 208)
const REQ_S_DONE  = 940064; // completed
const SITE_2      = 2;      // a second organisation on the same installation
const REQ_H       = 940081;
const REQ_I       = 940082;
const REQ_L       = 940084;
const REQ_M       = 940085;
const REQ_N       = 940086;
const REQ_O       = 940087;
const SITE_G1     = 3;      // "tax gifts only" (the default rule), in Europe/London
const SITE_G2     = 4;      // "every gift", in Pacific/Auckland
const SITE_K      = 12;     // section 16: "tax gifts only", in Pacific/Kiritimati, fourteen hours AHEAD of UTC
const P_Q         = 920088; // section 16: an erased donor there, kept until today at the last place on Earth
const REQ_Q       = 940088;
// Section 14 (the fix round after the independent check of 12 October 2026).
const P_R     = 920091; // a run stops, she gives again, and the request is run again
const P_ST    = 920092; // a run stops, the request is cancelled, and he stays a member
const P_U     = 920093; // a run that stops at its FIRST organisation, with nothing done
const P_V     = 920094; // somebody with no giving records, on a database with no kept-names table
const P_W     = 920095; // a donor named on envelopes in counts, open and closed
const REQ_R   = 940091;
const REQ_ST  = 940092; // the request that stops and is cancelled
const REQ_ST2 = 940093; // his later request, which is run to its end
const REQ_U   = 940094;
const REQ_V   = 940095;
const REQ_W   = 940096;
const SITE_F1 = 6;      // these four are all in Europe/London
const SITE_F2 = 7;
const SITE_F3 = 8;
const SITE_F4 = 9;
// Section 15 (the second fix round, after the narrow check of 12 October 2026).
const P_WW    = 920101; // his run stops just AFTER his account is emptied; the rule is then narrowed
const P_WD    = 920102; // the control: her erasure finished under the old rule
const P_NN    = 920103; // her run stops after her account is emptied; her tax declaration is then deleted
const P_XN    = 920104; // no giving records; her run stops after deleting her membership
const REQ_WW  = 940101;
const REQ_WD  = 940102;
const REQ_NN  = 940103;
const REQ_XN  = 940104;
const SITE_F5 = 10;     // "every gift", in Europe/London, with a hold on
const P_PS    = 920105; // his run stops after his account is emptied; the rule is then WIDENED (the checker's c3 attack)
const P_QC    = 920106; // the control for that: her erasure finished under the narrower rule
const REQ_PS  = 940105;
const REQ_QC  = 940106;
const SITE_F6 = 11;     // "tax gifts only" at first, in Europe/London, with a hold on

// Details typed about the person that an instruction must EMPTY, by name.
// Listed here, and not read from the instructions, on purpose: the rest of
// this file works out what to expect from each instruction, so an instruction
// that quietly promised LESS (a column dropped from its list) changed the
// expectation with it and nothing failed. The independent check of
// 11 October 2026 planted exactly that and this test passed.
// "table.matched column" => the columns that must be empty afterwards.
const MUST_BE_EMPTIED = [
    'tblPrayerRequests.submitterID' => ['submitterName', 'submitterEmail', 'submitterIP'],
    'tblCareCase.personUserID'      => ['personName'],
];

// Columns the database will not allow to be emptied, so the person's number
// stays on the row and the audit line says so. This list is EXACT: a new one
// appearing is a decision somebody has to take (migration 192 is where the
// others were made emptiable), not something to wave through.
const KNOWN_LEFT_IN_PLACE = ['tblExpenseClaims.userID'];

// 🧾 EVERY column in the database that still holds the erased person's account
//    number after a complete, successful erasure, with the reason. Section 4c
//    gives every person column in every table the person's number and then
//    compares what is left with this list. IT IS EXACT, BOTH WAYS: a column
//    that still names them and is not here fails, and so does a column listed
//    here that no longer does (the list would then be out of date).
//
//    Adding to this list is a decision, not a tidy-up.
const KNOWN_STILL_NAMED = [
    'kept on purpose, with the number, by an instruction that says so' => [
        'tblGiftAidDeclaration.donorID', 'tblGivingStatementLog.donorID', 'tblDbsChecks.userID',
        // Roll-out package A2b: the row that holds an erased donor's name
        // and address for tax reasons. (`tblGivingEntry.donorID` is NOT on
        // this list only because the made-up gift of sections 1 to 5 is not
        // a kept gift, so it loses its donor. A KEPT gift keeps the number
        // until the keep-until date: section 11 proves that by name.)
        'tblGivingKeptDonors.donorID',
    ],
    'the database does not allow the column to be empty' => [
        'tblExpenseClaims.userID',
    ],
    // (A group of 37 columns stood here on 11 October 2026: every table's
    // SECOND "who did it" column, #492. 31 were built that day. The last six
    // were built on 12 October, on Salem874's decisions: five once the
    // database allowed them to be empty, and an accepted invitation, which
    // is now deleted with its person.)
    // (The giver on a counted envelope, `tblCountEnvelopes.giverID`, stood
    // here until roll-out package A2b built it. It is cleared now.)
];

// 🔤 What a person column's NAME looks like. Section 4c treats an integer
//    column as a person column if its name is on GdprEraser's list, OR the
//    database links it to the account table, OR its name has this shape.
//
//    The third way was added on 12 October 2026. Until then a column that
//    was on no list and had no link was invisible here, and the independent
//    check found three that an erasure never reached: who sent an event in,
//    who marked an error as dealt with, and who ran a database upgrade.
const PERSON_COLUMN_SHAPE = '/(?:By|To)(?:User)?ID$|[Uu]serID$|^(?:giver|donor|member|owner|approver|moderator|presenter|viewer|leader|operator|submitter|assignee|reviewer|author|creator)ID$/';

// Columns whose name has that shape and which do NOT hold an account number.
// Exact, both ways: a new look-alike has to be looked at and added here with
// its reason, and an entry that stops being true has to come out.
const LOOKS_LIKE_A_PERSON_COLUMN_AND_IS_NOT = [
    'tblApiKeys.rotatedToID'             => 'the number of the KEY that replaced this one',
    'tblAssetOwners.ownerID'             => 'this table\'s own row number',
    'tblExternalAudienceMembers.ownerID' => 'the number of the calendar, choice or rule the list belongs to',
    'tblVenueAgreements.supersededByID'  => 'the number of the AGREEMENT that replaced this one',
];

// 📝 TEXT that can hold an account number, which an erasure does NOT clear.
//    Pinned here so that it is known, and so that building it changes this
//    list. A step of an approval route can be given to a role, a group, or
//    ONE NAMED PERSON, and then `assigneeValue` holds that person's account
//    number as text. It does not say who did something; it says who MUST.
//    Emptying it would leave a step that nobody can approve, so what the
//    step should become is a decision, and none has been taken.
const TEXT_THAT_CAN_HOLD_AN_ACCOUNT_NUMBER = [
    'tblWorkflowSteps.assigneeValue' => 'who an approval step is given to, when it is one named person',
];

// 🧍 The account row after an erasure, column by column. EXACT, both ways:
//    every column of tblUsers must be in one of these two lists, a column in
//    the first must hold exactly the value given, and a column in the second
//    must be as it was.
//
//    Added on 12 October 2026. The checks on the emptied account used to
//    name fifteen columns, and the independent check took `displayPhone` out
//    of the eraser's list and every test still passed: the person's second
//    telephone number stayed on an "emptied" account and nothing said so.
//    '@request' stands for that request's own made-up address.
const ACCOUNT_AFTER_ERASURE = [
    'fullName' => '[Deleted User]', 'emailAddress' => '@request',
    'isActive' => 0, 'isAdmin' => 0, 'isRootAdmin' => 0, 'totpEnabled' => 0,
    'phoneNumber' => null, 'avatarPath' => null, 'locale' => null, 'notifyPrefs' => null, 'totpSecret' => null,
    'calendarToken' => null, 'displayBio' => null, 'displayPhoto' => null, 'displayPhone' => null,
    'displayAddress' => null, 'latitude' => null, 'longitude' => null, 'what3words' => null,
];
// Not about the person: the row's own number and date, and switches that
// have nothing left to show once the columns above are empty.
const ACCOUNT_COLUMNS_LEFT_AS_THEY_WERE = [
    'userID', 'createdAt', 'sabbathHonour', 'visibilityName', 'visibilityRoles', 'visibilityEmail', 'visibilityPhone',
    'visibilityAddress', 'visibilityCoords', 'visibilityBio', 'visibilityPhoto',
];

// Person columns section 4c could not put a made-up row in, so it says nothing
// about them. Exact, for the same reason.
const COULD_NOT_BE_TRIED = [];

// A delete that the database carries on into a table whose records are KEPT.
// Each is here on purpose, with its reason; section 4b fails on any other.
// "parent -> child" => why it is right.
const CASCADES_INTO_KEPT_TABLES = [
    'tblVisitor -> tblVisitorContact' => 'The only visitor record an erasure deletes is the erased person\'s OWN (matched on '
        . '`convertedUserID`). The follow-up notes under it are about them, so they go with it. A note the person WROTE '
        . 'about somebody else\'s visitor record is kept (section 3b proves both).',
    'tblZoomAccount -> tblZoomMeeting' => 'A meeting made through the erased person\'s own Zoom connection is a room in '
        . 'THEIR Zoom account, which the portal cannot open once the connection is gone. Salem874 decided on 11 October '
        . '2026 that it goes with the connection. A meeting they made through somebody else\'s connection is kept.',
];

// A delete after which the database EMPTIES a link on a kept table's rows.
// "deleted table -> kept table.column" => why it is right.
const EMPTIED_LINKS_ON_KEPT_TABLES = [
    'tblPledges -> tblGivingEntry.pledgeID' => 'A pledge is a promise, about the person, and is deleted. The gifts given '
        . 'towards it are kept and lose only the link to it: each keeps its campaign, amount, date and method, so no total '
        . 'changes (section 3b proves it on a real gift).',
];

// 📁 A scratch folder to stand in for the portal's own. The giving-statement
//    step deletes files under PORTAL_ROOT, so it must never be the real one.
$scratchParent = getenv('SELFTEST_WEB_DIR') !== false ? (string) getenv('SELFTEST_WEB_DIR') : sys_get_temp_dir();
$scratch       = rtrim($scratchParent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
    . 'gdpr-eraser-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
$statementDir  = $scratch . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'giving'
    . DIRECTORY_SEPARATOR . 'statements' . DIRECTORY_SEPARATOR . '1';
if (mkdir($statementDir, 0700, true) === false) {
    echo "REFUSED — could not make the scratch folder {$scratch}. Nothing was checked.\n";
    exit(1);
}
define('PORTAL_ROOT', $scratch);

// Classes one by one, never through bootstrap.php: this test must not read
// the portal's real connection file or its encryption key.
$coreDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR;
require_once $coreDir . 'App.php';
require_once $coreDir . 'GivingRetention.php';
require_once $coreDir . 'GdprEraser.php';
// Since mail package M3 (#592) the erasure takes the person out of the mail
// queue, through this class. (The portal finds its classes by itself; this
// test names each one it needs.)
require_once $coreDir . 'MailQueue.php';
// GdprEraser::fileRequest() asks PortalAddress for the address in the
// confirmation e-mail's link (#500). Without this line that call stops with
// "class not found".
require_once $coreDir . 'PortalAddress.php';
// For sections 11 to 13: the tax claim file and the statement list
// (Giving), the activity log the job writes to (Logger, which asks Site for
// the organisation), and the scheduled job's own code.
require_once $coreDir . 'Giving.php';
require_once $coreDir . 'Site.php';
require_once $coreDir . 'Logger.php';
require_once $coreDir . 'AnonymousCheckins.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_apps' . DIRECTORY_SEPARATOR . 'cron'
    . DIRECTORY_SEPARATOR . '_retention-sweep.php';

use Portal\Core\App;
use Portal\Core\GdprEraser;
use Portal\Core\Giving;
use Portal\Core\GivingRetention;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
// `$mysqli` is a global on purpose: `App::db()` falls back to it.
$mysqli = new mysqli(
    getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    $dbName,
    getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
);
$mysqli->set_charset('utf8mb4');

// 🕰️ Where this installation keeps an organisation's time zone (#604). The
//    portal's start-up tells Site that from its settings; this test loads its
//    classes one by one, so it says so itself, as the three calendar-feed
//    tests do. Without it Site::timezone() answers UTC for every organisation
//    made below, and the checks on the "Confirmed on" date (sections 13 and
//    16), which is the organisation's OWN date, could not tell one
//    organisation's date from another's.
$zoneMark = $mysqli->query("SELECT settingValue FROM tblSettings WHERE settingKey = 'site.timezoneKeptAt' AND siteID IS NULL")->fetch_row();
\Portal\Core\Site::init($mysqli, ['site' => ['timezoneKeptAt' => (string) ($zoneMark[0] ?? '')]], 1);

$GLOBALS['ge_pass']     = 0;
$GLOBALS['ge_fail']     = 0;
$GLOBALS['ge_sections'] = [];
$GLOBALS['ge_triggers'] = [];
$GLOBALS['ge_files']    = [];
$GLOBALS['ge_dirs']     = [];
$GLOBALS['ge_family']   = 931000;

// 🧹 Whatever happens, take the planted triggers and the scratch files away.
//    Only files this run made are removed, by their exact names; the folders
//    are removed with rmdir(), which refuses a folder that is not empty.
register_shutdown_function(static function () use ($scratch): void {
    global $mysqli;
    foreach ($GLOBALS['ge_triggers'] as $trigger) {
        try {
            $mysqli->query('DROP TRIGGER IF EXISTS `' . $trigger . '`');
        } catch (Throwable $ignored) {
            // The database is a throwaway; nothing more can be done here.
        }
    }
    foreach ($GLOBALS['ge_files'] as $file) {
        if (is_file($file) === true) {
            @unlink($file);
        }
    }
    // Folders this run made for photos (section 7d makes one read-only, and
    // it may still be if the run stopped there). Newest first; rmdir() only
    // removes a folder that is empty.
    foreach (array_reverse($GLOBALS['ge_dirs']) as $dir) {
        if (is_dir($dir) === true) {
            @chmod($dir, 0700);
            foreach ($GLOBALS['ge_files'] as $file) {
                if (is_file($file) === true) {
                    @unlink($file);
                }
            }
            @rmdir($dir);
        }
    }
    $statements = $scratch . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'giving'
        . DIRECTORY_SEPARATOR . 'statements';
    // Section 7b's folder may still be read-only if the run stopped there.
    if (is_dir($statements . DIRECTORY_SEPARATOR . '2') === true) {
        @chmod($statements . DIRECTORY_SEPARATOR . '2', 0700);
        foreach ($GLOBALS['ge_files'] as $file) {
            if (is_file($file) === true) {
                @unlink($file);
            }
        }
        @rmdir($statements . DIRECTORY_SEPARATOR . '2');
    }
    $dir = $statements . DIRECTORY_SEPARATOR . '1';
    while (strlen($dir) >= strlen($scratch)) {
        @rmdir($dir);
        $dir = dirname($dir);
    }
});

// =============================================================================
// 🛠️ Small helpers
// =============================================================================

function ge_ok(string $label, bool $passed, string $detail = ''): void
{
    if ($passed === true) {
        $GLOBALS['ge_pass']++;
        echo 'PASS — ' . $label . "\n";

        return;
    }
    $GLOBALS['ge_fail']++;
    echo 'FAIL — ' . $label . ($detail === '' ? '' : "\n        " . mb_substr($detail, 0, 1500)) . "\n";
}

function ge_section(string $number, string $title): void
{
    $GLOBALS['ge_sections'][$number] = true;
    echo "\n== " . $number . '. ' . $title . "\n";
}

/** The end: exit 0 only when everything ran and nothing failed. */
function ge_finish(): never
{
    $ran = $GLOBALS['ge_pass'] + $GLOBALS['ge_fail'];
    echo "\n" . $GLOBALS['ge_pass'] . ' passed, ' . $GLOBALS['ge_fail'] . ' failed, ' . $ran . ' ran of '
        . EXPECTED_CHECKS . " expected.\n";
    $bad     = false;
    $missing = array_values(array_diff(REQUIRED_SECTIONS, array_map('strval', array_keys($GLOBALS['ge_sections']))));
    if ($missing !== []) {
        echo 'FAIL — these sections never ran: ' . implode(', ', $missing) . ". A skipped section never reads as a pass.\n";
        $bad = true;
    }
    if ($ran !== EXPECTED_CHECKS) {
        echo 'FAIL — ' . $ran . ' checks ran, but this file expects exactly ' . EXPECTED_CHECKS . ".\n";
        $bad = true;
    }
    if ($GLOBALS['ge_fail'] > 0) {
        echo 'FAIL — ' . $GLOBALS['ge_fail'] . " check(s) failed.\n";
        $bad = true;
    }
    if ($bad === true) {
        exit(1);
    }
    echo 'PASS — all ' . $GLOBALS['ge_pass'] . " checks passed and every section ran.\n";
    exit(0);
}

/** Run a statement with bound values and return its rows (if it has any). */
function ge_q(string $sql, string $types = '', array $params = []): array
{
    global $mysqli;
    $stmt = $mysqli->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows   = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function ge_one(string $sql, string $types = '', array $params = []): ?array
{
    return ge_q($sql, $types, $params)[0] ?? null;
}

/** A table's columns, as the database describes them. Remembered. */
function ge_columns(string $table): array
{
    static $seen = [];
    if (isset($seen[$table]) === false) {
        $seen[$table] = ge_q(
            'SELECT COLUMN_NAME AS name, DATA_TYPE AS type, COLUMN_TYPE AS fullType, IS_NULLABLE AS nullable, '
            . 'CHARACTER_MAXIMUM_LENGTH AS maxLen, EXTRA AS extra FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            's',
            [$table]
        );
    }

    return $seen[$table];
}

function ge_isNullable(string $table, string $column): bool
{
    foreach (ge_columns($table) as $col) {
        if ($col['name'] === $column) {
            return $col['nullable'] === 'YES';
        }
    }

    return false;
}

/** A table's primary-key columns, in order. Empty when it has none. */
function ge_pk(string $table): array
{
    static $all = null;
    if ($all === null) {
        $all = [];
        foreach (ge_q(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE "
            . "WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY TABLE_NAME, ORDINAL_POSITION"
        ) as $row) {
            $all[$row['t']][] = $row['c'];
        }
    }

    return $all[$table] ?? [];
}

/**
 * The columns of a table that name a person: every name on GdprEraser's one
 * list, every column the database itself links to an account, and every
 * integer column whose NAME has the shape of a person column. The second and
 * third matter: they are how this test notices a person column the list
 * forgot, linked by the database or not.
 */
function ge_personColumns(string $table): array
{
    static $fk = null;
    if ($fk === null) {
        $fk = [];
        foreach (ge_q(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE "
            . "WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'tblUsers' AND REFERENCED_COLUMN_NAME = 'userID'"
        ) as $row) {
            $fk[$row['t']][] = $row['c'];
        }
    }
    $names = [];
    foreach (ge_columns($table) as $col) {
        $looksLikeOne = in_array(strtolower((string) $col['type']), ['int', 'bigint', 'mediumint'], true) === true
            && preg_match(PERSON_COLUMN_SHAPE, (string) $col['name']) === 1
            && isset(LOOKS_LIKE_A_PERSON_COLUMN_AND_IS_NOT[$table . '.' . $col['name']]) === false;
        if (in_array($col['name'], GdprEraser::LINK_COLUMNS, true) === true
            || in_array($col['name'], $fk[$table] ?? [], true) === true
            || $looksLikeOne === true
        ) {
            $names[] = $col['name'];
        }
    }

    return $names;
}

/** A value that fits a column, made from a number unique to the row. */
function ge_value(array $col, int $family): mixed
{
    $type = strtolower((string) $col['type']);
    switch ($type) {
        case 'int':
        case 'bigint':
        case 'mediumint':
            return $family;
        case 'smallint':
            return ($family % 30000) + 1;
        case 'tinyint':
            return 1;
        case 'decimal':
        case 'float':
        case 'double':
            return 1;
        case 'year':
            return 2026;
        case 'enum':
        case 'set':
            if (preg_match("/^(?:enum|set)\\('((?:[^']|'')*)'/", (string) $col['fullType'], $m) === 1) {
                return str_replace("''", "'", $m[1]);
            }
            throw new RuntimeException('could not read the allowed values of ' . $col['name']);
        case 'char':
        case 'varchar':
        case 'tinytext':
        case 'text':
        case 'mediumtext':
        case 'longtext':
            // The digits reversed, so even a two-letter column differs from
            // row to row and a unique key on a short column is not tripped.
            return substr(strrev((string) $family) . '-selftest', 0, max(1, min(40, (int) ($col['maxLen'] ?? 40))));
        case 'json':
            return '{}';
        case 'date':
            return '2026-01-15';
        case 'datetime':
        case 'timestamp':
            return '2026-01-15 10:00:00';
        case 'time':
            return '10:00:00';
        case 'binary':
        case 'varbinary':
        case 'tinyblob':
        case 'blob':
        case 'mediumblob':
        case 'longblob':
            return 'x';
        default:
            throw new RuntimeException('no made-up value for a column of type ' . $type . ' (' . $col['name'] . ')');
    }
}

/**
 * Insert one made-up row and return its key.
 *
 * Every column is filled, including the ones allowed to be empty, so that
 * "this row is unchanged" compares real content. If the database refuses that
 * (a rule this file cannot guess), it tries once more with the optional
 * columns left empty; a second refusal is thrown, never skipped.
 *
 * @param array<string, int>   $people Person column => whose number goes in it.
 * @param array<string, mixed> $fixed  Columns given an exact value.
 * @param int                  $other  The number for person columns not in $people.
 *
 * @return array{table: string, key: array<string, mixed>}
 */
function ge_insert(string $table, array $people, array $fixed = [], int $other = D): array
{
    global $mysqli;
    $family = ++$GLOBALS['ge_family'];
    $person = ge_personColumns($table);

    $build = static function (bool $fillOptional) use ($table, $people, $fixed, $other, $family, $person): array {
        $row = [];
        foreach (ge_columns($table) as $col) {
            $name = (string) $col['name'];
            if (stripos((string) $col['extra'], 'GENERATED') !== false) {
                continue;
            }
            if (array_key_exists($name, $fixed) === true) {
                $row[$name] = $fixed[$name];
            } elseif (in_array($name, $person, true) === true) {
                $row[$name] = $people[$name] ?? $other;
            } elseif ($fillOptional === false && $col['nullable'] === 'YES') {
                $row[$name] = null;
            } else {
                $row[$name] = ge_value($col, $family);
            }
        }

        return $row;
    };

    $problem = null;
    foreach ([true, false] as $fillOptional) {
        $row = $build($fillOptional);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', array_keys($row)) . '`) VALUES ('
            . implode(', ', array_fill(0, count($row), '?')) . ')';
        try {
            $stmt   = $mysqli->prepare($sql);
            $values = array_values($row);
            $stmt->bind_param(str_repeat('s', count($values)), ...$values);
            $stmt->execute();
            $stmt->close();

            $key = [];
            foreach (ge_pk($table) as $pkCol) {
                $key[$pkCol] = $row[$pkCol];
            }
            if ($key === []) {
                throw new RuntimeException($table . ' has no primary key, so its made-up row cannot be found again');
            }

            return ['table' => $table, 'key' => $key];
        } catch (mysqli_sql_exception $e) {
            $problem = $e;
        }
    }
    throw $problem;
}

/**
 * Give a made-up row the parent rows its foreign keys point at.
 *
 * Needed for the rows the erasure UPDATES. The first version of this test
 * left them without parents and two steps failed: emptying
 * `tblEventAttendance.userID` was refused because of the EVENT link. The
 * person column is part of a unique key that begins with the event number,
 * the database uses that key to police the event link, and so changing the
 * person makes it look the event up again. In a real database the event is
 * always there. Here it has to be put there.
 *
 * Links to an account, and links that include a person column, are left
 * alone: the people already exist, and a made-up membership for the erased
 * person would itself be found and deleted by another instruction.
 */
function ge_ensureParents(array $ref): void
{
    static $links = null;
    if ($links === null) {
        $links = [];
        foreach (ge_q(
            "SELECT TABLE_NAME AS t, CONSTRAINT_NAME AS k, COLUMN_NAME AS c, REFERENCED_TABLE_NAME AS pt, REFERENCED_COLUMN_NAME AS pc "
            . "FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL "
            . "ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION"
        ) as $row) {
            $links[$row['t']][$row['k']]['parent']          = $row['pt'];
            $links[$row['t']][$row['k']]['cols'][$row['c']] = $row['pc'];
        }
    }
    $row    = ge_row($ref);
    $people = ge_personColumns($ref['table']);
    foreach ($links[$ref['table']] ?? [] as $link) {
        if ($link['parent'] === 'tblUsers' || array_intersect(array_keys($link['cols']), $people) !== []) {
            continue;
        }
        $fixed = [];
        foreach ($link['cols'] as $childCol => $parentCol) {
            if ($row[$childCol] === null) {
                continue 2;
            }
            $fixed[$parentCol] = $row[$childCol];
        }
        $where = implode(' AND ', array_map(static fn (string $c): string => '`' . $c . '` = ?', array_keys($fixed)));
        if (ge_one('SELECT 1 FROM `' . $link['parent'] . '` WHERE ' . $where, str_repeat('s', count($fixed)), array_values($fixed)) === null) {
            // Any person named on a parent row is the administrator, who is
            // never erased, so no instruction can find the parent.
            ge_insert($link['parent'], [], $fixed, ADMIN);
        }
    }
}

/** One row by its key, or null when it is gone. */
function ge_row(array $ref): ?array
{
    $where = [];
    foreach (array_keys($ref['key']) as $col) {
        $where[] = '`' . $col . '` = ?';
    }

    return ge_one(
        'SELECT * FROM `' . $ref['table'] . '` WHERE ' . implode(' AND ', $where),
        str_repeat('s', count($ref['key'])),
        array_values($ref['key'])
    );
}

function ge_keyText(string $table, array $key): string
{
    return $table . '#' . implode('|', array_map('strval', array_values($key)));
}

/**
 * A fingerprint of EVERY row of EVERY table in the database.
 *
 * @return array<string, string> "table#key" => fingerprint of the row.
 */
function ge_snapshot(): array
{
    global $mysqli;
    $prints = [];
    $tables = ge_q("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
    foreach ($tables as $one) {
        $table  = (string) $one['t'];
        $pk     = ge_pk($table);
        $result = $mysqli->query('SELECT * FROM `' . $table . '`');
        while ($row = $result->fetch_assoc()) {
            $print = sha1(serialize($row));
            // A table with no primary key is keyed on the whole row, so a
            // change to it shows up as one row gone and another arrived.
            $key = $pk === [] ? [$print] : array_intersect_key($row, array_flip($pk));
            $prints[ge_keyText($table, $key)] = $print;
        }
        $result->free();
    }

    return $prints;
}

/**
 * What differs between two fingerprints of the database, leaving out the rows
 * that were allowed to change.
 *
 * @param array<string, true> $allowed "table#key" of rows that may change or go.
 *
 * @return string[] One line per row that changed and should not have.
 */
function ge_unexpectedChanges(array $before, array $after, array $allowed): array
{
    $problems = [];
    foreach ($before as $key => $print) {
        if (isset($allowed[$key]) === true) {
            continue;
        }
        if (isset($after[$key]) === false) {
            $problems[] = $key . ' was DELETED';
        } elseif ($after[$key] !== $print) {
            $problems[] = $key . ' was CHANGED';
        }
    }
    foreach ($after as $key => $print) {
        if (isset($before[$key]) === false && isset($allowed[$key]) === false
            && str_starts_with($key, 'tblErasureAudit#') === false
        ) {
            $problems[] = $key . ' was ADDED';
        }
    }

    return $problems;
}

/**
 * Make a file under the scratch portal folder, and remember it (and any folder
 * made for it) so the end of the run takes it away again.
 *
 * @param string $relative Where, below PORTAL_ROOT, with forward slashes.
 */
function ge_file(string $relative, string $content = 'made-up image'): string
{
    $path = PORTAL_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $dir  = dirname($path);
    $made = [];
    while (is_dir($dir) === false) {
        $made[] = $dir;
        $dir    = dirname($dir);
    }
    foreach (array_reverse($made) as $one) {
        mkdir($one, 0700);
        $GLOBALS['ge_dirs'][] = $one;
    }
    file_put_contents($path, $content);
    $GLOBALS['ge_files'][] = $path;

    return $path;
}

/**
 * Insert a made-up row whose links are REAL: the columns given in $fixed point
 * at rows that exist, and every other link gets a parent made for it.
 */
function ge_linked(string $table, array $people, array $fixed = [], int $other = D): array
{
    $ref = ge_insert($table, $people, $fixed, $other);
    ge_ensureParents($ref);

    return $ref;
}

/** The first (usually only) key value of a tracked row. */
function ge_id(array $ref): int
{
    return (int) array_values($ref['key'])[0];
}

/**
 * Is a row the same as it was, apart from the columns named?
 * `updatedAt` is stamped by the database itself and never counts.
 */
function ge_same(?array $was, ?array $now, array $except = []): bool
{
    if ($was === null || $now === null) {
        return false;
    }
    foreach ($now as $name => $value) {
        if ($name !== 'updatedAt' && in_array($name, $except, true) === false && $value !== $was[$name]) {
            return false;
        }
    }

    return true;
}

/** Plant a trigger, remembering it so it is always taken away again. */
function ge_trigger(string $name, string $sql): void
{
    global $mysqli;
    $GLOBALS['ge_triggers'][] = $name;
    $mysqli->query('DROP TRIGGER IF EXISTS `' . $name . '`');
    $mysqli->query($sql);
}

function ge_dropTrigger(string $name): void
{
    global $mysqli;
    $mysqli->query('DROP TRIGGER IF EXISTS `' . $name . '`');
}

function ge_user(int $id, string $name, string $email): void
{
    ge_insert('tblUsers', [], [
        'userID' => $id, 'fullName' => $name, 'emailAddress' => $email, 'isActive' => 1, 'isAdmin' => 0, 'isRootAdmin' => 0,
    ]);
}

function ge_request(int $requestId, ?int $userId, string $email, string $name, string $status, ?string $notes = null): void
{
    ge_q(
        'INSERT INTO tblErasureRequest (requestID, siteID, userID, subjectEmail, subjectName, confirmToken, status, dueBy, notes) '
        . 'VALUES (?, 1, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 MONTH), ?)',
        'iisssss',
        [$requestId, $userId, $email, $name, $status === 'pending_confirmation' ? str_repeat('a', 64) : null, $status, $notes]
    );
}

function ge_audit(int $requestId): array
{
    return ge_q('SELECT auditID, action, tableName, recordKey, details FROM tblErasureAudit WHERE requestID = ? ORDER BY auditID', 'i', [$requestId]);
}

/**
 * Build the made-up world for one person: one row for every instruction.
 *
 * @return array{person: int, email: string, instructions: array, rows: array, allowed: array<string, true>, demo: array, emailLog: array}
 */
function ge_buildWorld(int $person, string $email, string $name): array
{
    global $mysqli;
    $mysqli->query('SET FOREIGN_KEY_CHECKS = 0');

    ge_user($person, $name, $email);
    $allowed = [ge_keyText('tblUsers', ['userID' => $person]) => true];

    $instructions = GdprEraser::catalogue();

    $rows = [];
    foreach ($instructions as $i => $instruction) {
        $table  = (string) $instruction['table'];
        $col    = (string) $instruction['userCol'];
        $action = (string) $instruction['action'];
        if ($table === 'tblUsers') {
            // The account row itself was made above.
            $rows[] = ['i' => $i, 'kind' => 'R1', 'ref' => ['table' => 'tblUsers', 'key' => ['userID' => $person]]];
            continue;
        }

        // R1: the row the instruction should find. Every OTHER person column
        // on it names D, so "only this person's own number goes" is tested on
        // every table that can name two people.
        $ref    = ge_insert($table, $col === '' ? [] : [$col => $person]);
        $rows[] = ['i' => $i, 'kind' => 'R1', 'ref' => $ref];
        if ($action !== 'retain') {
            $allowed[ge_keyText($table, $ref['key'])] = true;
        }
        if ($action === 'anonymise') {
            ge_ensureParents($ref);
        }
        // Roll-out package A2b. The giving-records step writes a kept row
        // for each organisation that keeps something, and that row links to
        // the organisation - so the made-up giving rows need a real one
        // behind them, as real giving rows always have. And the made-up gift
        // is NOT a kept gift (nothing at its organisation covers it), so it
        // loses its donor even though its instruction says "keep".
        if (in_array($table, ['tblGivingEntry', 'tblGiftAidDeclaration', 'tblGivingStatementLog'], true) === true) {
            ge_ensureParents($ref);
        }
        if (($table === 'tblGivingEntry' && $col === 'donorID') || ($table === 'tblCountEnvelopes' && $col === 'giverID')) {
            $allowed[ge_keyText($table, $ref['key'])] = true;
        }
    }

    // R2: a row naming the person in EVERY column its table has an "empty it"
    // instruction for (a task both given to them and created by them). Each
    // instruction must empty its own column on it, and the row must stay.
    //
    // CHANGED on 11 October 2026 (#492). R2 used to be made only for a table
    // with ONE instruction that emptied a second column "in passing". No
    // instruction does that any more (tools/gdpr-coverage-selftest.php fails
    // if one does): every person column has an instruction of its own, so R2
    // is now made for every table with two or more of them. Tables that also
    // delete or keep on a column are left out: the row would be deleted, or
    // would have to stay untouched, before the others ever looked at it.
    // A unique key may forbid the extra row; that is the one case skipped,
    // and section 3 checks enough of them were made.
    $emptied = [];
    $mixed   = [];
    foreach ($instructions as $i => $instruction) {
        $table = (string) $instruction['table'];
        if ((string) $instruction['action'] === 'anonymise' && $table !== 'tblUsers') {
            $emptied[$table][$i] = (string) $instruction['userCol'];
        } else {
            $mixed[$table] = true;
        }
    }
    $extra = [];
    foreach ($emptied as $table => $columns) {
        if (count($columns) < 2 || isset($mixed[$table]) === true) {
            continue;
        }
        try {
            $ref2   = ge_insert($table, array_fill_keys(array_values($columns), $person));
            $rows[] = ['i' => (int) array_key_first($columns), 'kind' => 'R2', 'ref' => $ref2, 'cols' => array_values($columns)];
            $allowed[ge_keyText($table, $ref2['key'])] = true;
            ge_ensureParents($ref2);
            // Every one of those instructions will report this row, so the
            // audit check has to count it for each of them.
            $extra[] = array_merge((array) ge_row($ref2), ['__table' => $table]);
        } catch (mysqli_sql_exception $e) {
            if ((int) $e->getCode() !== 1062) {
                throw $e;
            }
        }
    }

    // 🧪 The Demo Data page's list: two entries point at this person and go;
    //    an entry for an announcement that merely has the same NUMBER stays.
    $userSite = null;
    foreach ($rows as $row) {
        if ($row['ref']['table'] === 'tblUserSites' && (string) $instructions[$row['i']]['userCol'] === 'userID') {
            $userSite = (int) $row['ref']['key']['userSiteID'];
        }
    }
    $demo = [
        'account'      => ge_insert('tblDemoDataRegister', [], ['tableName' => 'tblUsers', 'rowID' => $person]),
        'membership'   => ge_insert('tblDemoDataRegister', [], ['tableName' => 'tblUserSites', 'rowID' => (int) $userSite]),
        'announcement' => ge_insert('tblDemoDataRegister', [], ['tableName' => 'tblAnnouncements', 'rowID' => $person]),
    ];
    $allowed[ge_keyText('tblDemoDataRegister', $demo['account']['key'])]    = true;
    $allowed[ge_keyText('tblDemoDataRegister', $demo['membership']['key'])] = true;

    // 📧 The send log: one message to this person and D together.
    $emailLog = ge_insert('tblEmailLog', [], ['toRecipients' => $email . ', dee.control@selftest.invalid']);
    $allowed[ge_keyText('tblEmailLog', $emailLog['key'])] = true;

    // 🧾 A rendered giving statement on disk.
    $file = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'giving' . DIRECTORY_SEPARATOR
        . 'statements' . DIRECTORY_SEPARATOR . '1' . DIRECTORY_SEPARATOR . 'statement-' . $person . '-2025.pdf';
    file_put_contents($file, 'made-up statement');
    $GLOBALS['ge_files'][] = $file;

    // 🖼️ A photo file in the uploads folder, a picture address from single
    //    sign-on, a biography, and administrator rights: an erasure has to
    //    take all of them off the account.
    $photo = ge_file('_uploads/directory/' . $person . '.jpg');
    ge_q(
        "UPDATE tblUsers SET displayPhoto = ?, avatarPath = ?, displayBio = 'I have helped with the youth club since 2019.', "
        . 'isAdmin = 1, isRootAdmin = 1, totpEnabled = 1 WHERE userID = ?',
        'ssi',
        ['directory/' . $person . '.jpg', 'https://sso.selftest.invalid/photo/' . $person, $person]
    );

    // The kept giving-statement row has ONE column the erasure does empty
    // (the address it was mailed to), so that row is allowed to change; its
    // other columns are checked one by one in section 3.
    foreach ($rows as $row) {
        if ($row['ref']['table'] === 'tblGivingStatementLog') {
            $allowed[ge_keyText('tblGivingStatementLog', $row['ref']['key'])] = true;
        }
    }

    $mysqli->query('SET FOREIGN_KEY_CHECKS = 1');

    return ['person' => $person, 'email' => $email, 'instructions' => $instructions, 'rows' => $rows,
        'allowed' => $allowed, 'demo' => $demo, 'emailLog' => $emailLog, 'file' => $file, 'photo' => $photo,
        'linkedBefore' => $extra];
}

/**
 * 🤝 A small set of rows that really ARE linked to each other, around one
 *    person about to be erased: other people's records that the person
 *    worked on, and a few records that are the person's own.
 *
 * The loop in ge_buildWorld() gives every instruction a row, but each row
 * stands alone. This is what real data looks like: a visitor with follow-up
 * notes under it, a swap request on a rota slot, a gift towards a pledge.
 *
 * @return array{refs: array<string, array>, before: array<string, ?array>, mayChange: string[]}
 */
function ge_buildLinked(int $p): array
{
    global $mysqli;
    $mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
    $r = [];

    // 🚶 Visitors. One is somebody else, followed up by the person. One is
    //    C, from before C had an account. One is the person themselves.
    $r['visitor']        = ge_linked('tblVisitor', ['assignedToID' => $p, 'createdByID' => D], ['convertedUserID' => null, 'fullName' => 'Vera Visitor']);
    $r['noteByPerson']   = ge_linked('tblVisitorContact', ['contactedByID' => $p], ['visitorID' => ge_id($r['visitor']), 'summary' => 'Rang Vera; she will come on Sunday.']);
    $r['noteByOther']    = ge_linked('tblVisitorContact', ['contactedByID' => D], ['visitorID' => ge_id($r['visitor']), 'summary' => 'Vera came with her sister.']);
    $r['visitorIsC']     = ge_linked('tblVisitor', ['assignedToID' => $p, 'createdByID' => $p, 'convertedUserID' => C], ['fullName' => 'Cal Control']);
    $r['visitorIsP']     = ge_linked('tblVisitor', ['assignedToID' => D, 'createdByID' => D, 'convertedUserID' => $p], ['fullName' => 'Pat Erased']);
    $r['noteAboutP']     = ge_linked('tblVisitorContact', ['contactedByID' => D], ['visitorID' => ge_id($r['visitorIsP']), 'summary' => 'Pat asked about joining.']);

    // 🕊️ A salvation card about C, given to the person to follow up.
    $r['card']           = ge_linked('tblSalvationCards', ['assignedToID' => $p], ['fullName' => 'Cal Control', 'email' => 'cal.control@selftest.invalid']);

    // ✅ Tasks: one given to the person, one the person created for C.
    $r['taskGiven']      = ge_linked('tblTasks', ['assignedToID' => $p, 'createdByID' => D], ['title' => 'Ring the plumber', 'parentTaskID' => null]);
    $r['taskMade']       = ge_linked('tblTasks', ['assignedToID' => C, 'createdByID' => $p], ['title' => 'Order the chairs', 'parentTaskID' => null]);

    // 📅 Rota: the person's own slot; C's slot, which the person set up; and
    //    three swap requests on C's slot.
    $r['slotOfPerson']   = ge_linked('tblRotaSlot', ['assignedToID' => $p, 'createdByID' => D]);
    $r['slotOfC']        = ge_linked('tblRotaSlot', ['assignedToID' => C, 'createdByID' => $p]);
    $r['swapWaiting']    = ge_linked('tblRotaSwapRequest', ['requestedByID' => C, 'targetUserID' => $p], ['slotID' => ge_id($r['slotOfC']), 'status' => 'pending', 'requestMessage' => 'Could you cover me?']);
    $r['swapAccepted']   = ge_linked('tblRotaSwapRequest', ['requestedByID' => D, 'targetUserID' => $p], ['slotID' => ge_id($r['slotOfC']), 'status' => 'accepted']);
    $r['swapOfOthers']   = ge_linked('tblRotaSwapRequest', ['requestedByID' => C, 'targetUserID' => D], ['slotID' => ge_id($r['slotOfC']), 'status' => 'pending']);

    // 🧒 C's child, checked in by C and checked OUT by the person.
    $r['child']          = ge_linked('tblKidProfiles', ['parentUserID' => C], ['fullName' => 'Kit Control']);
    $r['checkin']        = ge_linked('tblKidCheckins', ['checkedInByID' => C, 'checkedOutByID' => $p], ['childID' => ge_id($r['child'])]);

    // 📝 A child's event registration that C submitted and the person only
    //    REVIEWED. It holds the child's medical details, and a registration
    //    is DELETED when whoever submitted it is erased - so it matters a
    //    great deal that reviewing one is not treated the same way.
    $r['registration']   = ge_linked('tblEventRegistrations', ['submittedByUserID' => C, 'reviewedByID' => $p], ['fullName' => 'Kit Control']);

    // 🫶 A pastoral case about C: one visit the person made, and one D made
    //    whose follow-up was given to the person.
    $r['case']           = ge_linked('tblCareCase', ['personUserID' => C, 'openedByID' => D]);
    $r['visitByPerson']  = ge_linked('tblCareVisit', ['visitedByID' => $p, 'followUpAssignedToID' => D], ['caseID' => ge_id($r['case']), 'notes' => 'Visited Cal at home.']);
    $r['visitFollowUp']  = ge_linked('tblCareVisit', ['visitedByID' => D, 'followUpAssignedToID' => $p], ['caseID' => ge_id($r['case']), 'notes' => 'Cal asked for a call next week.']);

    // 💷 Money. C's gift, C's Gift Aid declaration, C's expense claim with a
    //    payment the person entered. And the person's own: a pledge, a gift
    //    towards it, and a Gift Aid declaration.
    $r['giftOfC']        = ge_linked('tblGivingEntry', ['donorID' => C, 'recordedByID' => D], ['pledgeID' => null, 'campaignID' => null]);
    $r['giftAidOfC']     = ge_linked('tblGiftAidDeclaration', ['donorID' => C]);
    $r['claimOfC']       = ge_linked('tblExpenseClaims', ['userID' => C]);
    $r['payment']        = ge_linked('tblExpenseClaimPayments', ['paidByID' => $p], ['claimID' => ge_id($r['claimOfC'])]);
    $r['pledge']         = ge_linked('tblPledges', ['userID' => $p]);
    $pledge              = ge_row($r['pledge']);
    $r['giftToPledge']   = ge_linked('tblGivingEntry', ['donorID' => $p, 'recordedByID' => D], ['pledgeID' => ge_id($r['pledge']), 'campaignID' => (int) $pledge['campaignID']]);
    $r['giftAidOfPerson'] = ge_linked('tblGiftAidDeclaration', ['donorID' => $p]);

    // ✉️ Invitations: one the person ACCEPTED (it is how they joined, and it
    //    holds their own email address), and one they SENT to C.
    $r['inviteAccepted'] = ge_linked('tblInvitation', ['acceptedByID' => $p, 'createdByID' => D, 'revokedByID' => D], ['email' => 'pat.erased@selftest.invalid']);
    $r['inviteSent']     = ge_linked('tblInvitation', ['acceptedByID' => C, 'createdByID' => $p, 'revokedByID' => D], ['email' => 'cal.control@selftest.invalid']);

    // 📅 Two swap requests the person ASKED for, on their own slot: one still
    //    waiting for C's answer, one D accepted long ago.
    $r['swapAsked']      = ge_linked('tblRotaSwapRequest', ['requestedByID' => $p, 'targetUserID' => C], ['slotID' => ge_id($r['slotOfPerson']), 'status' => 'pending', 'requestMessage' => 'I am away that week.']);
    $r['swapAskedDone']  = ge_linked('tblRotaSwapRequest', ['requestedByID' => $p, 'targetUserID' => D], ['slotID' => ge_id($r['slotOfPerson']), 'status' => 'accepted']);

    // 🏠 A room booking the person made, still waiting for a decision.
    $r['booking']        = ge_linked('tblResourceBooking', ['bookedByID' => $p, 'approvedByID' => D], ['status' => 'pending', 'purpose' => 'Welcome team supper']);

    // 🎥 Zoom: the person's own connection with a meeting made through it,
    //    and a meeting the person made through C's connection.
    $r['zoomOfPerson']   = ge_linked('tblZoomAccount', ['userID' => $p]);
    $r['meetingViaPerson'] = ge_linked('tblZoomMeeting', ['createdByID' => D], ['accountID' => ge_id($r['zoomOfPerson'])]);
    $r['zoomOfC']        = ge_linked('tblZoomAccount', ['userID' => C]);
    $r['meetingViaC']    = ge_linked('tblZoomMeeting', ['createdByID' => $p], ['accountID' => ge_id($r['zoomOfC'])]);

    $mysqli->query('SET FOREIGN_KEY_CHECKS = 1');

    $before = [];
    foreach ($r as $name => $ref) {
        $before[$name] = ge_row($ref);
    }

    // Everything NOT named here must come through the erasure byte for byte:
    // the whole-database comparison in section 4 holds it to that.
    $mayChange = ['inviteAccepted', 'inviteSent', 'swapAsked', 'swapAskedDone', 'booking', 'registration', 'visitor', 'noteByPerson', 'visitorIsC', 'visitorIsP', 'noteAboutP', 'card', 'taskGiven', 'taskMade',
        'slotOfPerson', 'slotOfC', 'swapWaiting', 'swapAccepted', 'checkin', 'visitByPerson', 'visitFollowUp', 'payment',
        'pledge', 'giftToPledge', 'zoomOfPerson', 'meetingViaPerson', 'meetingViaC'];

    return ['refs' => $r, 'before' => $before, 'mayChange' => $mayChange];
}

/**
 * 🔢 Give EVERY person column in the database the person's number.
 *
 * ge_buildWorld() makes a row for each column an instruction matches on. This
 * makes one for every OTHER column that can hold an account number - in every
 * table, whether the written list knows the table or not - so that section 4c
 * can say which of them an erasure leaves behind.
 *
 * Left out, and checked by name elsewhere: the account row itself, and the
 * erasure request (a made-up one would be closed along with the real one).
 *
 * @return array{rows: array<int, array{where: string, ref: array}>, untried: string[]}
 */
function ge_buildEveryPersonColumn(int $person, array $instructions): array
{
    global $mysqli;
    $matched = [];
    foreach ($instructions as $instruction) {
        $matched[(string) $instruction['table'] . '.' . (string) $instruction['userCol']] = true;
    }

    $mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
    $rows    = [];
    $untried = [];
    foreach (ge_q("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME") as $one) {
        $table = (string) $one['t'];
        if (in_array($table, ['tblUsers', 'tblErasureRequest', 'tblErasureAudit'], true) === true) {
            continue;
        }
        foreach (ge_personColumns($table) as $column) {
            if (isset($matched[$table . '.' . $column]) === true) {
                continue;
            }
            try {
                $rows[] = ['where' => $table . '.' . $column, 'ref' => ge_insert($table, [$column => $person])];
            } catch (mysqli_sql_exception | RuntimeException $e) {
                // A unique key that will not take a second made-up row, or a
                // table with no key to find the row again by.
                $untried[] = $table . '.' . $column;
            }
        }
    }
    $mysqli->query('SET FOREIGN_KEY_CHECKS = 1');

    return ['rows' => $rows, 'untried' => $untried];
}

/**
 * 🧾 The lines the giving-records step should write for a person, worked out
 *    from the rows in the database BEFORE a run, not from the eraser.
 *
 * One line per organisation that holds a gift, a tax declaration or a
 * statement record of theirs, in organisation order. Every made-up
 * organisation follows the default rule (only gifts with a declaration,
 * receipt or statement keep the name), so: an organisation with a
 * declaration or a statement keeps something ("retain"); one with gifts only
 * keeps nothing, and its gifts lose their donor ("anonymise"). On a SECOND
 * run the gift-only organisations are no longer found at all, because their
 * gifts have no donor left, and the others say "already dealt with", which
 * is "retain" too.
 *
 * @return array<int, array{0: string, 1: string[], 2?: string}>
 */
function ge_expectedGivingLines(int $person): array
{
    $sites = [];
    foreach (['tblGivingEntry' => 'gifts', 'tblGiftAidDeclaration' => 'evidence', 'tblGivingStatementLog' => 'evidence'] as $table => $kind) {
        foreach (ge_q('SELECT siteID, COUNT(*) AS n FROM `' . $table . '` WHERE donorID = ? GROUP BY siteID', 'i', [$person]) as $row) {
            $sites[(int) $row['siteID']][$kind] = ($sites[(int) $row['siteID']][$kind] ?? 0) + (int) $row['n'];
        }
    }
    // An organisation where the person is named only on a counted envelope
    // is looked at too (added after the independent check of 12 October
    // 2026): the gift made from an envelope in an open count may be one that
    // keeps the donor's name. With nothing else there, nothing is kept.
    foreach (ge_q('SELECT cs.siteID, COUNT(*) AS n FROM tblCountEnvelopes ce INNER JOIN tblCountSessions cs ON cs.countSessionID = ce.countSessionID '
        . 'WHERE ce.giverID = ? GROUP BY cs.siteID', 'i', [$person]) as $row) {
        $sites[(int) $row['siteID']]['envelopes'] = (int) $row['n'];
    }
    ksort($sites);
    $lines = [];
    foreach ($sites as $siteId => $held) {
        $lines[] = ['GivingRecords', [($held['evidence'] ?? 0) > 0 ? 'retain' : 'anonymise'], 'organisation #' . $siteId];
    }
    if ($lines === []) {
        $lines[] = ['GivingRecords', ['none']];
    }
    // Then ONE line for the counted envelopes, whatever organisation each is
    // at. In the made-up world none is in an open count at an organisation
    // that keeps the giver's name, so every one loses its giver.
    $envelopes = (int) ge_one('SELECT COUNT(*) AS n FROM tblCountEnvelopes WHERE giverID = ?', 'i', [$person])['n'];
    $lines[]   = $envelopes > 0 ? ['CountEnvelopes', ['anonymise'], $envelopes . ' rows'] : ['CountEnvelopes', ['none']];

    return $lines;
}

/** Run the real erasure. Returns its answer, or the exception it threw. */
function ge_execute(int $requestId, int $person, int $admin = ADMIN): mixed
{
    try {
        return GdprEraser::execute($requestId, $person, $admin);
    } catch (Throwable $e) {
        return $e;
    }
}

/**
 * Look at every made-up row after a run and say what is wrong with it.
 *
 * @param string[] $expectStillNamed "table.column" of instructions that were
 *                                   made to fail, whose rows must be untouched.
 *
 * @return array{problems: string[], left: string[], r2: int, checked: int}
 */
function ge_checkRows(array $world, array $before, array $expectStillNamed = []): array
{
    $person   = $world['person'];
    $problems = [];
    $left     = [];
    $r2       = 0;
    $checked  = 0;

    foreach ($world['rows'] as $n => $tracked) {
        $instruction = $world['instructions'][$tracked['i']];
        $table       = (string) $instruction['table'];
        $col         = (string) $instruction['userCol'];
        $action      = (string) $instruction['action'];
        $was         = $before[$n];
        $now         = ge_row($tracked['ref']);
        $label       = $table . ($col === '' ? '' : '.' . $col) . ' (' . $action . ', ' . $tracked['kind'] . ')';
        $checked++;
        if ($tracked['kind'] === 'R2') {
            $r2++;
        }
        if ($table === 'tblUsers') {
            continue; // checked by name in section 1
        }

        if (in_array($table . '.' . $col, $expectStillNamed, true) === true) {
            if ($now !== $was) {
                $problems[] = $label . ': its step failed, yet the row changed';
            }
            continue;
        }

        if ($action === 'delete') {
            if ($now !== null) {
                $problems[] = $label . ': the row is still there';
            }
            continue;
        }
        if ($now === null) {
            $problems[] = $label . ': the row was DELETED and should have been kept';
            continue;
        }

        $onUpdate = [];
        foreach (ge_columns($table) as $c) {
            if (stripos((string) $c['extra'], 'on update') !== false) {
                $onUpdate[] = $c['name'];
            }
        }

        // Roll-out package A2b. The made-up gift is not a kept gift (no
        // declaration or statement at its organisation covers it), so the
        // giving-records step must have taken the donor's number AND any
        // typed name off it, and nothing else. Its instruction says "keep",
        // which is about KEPT gifts; section 11 checks those by name.
        // The same goes for the made-up counted envelope: its count is not one
        // at an organisation that keeps the giver's name, so it loses its
        // giver, though its instruction says "keep".
        $notKept = ['tblGivingEntry.donorID' => ['donorID', 'donorName'], 'tblCountEnvelopes.giverID' => ['giverID', 'giverName']][$table . '.' . $col] ?? null;
        if ($notKept !== null) {
            foreach ($now as $name => $value) {
                if (in_array($name, $notKept, true) === true) {
                    if ($value !== null) {
                        $problems[] = $label . ': a gift or envelope that is not kept still holds `' . $name . '`';
                    }
                } elseif (in_array($name, $onUpdate, true) === false && $value !== $was[$name]) {
                    $problems[] = $label . ': `' . $name . '` changed on a gift, from ' . json_encode($was[$name]) . ' to ' . json_encode($value);
                }
            }
            continue;
        }

        if ($action === 'retain') {
            foreach ($now as $name => $value) {
                if ($table === 'tblGivingStatementLog' && $name === 'emailedTo') {
                    if ($value !== null) {
                        $problems[] = $label . ': the mailed-to address is still there';
                    }
                    continue;
                }
                if (in_array($name, $onUpdate, true) === false && $value !== $was[$name]) {
                    $problems[] = $label . ': kept row changed in `' . $name . '`';
                }
            }
            continue;
        }

        // anonymise
        $listed = array_map('strval', (array) ($instruction['nullCols'] ?? []));
        $people = ge_personColumns($table);
        // A column an instruction changes ON PURPOSE with a fixed piece of
        // SQL (`sqlSets`): today only the status of a swap request, which is
        // cancelled if it was waiting. This loop cannot work out what the
        // new value should be, so it steps over that column - and section 3b
        // checks the exact value by name, on a waiting request AND on an
        // accepted one. tools/gdpr-coverage-selftest.php holds the list of
        // such pieces to exactly that one.
        $changedOnPurpose = [];
        foreach ((array) ($instruction['sqlSets'] ?? []) as $piece) {
            if (preg_match('/^`(\w+)`\s*=/', (string) $piece, $m) === 1) {
                $changedOnPurpose[] = $m[1];
            }
        }
        foreach ($now as $name => $value) {
            if (in_array($name, $onUpdate, true) === true || in_array($name, $changedOnPurpose, true) === true) {
                continue;
            }
            // An R2 row names the person in several columns, each emptied
            // by its own instruction.
            $mayEmpty = $name === $col || in_array($name, $listed, true) === true
                || in_array($name, (array) ($tracked['cols'] ?? []), true) === true;
            $isPerson = in_array($name, $people, true) === true;
            if ($mayEmpty === true && ($isPerson === false || (int) $was[$name] === $person)) {
                // The person's own number, or free text about them: must go,
                // unless the database forbids emptying the column.
                if (ge_isNullable($table, $name) === true) {
                    if ($value !== null) {
                        $problems[] = $label . ': `' . $name . '` was not emptied';
                    }
                } else {
                    $left[] = $table . '.' . $name;
                    if ($value !== $was[$name]) {
                        $problems[] = $label . ': `' . $name . '` cannot be empty, yet it changed';
                    }
                }
                continue;
            }
            if ($value !== $was[$name]) {
                $problems[] = $label . ': `' . $name . '` changed from ' . json_encode($was[$name]) . ' to ' . json_encode($value)
                    . ($isPerson === true ? ' — ANOTHER PERSON\'S NUMBER' : '');
            }
            if ($isPerson === true && $value !== null && (int) $value === $person) {
                $problems[] = $label . ': `' . $name . '` still names the erased person';
            }
        }
    }

    return ['problems' => $problems, 'left' => array_values(array_unique($left)), 'r2' => $r2, 'checked' => $checked];
}

/**
 * Compare a run's audit lines with what its instructions should have written,
 * one by one and in order.
 *
 * @param array    $lines       The audit lines of ONE run, in order.
 * @param bool     $demoLine    Whether the Demo Data step wrote a line first.
 * @param string[] $failTables  Tables whose steps were made to fail.
 * @param string   $lastAction  'completed' for a clean run, 'failed' otherwise.
 *
 * `$world['givingLines']` must hold ge_expectedGivingLines() as worked out
 * just before the run being checked.
 *
 * @return string[] What is wrong; empty when the trail is exactly as expected.
 */
function ge_checkAudit(array $world, array $lines, bool $demoLine, array $failTables, string $lastAction): array
{
    $problems = [];
    $expected = [];
    if ($demoLine === true) {
        $expected[] = ['tblDemoDataRegister', ['delete']];
    }
    // Roll-out package A2b: the giving-records step, straight after the Demo
    // Data step and before the first instruction. One line per organisation.
    foreach ($world['givingLines'] as $line) {
        $expected[] = $line;
    }
    // Mail package M3 (#592): the two steps that find the person by their
    // e-mail ADDRESS, straight after the giving-records step and before the
    // first instruction. First the mail queue (the made-up person has no
    // mail waiting, so it must say that nothing was held), then the send
    // log, whose line used to come after the giving-statement lines below.
    $expected[] = ['tblMailQueue', ['none']];
    $expected[] = ['tblEmailLog', ['anonymise']];
    $rowsFor = [];
    foreach ($world['rows'] as $tracked) {
        if ($tracked['kind'] === 'R1') {
            $rowsFor[$tracked['i']] = ($rowsFor[$tracked['i']] ?? 0) + 1;
        }
    }
    // The R2 rows (the person in several columns of one row) and the
    // hand-built linked rows of section 3b are found by instructions too.
    // Each is counted for every instruction whose column names the person on
    // it, and each line's row count has to match EXACTLY: a count that is one
    // too high would mean an instruction touched a row it should not.
    foreach ($world['instructions'] as $i => $instruction) {
        foreach ($world['linkedBefore'] ?? [] as $row) {
            $column = (string) $instruction['userCol'];
            if ($row !== null && $column !== '' && $row['__table'] === $instruction['table']
                && array_key_exists($column, $row) === true && $row[$column] !== null && (int) $row[$column] === $world['person']
            ) {
                $rowsFor[$i] = ($rowsFor[$i] ?? 0) + 1;
            }
        }
    }
    foreach ($world['instructions'] as $i => $instruction) {
        $table  = (string) $instruction['table'];
        $name   = $table . '.' . (string) $instruction['userCol'];
        $action = (string) $instruction['action'];
        if (in_array($table, $failTables, true) === true) {
            $expected[] = [$table, ['failed']];
        } elseif ($action === 'anonymise' && in_array($name, KNOWN_LEFT_IN_PLACE, true) === true) {
            $expected[] = [$table, ['skip', 'partial']];
        } elseif ($name === 'tblGivingEntry.donorID' || $name === 'tblCountEnvelopes.giverID') {
            // "Kept" is about gifts (and open-count envelopes) that keep the
            // donor's name. The made-up ones do not, and have already lost
            // their donor by now, so this instruction finds nothing and must
            // say so.
            $expected[] = [$table, ['none']];
        } elseif ($action === 'retain') {
            $expected[] = [$table, ['retain']];
        } else {
            $expected[] = [$table, [$action], (string) ($rowsFor[$i] ?? 0) . ' rows'];
        }
    }
    $expected[] = ['GivingStatementPdfFiles', ['delete']];
    $expected[] = ['tblGivingStatementLog', ['anonymise']];
    $expected[] = ['ProfileImageFiles', ['delete'], '1 file(s)'];
    $expected[] = ['tblErasureRequest', [$lastAction]];

    if (count($lines) !== count($expected)) {
        $problems[] = count($lines) . ' audit lines were written and exactly ' . count($expected) . ' were expected';
    }
    foreach ($expected as $n => $want) {
        $line = $lines[$n] ?? null;
        if ($line === null) {
            $problems[] = 'line ' . ($n + 1) . ' is missing (expected ' . $want[0] . ')';
            break;
        }
        if ($line['tableName'] !== $want[0] || in_array($line['action'], $want[1], true) === false) {
            $problems[] = 'line ' . ($n + 1) . ': expected ' . $want[0] . ' ' . implode('/', $want[1]) . ', found '
                . $line['tableName'] . ' ' . $line['action'] . ' (' . mb_substr((string) $line['details'], 0, 120) . ')';
        } elseif (isset($want[2]) === true && $line['action'] !== 'partial' && (string) $line['recordKey'] !== $want[2]) {
            $problems[] = 'line ' . ($n + 1) . ' (' . $want[0] . '): expected "' . $want[2] . '", found "' . (string) $line['recordKey'] . '"';
        }
        if (stripos((string) $line['details'], 'table-missing') !== false) {
            $problems[] = 'line ' . ($n + 1) . ' (' . $want[0] . ') says "table-missing" about a table that exists';
        }
    }

    return $problems;
}

/** The tracked row of one instruction, found by table and matched column. */
function ge_find(array $world, string $table, string $col): ?array
{
    foreach ($world['rows'] as $n => $tracked) {
        $instruction = $world['instructions'][$tracked['i']];
        if ($tracked['kind'] === 'R1' && $instruction['table'] === $table && $instruction['userCol'] === $col) {
            return ['n' => $n, 'ref' => $tracked['ref'], 'action' => (string) $instruction['action']];
        }
    }

    return null;
}

// =============================================================================
ge_section('0', 'The made-up world');
// =============================================================================
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_user(C, 'Cal Control', 'cal.control@selftest.invalid');
ge_user(D, 'Dee Control', 'dee.control@selftest.invalid');
ge_user(ADMIN, 'Ada Admin', 'ada.admin@selftest.invalid');
// C's own row in every table an instruction touches: every person column on
// it names C. And one row in each kept table that no column ties to anybody.
$controlTables = [];
foreach (GdprEraser::catalogue() as $instruction) {
    $controlTables[(string) $instruction['table']] = true;
}
unset($controlTables['tblUsers']);
foreach (array_keys($controlTables) as $table) {
    ge_insert($table, [], [], C);
}
ge_insert('tblDemoDataRegister', [], ['tableName' => 'tblUsers', 'rowID' => C]);
ge_insert('tblEmailLog', [], ['toRecipients' => 'cal.control@selftest.invalid']);
$controlFile = $statementDir . DIRECTORY_SEPARATOR . 'statement-' . C . '-2025.pdf';
file_put_contents($controlFile, 'made-up statement');
$GLOBALS['ge_files'][] = $controlFile;
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');

$worldA = ge_buildWorld(P_A, 'pat.erased@selftest.invalid', 'Pat Erased');
ge_request(REQ_A, P_A, 'pat.erased@selftest.invalid', 'Pat Erased', 'processing', 'Pat rang the office about this.');
ge_request(REQ_A_DUP, P_A, 'pat.erased@selftest.invalid', 'Pat Erased', 'pending_review');
ge_request(REQ_A_OLD, P_A, 'pat.old-address@selftest.invalid', 'Pat Erased', 'cancelled', 'Pat changed their mind.');
ge_request(REQ_C, C, 'cal.control@selftest.invalid', 'Cal Control', 'pending_review', 'Cal\'s own request.');
// Roll-out package A2a: three more requests around the same account.
//  - one of P_A's own left at "processing" by a run that died;
//  - one P_A filed at a SECOND organisation, still waiting there;
//  - D's request, finished long ago, which P_A carried out as administrator.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_insert('tblSites', [], ['siteID' => SITE_2]);
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_request(REQ_A_STUCK, P_A, 'pat.erased@selftest.invalid', 'Pat Erased', 'processing');
ge_request(REQ_A_SITE2, P_A, 'pat.erased@selftest.invalid', 'Pat Erased', 'pending_review');
ge_q('UPDATE tblErasureRequest SET siteID = ? WHERE requestID = ?', 'ii', [SITE_2, REQ_A_SITE2]);
ge_request(REQ_D_DONE, null, 'deleted-' . REQ_D_DONE . '@example.invalid', '', 'completed');
ge_q('UPDATE tblErasureRequest SET subjectName = NULL, processedAt = NOW(), processedByID = ? WHERE requestID = ?', 'ii', [P_A, REQ_D_DONE]);
foreach ([REQ_A, REQ_A_DUP, REQ_A_OLD, REQ_A_STUCK, REQ_A_SITE2, REQ_D_DONE] as $requestId) {
    $worldA['allowed'][ge_keyText('tblErasureRequest', ['requestID' => $requestId])] = true;
}
$requestDoneWas = ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_D_DONE]);

// 🤝 The linked rows of section 3b.
$linkedA = ge_buildLinked(P_A);
// Each linked row as it was, with its table's name beside it, so the audit
// check can work out how many rows every instruction should report.
foreach ($linkedA['before'] as $name => $row) {
    $worldA['linkedBefore'][$name] = $row === null ? null : array_merge($row, ['__table' => $linkedA['refs'][$name]['table']]);
}
foreach ($linkedA['mayChange'] as $name) {
    $worldA['allowed'][ge_keyText($linkedA['refs'][$name]['table'], $linkedA['refs'][$name]['key'])] = true;
}

// 🖼️ Photos. P_A's own file is made by ge_buildWorld(). Here: C's own photo,
//    which must not be touched, and a file that P_A's account AND D's both
//    point at, which must be kept because it is D's photo too.
$controlPhoto = ge_file('_uploads/directory/' . C . '.jpg');
ge_q('UPDATE tblUsers SET displayPhoto = ? WHERE userID = ?', 'si', ['directory/' . C . '.jpg', C]);
$sharedPhoto = ge_file('_uploads/directory/shared-with-dee.png');
ge_q("UPDATE tblUsers SET avatarPath = 'directory/shared-with-dee.png' WHERE userID = ?", 'i', [P_A]);
ge_q("UPDATE tblUsers SET displayPhoto = 'directory/shared-with-dee.png' WHERE userID = ?", 'i', [D]);

// 📧 The send log, for section 5c: P_A's address beside a LONGER address that
//    contains it; that longer address on its own (must not be touched); and
//    P_A's address written with capitals and no space after the comma.
$logBesideLonger = ge_insert('tblEmailLog', [], ['toRecipients' => 'xpat.erased@selftest.invalid, pat.erased@selftest.invalid']);
$logOnlyLonger   = ge_insert('tblEmailLog', [], ['toRecipients' => 'xpat.erased@selftest.invalid']);
$logCapitals     = ge_insert('tblEmailLog', [], ['toRecipients' => 'Pat.Erased@SELFTEST.invalid,dee.control@selftest.invalid']);
$worldA['allowed'][ge_keyText('tblEmailLog', $logBesideLonger['key'])] = true;
$worldA['allowed'][ge_keyText('tblEmailLog', $logCapitals['key'])]     = true;

// 🔢 The person's number in every OTHER person column of every table (4c).
$everyColumnA = ge_buildEveryPersonColumn(P_A, $worldA['instructions']);

// 📝 A step of an approval route given to the person by name: the one place
//    an account number is kept as TEXT (see TEXT_THAT_CAN_HOLD_AN_ACCOUNT_NUMBER).
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
$stepForPerson = ge_insert('tblWorkflowSteps', [], ['assigneeType' => 'user', 'assigneeValue' => (string) P_A]);
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');

// 🧾 What the giving-records step should write for this person, worked out
//    from the rows as they are now.
$worldA['givingLines'] = ge_expectedGivingLines(P_A);
$givingCountA          = count($worldA['givingLines']);

$instructionCount = count($worldA['instructions']);
$tablesCovered    = count($controlTables) + 1;
echo '   ' . $instructionCount . ' instructions over ' . $tablesCovered . ' tables; ' . count($worldA['rows'])
    . " made-up rows for the person; one control row per table.\n";
ge_ok('the world was built: a row for every instruction (more than 130 of them)',
    $instructionCount > 130 && count($worldA['rows']) >= $instructionCount,
    $instructionCount . ' instructions, ' . count($worldA['rows']) . ' rows');

$beforeRowsA = [];
foreach ($worldA['rows'] as $n => $tracked) {
    $beforeRowsA[$n] = ge_row($tracked['ref']);
}
$missingBefore = array_keys(array_filter($beforeRowsA, static fn ($row): bool => $row === null));
ge_ok('every made-up row can be read back before the run', $missingBefore === [], json_encode($missingBefore));
$printsBeforeA = ge_snapshot();
echo '   ' . count($printsBeforeA) . " rows fingerprinted across the whole database.\n";

// "What we hold about you" (/account/my-data) must list the person's own
// erasure requests and the state each is in. It briefly stopped doing so: the
// list is built from the same instructions as the erasure, and #510 took the
// request out of those.
$ownRequest = static function (int $person): ?array {
    foreach (GdprEraser::inventory($person) as $held) {
        if ($held['table'] === 'tblErasureRequest') {
            return $held;
        }
    }

    return null;
};
$heldA = $ownRequest(P_A);
$heldC = $ownRequest(C);
ge_ok('"what we hold about you" lists the person\'s own erasure requests, with the state of each',
    $heldA !== null && $heldA['rows'] === 5 && $heldA['action'] === 'anonymise'
    && str_contains($heldA['reason'], '2 processing') && str_contains($heldA['reason'], '2 pending review')
    && str_contains($heldA['reason'], '1 cancelled')
    && $heldC !== null && $heldC['rows'] === 1 && str_contains($heldC['reason'], '1 pending review'),
    json_encode([$heldA, $heldC]));

// =============================================================================
ge_section('1', 'A run ends, the request survives, and it is closed (#510)');
// =============================================================================
$resultA = ge_execute(REQ_A, P_A);
ge_ok('the run ends without an exception',
    ($resultA instanceof Throwable) === false,
    $resultA instanceof Throwable ? get_class($resultA) . ': ' . $resultA->getMessage() : '');
if ($resultA instanceof Throwable || is_array($resultA) === false) {
    // Nothing below can be trusted after a crash, and running on would bury
    // the one line that matters under a hundred that follow from it.
    echo "\nThe erasure did not finish, so the remaining sections were not run.\n";
    ge_finish();
}
ge_ok('it reports no failed step, and the number of steps it ran',
    // CHANGED by roll-out package A2b: + 5 became + 6 (the giving-records step).
    // CHANGED by mail package M3: + 6 became + 7 (the mail queue's step).
    $resultA['failed'] === 0 && $resultA['changed'] === true && $resultA['steps'] === $instructionCount + 7,
    json_encode($resultA) . ' (expected steps: ' . ($instructionCount + 7) . ')');

$reqA = ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_A]);
ge_ok('the request row still exists and is "completed"', $reqA !== null && $reqA['status'] === 'completed', json_encode($reqA));
ge_ok('its account number, name, notes and token are cleared, and its address replaced',
    $reqA !== null && $reqA['userID'] === null && $reqA['subjectName'] === null && $reqA['notes'] === null
    && $reqA['confirmToken'] === null && $reqA['subjectEmail'] === 'deleted-' . REQ_A . '@example.invalid',
    json_encode($reqA));
ge_ok('its dates and who processed it are kept (the record that it was done)',
    $reqA !== null && $reqA['processedAt'] !== null && (int) $reqA['processedByID'] === ADMIN
    && $reqA['requestedAt'] !== null && $reqA['dueBy'] !== null, json_encode($reqA));

$reqDup = ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_A_DUP]);
ge_ok('a second waiting request from the same account is closed with it and cleared',
    $reqDup !== null && $reqDup['status'] === 'completed' && $reqDup['userID'] === null && $reqDup['subjectName'] === null
    && $reqDup['subjectEmail'] === 'deleted-' . REQ_A_DUP . '@example.invalid',
    json_encode($reqDup));
$dupAudit = ge_audit(REQ_A_DUP);
ge_ok('...with one line in its own audit trail naming the request that did the work',
    count($dupAudit) === 1 && $dupAudit[0]['action'] === 'completed' && str_contains((string) $dupAudit[0]['details'], '#' . REQ_A)
    && GdprEraser::verifyAuditChain(REQ_A_DUP) === true, json_encode($dupAudit));
// Roll-out package A2a. One left at "processing" by a run that died used to
// have its account number cleared and then stay at "processing" for ever.
$reqStuck   = ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_A_STUCK]);
$stuckAudit = ge_audit(REQ_A_STUCK);
ge_ok('another request from the same account that was stuck at "processing" is closed with it too, with one line saying why',
    $reqStuck !== null && $reqStuck['status'] === 'completed' && $reqStuck['userID'] === null
    && $reqStuck['subjectEmail'] === 'deleted-' . REQ_A_STUCK . '@example.invalid'
    && count($stuckAudit) === 1 && $stuckAudit[0]['action'] === 'completed' && str_contains((string) $stuckAudit[0]['details'], '#' . REQ_A),
    json_encode([$reqStuck, $stuckAudit]));
// A request at ANOTHER organisation is closed too. Its administrator cannot
// open this organisation's report, so the line must not send them to it, nor
// hand them this organisation's request number.
$reqSite2   = ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_A_SITE2]);
$site2Audit = ge_audit(REQ_A_SITE2);
$site2Line  = (string) ($site2Audit[0]['details'] ?? '');
ge_ok('a waiting request at ANOTHER organisation is closed too, and its line does not send that organisation to a report it cannot open',
    $reqSite2 !== null && $reqSite2['status'] === 'completed' && $reqSite2['userID'] === null && (int) $reqSite2['siteID'] === SITE_2
    && count($site2Audit) === 1 && str_contains($site2Line, 'another organisation')
    && str_contains($site2Line, 'nothing more needs doing here')
    && str_contains($site2Line, 'that request') === false && str_contains($site2Line, '#' . REQ_A) === false
    && GdprEraser::verifyAuditChain(REQ_A_SITE2) === true,
    json_encode([$reqSite2, $site2Audit]));
// D's finished request, which the erased person carried out as administrator.
$requestDoneNow = ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_D_DONE]);
$closingLine    = ge_audit(REQ_A);
$closingLine    = (string) ($closingLine[count($closingLine) - 1]['details'] ?? '');
ge_ok('on somebody else\'s request that the erased person handled as administrator, only who handled it is cleared, and the closing line says so',
    (int) $requestDoneWas['processedByID'] === P_A && $requestDoneNow !== null && $requestDoneNow['processedByID'] === null
    && ge_same($requestDoneWas, $requestDoneNow, ['processedByID']) === true && count(ge_audit(REQ_D_DONE)) === 0
    && str_contains($closingLine, 'On 1 other request(s) that this person handled as an administrator'),
    json_encode([$requestDoneNow, $closingLine]));
$reqOld = ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_A_OLD]);
ge_ok('an older, cancelled request from the same account is cleared and stays "cancelled"',
    $reqOld !== null && $reqOld['status'] === 'cancelled' && $reqOld['userID'] === null && $reqOld['notes'] === null
    && $reqOld['subjectEmail'] === 'deleted-' . REQ_A_OLD . '@example.invalid', json_encode($reqOld));
$reqC = ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_C]);
ge_ok('ANOTHER person\'s waiting request is untouched',
    $reqC !== null && $reqC['status'] === 'pending_review' && (int) $reqC['userID'] === C
    && $reqC['subjectEmail'] === 'cal.control@selftest.invalid' && $reqC['notes'] === 'Cal\'s own request.', json_encode($reqC));

ge_ok('after the erasure nothing links a request to the erased account, and the OTHER person still sees their own request',
    $ownRequest(P_A) === null && ($ownRequest(C)['rows'] ?? 0) === 1, json_encode([$ownRequest(P_A), $ownRequest(C)]));

$userA = ge_one('SELECT * FROM tblUsers WHERE userID = ?', 'i', [P_A]);
ge_ok('the account row is emptied, never deleted',
    $userA !== null && $userA['fullName'] === GdprEraser::TOMBSTONE_NAME
    && $userA['emailAddress'] === 'deleted-' . REQ_A . '@example.invalid' && $userA['phoneNumber'] === null
    && $userA['displayAddress'] === null && $userA['latitude'] === null && $userA['totpSecret'] === null
    && (int) $userA['isActive'] === 0, json_encode($userA));
// Roll-out package A2a. All of these were left on the "emptied" account.
$userAWas = $beforeRowsA[ge_find($worldA, 'tblUsers', 'userID')['n']];

// THE WHOLE ROW, against an exact list (see ACCOUNT_AFTER_ERASURE for why).
$accountProblems = [];
$sorted          = array_merge(array_keys(ACCOUNT_AFTER_ERASURE), ACCOUNT_COLUMNS_LEFT_AS_THEY_WERE);
foreach (array_diff(array_keys((array) $userA), $sorted) as $column) {
    $accountProblems[] = '`' . $column . '` is on neither list: decide whether an erasure must empty it';
}
foreach (array_diff($sorted, array_keys((array) $userA)) as $column) {
    $accountProblems[] = '`' . $column . '` is on a list and is not a column of tblUsers';
}
foreach (ACCOUNT_AFTER_ERASURE as $column => $expected) {
    $expected = $expected === '@request' ? 'deleted-' . REQ_A . '@example.invalid' : $expected;
    $actual   = $userA[$column] ?? null;
    if ($actual !== $expected) {
        $accountProblems[] = '`' . $column . '` holds ' . json_encode($actual) . ', expected ' . json_encode($expected);
    }
    if (($userAWas[$column] ?? null) === $expected) {
        $accountProblems[] = '`' . $column . '` already held ' . json_encode($expected) . ' before the run, so nothing was proven';
    }
}
foreach (ACCOUNT_COLUMNS_LEFT_AS_THEY_WERE as $column) {
    if (($userA[$column] ?? null) !== ($userAWas[$column] ?? null)) {
        $accountProblems[] = '`' . $column . '` changed from ' . json_encode($userAWas[$column] ?? null) . ' to ' . json_encode($userA[$column] ?? null);
    }
}
ge_ok('the emptied account matches an EXACT list, column by column: ' . count(ACCOUNT_AFTER_ERASURE) . ' columns hold exactly what they should, '
    . count(ACCOUNT_COLUMNS_LEFT_AS_THEY_WERE) . ' are as they were, and no column of the account is on neither list',
    $accountProblems === [], implode('; ', $accountProblems));
ge_ok('the biography the person wrote is cleared, and so are the links to their photo',
    $userAWas['displayBio'] !== null && $userAWas['displayPhoto'] !== null && $userAWas['avatarPath'] !== null
    && $userA['displayBio'] === null && $userA['displayPhoto'] === null && $userA['avatarPath'] === null, json_encode($userA));
ge_ok('an emptied account is no longer an administrator, and its two-step sign-in is switched off',
    (int) $userAWas['isAdmin'] === 1 && (int) $userAWas['isRootAdmin'] === 1 && (int) $userAWas['totpEnabled'] === 1
    && (int) $userA['isAdmin'] === 0 && (int) $userA['isRootAdmin'] === 0 && (int) $userA['totpEnabled'] === 0, json_encode($userA));
// The key inside the person's private calendar-feed address. The feed looks
// an account up by it without asking whether the account is still active, so
// while it stayed on the emptied account that address went on answering.
ge_ok('the key to the person\'s private calendar feed is cleared, so the feed address stops working; so are their notification choices',
    $userAWas['calendarToken'] !== null && $userAWas['notifyPrefs'] !== null
    && $userA['calendarToken'] === null && $userA['notifyPrefs'] === null
    && ge_one('SELECT userID FROM tblUsers WHERE calendarToken = ?', 's', [(string) $userAWas['calendarToken']]) === null,
    json_encode([$userA['calendarToken'], $userA['notifyPrefs']]));
$photoLine = array_values(array_filter(ge_audit(REQ_A), static fn (array $l): bool => $l['tableName'] === 'ProfileImageFiles'));
ge_ok('the photo FILE is deleted; another person\'s photo is still there; a file another account also uses is kept, and the line says so',
    is_file($worldA['photo']) === false && is_file($controlPhoto) === true && is_file($sharedPhoto) === true
    && count($photoLine) === 1 && $photoLine[0]['action'] === 'delete' && $photoLine[0]['recordKey'] === '1 file(s)'
    && str_contains((string) $photoLine[0]['details'], '1 file(s) kept because another account uses the same one')
    && str_contains((string) $photoLine[0]['details'], (string) P_A) === false,
    json_encode([is_file($worldA['photo']), is_file($controlPhoto), is_file($sharedPhoto), $photoLine]));

// =============================================================================
ge_section('2', 'The audit trail: one line per instruction, in order, and sealed');
// =============================================================================
$auditA = ge_audit(REQ_A);
$auditProblems = ge_checkAudit($worldA, $auditA, true, [], 'completed');
ge_ok('exactly one line per instruction, each for the right table, with the right outcome and row count ('
    . count($auditA) . ' lines)', $auditProblems === [], implode('; ', array_slice($auditProblems, 0, 8)));
// CHANGED by roll-out package A2b: the giving-records step writes one line
// per organisation that holds giving records of the person's.
ge_ok('the number of lines is the instructions, the Demo Data step, the giving-records lines (' . $givingCountA
    // CHANGED by mail package M3: four dedicated lines became five (the mail queue's).
    . ', one per organisation), the five dedicated lines and the closing line',
    count($auditA) === $instructionCount + 7 + $givingCountA && $givingCountA >= 3,
    count($auditA) . ' lines for ' . $instructionCount . ' instructions and ' . $givingCountA . ' organisations');
ge_ok('the chain of the audit trail verifies', GdprEraser::verifyAuditChain(REQ_A) === true);
// CHANGED by roll-out package A2b, in two ways. The giving-records step
// writes "retain" lines of its own, under the label GivingRecords, which are
// not table instructions and are counted in the check above. And the gift
// table's "keep" instruction finds nothing here, because the made-up gift is
// not a kept gift and has already lost its donor: its line is "nothing held"
// (ge_checkAudit() expects exactly that, by name).
$keptLines = array_values(array_filter($auditA, static fn (array $l): bool => $l['action'] === 'retain' && str_starts_with((string) $l['tableName'], 'tbl') === true));
$keptCount = count(array_filter($worldA['instructions'], static fn (array $e): bool => $e['action'] === 'retain'));
ge_ok('every kept table has a "retain" line, and none says a table is missing (' . $keptCount . ' kept tables, less the gift table and the envelope table, whose made-up rows are not kept ones)',
    $keptCount >= 10 && count($keptLines) === $keptCount - 2, count($keptLines) . ' retain lines for ' . $keptCount . ' kept tables');
$notSearched = array_values(array_filter($keptLines, static fn (array $l): bool => str_contains((string) $l['details'], 'not searched')));
$noColumn    = count(array_filter($worldA['instructions'], static fn (array $e): bool => $e['action'] === 'retain' && $e['userCol'] === ''));
ge_ok('a kept table that no column ties to an account says so, rather than claiming to have looked',
    $noColumn >= 5 && count($notSearched) === $noColumn, count($notSearched) . ' of ' . $noColumn);
// ...and that statement has to be TRUE. It goes into a sealed record. It was
// made about pastoral visits, which carry the account number of whoever made
// the visit; the written list simply had not named the column. So the claim is
// checked against the database itself: its own links to accounts, and every
// column name on GdprEraser's list.
$falselyUnlinked = [];
foreach ($worldA['instructions'] as $instruction) {
    if ($instruction['action'] === 'retain' && $instruction['userCol'] === '' && ge_personColumns((string) $instruction['table']) !== []) {
        $falselyUnlinked[] = $instruction['table'] . ' (has ' . implode(', ', ge_personColumns((string) $instruction['table'])) . ')';
    }
}
ge_ok('every table the audit trail says "no column ties to an account" really has no such column in the database',
    $falselyUnlinked === [], implode('; ', $falselyUnlinked));
// CHANGED by roll-out package A2a. This used to expect ONE "retain" line
// saying the number stays because the column cannot be empty. Migration 208
// made it emptiable, so there are now two lines, one per person column, each
// saying the visit is kept and who is detached.
$visitLine = array_values(array_filter($auditA, static fn (array $l): bool => $l['tableName'] === 'tblCareVisit'));
ge_ok('tblCareVisit: two audit lines, one for who made a visit and one for who its follow-up was given to, each saying the visit is kept',
    count($visitLine) === 2 && $visitLine[0]['action'] === 'anonymise' && $visitLine[1]['action'] === 'anonymise'
    && str_contains((string) $visitLine[0]['details'], 'who made the visit is detached')
    && str_contains((string) $visitLine[1]['details'], 'who its follow-up was given to is detached')
    && str_contains((string) $visitLine[0]['details'], 'kept with the case')
    && str_contains((string) $visitLine[0]['details'], 'not searched') === false, json_encode($visitLine));

// =============================================================================
ge_section('3', 'Every instruction did what it says, and only that');
// =============================================================================
$rowsA = ge_checkRows($worldA, $beforeRowsA);
ge_ok('every made-up row was looked at (' . $rowsA['checked'] . ' rows)', $rowsA['checked'] === count($worldA['rows']) && $rowsA['checked'] >= $instructionCount);
ge_ok('delete: no row of the person is left. unlink: the row stays and their own number is gone. keep: the row is unchanged. '
    . 'And no OTHER person\'s number on any of those rows changed',
    $rowsA['problems'] === [], implode('; ', array_slice($rowsA['problems'], 0, 8)));
$stillThere = [];
foreach (MUST_BE_EMPTIED as $where => $columns) {
    [$table, $col] = explode('.', $where);
    $found = ge_find($worldA, $table, $col);
    $now   = $found === null ? null : ge_row($found['ref']);
    foreach ($columns as $column) {
        if ($found === null || $now === null) {
            $stillThere[] = $where . ': no row to look at';
        } elseif ($beforeRowsA[$found['n']][$column] === null) {
            $stillThere[] = $where . ': `' . $column . '` was empty before the run, so nothing was proven';
        } elseif ($now[$column] !== null) {
            $stillThere[] = $where . ': `' . $column . '` still holds ' . json_encode($now[$column]);
        }
    }
}
ge_ok('typed details about the person are emptied, by name: a prayer request\'s name, email address and sender address, and a pastoral case\'s name',
    $stillThere === [], implode('; ', $stillThere));
$twoColumnTables = [];
foreach ($worldA['rows'] as $tracked) {
    if ($tracked['kind'] === 'R2') {
        $twoColumnTables[] = $tracked['ref']['table'];
    }
}
// CHANGED on 11 October 2026 (#492): "at least 3" became "at least 15", now
// that every table with two or more "empty it" instructions gets such a row.
ge_ok('rows naming the person in TWO OR MORE columns were tested too, each column emptied by its own instruction (' . count($twoColumnTables)
    . ' tables: ' . implode(', ', $twoColumnTables) . ')',
    $rowsA['r2'] >= 15 && $rowsA['r2'] === count($twoColumnTables), 'only ' . $rowsA['r2']);
sort($rowsA['left']);
ge_ok('the columns that could not be emptied are exactly the known list: ' . implode(', ', KNOWN_LEFT_IN_PLACE),
    $rowsA['left'] === KNOWN_LEFT_IN_PLACE, json_encode($rowsA['left']));
$leftLines = array_values(array_filter($auditA, static fn (array $l): bool => in_array($l['action'], ['skip', 'partial'], true)));
ge_ok('...and each has an audit line saying why (' . count($leftLines) . ' line)',
    count($leftLines) === count(KNOWN_LEFT_IN_PLACE) && str_contains((string) ($leftLines[0]['details'] ?? ''), 'does not allow'),
    json_encode($leftLines));

// =============================================================================
ge_section('3b', 'Other people\'s records survive, with rows that really are linked (roll-out package A2a)');
// =============================================================================
// Until 11 October 2026 the first eight checks here would each have found the
// row DELETED: an erasure deleted every visitor, salvation card, task and rota
// slot that had been given to the person, and every swap request sent to them.
$was = $linkedA['before'];
$now = [];
foreach ($linkedA['refs'] as $name => $ref) {
    $now[$name] = ge_row($ref);
}
$linkedBuilt = array_keys(array_filter($was, static fn ($row): bool => $row === null));
ge_ok('the linked rows were all built, and each named the person where it should before the run (' . count($was) . ' rows)',
    $linkedBuilt === [] && (int) $was['visitor']['assignedToID'] === P_A && (int) $was['noteByPerson']['contactedByID'] === P_A
    && (int) $was['card']['assignedToID'] === P_A && (int) $was['taskGiven']['assignedToID'] === P_A
    && (int) $was['slotOfPerson']['assignedToID'] === P_A && (int) $was['swapWaiting']['targetUserID'] === P_A
    && (int) $was['checkin']['checkedOutByID'] === P_A && (int) $was['visitByPerson']['visitedByID'] === P_A
    && (int) $was['visitFollowUp']['followUpAssignedToID'] === P_A && (int) $was['payment']['paidByID'] === P_A
    && (int) $was['visitorIsP']['convertedUserID'] === P_A && (int) $was['meetingViaC']['createdByID'] === P_A,
    json_encode($linkedBuilt));

ge_ok('a VISITOR the person was following up is kept, with their name, contact details and notes; only the worker is detached',
    ge_same($was['visitor'], $now['visitor'], ['assignedToID']) === true && $now['visitor']['assignedToID'] === null
    && $now['visitor']['fullName'] === 'Vera Visitor' && (int) $now['visitor']['createdByID'] === D,
    json_encode($now['visitor']));
ge_ok('...and so are the follow-up notes about that visitor: the person\'s own note stays with its words and loses only its author; a colleague\'s note is untouched',
    ge_same($was['noteByPerson'], $now['noteByPerson'], ['contactedByID']) === true && $now['noteByPerson']['contactedByID'] === null
    && $now['noteByPerson']['summary'] === 'Rang Vera; she will come on Sunday.'
    && $now['noteByOther'] === $was['noteByOther'],
    json_encode([$now['noteByPerson'], $now['noteByOther']]));
ge_ok('ANOTHER PERSON\'S own visitor record (they later became a member) is kept; the erased person is detached as its worker and as who typed it',
    ge_same($was['visitorIsC'], $now['visitorIsC'], ['assignedToID', 'createdByID']) === true
    && (int) $now['visitorIsC']['convertedUserID'] === C && $now['visitorIsC']['assignedToID'] === null
    && $now['visitorIsC']['createdByID'] === null, json_encode($now['visitorIsC']));
ge_ok('the erased person\'s OWN visitor record is deleted, and the notes about them go with it',
    $now['visitorIsP'] === null && $now['noteAboutP'] === null, json_encode([$now['visitorIsP'], $now['noteAboutP']]));
ge_ok('a SALVATION CARD about somebody else is kept whole; only the worker is detached',
    ge_same($was['card'], $now['card'], ['assignedToID']) === true && $now['card']['assignedToID'] === null
    && $now['card']['fullName'] === 'Cal Control' && $now['card']['email'] === 'cal.control@selftest.invalid',
    json_encode($now['card']));
ge_ok('a TASK given to the person is kept and unassigned; a task they created for somebody else is kept, still assigned to that person',
    ge_same($was['taskGiven'], $now['taskGiven'], ['assignedToID']) === true && $now['taskGiven']['assignedToID'] === null
    && (int) $now['taskGiven']['createdByID'] === D && $now['taskGiven']['title'] === 'Ring the plumber'
    && ge_same($was['taskMade'], $now['taskMade'], ['createdByID']) === true && $now['taskMade']['createdByID'] === null
    && (int) $now['taskMade']['assignedToID'] === C, json_encode([$now['taskGiven'], $now['taskMade']]));
ge_ok('a ROTA SLOT the person was filling is kept and unfilled; a slot they set up for somebody else is kept, still filled by that person',
    ge_same($was['slotOfPerson'], $now['slotOfPerson'], ['assignedToID']) === true && $now['slotOfPerson']['assignedToID'] === null
    && ge_same($was['slotOfC'], $now['slotOfC'], ['createdByID']) === true && $now['slotOfC']['createdByID'] === null
    && (int) $now['slotOfC']['assignedToID'] === C, json_encode([$now['slotOfPerson'], $now['slotOfC']]));
ge_ok('a SWAP REQUEST aimed at the person is kept and CANCELLED, not deleted: it still shows who asked, and their message',
    ge_same($was['swapWaiting'], $now['swapWaiting'], ['targetUserID', 'status']) === true
    && $now['swapWaiting']['status'] === 'cancelled' && $now['swapWaiting']['targetUserID'] === null
    && (int) $now['swapWaiting']['requestedByID'] === C && $now['swapWaiting']['requestMessage'] === 'Could you cover me?',
    json_encode($now['swapWaiting']));
ge_ok('...one already accepted keeps its status (that is history), and a request between two other people is untouched',
    ge_same($was['swapAccepted'], $now['swapAccepted'], ['targetUserID']) === true && $now['swapAccepted']['status'] === 'accepted'
    && $now['swapAccepted']['targetUserID'] === null && $now['swapOfOthers'] === $was['swapOfOthers'],
    json_encode([$now['swapAccepted'], $now['swapOfOthers']]));
ge_ok('a CHILD\'S CHECK-IN made by somebody else and checked OUT by the person loses only the check-out number; the child\'s profile is untouched',
    ge_same($was['checkin'], $now['checkin'], ['checkedOutByID']) === true && $now['checkin']['checkedOutByID'] === null
    && (int) $now['checkin']['checkedInByID'] === C && $now['child'] === $was['child'], json_encode($now['checkin']));
ge_ok('an INVITATION the person ACCEPTED, which holds their own email address, is deleted; one they only SENT to somebody else is kept whole, with only who sent it detached',
    (int) $was['inviteAccepted']['acceptedByID'] === P_A && $now['inviteAccepted'] === null
    && ge_same($was['inviteSent'], $now['inviteSent'], ['createdByID']) === true && $now['inviteSent']['createdByID'] === null
    && (int) $now['inviteSent']['acceptedByID'] === C && $now['inviteSent']['email'] === 'cal.control@selftest.invalid',
    json_encode([$now['inviteAccepted'], $now['inviteSent']]));
ge_ok('a SWAP REQUEST the person ASKED for is kept with its message and cancelled if it was still waiting; one already accepted keeps its status; who asked is detached on both',
    (int) $was['swapAsked']['requestedByID'] === P_A
    && ge_same($was['swapAsked'], $now['swapAsked'], ['requestedByID', 'status']) === true && $now['swapAsked']['requestedByID'] === null
    && $now['swapAsked']['status'] === 'cancelled' && (int) $now['swapAsked']['targetUserID'] === C && $now['swapAsked']['requestMessage'] === 'I am away that week.'
    && ge_same($was['swapAskedDone'], $now['swapAskedDone'], ['requestedByID']) === true && $now['swapAskedDone']['requestedByID'] === null
    && $now['swapAskedDone']['status'] === 'accepted', json_encode([$now['swapAsked'], $now['swapAskedDone']]));
ge_ok('a ROOM BOOKING the person made is kept, with its times, purpose and status; only who booked it is detached',
    (int) $was['booking']['bookedByID'] === P_A && ge_same($was['booking'], $now['booking'], ['bookedByID']) === true
    && $now['booking']['bookedByID'] === null && $now['booking']['purpose'] === 'Welcome team supper' && $now['booking']['status'] === 'pending',
    json_encode($now['booking']));
ge_ok('a CHILD\'S EVENT REGISTRATION that somebody else submitted and the person only REVIEWED is kept, with the child\'s name and medical details; only the reviewer is detached',
    (int) $was['registration']['reviewedByID'] === P_A && ge_same($was['registration'], $now['registration'], ['reviewedByID']) === true
    && $now['registration']['reviewedByID'] === null && (int) $now['registration']['submittedByUserID'] === C
    && $now['registration']['fullName'] === 'Kit Control' && $now['registration']['medicalNotes'] === $was['registration']['medicalNotes']
    && $now['registration']['medicalNotes'] !== null, json_encode($now['registration']));
ge_ok('a PASTORAL CASE about somebody else is untouched; a visit the person made is kept with its notes and no number; so is a visit whose follow-up was given to them',
    $now['case'] === $was['case']
    && ge_same($was['visitByPerson'], $now['visitByPerson'], ['visitedByID']) === true && $now['visitByPerson']['visitedByID'] === null
    && $now['visitByPerson']['notes'] === 'Visited Cal at home.' && (int) $now['visitByPerson']['followUpAssignedToID'] === D
    && ge_same($was['visitFollowUp'], $now['visitFollowUp'], ['followUpAssignedToID']) === true
    && $now['visitFollowUp']['followUpAssignedToID'] === null && (int) $now['visitFollowUp']['visitedByID'] === D,
    json_encode([$now['case'], $now['visitByPerson'], $now['visitFollowUp']]));
ge_ok('ANOTHER PERSON\'S MONEY RECORDS are untouched, byte for byte: their gift, their Gift Aid declaration and their expense claim',
    $now['giftOfC'] !== null && $now['giftOfC'] === $was['giftOfC'] && $now['giftAidOfC'] !== null
    && $now['giftAidOfC'] === $was['giftAidOfC'] && $now['claimOfC'] !== null && $now['claimOfC'] === $was['claimOfC'],
    json_encode([$now['giftOfC'], $now['giftAidOfC'], $now['claimOfC']]));
ge_ok('a PAYMENT the person entered against somebody else\'s expense claim is kept, with its amount, method and reference; only who entered it is cleared',
    ge_same($was['payment'], $now['payment'], ['paidByID']) === true && $now['payment']['paidByID'] === null
    && $now['payment']['payAmount'] === $was['payment']['payAmount'] && $now['payment']['payAmount'] !== null,
    json_encode($now['payment']));
// CHANGED by roll-out package A2b: a name typed by hand on the gift
// (`donorName`) now goes with the donor's number. It used to stay.
ge_ok('the person\'s own PLEDGE is deleted; the gift given towards it is kept with its amount, date and campaign, and loses the donor (number and typed name) and the pledge link; their Gift Aid declaration is unchanged',
    $now['pledge'] === null && ge_same($was['giftToPledge'], $now['giftToPledge'], ['donorID', 'donorName', 'pledgeID']) === true
    && $now['giftToPledge']['donorID'] === null && $now['giftToPledge']['donorName'] === null && $was['giftToPledge']['donorName'] !== null
    && $now['giftToPledge']['pledgeID'] === null
    && $now['giftToPledge']['amountPence'] === $was['giftToPledge']['amountPence']
    && $now['giftToPledge']['campaignID'] === $was['giftToPledge']['campaignID']
    && $now['giftAidOfPerson'] !== null && $now['giftAidOfPerson'] === $was['giftAidOfPerson'],
    json_encode([$now['pledge'], $now['giftToPledge'], $now['giftAidOfPerson']]));
ge_ok('ZOOM: the person\'s own connection and the meeting made through it are gone; a meeting they made through somebody else\'s connection is kept, and that connection is untouched',
    $now['zoomOfPerson'] === null && $now['meetingViaPerson'] === null && $now['zoomOfC'] === $was['zoomOfC']
    && ge_same($was['meetingViaC'], $now['meetingViaC'], ['createdByID']) === true && $now['meetingViaC']['createdByID'] === null,
    json_encode([$now['zoomOfPerson'], $now['meetingViaPerson'], $now['meetingViaC']]));

// =============================================================================
ge_section('4', 'Nothing else in the whole database changed');
// =============================================================================
$printsAfterA = ge_snapshot();
// Roll-out package A2b. An erasure now ADDS rows: one kept-donor row for each
// organisation that keeps the person's name. They are the person's own, so
// they are allowed - and counted, so that "allowed" cannot hide a row too many.
$keptRowsA = ge_q('SELECT keptDonorID, siteID, fullName, clearedAt FROM tblGivingKeptDonors WHERE donorID = ? AND erasureRequestID = ? ORDER BY siteID', 'ii', [P_A, REQ_A]);
foreach ($keptRowsA as $keptRow) {
    $worldA['allowed'][ge_keyText('tblGivingKeptDonors', ['keptDonorID' => (int) $keptRow['keptDonorID']])] = true;
}
$keepingSitesA = array_values(array_filter($worldA['givingLines'], static fn (array $l): bool => $l[0] === 'GivingRecords' && $l[1] === ['retain']));
ge_ok('the erasure added exactly one kept-donor row for each organisation that keeps something (' . count($keepingSitesA)
    . '), each holding the person\'s REAL name, and none for an organisation that keeps nothing',
    count($keptRowsA) === count($keepingSitesA) && count($keptRowsA) >= 2
    && count(array_filter($keptRowsA, static fn (array $r): bool => $r['fullName'] === 'Pat Erased' && $r['clearedAt'] === null)) === count($keptRowsA)
    && array_map(static fn (array $r): string => 'organisation #' . $r['siteID'], $keptRowsA) === array_column($keepingSitesA, 2),
    json_encode($keptRowsA));
$unexpectedA  = ge_unexpectedChanges($printsBeforeA, $printsAfterA, $worldA['allowed']);
ge_ok('every row of every table that is not the person\'s own is byte-for-byte unchanged (' . count($printsAfterA) . ' rows compared)',
    $unexpectedA === [], implode('; ', array_slice($unexpectedA, 0, 10)));
$changedCount = 0;
foreach ($printsBeforeA as $key => $print) {
    if (($printsAfterA[$key] ?? null) !== $print) {
        $changedCount++;
    }
}
ge_ok('...and the comparison is not empty-handed: the person\'s own rows DID change (' . $changedCount . ' rows)',
    $changedCount >= $instructionCount - $keptCount, (string) $changedCount);
$demoNow = [
    'account'      => ge_row($worldA['demo']['account']),
    'membership'   => ge_row($worldA['demo']['membership']),
    'announcement' => ge_row($worldA['demo']['announcement']),
];
ge_ok('Demo Data list (#498): the two entries pointing at the person are gone; the announcement with the same number stays',
    $demoNow['account'] === null && $demoNow['membership'] === null && $demoNow['announcement'] !== null, json_encode($demoNow));
$logNow = ge_row($worldA['emailLog']);
ge_ok('the email send log: the person\'s address is gone and the other recipient\'s is still there',
    $logNow !== null && $logNow['toRecipients'] === '[erased], dee.control@selftest.invalid', json_encode($logNow));
ge_ok('the giving statement PDF: the person\'s file is deleted and another person\'s file is still there',
    is_file($worldA['file']) === false && is_file($controlFile) === true);

// -----------------------------------------------------------------------------
ge_section('4b', 'No delete can cascade into a record that must be kept');
// -----------------------------------------------------------------------------
// The made-up rows are not linked to each other, so this asks the database
// for its own rules instead: starting from every table an instruction deletes
// from, follow each "delete the children too" link, as far as it goes.
$catalogueFile = require $coreDir . 'personal-data-catalogue.php';
$mustKeep = ['tblUsers', 'tblErasureRequest', 'tblErasureAudit', 'tblGivingEntry', 'tblPayment', 'tblExpenseClaims', 'tblKidProfiles'];
foreach ($catalogueFile as $table => $meta) {
    // CHANGED by roll-out package A2a: 'unlink' tables count as kept too.
    // Only 'retain' did, and that is how the cascade from a person's Zoom
    // connection into `tblZoomMeeting` (which the list said was kept) went
    // unseen. A table that is BOTH deleted from and kept (group memberships:
    // the person's own rows go, rows they merely added stay) is left out,
    // because a delete "reaching" the table it is aimed at says nothing.
    if (in_array(($meta['decision'] ?? ''), ['retain', 'unlink'], true) === true) {
        $mustKeep[] = (string) $table;
    }
}
$cascades = [];
$emptied  = [];
foreach (ge_q(
    "SELECT r.REFERENCED_TABLE_NAME AS parent, r.TABLE_NAME AS child, r.DELETE_RULE AS rule, k.COLUMN_NAME AS col "
    . "FROM information_schema.REFERENTIAL_CONSTRAINTS r JOIN information_schema.KEY_COLUMN_USAGE k "
    . "ON k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA AND k.CONSTRAINT_NAME = r.CONSTRAINT_NAME AND k.TABLE_NAME = r.TABLE_NAME "
    . "WHERE r.CONSTRAINT_SCHEMA = DATABASE() AND r.DELETE_RULE IN ('CASCADE', 'SET NULL') "
    . "ORDER BY r.REFERENCED_TABLE_NAME, r.TABLE_NAME, k.COLUMN_NAME"
) as $link) {
    if ($link['rule'] === 'CASCADE') {
        $cascades[$link['parent']][$link['child']] = true;
    } else {
        $emptied[$link['parent']][] = $link['child'] . '.' . $link['col'];
    }
}
$deleteFrom = [];
foreach ($worldA['instructions'] as $instruction) {
    if ($instruction['action'] === 'delete') {
        $deleteFrom[(string) $instruction['table']] = true;
    }
}
// Every table a delete can reach: the ones deleted from, and everything the
// database then deletes below them, as far as it goes.
$reached = $deleteFrom;
$queue   = array_keys($deleteFrom);
$dragged = [];
while ($queue !== []) {
    $parent = array_shift($queue);
    foreach (array_keys($cascades[$parent] ?? []) as $child) {
        if (in_array($child, $mustKeep, true) === true && isset($deleteFrom[$child]) === false) {
            $dragged[$parent . ' -> ' . $child] = true;
        }
        if (isset($reached[$child]) === false) {
            $reached[$child] = true;
            $queue[]         = $child;
        }
    }
}
$dragged = array_keys($dragged);
sort($dragged);
$allowedCascades = array_keys(CASCADES_INTO_KEPT_TABLES);
sort($allowedCascades);
$cascadeCount = array_sum(array_map('count', $cascades));
ge_ok('the database\'s cascade rules were read (more than 100 of them)', $cascadeCount > 100, (string) $cascadeCount);
ge_ok('a delete reaches a KEPT table (kept on purpose, or kept with the name removed) only in the ' . count($allowedCascades)
    . ' named cases, each with its reason',
    $dragged === $allowedCascades && in_array('', CASCADES_INTO_KEPT_TABLES, true) === false,
    'not on the list: ' . implode('; ', array_diff($dragged, $allowedCascades)) . ' | on the list but no longer true: '
    . implode('; ', array_diff($allowedCascades, $dragged)));
// And the quieter kind: the row is kept, but the database empties a link on
// it because the row it pointed at was deleted (a gift losing its pledge).
$unlinked = [];
foreach (array_keys($reached) as $parent) {
    foreach ($emptied[$parent] ?? [] as $childColumn) {
        $child = explode('.', $childColumn)[0];
        if (in_array($child, $mustKeep, true) === true && isset($deleteFrom[$child]) === false) {
            $unlinked[$parent . ' -> ' . $childColumn] = true;
        }
    }
}
$unlinked = array_keys($unlinked);
sort($unlinked);
$allowedUnlinks = array_keys(EMPTIED_LINKS_ON_KEPT_TABLES);
sort($allowedUnlinks);
ge_ok('a delete empties a link on a KEPT table\'s rows only in the ' . count($allowedUnlinks) . ' named cases, each with its reason',
    $unlinked === $allowedUnlinks && in_array('', EMPTIED_LINKS_ON_KEPT_TABLES, true) === false,
    'not on the list: ' . implode('; ', array_diff($unlinked, $allowedUnlinks)) . ' | on the list but no longer true: '
    . implode('; ', array_diff($allowedUnlinks, $unlinked)));

// -----------------------------------------------------------------------------
ge_section('4c', 'No account number of the erased person is left anywhere, except where a named list says so');
// -----------------------------------------------------------------------------
// Every column that can hold an account number was given the person's: the
// columns an instruction matches on by ge_buildWorld(), every other one by
// ge_buildEveryPersonColumn(). Which of them still hold it?
$stillNamed = [];
foreach ($worldA['rows'] as $tracked) {
    $instruction = $worldA['instructions'][$tracked['i']];
    $column      = (string) $instruction['userCol'];
    $row         = ge_row($tracked['ref']);
    if ($tracked['kind'] === 'R1' && $instruction['table'] !== 'tblUsers' && $column !== '' && $row !== null
        && $row[$column] !== null && (int) $row[$column] === P_A
    ) {
        $stillNamed[$instruction['table'] . '.' . $column] = true;
    }
}
$unreachedGone = [];
foreach ($everyColumnA['rows'] as $tracked) {
    $column = explode('.', $tracked['where'])[1];
    $row    = ge_row($tracked['ref']);
    if ($row === null) {
        $unreachedGone[] = $tracked['where'];
    } elseif ($row[$column] !== null && (int) $row[$column] === P_A) {
        $stillNamed[$tracked['where']] = true;
    }
}
$stillNamed = array_keys($stillNamed);
sort($stillNamed);
$knownStillNamed = array_merge(...array_values(KNOWN_STILL_NAMED));
sort($knownStillNamed);
$untried = $everyColumnA['untried'];
sort($untried);
echo '   ' . count($everyColumnA['rows']) . ' more rows were made, one for each person column that no instruction matches on; '
    . count($untried) . " column(s) could not be given one.\n";
// How many different columns were given the person's number: one for each
// column an instruction matches on, and one for each column nothing matches.
// (CHANGED on 11 October 2026: this used to require at least 20 columns that
// NOTHING matched on, which was true while #492 was open. Building #492 took
// that to seven, so the floor is now on the total, which is what the check is
// for: that the whole database was covered.)
$columnsTried = [];
foreach ($worldA['instructions'] as $instruction) {
    if ((string) $instruction['userCol'] !== '') {
        $columnsTried[$instruction['table'] . '.' . $instruction['userCol']] = true;
    }
}
foreach ($everyColumnA['rows'] as $tracked) {
    $columnsTried[$tracked['where']] = true;
}
ge_ok('the person\'s number was put in every person column of every table (' . count($columnsTried)
    . ' columns), apart from an exact list of ' . count(COULD_NOT_BE_TRIED) . ' that could not be tried',
    count($columnsTried) >= 190 && $untried === COULD_NOT_BE_TRIED && $unreachedGone === [],
    'could not be tried: ' . json_encode($untried) . ' | rows that vanished: ' . json_encode($unreachedGone));
// Found by the SHAPE of its name alone (not on GdprEraser's list, not linked
// by the database): each such column must be a known look-alike, with its
// reason. Anything else is a person column nobody has dealt with.
$shapeOnly = [];
$linked    = [];
foreach (ge_q(
    "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE "
    . "WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'tblUsers' AND REFERENCED_COLUMN_NAME = 'userID'"
) as $row) {
    $linked[$row['t'] . '.' . $row['c']] = true;
}
$shapedCount = 0;
foreach (ge_q(
    "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
    . "AND DATA_TYPE IN ('int', 'bigint', 'mediumint') ORDER BY TABLE_NAME, COLUMN_NAME"
) as $row) {
    if (preg_match(PERSON_COLUMN_SHAPE, (string) $row['c']) !== 1 || str_starts_with((string) $row['t'], 'selftest_') === true) {
        continue;
    }
    $shapedCount++;
    if (in_array($row['c'], GdprEraser::LINK_COLUMNS, true) === false && isset($linked[$row['t'] . '.' . $row['c']]) === false) {
        $shapeOnly[] = $row['t'] . '.' . $row['c'];
    }
}
sort($shapeOnly);
$lookAlikes = array_keys(LOOKS_LIKE_A_PERSON_COLUMN_AND_IS_NOT);
sort($lookAlikes);
ge_ok('person columns are also looked for by the SHAPE of their name (' . $shapedCount . ' columns have it): the only ones that are on no list and have no link are the '
    . count($lookAlikes) . ' named look-alikes',
    $shapedCount > 150 && $shapeOnly === $lookAlikes,
    'looks like a person column, on no list, no link, and not a named look-alike: ' . implode(', ', array_diff($shapeOnly, $lookAlikes))
    . ' | named as a look-alike but no longer one: ' . implode(', ', array_diff($lookAlikes, $shapeOnly)));
// The one place an account number is kept as TEXT. Pinned, not built.
$stepNow = ge_row($stepForPerson);
ge_ok('PINNED, not built: a step of an approval route given to the person by name still holds their number as text ('
    . implode(', ', array_keys(TEXT_THAT_CAN_HOLD_AN_ACCOUNT_NUMBER)) . '). When that is decided and built, this check and its list must change',
    array_keys(TEXT_THAT_CAN_HOLD_AN_ACCOUNT_NUMBER) === ['tblWorkflowSteps.assigneeValue'] && $stepNow !== null
    && $stepNow['assigneeType'] === 'user' && $stepNow['assigneeValue'] === (string) P_A, json_encode($stepNow));
ge_ok('after the erasure, the columns that still hold the person\'s number are EXACTLY the named list (' . count($knownStillNamed) . ' columns)',
    $stillNamed === $knownStillNamed,
    'still named and NOT on the list: ' . implode(', ', array_diff($stillNamed, $knownStillNamed))
    . ' | on the list but no longer named: ' . implode(', ', array_diff($knownStillNamed, $stillNamed)));

// =============================================================================
ge_section('5', 'The tables #535 was about, each by name');
// =============================================================================
$named = [
    ['tblRecording', 'uploadedByID'], ['tblRecording', 'presenterID'], ['tblAssetAudit', 'actorUserID'],
    ['tblAssetScanLog', 'actorUserID'], ['tblExternalFeedRuns', 'triggeredByID'], ['tblServicePlanItem', 'presenterID'],
    ['tblLiveChatMessages', 'moderatedByID'],
];
foreach ($named as [$table, $col]) {
    $found = ge_find($worldA, $table, $col);
    $now   = $found === null ? null : ge_row($found['ref']);
    $was   = $found === null ? null : $beforeRowsA[$found['n']];
    ge_ok($table . '.' . $col . ': the record is kept, and the person\'s number is emptied',
        $found !== null && $found['action'] === 'anonymise' && $was !== null && (int) $was[$col] === P_A
        && $now !== null && $now[$col] === null, json_encode([$found['action'] ?? 'no instruction', $now]));
}
$viewer = ge_find($worldA, 'tblCareAccessLog', 'viewerID');
ge_ok('tblCareAccessLog.viewerID: the line saying the person opened a file is deleted',
    $viewer !== null && $viewer['action'] === 'delete' && $beforeRowsA[$viewer['n']] !== null && ge_row($viewer['ref']) === null);
$chat = ge_find($worldA, 'tblLiveChatMessages', 'moderatedByID');
$chatNow = $chat === null ? null : ge_row($chat['ref']);
$chatWas = $chat === null ? null : $beforeRowsA[$chat['n']];
ge_ok('tblLiveChatMessages: the sender\'s typed name, address and message are untouched (they belong to somebody else)',
    $chatNow !== null && $chatNow['displayName'] === $chatWas['displayName'] && $chatNow['senderIP'] === $chatWas['senderIP']
    && $chatNow['body'] === $chatWas['body'], json_encode($chatNow));

// -----------------------------------------------------------------------------
ge_section('5b', 'The records that must never be lost, each by name');
// -----------------------------------------------------------------------------
foreach ([['tblGivingEntry', 'donorID'], ['tblPayment', 'userID'], ['tblExpenseClaims', 'userID']] as [$table, $col]) {
    $found = ge_find($worldA, $table, $col);
    $now   = $found === null ? null : ge_row($found['ref']);
    $was   = $found === null ? null : $beforeRowsA[$found['n']];
    $same  = $now !== null;
    foreach ((array) $now as $name => $value) {
        // `updatedAt` is stamped by the database itself whenever a row is
        // saved, so it is the one other column allowed to differ.
        // CHANGED by roll-out package A2b: on a gift, a name typed by hand
        // (`donorName`) goes with the number, as the owner asked. It used
        // to be left on the row.
        if ($name !== $col && $name !== 'updatedAt' && ($table !== 'tblGivingEntry' || $name !== 'donorName') && $value !== $was[$name]) {
            $same = false;
        }
    }
    ge_ok($table . ': the row and every amount on it stay; only the person\'s own number may go',
        $found !== null && $same === true, json_encode([$was, $now]));
}
foreach ([['tblDbsChecks', 'userID'], ['tblAnonymousCheckins', '']] as [$table, $col]) {
    $found = ge_find($worldA, $table, $col);
    ge_ok($table . ': kept, and not one column changed',
        $found !== null && $found['action'] === 'retain' && ge_row($found['ref']) === $beforeRowsA[$found['n']]);
}
// CHANGED by roll-out package A2a. tblCareVisit was in the loop above ("kept,
// and not one column changed"), because the visitor's number could not be
// removed. It can now, so the check is: the visit and its notes are kept, and
// the visitor's number - nothing else - is gone.
$careVisit = ge_find($worldA, 'tblCareVisit', 'visitedByID');
ge_ok('tblCareVisit: the visit is kept with its notes and its case; only the number of whoever made it is emptied',
    $careVisit !== null && $careVisit['action'] === 'anonymise' && ge_row($careVisit['ref']) !== null
    && ge_same($beforeRowsA[$careVisit['n']], ge_row($careVisit['ref']), ['visitedByID']) === true
    && ge_row($careVisit['ref'])['visitedByID'] === null && ge_row($careVisit['ref'])['notes'] !== null,
    json_encode($careVisit === null ? null : ge_row($careVisit['ref'])));
// Roll-out decision 8 (Salem874, 11 October 2026): kept for tax reasons. It
// was DELETED on erasure until then, so this is checked by name.
$giftAid     = ge_find($worldA, 'tblGiftAidDeclaration', 'donorID');
$giftAidLine = array_values(array_filter($auditA, static fn (array $l): bool => $l['tableName'] === 'tblGiftAidDeclaration'));
// CHANGED by roll-out package A2b: the line used to have to name the United
// Kingdom's tax office. The product is used in any country, so it must NOT
// name one any more, nor a number of years; it points at the giving-records
// line, which carries the organisation's own date.
ge_ok('tblGiftAidDeclaration: the declaration is kept, not one column changed, and the audit trail says it was kept on purpose, without naming one country\'s tax office or a number of years',
    $giftAid !== null && $giftAid['action'] === 'retain' && $beforeRowsA[$giftAid['n']] !== null
    && ge_row($giftAid['ref']) === $beforeRowsA[$giftAid['n']]
    && count($giftAidLine) === 1 && $giftAidLine[0]['action'] === 'retain'
    && str_contains((string) $giftAidLine[0]['details'], 'kept on purpose')
    && str_contains((string) $giftAidLine[0]['details'], 'keep-until date')
    && str_contains((string) $giftAidLine[0]['details'], 'GivingRecords')
    && preg_match('/HMRC|six years|6 years/i', (string) $giftAidLine[0]['details']) !== 1,
    json_encode([$giftAid['action'] ?? 'no instruction', $giftAid === null ? null : ge_row($giftAid['ref']), $giftAidLine]));
$statement = ge_find($worldA, 'tblGivingStatementLog', 'donorID');
$statementNow = $statement === null ? null : ge_row($statement['ref']);
$statementWas = $statement === null ? null : $beforeRowsA[$statement['n']];
ge_ok('tblGivingStatementLog: kept with its totals and period; only the mailed-to address is emptied',
    $statementNow !== null && $statementNow['emailedTo'] === null && $statementWas['emailedTo'] !== null
    && (int) $statementNow['donorID'] === P_A && $statementNow['totalPence'] === $statementWas['totalPence']
    && $statementNow['periodKey'] === $statementWas['periodKey'], json_encode($statementNow));
$kid = ge_find($worldA, 'tblKidProfiles', 'parentUserID');
$kidNow = $kid === null ? null : ge_row($kid['ref']);
$kidWas = $kid === null ? null : $beforeRowsA[$kid['n']];
ge_ok('tblKidProfiles: the child\'s record stays with its name and medical notes; only the parent link is emptied',
    $kidNow !== null && $kidNow['parentUserID'] === null && $kidNow['fullName'] === $kidWas['fullName']
    && $kidNow['medicalNotes'] === $kidWas['medicalNotes'], json_encode($kidNow));

// -----------------------------------------------------------------------------
ge_section('5c', 'The send log: only an entry that IS the address is replaced (roll-out package A2a)');
// -----------------------------------------------------------------------------
// The step used to replace the text wherever it appeared, so erasing
// pat.erased@... also rewrote somebody else's xpat.erased@... .
$besideLonger = ge_row($logBesideLonger);
$onlyLonger   = ge_row($logOnlyLonger);
ge_ok('a LONGER address that merely contains the person\'s address is left exactly as it was, beside theirs and on its own',
    ($besideLonger['toRecipients'] ?? '') === 'xpat.erased@selftest.invalid, [erased]'
    && ($onlyLonger['toRecipients'] ?? '') === 'xpat.erased@selftest.invalid', json_encode([$besideLonger, $onlyLonger]));
$capitals = ge_row($logCapitals);
ge_ok('the person\'s address written with capitals, and with no space after the comma, is still found; the list keeps its shape',
    ($capitals['toRecipients'] ?? '') === '[erased],dee.control@selftest.invalid', json_encode($capitals));

// =============================================================================
ge_section('6', 'A step that fails: recorded, the rest still run, and a second run finishes');
// =============================================================================
$worldB = ge_buildWorld(P_B, 'bo.second@selftest.invalid', 'Bo Second');
ge_request(REQ_B, P_B, 'bo.second@selftest.invalid', 'Bo Second', 'processing', 'Bo asked by letter.');
$worldB['allowed'][ge_keyText('tblErasureRequest', ['requestID' => REQ_B])] = true;
$beforeRowsB = [];
foreach ($worldB['rows'] as $n => $tracked) {
    $beforeRowsB[$n] = ge_row($tracked['ref']);
}
$printsBeforeB = ge_snapshot();
$worldB['givingLines'] = ge_expectedGivingLines(P_B);

// One table's update is made to fail, the way a lock or a full disk would.
ge_trigger('selftest_gdpr_fail_step', "CREATE TRIGGER `selftest_gdpr_fail_step` BEFORE UPDATE ON `tblExternalFeedRuns` "
    . "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'selftest: this update was made to fail'");
$resultB1 = ge_execute(REQ_B, P_B);
ge_ok('the run still ends without an exception, and reports exactly one failed step',
    // CHANGED by mail package M3: + 6 became + 7 (the mail queue's step), here and four lines down.
    is_array($resultB1) === true && $resultB1['failed'] === 1 && $resultB1['steps'] === $instructionCount + 7,
    $resultB1 instanceof Throwable ? get_class($resultB1) . ': ' . $resultB1->getMessage() : json_encode($resultB1));
$reqB = ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_B]);
ge_ok('the request is back at "pending review" with its account number, name, address and notes all still on it',
    $reqB !== null && $reqB['status'] === 'pending_review' && (int) $reqB['userID'] === P_B
    && $reqB['subjectEmail'] === 'bo.second@selftest.invalid' && $reqB['subjectName'] === 'Bo Second'
    && $reqB['notes'] === 'Bo asked by letter.' && $reqB['processedAt'] === null, json_encode($reqB));
$auditB1 = ge_audit(REQ_B);
$auditB1Problems = ge_checkAudit($worldB, $auditB1, true, ['tblExternalFeedRuns'], 'failed');
ge_ok('the audit trail has a line for EVERY instruction: one "failed", and nothing after it was skipped',
    $auditB1Problems === [], implode('; ', array_slice($auditB1Problems, 0, 8)));
$summary = $auditB1[count($auditB1) - 1] ?? ['details' => ''];
ge_ok('its last line says how many steps failed and that the request can be run again',
    str_contains((string) $summary['details'], '1 of ' . ($instructionCount + 7) . ' steps failed')
    && str_contains((string) $summary['details'], 'back at pending review'), (string) $summary['details']);
$rowsB1 = ge_checkRows($worldB, $beforeRowsB, ['tblExternalFeedRuns.triggeredByID']);
ge_ok('the failed table\'s row is untouched, and every other instruction still did its work',
    $rowsB1['problems'] === [], implode('; ', array_slice($rowsB1['problems'], 0, 8)));

// Between the two runs a message to the person's address is logged. The
// account row is already emptied, so only the request still knows the address.
ge_dropTrigger('selftest_gdpr_fail_step');
$lateLog = ge_insert('tblEmailLog', [], ['toRecipients' => 'bo.second@selftest.invalid']);
$worldB['allowed'][ge_keyText('tblEmailLog', $lateLog['key'])] = true;
// Roll-out package A2b. After the FIRST run the account is already emptied
// ("[Deleted User]"), and the kept rows hold the only copy of the name.
$keptAfterB1 = ge_q('SELECT keptDonorID, siteID, fullName, address, postcode, lastGiftDate, createdAt FROM tblGivingKeptDonors WHERE donorID = ? AND erasureRequestID = ? ORDER BY keptDonorID', 'ii', [P_B, REQ_B]);
$accountB1   = ge_one('SELECT fullName FROM tblUsers WHERE userID = ?', 'i', [P_B]);
$worldB['givingLines'] = ge_expectedGivingLines(P_B);
$givingCountB2         = count($worldB['givingLines']);
ge_q("UPDATE tblErasureRequest SET status = 'processing' WHERE requestID = ? AND status = 'pending_review'", 'i', [REQ_B]);
$resultB2 = ge_execute(REQ_B, P_B);
$keptAfterB2 = ge_q('SELECT keptDonorID, siteID, fullName, address, postcode, lastGiftDate, createdAt FROM tblGivingKeptDonors WHERE donorID = ? AND erasureRequestID = ? ORDER BY keptDonorID', 'ii', [P_B, REQ_B]);
foreach ($keptAfterB2 as $keptRow) {
    $worldB['allowed'][ge_keyText('tblGivingKeptDonors', ['keptDonorID' => (int) $keptRow['keptDonorID']])] = true;
}
ge_ok('A SECOND RUN NEVER OVERWRITES THE KEPT NAME: the account already said "[Deleted User]" when it ran, and every kept row still holds the real name, unchanged, with no row added',
    $accountB1['fullName'] === GdprEraser::TOMBSTONE_NAME && count($keptAfterB1) >= 2 && $keptAfterB2 === $keptAfterB1
    && count(array_filter($keptAfterB2, static fn (array $r): bool => $r['fullName'] === 'Bo Second')) === count($keptAfterB2),
    json_encode([$accountB1, $keptAfterB1, $keptAfterB2]));
ge_ok('run again: no failed step',
    is_array($resultB2) === true && $resultB2['failed'] === 0,
    $resultB2 instanceof Throwable ? get_class($resultB2) . ': ' . $resultB2->getMessage() : json_encode($resultB2));
$reqB2 = ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_B]);
ge_ok('the request is now "completed" and cleared',
    $reqB2 !== null && $reqB2['status'] === 'completed' && $reqB2['userID'] === null
    && $reqB2['subjectEmail'] === 'deleted-' . REQ_B . '@example.invalid', json_encode($reqB2));
$rowsB2 = ge_checkRows($worldB, $beforeRowsB);
ge_ok('every instruction has now done its work, the one that failed included', $rowsB2['problems'] === [],
    implode('; ', array_slice($rowsB2['problems'], 0, 8)));
// The second run finds almost nothing left to do. It must still write a line
// for every instruction - "nothing held" - or a step that never ran would
// look exactly like a step with nothing to do.
$auditB2   = array_slice(ge_audit(REQ_B), count($auditB1));
$noneLines = count(array_filter($auditB2, static fn (array $l): bool => $l['action'] === 'none'));
ge_ok('the second run also wrote one line per instruction, most of them "nothing held" (' . count($auditB2) . ' lines, ' . $noneLines . ' "none")',
    // CHANGED by roll-out package A2b: plus one "already dealt with" line
    // for each organisation that kept something on the first run.
    // (And one line for the counted envelopes, which is why "- 1" below.)
    // CHANGED by mail package M3: + 5 became + 6 (the mail queue's line).
    count($auditB2) === $instructionCount + 6 + $givingCountB2 && $givingCountB2 >= 3 && $noneLines > 100
    && ($auditB2[count($auditB2) - 1]['action'] ?? '') === 'completed'
    && count(array_filter($auditB2, static fn (array $l): bool => $l['tableName'] === 'GivingRecords'
        && str_contains((string) $l['details'], 'already dealt with by an earlier run')
        && str_contains((string) $l['details'], 'was not overwritten'))) === $givingCountB2 - 1,
    count($auditB2) . ' lines, expected ' . ($instructionCount + 6 + $givingCountB2));
$lateNow = ge_row($lateLog);
ge_ok('the second run still found the person\'s address: the message logged between the runs is scrubbed',
    $lateNow !== null && $lateNow['toRecipients'] === '[erased]', json_encode($lateNow));
ge_ok('the audit trail of both runs together still verifies', GdprEraser::verifyAuditChain(REQ_B) === true);
$unexpectedB = ge_unexpectedChanges($printsBeforeB, ge_snapshot(), $worldB['allowed']);
ge_ok('across both runs, nothing that was not the person\'s own changed anywhere in the database',
    $unexpectedB === [], implode('; ', array_slice($unexpectedB, 0, 10)));

// =============================================================================
ge_section('7', 'If the audit trail cannot be written, the run stops and nothing is half-recorded');
// =============================================================================
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_user(P_C, 'Cy Third', 'cy.third@selftest.invalid');
$first    = GdprEraser::catalogue()[0];
$firstRef = ge_insert((string) $first['table'], [(string) $first['userCol'] => P_C]);
ge_ensureParents($firstRef);
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_request(REQ_PC, P_C, 'cy.third@selftest.invalid', 'Cy Third', 'processing');
$firstWas = ge_row($firstRef);

ge_trigger('selftest_gdpr_fail_audit', "CREATE TRIGGER `selftest_gdpr_fail_audit` BEFORE INSERT ON `tblErasureAudit` "
    . "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'selftest: the audit trail was made to fail'");
$resultC1 = ge_execute(REQ_PC, P_C);
ge_dropTrigger('selftest_gdpr_fail_audit');
ge_ok('the run stops with a plain message that says the request can be run again',
    $resultC1 instanceof RuntimeException && str_contains($resultC1->getMessage(), 'stopped before it finished')
    && str_contains($resultC1->getMessage(), 'back at pending review'),
    $resultC1 instanceof Throwable ? $resultC1->getMessage() : json_encode($resultC1));
$reqPc = ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_PC]);
ge_ok('the request is back at "pending review" with its account number still on it',
    $reqPc !== null && $reqPc['status'] === 'pending_review' && (int) $reqPc['userID'] === P_C, json_encode($reqPc));
ge_ok('the step whose audit line could not be written was undone: the row still names the person, and no audit line exists',
    ge_row($firstRef) === $firstWas && ge_audit(REQ_PC) === [], json_encode([ge_row($firstRef), count(ge_audit(REQ_PC))]));
$userC = ge_one('SELECT fullName, emailAddress FROM tblUsers WHERE userID = ?', 'i', [P_C]);
ge_ok('nothing later ran: the account row is not emptied', $userC !== null && $userC['fullName'] === 'Cy Third', json_encode($userC));
ge_q("UPDATE tblErasureRequest SET status = 'processing' WHERE requestID = ?", 'i', [REQ_PC]);
$resultC2 = ge_execute(REQ_PC, P_C);
$reqPc2   = ge_one('SELECT status, userID FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_PC]);
ge_ok('run again with the audit trail working: completed, and the first step is now recorded',
    // CHANGED by roll-out package A2b: the first line of a run is now the
    // giving-records step's ("no giving records are held" for this person),
    // and the first instruction's line is the second.
    is_array($resultC2) === true && $resultC2['failed'] === 0 && $reqPc2['status'] === 'completed'
    // (Then the counted-envelopes line, so the first instruction's is the third.)
    && (ge_audit(REQ_PC)[0]['tableName'] ?? '') === 'GivingRecords' && (ge_audit(REQ_PC)[0]['action'] ?? '') === 'none'
    && (ge_audit(REQ_PC)[1]['tableName'] ?? '') === 'CountEnvelopes' && (ge_audit(REQ_PC)[1]['action'] ?? '') === 'none'
    // CHANGED by mail package M3: then the two steps that find the person by
    // their e-mail address (the mail queue, then the send log), so the first
    // instruction's line is the fifth.
    && (ge_audit(REQ_PC)[2]['tableName'] ?? '') === 'tblMailQueue' && (ge_audit(REQ_PC)[2]['action'] ?? '') === 'none'
    && (ge_audit(REQ_PC)[3]['tableName'] ?? '') === 'tblEmailLog'
    && (ge_audit(REQ_PC)[4]['tableName'] ?? '') === (string) $first['table']
    && in_array(ge_audit(REQ_PC)[4]['action'] ?? '', ['anonymise', 'delete'], true),
    $resultC2 instanceof Throwable ? $resultC2->getMessage() : json_encode([$resultC2, $reqPc2, ge_audit(REQ_PC)[0] ?? null, ge_audit(REQ_PC)[4] ?? null]));

// =============================================================================
ge_section('7b', 'A file that cannot be deleted, and a send log that cannot be updated');
// =============================================================================
// Two steps that are not table instructions. Each used to be passed over when
// it failed: the statement with the person's name on it stayed on disk, or
// their address stayed in the send log, and the erasure was reported complete.
// One run for each, so each is shown on its own to stop the request closing.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_user(P_E, 'Eli Fifth', 'eli.fifth@selftest.invalid');
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_request(REQ_E, P_E, 'eli.fifth@selftest.invalid', 'Eli Fifth', 'processing');
$dirE  = dirname($statementDir) . DIRECTORY_SEPARATOR . '2';
$fileE = $dirE . DIRECTORY_SEPARATOR . 'statement-' . P_E . '-2025.pdf';
mkdir($dirE, 0700);
file_put_contents($fileE, 'made-up statement');
$GLOBALS['ge_files'][] = $fileE;
$failedLines = static fn (int $requestId, int $from): array => array_values(array_filter(
    array_slice(ge_audit($requestId), $from),
    static fn (array $l): bool => $l['action'] === 'failed' && $l['tableName'] !== 'tblErasureRequest'
));
$isRoot = function_exists('posix_geteuid') === true && posix_geteuid() === 0;

// Run 1: the folder is read-only, so the file cannot be deleted.
chmod($dirE, 0500);
$resultE1 = ge_execute(REQ_E, P_E);
chmod($dirE, 0700);
$failedE1 = $failedLines(REQ_E, 0);
$reqE1    = ge_one('SELECT status, userID FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_E]);
ge_ok('a statement file that cannot be deleted is a failed step, recorded as one',
    is_array($resultE1) === true && $resultE1['failed'] === 1 && count($failedE1) === 1
    && $failedE1[0]['tableName'] === 'GivingStatementPdfFiles' && str_contains((string) $failedE1[0]['details'], 'still on disk'),
    ($isRoot === true ? 'THIS RUN IS ROOT, which a read-only folder cannot stop: run it as an ordinary user. ' : '')
    . ($resultE1 instanceof Throwable ? $resultE1->getMessage() : json_encode([$resultE1, $failedE1])));
ge_ok('...so the request is NOT closed, and the file is still there to be deleted next time',
    $reqE1['status'] === 'pending_review' && (int) $reqE1['userID'] === P_E && is_file($fileE) === true, json_encode($reqE1));

// Run 2: the file can now go, but the send log cannot be updated.
$logE = ge_insert('tblEmailLog', [], ['toRecipients' => 'eli.fifth@selftest.invalid']);
ge_trigger('selftest_gdpr_fail_log', "CREATE TRIGGER `selftest_gdpr_fail_log` BEFORE UPDATE ON `tblEmailLog` "
    . "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'selftest: the send log was made to fail'");
$linesSoFar = count(ge_audit(REQ_E));
ge_q("UPDATE tblErasureRequest SET status = 'processing' WHERE requestID = ?", 'i', [REQ_E]);
$resultE2 = ge_execute(REQ_E, P_E);
ge_dropTrigger('selftest_gdpr_fail_log');
$failedE2 = $failedLines(REQ_E, $linesSoFar);
$reqE2    = ge_one('SELECT status, userID FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_E]);
ge_ok('a send log that cannot be updated is a failed step, recorded as one',
    is_array($resultE2) === true && $resultE2['failed'] === 1 && count($failedE2) === 1 && $failedE2[0]['tableName'] === 'tblEmailLog',
    $resultE2 instanceof Throwable ? $resultE2->getMessage() : json_encode([$resultE2, $failedE2]));
ge_ok('...so the request is NOT closed, and the address is still in the send log to be removed next time',
    $reqE2['status'] === 'pending_review' && (int) $reqE2['userID'] === P_E
    && (ge_row($logE)['toRecipients'] ?? '') === 'eli.fifth@selftest.invalid' && is_file($fileE) === false,
    json_encode([$reqE2, ge_row($logE), is_file($fileE)]));

// Run 3: nothing in the way.
ge_q("UPDATE tblErasureRequest SET status = 'processing' WHERE requestID = ?", 'i', [REQ_E]);
$resultE3 = ge_execute(REQ_E, P_E);
$reqE3    = ge_one('SELECT status, userID FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_E]);
ge_ok('with both put right, the next run completes: the file is gone and the address is out of the send log',
    is_array($resultE3) === true && $resultE3['failed'] === 0 && $reqE3['status'] === 'completed'
    && is_file($fileE) === false && (ge_row($logE)['toRecipients'] ?? '') === '[erased]',
    $resultE3 instanceof Throwable ? $resultE3->getMessage() : json_encode([$resultE3, $reqE3, ge_row($logE)]));
@rmdir($dirE);

// =============================================================================
ge_section('7c', 'A database that is missing a column, and one that is missing a table');
// =============================================================================
// The two look alike and must not be treated alike. A missing TABLE is an app
// that was never installed: nothing can be held there, so the step is skipped.
// A missing COLUMN is a table that exists and was never looked at: that is a
// failed step, and the request must not be closed as if it had been.
// (Both were once written down as "table-missing" and the request closed.)
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_user(P_F, 'Fen Sixth', 'fen.sixth@selftest.invalid');
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_request(REQ_F, P_F, 'fen.sixth@selftest.invalid', 'Fen Sixth', 'processing');
$mysqli->query('ALTER TABLE `tblAssetAudit` DROP COLUMN `actorUserID`');
$mysqli->query('RENAME TABLE `tblUserTours` TO `selftest_hidden_user_tours`');
$resultF1 = ge_execute(REQ_F, P_F);
$mysqli->query('RENAME TABLE `selftest_hidden_user_tours` TO `tblUserTours`');
$mysqli->query('ALTER TABLE `tblAssetAudit` ADD COLUMN `actorUserID` INT DEFAULT NULL, ADD KEY `idx_astaud_actor` (`actorUserID`)');
$auditF1  = ge_audit(REQ_F);
$columnF  = array_values(array_filter($auditF1, static fn (array $l): bool => $l['tableName'] === 'tblAssetAudit'));
$tableF   = array_values(array_filter($auditF1, static fn (array $l): bool => $l['tableName'] === 'tblUserTours'));
$reqF1    = ge_one('SELECT status, userID, subjectEmail FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_F]);
ge_ok('a missing column is a failed step and the request is NOT closed: back at "pending review" with everything on it',
    is_array($resultF1) === true && $resultF1['failed'] === 1 && $reqF1['status'] === 'pending_review'
    && (int) $reqF1['userID'] === P_F && $reqF1['subjectEmail'] === 'fen.sixth@selftest.invalid',
    $resultF1 instanceof Throwable ? $resultF1->getMessage() : json_encode([$resultF1, $reqF1]));
ge_ok('the audit line names the column and does not call it a missing table; the missing TABLE is a skip, not a failure',
    count($columnF) === 1 && $columnF[0]['action'] === 'failed' && str_contains((string) $columnF[0]['details'], 'actorUserID')
    && str_contains((string) $columnF[0]['details'], 'table-missing') === false
    && count($tableF) === 1 && $tableF[0]['action'] === 'skip' && $tableF[0]['details'] === 'table-missing',
    json_encode([$columnF, $tableF]));
ge_q("UPDATE tblErasureRequest SET status = 'processing' WHERE requestID = ?", 'i', [REQ_F]);
// This run is pressed by the person being erased (an administrator carrying
// out their own request), for the check after next.
$resultF2 = ge_execute(REQ_F, P_F, P_F);
$reqF2    = ge_one('SELECT status, processedByID, processedAt FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_F]);
ge_ok('once the column is back (the database upgraded), running the request again completes it',
    is_array($resultF2) === true && $resultF2['failed'] === 0 && $reqF2['status'] === 'completed',
    $resultF2 instanceof Throwable ? $resultF2->getMessage() : json_encode([$resultF2, $reqF2]));
ge_ok('an administrator who carries out their OWN erasure is not written onto the request as who did it (that would put their number straight back)',
    $reqF2['processedByID'] === null && $reqF2['processedAt'] !== null, json_encode($reqF2));

// =============================================================================
ge_section('7d', 'The person\'s photo: what is refused, and a file that cannot be deleted (roll-out package A2a)');
// =============================================================================
// A path kept in a database column must not be trusted with the right to
// delete files. Three things are refused, and each must leave the file where
// it is, leave the links on the account, and count as a FAILED step.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_user(P_G, 'Gil Seventh', 'gil.seventh@selftest.invalid');
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_request(REQ_G, P_G, 'gil.seventh@selftest.invalid', 'Gil Seventh', 'processing');
$photoStep  = new ReflectionMethod(GdprEraser::class, 'eraseProfileImages');
$photoState = static fn (): array => ge_one('SELECT avatarPath, displayPhoto FROM tblUsers WHERE userID = ?', 'i', [P_G]);
$lastAudit  = static function (int $requestId): array {
    $lines = ge_audit($requestId);

    return $lines[count($lines) - 1] ?? ['action' => '', 'tableName' => '', 'details' => ''];
};

// 1. An image, but OUTSIDE the uploads folder, reached with "..".
$outside = ge_file('outside-the-uploads-folder.jpg');
ge_q("UPDATE tblUsers SET avatarPath = NULL, displayPhoto = '../outside-the-uploads-folder.jpg' WHERE userID = ?", 'i', [P_G]);
$outcomeOutside = $photoStep->invoke(null, REQ_G, P_G);
ge_ok('a photo path that climbs OUT of the uploads folder is refused: a failed step, the file untouched, the link left on the account',
    $outcomeOutside === 'failed' && is_file($outside) === true && $photoState()['displayPhoto'] === '../outside-the-uploads-folder.jpg'
    && $lastAudit(REQ_G)['action'] === 'failed' && $lastAudit(REQ_G)['tableName'] === 'ProfileImageFiles'
    && str_contains((string) $lastAudit(REQ_G)['details'], 'outside-the-uploads-folder') === false,
    json_encode([$outcomeOutside, is_file($outside), $photoState(), $lastAudit(REQ_G)]));

// 2. Inside the uploads folder, but NOT an image: another person's statement.
ge_q("UPDATE tblUsers SET displayPhoto = ? WHERE userID = ?", 'si', ['giving/statements/1/statement-' . C . '-2025.pdf', P_G]);
$outcomePdf = $photoStep->invoke(null, REQ_G, P_G);
ge_ok('a photo path that points at a file which is not an image (another person\'s giving statement) is refused, and that file is untouched',
    $outcomePdf === 'failed' && is_file($controlFile) === true && $photoState()['displayPhoto'] !== null
    && $lastAudit(REQ_G)['action'] === 'failed', json_encode([$outcomePdf, is_file($controlFile), $photoState()]));

// 2b. In the WEB folder. A photo address without "http" in front means a file
//     under the web folder (Portal\Core\Avatar). Nothing in the web folder is
//     ever deleted on the say-so of a column: that folder holds the portal's
//     own pages and pictures. A file found there is refused, so that a person
//     looks at it. (The web folder is wherever the web server says it is;
//     here it is a made-up one inside the scratch folder.)
$inWebFolder = ge_file('made-up-web-folder/uploads/avatars/' . P_G . '.jpg');
$_SERVER['DOCUMENT_ROOT'] = PORTAL_ROOT . DIRECTORY_SEPARATOR . 'made-up-web-folder';
ge_q("UPDATE tblUsers SET displayPhoto = NULL, avatarPath = ? WHERE userID = ?", 'si', ['uploads/avatars/' . P_G . '.jpg', P_G]);
$outcomeWeb = $photoStep->invoke(null, REQ_G, P_G);
unset($_SERVER['DOCUMENT_ROOT']);
ge_ok('a photo that is a file in the WEB folder is refused too: a failed step, the file untouched, the link left on the account',
    $outcomeWeb === 'failed' && is_file($inWebFolder) === true && $photoState()['avatarPath'] === 'uploads/avatars/' . P_G . '.jpg'
    && $lastAudit(REQ_G)['action'] === 'failed', json_encode([$outcomeWeb, is_file($inWebFolder), $photoState()]));
ge_q('UPDATE tblUsers SET avatarPath = NULL WHERE userID = ?', 'i', [P_G]);

// 3. A real photo that cannot be deleted (its folder is read-only). Through
//    the whole erasure this time, to show the request is NOT closed.
$lockedPhoto = ge_file('_uploads/directory-locked/' . P_G . '.png');
ge_q("UPDATE tblUsers SET displayPhoto = ? WHERE userID = ?", 'si', ['directory-locked/' . P_G . '.png', P_G]);
chmod(dirname($lockedPhoto), 0500);
$resultG1 = ge_execute(REQ_G, P_G);
chmod(dirname($lockedPhoto), 0700);
$reqG1    = ge_one('SELECT status, userID FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_G]);
$failedG1 = $failedLines(REQ_G, 3);
ge_ok('a photo file that cannot be deleted is a failed step: the request is NOT closed, the file is still there, and so is the link to it',
    is_array($resultG1) === true && $resultG1['failed'] === 1 && count($failedG1) === 1
    && $failedG1[0]['tableName'] === 'ProfileImageFiles' && str_contains((string) $failedG1[0]['details'], 'still on disk')
    && $reqG1['status'] === 'pending_review' && (int) $reqG1['userID'] === P_G && is_file($lockedPhoto) === true
    && $photoState()['displayPhoto'] === 'directory-locked/' . P_G . '.png',
    ($isRoot === true ? 'THIS RUN IS ROOT, which a read-only folder cannot stop: run it as an ordinary user. ' : '')
    . ($resultG1 instanceof Throwable ? $resultG1->getMessage() : json_encode([$resultG1, $failedG1, $reqG1, $photoState()])));

// 4. Nothing in the way: the next run finds the file again and finishes.
ge_q("UPDATE tblErasureRequest SET status = 'processing' WHERE requestID = ?", 'i', [REQ_G]);
$resultG2 = ge_execute(REQ_G, P_G);
$reqG2    = ge_one('SELECT status FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_G]);
ge_ok('with the folder writable again the next run finds the file through the link it left, deletes it, empties the links and completes',
    is_array($resultG2) === true && $resultG2['failed'] === 0 && $reqG2['status'] === 'completed' && is_file($lockedPhoto) === false
    && $photoState()['displayPhoto'] === null && $photoState()['avatarPath'] === null && is_file($outside) === true,
    $resultG2 instanceof Throwable ? $resultG2->getMessage() : json_encode([$resultG2, $reqG2, $photoState()]));

// =============================================================================
ge_section('8', 'The guards themselves can fail');
// =============================================================================
// A check that can only ever say "yes" proves nothing, so two of them are
// shown saying "no".
$processEntry = new ReflectionMethod(GdprEraser::class, 'processEntry');
$controlRef   = null;
foreach (ge_q('SELECT * FROM tblEventAttendance WHERE userID = ?', 'i', [C]) as $row) {
    $controlRef = $row;
}
$outcome = $processEntry->invoke(null, $mysqli, REQ_PC, C, ['table' => 'tblEventAttendance', 'userCol' => 'userID', 'action' => 'tidy-up']);
$stillThere = ge_q('SELECT * FROM tblEventAttendance WHERE userID = ?', 'i', [C]);
$lastLine   = ge_audit(REQ_PC);
$lastLine   = $lastLine[count($lastLine) - 1];
ge_ok('an instruction nobody recognises is refused: a failed step, a line saying so, and the row untouched',
    $outcome === 'failed' && $controlRef !== null && ($stillThere[0] ?? null) === $controlRef
    && $lastLine['action'] === 'failed' && str_contains((string) $lastLine['details'], 'unrecognised instruction'),
    json_encode([$outcome, $lastLine]));
// The guard that stops an instruction wiping ANOTHER person's number. When an
// instruction lists a person column to empty beside the one it matches on,
// that column is emptied only where it names the erased person. No
// instruction lists one any more (each person column has an instruction of
// its own since 11 October 2026, and tools/gdpr-coverage-selftest.php fails
// if one comes back), so the made-up world no longer exercises this guard,
// and a planted fault that removed it went unnoticed. It is still the last
// thing standing between a careless instruction and somebody else's number,
// so it is tested here directly, with an instruction of exactly that shape.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
$sharedTask = ge_insert('tblTasks', ['assignedToID' => C, 'createdByID' => D], ['parentTaskID' => null]);
ge_ensureParents($sharedTask);
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
$guardOutcome = $processEntry->invoke(null, $mysqli, REQ_PC, C, ['table' => 'tblTasks', 'userCol' => 'assignedToID', 'action' => 'anonymise',
    'nullCols' => ['createdByID'], 'reason' => 'selftest: an instruction that lists a second person column']);
$sharedNow = ge_row($sharedTask);
ge_ok('an instruction that lists a second person column empties it ONLY where it names the erased person: somebody else\'s number on the same row is left alone',
    $guardOutcome === 'changed' && $sharedNow !== null && $sharedNow['assignedToID'] === null && (int) $sharedNow['createdByID'] === D,
    json_encode([$guardOutcome, $sharedNow]));
ge_q("UPDATE tblErasureAudit SET details = 'edited afterwards' WHERE requestID = ? ORDER BY auditID LIMIT 1", 'i', [REQ_A]);
ge_ok('an audit line edited afterwards breaks the chain, and verifying says so', GdprEraser::verifyAuditChain(REQ_A) === false);

// =============================================================================
ge_section('9', 'A request stuck at "processing" can be put back; one still running cannot (roll-out package A2a)');
// =============================================================================
// A run killed by the web host leaves its request at "processing", and the
// erasure page only runs one that is at "pending review". Nothing could put
// it back. Now something can, but only when the request has shown no sign of
// a run for a quarter of an hour.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_user(P_S, 'Sol Stuck', 'sol.stuck@selftest.invalid');
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_request(REQ_S_OLD, P_S, 'sol.stuck@selftest.invalid', 'Sol Stuck', 'processing');
ge_request(REQ_S_LIVE, C, 'cal.control@selftest.invalid', 'Cal Control', 'processing');
ge_request(REQ_S_NULL, D, 'dee.control@selftest.invalid', 'Dee Control', 'processing');
ge_request(REQ_S_DONE, null, 'deleted-' . REQ_S_DONE . '@example.invalid', '', 'completed');
$setClaimed = static function (int $requestId, ?int $minutesAgo): void {
    if ($minutesAgo === null) {
        ge_q('UPDATE tblErasureRequest SET claimedAt = NULL WHERE requestID = ?', 'i', [$requestId]);

        return;
    }
    ge_q('UPDATE tblErasureRequest SET claimedAt = DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE) WHERE requestID = ?', 'ii', [$minutesAgo, $requestId]);
};
$requestRow = static fn (int $requestId): ?array => ge_one('SELECT status, claimedAt, userID, siteID FROM tblErasureRequest WHERE requestID = ?', 'i', [$requestId]);
$putBack    = static function (int $requestId, int $siteId = 1): string {
    try {
        return GdprEraser::putBackStuckRequest($requestId, $siteId);
    } catch (Throwable $e) {
        return 'threw: ' . $e->getMessage();
    }
};

// THE SERVER'S CLOCK MUST NOT MATTER. The connection is told it is thirteen
// hours ahead of UTC, then eleven behind. Written with NOW() instead of
// UTC_TIMESTAMP(), the first would put back a request that showed life a
// minute ago, and the second would refuse one that has been dead for twenty.
$setClaimed(REQ_S_LIVE, 1);
$mysqli->query("SET time_zone = '+13:00'");
$liveAhead = $putBack(REQ_S_LIVE);
$mysqli->query("SET time_zone = '-11:00'");
$liveBehind = $putBack(REQ_S_LIVE);
$liveRow    = $requestRow(REQ_S_LIVE);
ge_ok('a request that showed a sign of a run ONE minute ago is refused, whatever zone the database server keeps its clock in',
    $liveAhead === 'still-going' && $liveBehind === 'still-going' && $liveRow['status'] === 'processing'
    && $liveRow['claimedAt'] !== null && ge_audit(REQ_S_LIVE) === [], json_encode([$liveAhead, $liveBehind, $liveRow]));

$setClaimed(REQ_S_OLD, 20);
$oldBehind = $putBack(REQ_S_OLD);
$mysqli->query("SET time_zone = 'SYSTEM'");
$oldRow   = $requestRow(REQ_S_OLD);
$oldAudit = ge_audit(REQ_S_OLD);
ge_ok('one with no sign of a run for TWENTY minutes is put back to "pending review", with one audit line saying so and nothing else changed',
    $oldBehind === 'put-back' && $oldRow['status'] === 'pending_review' && $oldRow['claimedAt'] === null && (int) $oldRow['userID'] === P_S
    && count($oldAudit) === 1 && $oldAudit[0]['action'] === 'put-back' && str_contains((string) $oldAudit[0]['details'], 'safe to repeat')
    && GdprEraser::verifyAuditChain(REQ_S_OLD) === true, json_encode([$oldBehind, $oldRow, $oldAudit]));

$setClaimed(REQ_S_NULL, null);
$wrongSite    = $putBack(REQ_S_NULL, SITE_2);
$afterWrong   = $requestRow(REQ_S_NULL);
$rightSite    = $putBack(REQ_S_NULL);
$finished     = $putBack(REQ_S_DONE);
$alreadyBack  = $putBack(REQ_S_OLD);
ge_ok('another organisation\'s request is "not found" and untouched; one stuck from before the moment was recorded is put back; a finished one, and one already back, are left alone',
    $wrongSite === 'not-found' && $afterWrong['status'] === 'processing'
    && $rightSite === 'put-back' && $requestRow(REQ_S_NULL)['status'] === 'pending_review'
    && $finished === 'not-processing' && $requestRow(REQ_S_DONE)['status'] === 'completed'
    && $alreadyBack === 'not-processing' && count(ge_audit(REQ_S_OLD)) === 1,
    json_encode([$wrongSite, $afterWrong, $rightSite, $finished, $alreadyBack]));

// The sign of life itself: refreshed when a minute has gone by, and not before.
$beat = new ReflectionMethod(GdprEraser::class, 'stillGoing');
$setClaimed(REQ_S_LIVE, 10);
$tooSoon   = $beat->invoke(null, $mysqli, REQ_S_LIVE, time() - 30);
$agoBefore = (int) ge_one('SELECT TIMESTAMPDIFF(SECOND, claimedAt, UTC_TIMESTAMP()) AS s FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_S_LIVE])['s'];
$refreshed = $beat->invoke(null, $mysqli, REQ_S_LIVE, time() - 61);
$agoAfter  = (int) ge_one('SELECT TIMESTAMPDIFF(SECOND, claimedAt, UTC_TIMESTAMP()) AS s FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_S_LIVE])['s'];
ge_ok('a run refreshes its sign of life once a minute has passed, in UTC, and not before',
    $agoBefore >= 590 && $agoAfter >= 0 && $agoAfter <= 30 && $refreshed >= time() - 2 && $tooSoon < time() - 20,
    json_encode([$agoBefore, $agoAfter]));

// And the point of it all: a request that was put back can be run.
ge_q("UPDATE tblErasureRequest SET status = 'processing', claimedAt = UTC_TIMESTAMP() WHERE requestID = ? AND status = 'pending_review'", 'i', [REQ_S_OLD]);
$resultS = ge_execute(REQ_S_OLD, P_S);
ge_ok('a request that was put back runs to completion, and its audit trail (the put-back line and the run) verifies',
    is_array($resultS) === true && $resultS['failed'] === 0 && $requestRow(REQ_S_OLD)['status'] === 'completed'
    && GdprEraser::verifyAuditChain(REQ_S_OLD) === true,
    $resultS instanceof Throwable ? $resultS->getMessage() : json_encode([$resultS, $requestRow(REQ_S_OLD)]));

// =============================================================================
ge_section('10', 'Filing a request, and an administrator confirming one on the person\'s behalf (roll-out package A2a)');
// =============================================================================
// Before an administrator can run a request, somebody has to have confirmed
// it is the person's. Three ways: the emailed link ("Request data erasure"),
// the typed phrase ("Delete my account"), or an administrator who has checked.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_user(P_T, 'Tam Tenth', 'tam.tenth@selftest.invalid');
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
// No address to build a link from, so no email is attempted here: the request
// names no host, and this throwaway database has no portal address set (the
// install script seeds `site.url` empty). PortalAddress then gives nothing.
unset($_SERVER['HTTP_HOST']);
$fullRequest = static fn (int $requestId): ?array => ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [$requestId]);

$ordinary    = GdprEraser::fileRequest(1, P_T);
$ordinaryRow = $fullRequest($ordinary['requestID']);
ge_ok('"Request data erasure" files a request that WAITS: "pending confirmation", with a link token, and no moment of confirmation',
    $ordinary['confirmed'] === false && $ordinaryRow['status'] === 'pending_confirmation' && (int) $ordinaryRow['userID'] === P_T
    && preg_match('/^[0-9a-f]{64}$/', (string) $ordinaryRow['confirmToken']) === 1 && $ordinaryRow['confirmedAt'] === null
    && $ordinaryRow['subjectEmail'] === 'tam.tenth@selftest.invalid', json_encode([$ordinary, $ordinaryRow]));

$typed    = GdprEraser::fileRequest(1, P_T, true);
$typedRow = $fullRequest($typed['requestID']);
ge_ok('"Delete my account" files a request that is ALREADY CONFIRMED: straight to "pending review", the moment recorded, no link token and no email',
    $typed['confirmed'] === true && $typed['mailed'] === false && $typedRow['status'] === 'pending_review'
    && $typedRow['confirmToken'] === null && $typedRow['confirmedAt'] !== null && (int) $typedRow['userID'] === P_T
    && ge_audit($typed['requestID']) === [], json_encode([$typed, $typedRow]));

$confirm = static function (int $requestId, int $siteId): string {
    try {
        return GdprEraser::confirmOnBehalf($requestId, $siteId, ADMIN);
    } catch (Throwable $e) {
        return 'threw: ' . $e->getMessage();
    }
};
$otherOrganisation = $confirm($ordinary['requestID'], SITE_2);
$afterOther        = $fullRequest($ordinary['requestID']);
ge_ok('an administrator of ANOTHER organisation cannot confirm it: "not found", the request untouched, no audit line',
    $otherOrganisation === 'not-found' && $afterOther === $ordinaryRow && ge_audit($ordinary['requestID']) === [],
    json_encode([$otherOrganisation, $afterOther]));

$onBehalf     = $confirm($ordinary['requestID'], 1);
$confirmedRow = $fullRequest($ordinary['requestID']);
$behalfAudit  = ge_audit($ordinary['requestID']);
ge_ok('an administrator of ITS organisation can: it moves to "pending review", the emailed link stops working, and ONE audit line says who confirmed and that they state they checked the person\'s identity',
    $onBehalf === 'confirmed' && $confirmedRow['status'] === 'pending_review' && $confirmedRow['confirmedAt'] !== null
    && $confirmedRow['confirmToken'] === null && (int) $confirmedRow['userID'] === P_T
    && count($behalfAudit) === 1 && $behalfAudit[0]['action'] === 'confirmed-on-behalf'
    && str_contains((string) $behalfAudit[0]['details'], 'account #' . ADMIN)
    && str_contains((string) $behalfAudit[0]['details'], 'states that they checked the person\'s identity')
    && str_contains((string) $behalfAudit[0]['details'], 'Nothing has been erased by this')
    && GdprEraser::verifyAuditChain($ordinary['requestID']) === true,
    json_encode([$onBehalf, $confirmedRow, $behalfAudit]));

$again        = $confirm($ordinary['requestID'], 1);
$typedAgain   = $confirm($typed['requestID'], 1);
$finishedOne  = $confirm(REQ_S_DONE, 1);
ge_ok('only a request that is WAITING can be confirmed: a second press, a request already confirmed by the typed phrase, and a finished one are all left alone, with no new audit line',
    $again === 'not-waiting' && $typedAgain === 'not-waiting' && $finishedOne === 'not-waiting'
    && count(ge_audit($ordinary['requestID'])) === 1 && ge_audit($typed['requestID']) === []
    && $fullRequest($typed['requestID']) === $typedRow && $fullRequest(REQ_S_DONE)['status'] === 'completed',
    json_encode([$again, $typedAgain, $finishedOne]));

ge_q("UPDATE tblErasureRequest SET status = 'processing', claimedAt = UTC_TIMESTAMP() WHERE requestID = ? AND status = 'pending_review'", 'i', [$ordinary['requestID']]);
$resultT = ge_execute($ordinary['requestID'], P_T);
ge_ok('a request confirmed on the person\'s behalf then runs to completion; the other request from the same account is closed with it; and the trail (the confirmation line, then the run) verifies',
    is_array($resultT) === true && $resultT['failed'] === 0 && $fullRequest($ordinary['requestID'])['status'] === 'completed'
    && $fullRequest($typed['requestID'])['status'] === 'completed' && GdprEraser::verifyAuditChain($ordinary['requestID']) === true
    && (ge_audit($ordinary['requestID'])[0]['action'] ?? '') === 'confirmed-on-behalf',
    $resultT instanceof Throwable ? $resultT->getMessage() : json_encode([$resultT, $fullRequest($ordinary['requestID'])]));

// =============================================================================
ge_section('11', 'Giving records: what keeps the donor\'s name, with rows that really are linked (roll-out package A2b)');
// =============================================================================
// Two REAL organisations with different rules, and three donors. Every date
// is counted back from today, so this section cannot go stale.
//
//   organisation 3   only gifts with a tax declaration, receipt or statement
//                    keep the donor's name (the default rule)
//   organisation 4   every gift keeps it
//
//   Hana   gives at both. At 3 she has a tax declaration, three gifts (one
//          from before the declaration), a statement the organisation issued,
//          and a statement she downloaded for herself.
//   Ian    gives at 3, with no declaration and no statement.
//   Kay    is never erased. Everything of hers must come through untouched.
/**
 * The moment this test takes as "now", in UTC.
 *
 * ONE reader, on purpose. Everything below that needs "today" starts from
 * it, so a COPY of this file can be run "at another hour of the day" by
 * moving this one line together with the clock reader in
 * GivingRetention::today(). Never moved in the checkout itself.
 */
function ge_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

// "TODAY", for everything about WHEN a kept name is cleared, is the date
// TWELVE HOURS BEHIND UTC: the last place on Earth to reach a date, which is
// what the clearing asks since #604, for every organisation alike. Worked out
// here by taking twelve hours off UTC, not by the code under test and not
// through a zone's rules. (See "WHAT IT CANNOT PROVE" at the top for what
// this used to be.) The run takes under a minute and this is read once, so a
// run begun in the last minutes before 12:00 UTC is refused, not left to fail
// half way through when that date moves on.
if (ge_now()->format('H:i') >= '11:55' && ge_now()->format('H:i') < '12:00') {
    echo "REFUSED — it is within five minutes of 12:00 UTC, when the date at the last place on Earth changes; the giving sections read it once. Run this again after 12:00 UTC. Nothing more was checked.\n";
    exit(1);
}
$todayG1 = ge_now()->modify('-12 hours')->format('Y-m-d');
$ago = static fn (int $days): string => (new DateTimeImmutable($todayG1, new DateTimeZone('UTC')))->modify('-' . $days . ' days')->format('Y-m-d');

/** Write one organisation's own value of a setting, as the settings page does. */
function ge_setting(int $siteId, string $key, string $value): void
{
    ge_q(
        'INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, ?, ?, ?, 0) '
        . 'ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue)',
        'isss',
        [$siteId, $key, $value, $value]
    );
}

/** A gift with real links: a real organisation, a real category, a real donor. */
function ge_gift(int $siteId, int $donor, string $date, int $pence, int $categoryId): array
{
    return ge_insert('tblGivingEntry', ['donorID' => $donor, 'recordedByID' => ADMIN], [
        'siteID' => $siteId, 'donatedAt' => $date, 'amountPence' => $pence, 'categoryID' => $categoryId, 'method' => 'bank-transfer',
        'donorName' => null, 'pledgeID' => null, 'campaignID' => null, 'currency' => 'GBP',
    ]);
}

/** A tax declaration that covers every date from `$from` on. */
function ge_declaration(int $siteId, int $donor, string $from, string $address, string $postcode): array
{
    return ge_insert('tblGiftAidDeclaration', ['donorID' => $donor], [
        'siteID' => $siteId, 'status' => 'active', 'validFrom' => $from, 'validTo' => null, 'address' => $address, 'postcode' => $postcode,
    ]);
}

/** A statement the organisation issued: its record, and the PDF on disk. */
function ge_statement(int $siteId, int $donor, string $from, string $to, string $mailedTo): array
{
    $file = ge_file('_uploads/giving/statements/' . $siteId . '/statement-' . $donor . '-' . $from . '_' . $to . '.pdf', 'made-up statement for ' . $donor);
    $ref  = ge_insert('tblGivingStatementLog', ['donorID' => $donor, 'createdByID' => ADMIN], [
        'siteID' => $siteId, 'periodKey' => $from . '_' . $to, 'fromDate' => $from, 'toDate' => $to, 'pdfPath' => $file, 'emailedTo' => $mailedTo,
        'errorMsg' => null,
    ]);

    return ['ref' => $ref, 'file' => $file];
}

$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_insert('tblSites', [], ['siteID' => SITE_G1, 'timezone' => 'Europe/London']);
ge_insert('tblSites', [], ['siteID' => SITE_G2, 'timezone' => 'Pacific/Auckland']);
$catG1 = ge_id(ge_insert('tblGivingCategory', [], ['siteID' => SITE_G1]));
$catG2 = ge_id(ge_insert('tblGivingCategory', [], ['siteID' => SITE_G2]));
ge_user(P_H, 'Hana Kept', 'hana.kept@selftest.invalid');
ge_user(P_I, 'Ian Unkept', 'ian.unkept@selftest.invalid');
ge_user(P_K, 'Kay Control', 'kay.control@selftest.invalid');
ge_q("UPDATE tblUsers SET displayAddress = '3 Account Road, Keptown' WHERE userID = ?", 'i', [P_H]);

$h = [];
$h['declaration'] = ge_declaration(SITE_G1, P_H, $ago(400), '12 Kept Lane, Keptown', 'KT1 2AB');
$h['giftBefore']  = ge_gift(SITE_G1, P_H, $ago(500), 1000, $catG1);   // before the declaration, and in no statement
$h['giftOne']     = ge_gift(SITE_G1, P_H, $ago(300), 2500, $catG1);   // inside the declaration, and in the statement
$h['giftTwo']     = ge_gift(SITE_G1, P_H, $ago(100), 4000, $catG1);   // inside the declaration: the LAST kept gift
$issuedH          = ge_statement(SITE_G1, P_H, $ago(330), $ago(250), 'hana.kept@selftest.invalid');
$h['statement']   = $issuedH['ref'];
// A statement Hana made for herself from "my statement": a file, and no
// statement record behind it.
$ownFileH         = ge_file('_uploads/giving/statements/' . SITE_G1 . '/statement-' . P_H . '-' . $ago(700) . '_' . $ago(600) . '.pdf', 'made-up statement');
// Two more she made for herself. One lists only a gift that is kept (the
// one of 100 days ago, inside the declaration): a copy that could not be made
// again. The other lists a kept gift AND the gift from before the declaration.
$ownAllKeptH      = ge_file('_uploads/giving/statements/' . SITE_G1 . '/statement-' . P_H . '-' . $ago(120) . '_' . $ago(90) . '.pdf', 'made-up statement, all kept');
$ownMixedH        = ge_file('_uploads/giving/statements/' . SITE_G1 . '/statement-' . P_H . '-' . $ago(510) . '_' . $ago(290) . '.pdf', 'made-up statement, mixed');
$h['envelope']    = ge_linked('tblCountEnvelopes', ['giverID' => P_H], ['giverName' => null, 'amount' => '12.50']);
$h['giftAllOne']  = ge_gift(SITE_G2, P_H, $ago(900), 700, $catG2);
$h['giftAllTwo']  = ge_gift(SITE_G2, P_H, $ago(50), 800, $catG2);
$fileAllH         = ge_file('_uploads/giving/statements/' . SITE_G2 . '/statement-' . P_H . '-' . $ago(60) . '_' . $ago(40) . '.pdf', 'made-up statement');

$i = [];
$i['giftOne'] = ge_gift(SITE_G1, P_I, $ago(200), 1500, $catG1);
$i['giftTwo'] = ge_gift(SITE_G1, P_I, $ago(20), 1600, $catG1);
$ownFileI     = ge_file('_uploads/giving/statements/' . SITE_G1 . '/statement-' . P_I . '-' . $ago(365) . '_' . $ago(1) . '.pdf', 'made-up statement');

$k = [];
$k['declaration'] = ge_declaration(SITE_G1, P_K, $ago(400), '9 Control Close', 'CT9 9ZZ');
$k['gift']        = ge_gift(SITE_G1, P_K, $ago(90), 5000, $catG1);
$issuedK          = ge_statement(SITE_G1, P_K, $ago(330), $ago(250), 'kay.control@selftest.invalid');
$k['statement']   = $issuedK['ref'];
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');

ge_setting(SITE_G2, GivingRetention::KEY_SCOPE, GivingRetention::SCOPE_ALL);
ge_request(REQ_H, P_H, 'hana.kept@selftest.invalid', 'Hana Kept', 'processing');
ge_request(REQ_I, P_I, 'ian.unkept@selftest.invalid', 'Ian Unkept', 'processing');
ge_q('UPDATE tblErasureRequest SET siteID = ? WHERE requestID IN (?, ?)', 'iii', [SITE_G1, REQ_H, REQ_I]);

$hWas = array_map('ge_row', $h);
$iWas = array_map('ge_row', $i);
$kWas = array_map('ge_row', $k);
$printsBeforeH = ge_snapshot();
$settingsOfG1  = ge_q("SELECT settingKey, settingValue FROM tblSettings WHERE siteID = ? AND settingKey LIKE 'giving.retention.%'", 'i', [SITE_G1]);
ge_ok('the linked giving rows were built, and organisation 3 has set nothing yet: no country, no year end, nothing confirmed',
    in_array(null, $hWas, true) === false && in_array(null, $iWas, true) === false && in_array(null, $kWas, true) === false
    && $settingsOfG1 === [] && is_file($issuedH['file']) === true && is_file($ownFileH) === true && is_file($fileAllH) === true
    && is_file($ownFileI) === true && is_file($issuedK['file']) === true
    && GivingRetention::ruleForSite(SITE_G1)['confirmed'] === false && GivingRetention::ruleForSite(SITE_G2)['scope'] === GivingRetention::SCOPE_ALL);

$resultH = ge_execute(REQ_H, P_H);
$hNow    = array_map('ge_row', $h);
$auditH  = ge_audit(REQ_H);
ge_ok('the erasure of a donor WITH a tax declaration ends with no failed step, and the request is completed',
    is_array($resultH) === true && $resultH['failed'] === 0
    && (ge_one('SELECT status FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_H])['status'] ?? '') === 'completed',
    $resultH instanceof Throwable ? $resultH->getMessage() : json_encode($resultH));

$accountH = ge_one('SELECT fullName, displayAddress, isActive FROM tblUsers WHERE userID = ?', 'i', [P_H]);
$keptG1   = ge_one('SELECT * FROM tblGivingKeptDonors WHERE siteID = ? AND donorID = ?', 'ii', [SITE_G1, P_H]);
ge_ok('THE NAME IS REALLY KEPT: the account is emptied, and the kept row holds the donor\'s full name, the home address and postcode from '
    . 'their declaration, the last kept gift\'s date, how many gifts are kept, and which request made it',
    $accountH['fullName'] === GdprEraser::TOMBSTONE_NAME && $accountH['displayAddress'] === null && (int) $accountH['isActive'] === 0
    && $keptG1 !== null && $keptG1['fullName'] === 'Hana Kept' && $keptG1['address'] === '12 Kept Lane, Keptown'
    && $keptG1['postcode'] === 'KT1 2AB' && $keptG1['scope'] === 'receipted' && $keptG1['lastGiftDate'] === $ago(100)
    && (int) $keptG1['keptGiftCount'] === 2 && (int) $keptG1['erasureRequestID'] === REQ_H && $keptG1['clearedAt'] === null
    && $keptG1['createdAt'] !== null, json_encode([$accountH, $keptG1]));
ge_ok('THE DECLARATION KEEPS THE FULL ADDRESS: not one column of it changed, and it still points at the donor\'s account',
    $hNow['declaration'] === $hWas['declaration'] && $hNow['declaration']['address'] === '12 Kept Lane, Keptown'
    && $hNow['declaration']['postcode'] === 'KT1 2AB' && (int) $hNow['declaration']['donorID'] === P_H, json_encode($hNow['declaration']));
$claimed = ge_q(
    'SELECT e.entryID, d.declarationID, k.fullName FROM tblGivingEntry e '
    . 'JOIN tblGiftAidDeclaration d ON d.siteID = e.siteID AND d.donorID = e.donorID AND d.validFrom <= e.donatedAt AND (d.validTo IS NULL OR d.validTo >= e.donatedAt) '
    . 'JOIN tblGivingKeptDonors k ON k.siteID = e.siteID AND k.donorID = e.donorID WHERE e.entryID IN (?, ?, ?) ORDER BY e.entryID',
    'iii',
    [ge_id($h['giftBefore']), ge_id($h['giftOne']), ge_id($h['giftTwo'])]
);
ge_ok('EACH CLAIMED GIFT STILL LINKS TO THE DECLARATION AND TO THE KEPT NAME: the two gifts the declaration covers are unchanged, still carry the donor\'s account number, and lead to the declaration and the name',
    $hNow['giftOne'] === $hWas['giftOne'] && $hNow['giftTwo'] === $hWas['giftTwo'] && (int) $hNow['giftOne']['donorID'] === P_H
    && (int) $hNow['giftTwo']['donorID'] === P_H && count($claimed) === 2
    && array_map('intval', array_column($claimed, 'entryID')) === [ge_id($h['giftOne']), ge_id($h['giftTwo'])]
    && array_unique(array_column($claimed, 'fullName')) === ['Hana Kept']
    && array_map('intval', array_unique(array_column($claimed, 'declarationID'))) === [ge_id($h['declaration'])],
    json_encode([$hNow['giftOne'], $hNow['giftTwo'], $claimed]));

// The tax claim file an organisation sends in, for a period that covers
// every gift here. It read the donor's name from the account, which now says
// "[Deleted User]".
$claimRows = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), array_filter(explode("\n", Giving::buildHmrcCsv(SITE_G1, $ago(800), $todayG1))));
$claimOfH  = array_values(array_filter($claimRows, static fn (array $r): bool => ($r[3] ?? '') === '12 Kept Lane'));
$claimText = json_encode($claimRows);
ge_ok('THE TAX CLAIM FILE still names the erased donor on both claimed gifts, with the house and postcode from the declaration, and has no "[Deleted User]" in it',
    count($claimOfH) === 2 && $claimOfH[0][1] === 'Hana' && $claimOfH[0][2] === 'Kept' && $claimOfH[0][4] === 'KT1 2AB'
    && array_column($claimOfH, 8) === ['25.00', '40.00'] && str_contains($claimText, 'Deleted') === false
    && count(array_filter($claimRows, static fn (array $r): bool => ($r[1] ?? '') === 'Kay')) === 1, $claimText);

ge_ok('a gift from BEFORE the declaration, in no statement, keeps its amount, date and method and loses the donor at once',
    ge_same($hWas['giftBefore'], $hNow['giftBefore'], ['donorID', 'donorName']) === true && $hNow['giftBefore']['donorID'] === null
    && $hNow['giftBefore']['donorName'] === null && (int) $hNow['giftBefore']['amountPence'] === 1000
    && $hNow['giftBefore']['donatedAt'] === $ago(500) && $hNow['giftBefore']['method'] === 'bank-transfer', json_encode($hNow['giftBefore']));

$fileLine = array_values(array_filter($auditH, static fn (array $l): bool => $l['tableName'] === 'GivingStatementPdfFiles'));
ge_ok('THE STATEMENT FILE IS STILL THERE: the statement the organisation issued is kept on disk and its record still points at it; only the address it was mailed to is emptied',
    is_file($issuedH['file']) === true && file_get_contents($issuedH['file']) === 'made-up statement for ' . P_H
    && ge_same($hWas['statement'], $hNow['statement'], ['emailedTo']) === true && $hNow['statement']['emailedTo'] === null
    && $hNow['statement']['pdfPath'] === $issuedH['file'] && (int) $hNow['statement']['donorID'] === P_H, json_encode($hNow['statement']));
// CHANGED after the independent check of 12 October 2026: a statement the
// donor made for themselves used to be deleted always. It is kept when every
// gift on it is a kept gift.
ge_ok('A STATEMENT THE DONOR MADE FOR THEMSELVES IS KEPT when every gift on it is a kept gift, and deleted when it lists a gift that lost the donor\'s name, or no gift at all; and the line says how many files were kept and how many deleted',
    is_file($ownAllKeptH) === true && file_get_contents($ownAllKeptH) === 'made-up statement, all kept'
    && is_file($ownMixedH) === false && is_file($ownFileH) === false
    && count($fileLine) === 1 && $fileLine[0]['action'] === 'delete' && $fileLine[0]['recordKey'] === '2 file(s)'
    && str_contains((string) $fileLine[0]['details'], '3 rendered statement PDF(s) kept with the donor\'s giving records as tax evidence'),
    json_encode([is_file($ownAllKeptH), is_file($ownMixedH), is_file($ownFileH), $fileLine]));

$keptG2 = ge_one('SELECT * FROM tblGivingKeptDonors WHERE siteID = ? AND donorID = ?', 'ii', [SITE_G2, P_H]);
ge_ok('AT THE ORGANISATION THAT KEEPS EVERY GIFT: both gifts keep the donor with no declaration at all, the kept row takes the address from the account, and the statement file there is kept',
    $hNow['giftAllOne'] === $hWas['giftAllOne'] && $hNow['giftAllTwo'] === $hWas['giftAllTwo'] && (int) $hNow['giftAllOne']['donorID'] === P_H
    && $keptG2 !== null && $keptG2['scope'] === 'all' && (int) $keptG2['keptGiftCount'] === 2 && $keptG2['fullName'] === 'Hana Kept'
    && $keptG2['address'] === '3 Account Road, Keptown' && $keptG2['postcode'] === null && $keptG2['lastGiftDate'] === $ago(50)
    && is_file($fileAllH) === true, json_encode([$keptG2, is_file($fileAllH)]));
ge_ok('a counted envelope loses its giver at once and keeps its amount',
    ge_same($hWas['envelope'], $hNow['envelope'], ['giverID', 'giverName']) === true && $hNow['envelope']['giverID'] === null
    && $hNow['envelope']['amount'] === $hWas['envelope']['amount'], json_encode($hNow['envelope']));

$lineG1 = array_values(array_filter($auditH, static fn (array $l): bool => $l['tableName'] === 'GivingRecords' && $l['recordKey'] === 'organisation #' . SITE_G1));
$lineG2 = array_values(array_filter($auditH, static fn (array $l): bool => $l['tableName'] === 'GivingRecords' && $l['recordKey'] === 'organisation #' . SITE_G2));
$textG1 = (string) ($lineG1[0]['details'] ?? '');
// The date the report must give, worked out HERE and not by the code under
// test: no year end is set, so one year after the last kept gift, plus the
// placeholder's years. (It used to be asked of GivingRetention::keepUntil()
// itself, and a planted fault that made that function a year short then
// passed this whole file, because the expectation moved with the fault.)
$untilG1 = (new DateTimeImmutable($ago(100), new DateTimeZone('UTC')))
    ->modify('+' . (1 + GivingRetention::data()['placeholderYears']) . ' years')->format('Y-m-d');
ge_ok('THE REPORT says what is kept and until when, in words: 2 gifts, 1 tax declaration and 1 giving statement; 1 other gift that no longer names the donor; and how the date was worked out',
    count($lineG1) === 1 && $lineG1[0]['action'] === 'retain'
    && str_contains($textG1, '2 gifts, 1 tax declaration and 1 giving statement keep this donor\'s name and address until ' . GivingRetention::dateInWords($untilG1))
    && str_contains($textG1, '1 other gift keeps the amount, date and method and no longer names the donor')
    && str_contains($textG1, 'only on gifts that have a tax declaration, receipt or statement')
    && str_contains($textG1, 'last such gift ' . GivingRetention::dateInWords($ago(100)))
    && str_contains($textG1, 'no financial year end is set, so one year after that was used'), $textG1);
ge_ok('...and it says the period is NOT YET CONFIRMED and is a cautious placeholder, because the organisation has not said which country it is in; it never says the figure is what the law requires',
    str_contains($textG1, 'cautious placeholder, not a finding: this organisation has not said which country it is in')
    && str_contains($textG1, 'HAS NOT YET CONFIRMED IT, so nothing will be cleared until it does')
    && preg_match('/the law requires|required by law|legally required/i', $textG1) !== 1
    && count($lineG2) === 1 && str_contains((string) $lineG2[0]['details'], 'keeps the donor\'s name on every gift')
    && str_contains((string) $lineG2[0]['details'], 'No other gift of theirs is held there'), $textG1 . ' | ' . ($lineG2[0]['details'] ?? ''));
$named = array_values(array_filter($auditH, static fn (array $l): bool => preg_match('/Hana|Kept Lane|Account Road|KT1 2AB|hana\.kept/i', (string) $l['details'] . (string) $l['recordKey']) === 1));
ge_ok('NO LINE of the audit trail holds the donor\'s name, address, postcode or email address (' . count($auditH) . ' lines read), and the chain verifies',
    $named === [] && count($auditH) > 100 && GdprEraser::verifyAuditChain(REQ_H) === true, json_encode(array_slice($named, 0, 3)));

// Nothing else in the whole database changed: the rows named here are the
// only ones allowed to. The kept gifts and the declaration are NOT on the
// list, so this also holds each of them to "unchanged, byte for byte".
$allowedH = [ge_keyText('tblUsers', ['userID' => P_H]) => true, ge_keyText('tblErasureRequest', ['requestID' => REQ_H]) => true];
foreach (['giftBefore', 'statement', 'envelope'] as $name) {
    $allowedH[ge_keyText($h[$name]['table'], $h[$name]['key'])] = true;
}
foreach ([$keptG1, $keptG2] as $keptRow) {
    $allowedH[ge_keyText('tblGivingKeptDonors', ['keptDonorID' => (int) ($keptRow['keptDonorID'] ?? 0)])] = true;
}
$unexpectedH = ge_unexpectedChanges($printsBeforeH, ge_snapshot(), $allowedH);
ge_ok('ANOTHER DONOR is untouched, and so is everything else: across the whole database only the erased donor\'s account, request, un-kept gift, statement record and envelope changed, and only their two kept rows were added',
    $unexpectedH === [] && array_map('ge_row', $k) === $kWas && is_file($issuedK['file']) === true
    && (int) ge_one('SELECT COUNT(*) AS n FROM tblGivingKeptDonors WHERE donorID = ?', 'i', [P_H])['n'] === 2
    && (int) ge_one('SELECT COUNT(*) AS n FROM tblGivingKeptDonors WHERE donorID = ?', 'i', [P_K])['n'] === 0,
    implode('; ', array_slice($unexpectedH, 0, 8)));

$preview = Giving::statementsPreview(SITE_G1, $ago(800), $todayG1);
ge_ok('an erased donor is never offered a new statement: the bulk list leaves them out (and still lists the two donors who have not been erased), and rendering one for them is refused',
    array_map('intval', array_column($preview, 'donorID')) === [P_I, P_K] && Giving::isErasedDonor(SITE_G1, P_H) === true
    && Giving::isErasedDonor(SITE_G1, P_K) === false && Giving::renderStatementPdf(SITE_G1, P_H, $ago(330), $ago(250), 'x') === false
    && file_get_contents($issuedH['file']) === 'made-up statement for ' . P_H, json_encode($preview));
$regen = Giving::renderStatementBatch(SITE_G1, $ago(330), $ago(250), 0, true);
ge_ok('"regenerate" for that period does not un-link the erased donor\'s kept statement (it would have been replaced by one in the name "[Deleted User]")',
    (ge_row($h['statement'])['pdfPath'] ?? null) === $issuedH['file'] && ge_row($k['statement'])['pdfPath'] === null && $regen['remaining'] === 1,
    json_encode([ge_row($h['statement']), $regen]));
ge_q('UPDATE tblGivingStatementLog SET pdfPath = ? WHERE logID = ?', 'si', [$issuedK['file'], ge_id($k['statement'])]);

// A donor WITHOUT a declaration or a statement.
$resultI = ge_execute(REQ_I, P_I);
$iNow    = array_map('ge_row', $i);
$lineI   = array_values(array_filter(ge_audit(REQ_I), static fn (array $l): bool => $l['tableName'] === 'GivingRecords'));
ge_ok('A DONOR WITHOUT A TAX DECLARATION: both gifts keep their amount, date and method and lose the name at once; no name is kept anywhere; and the statement they made for themselves is deleted',
    is_array($resultI) === true && $resultI['failed'] === 0
    && ge_same($iWas['giftOne'], $iNow['giftOne'], ['donorID', 'donorName']) === true && ge_same($iWas['giftTwo'], $iNow['giftTwo'], ['donorID', 'donorName']) === true
    && $iNow['giftOne']['donorID'] === null && $iNow['giftTwo']['donorID'] === null && (int) $iNow['giftOne']['amountPence'] === 1500
    && $iNow['giftTwo']['donatedAt'] === $ago(20)
    && (int) ge_one('SELECT COUNT(*) AS n FROM tblGivingKeptDonors WHERE donorID = ?', 'i', [P_I])['n'] === 0
    && is_file($ownFileI) === false
    && count($lineI) === 1 && $lineI[0]['action'] === 'anonymise'
    && str_contains((string) $lineI[0]['details'], 'nothing has to be kept with the donor\'s name. 2 gifts keep the amount, date and method and no longer name the donor'),
    $resultI instanceof Throwable ? $resultI->getMessage() : json_encode([$iNow, $lineI]));

// IF THE NAME CANNOT BE KEPT, THE RUN STOPS before the account is emptied.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_user(P_L, 'Lee Stopped', 'lee.stopped@selftest.invalid');
$l = ['declaration' => ge_declaration(SITE_G1, P_L, $ago(400), '5 Stop Street', 'ST5 5OP'), 'gift' => ge_gift(SITE_G1, P_L, $ago(30), 900, $catG1)];
$anyInstruction = ge_insert('tblTasks', ['assignedToID' => P_L, 'createdByID' => D], ['parentTaskID' => null]);
ge_ensureParents($anyInstruction);
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_request(REQ_L, P_L, 'lee.stopped@selftest.invalid', 'Lee Stopped', 'processing');
$lWas = array_map('ge_row', $l);
ge_trigger('selftest_gdpr_fail_kept', "CREATE TRIGGER `selftest_gdpr_fail_kept` BEFORE INSERT ON `tblGivingKeptDonors` "
    . "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'selftest: the kept name could not be written'");
$resultL1 = ge_execute(REQ_L, P_L);
ge_dropTrigger('selftest_gdpr_fail_kept');
$accountL = ge_one('SELECT fullName, emailAddress, isActive FROM tblUsers WHERE userID = ?', 'i', [P_L]);
$auditL1  = ge_audit(REQ_L);
$requestL = ge_one('SELECT status, userID FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_L]);
ge_ok('IF THE KEPT NAME CANNOT BE WRITTEN, THE RUN STOPS THERE: a plain message, the request back at "pending review", and the account NOT emptied',
    $resultL1 instanceof RuntimeException && str_contains($resultL1->getMessage(), 'stopped before it finished, while keeping the giving records at organisation #' . SITE_G1)
    && str_contains($resultL1->getMessage(), 'back at pending review')
    && $accountL['fullName'] === 'Lee Stopped' && $accountL['emailAddress'] === 'lee.stopped@selftest.invalid' && (int) $accountL['isActive'] === 1
    && $requestL['status'] === 'pending_review' && (int) $requestL['userID'] === P_L,
    $resultL1 instanceof Throwable ? $resultL1->getMessage() . ' | ' . json_encode([$accountL, $requestL]) : json_encode($resultL1));
ge_ok('...nothing after it ran and nothing was half done: the gift still names the donor, the task given to them is untouched, no kept row exists, and the trail holds one line, saying it stopped',
    array_map('ge_row', $l) === $lWas && (int) (ge_row($anyInstruction)['assignedToID'] ?? 0) === P_L
    && (int) ge_one('SELECT COUNT(*) AS n FROM tblGivingKeptDonors WHERE donorID = ?', 'i', [P_L])['n'] === 0
    && count($auditL1) === 1 && $auditL1[0]['action'] === 'failed' && $auditL1[0]['tableName'] === 'GivingRecords'
    && str_contains((string) $auditL1[0]['details'], 'nothing after this step was done'), json_encode($auditL1));
ge_q("UPDATE tblErasureRequest SET status = 'processing' WHERE requestID = ?", 'i', [REQ_L]);
$resultL2 = ge_execute(REQ_L, P_L);
$keptL    = ge_one('SELECT fullName, address, postcode FROM tblGivingKeptDonors WHERE siteID = ? AND donorID = ?', 'ii', [SITE_G1, P_L]);
ge_ok('run again with nothing in the way: completed, and the kept row holds the real name and address (it was still on the account to be read)',
    is_array($resultL2) === true && $resultL2['failed'] === 0 && $keptL !== null && $keptL['fullName'] === 'Lee Stopped'
    && $keptL['address'] === '5 Stop Street' && $keptL['postcode'] === 'ST5 5OP'
    && ge_one('SELECT fullName FROM tblUsers WHERE userID = ?', 'i', [P_L])['fullName'] === GdprEraser::TOMBSTONE_NAME,
    $resultL2 instanceof Throwable ? $resultL2->getMessage() : json_encode([$resultL2, $keptL]));

// =============================================================================
ge_section('12', 'The scheduled clearing: never before "confirmed", never on hold, never early, and then everything together (roll-out package A2b)');
// =============================================================================
// Through run_giving_identity_sweep(), the function the nightly job and the
// staff page both run. Organisation 3 now holds Hana's kept row and Lee's. A
// third erased donor, Mo, is added so that one record is a day PAST its date
// and one is exactly ON it.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_user(P_M, 'Mo Tomorrow', 'mo.tomorrow@selftest.invalid');
$m = ['declaration' => ge_declaration(SITE_G1, P_M, $ago(900), '7 Morrow Mews', 'MM7 7MM'), 'gift' => ge_gift(SITE_G1, P_M, $ago(10), 600, $catG1)];
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_request(REQ_M, P_M, 'mo.tomorrow@selftest.invalid', 'Mo Tomorrow', 'processing');
$resultM = ge_execute(REQ_M, P_M);

// The rule for organisation 3: no year end, one year. So "keep until" is the
// last gift plus two calendar years. Find the two last-gift dates either side
// of the line: the latest one whose record is due TODAY, and the day after
// it, whose record is not. (Searched for rather than subtracted, so that a
// leap day cannot make this test wrong twice every four years.)
//
// "Plus two years" is worked out HERE, by PHP's own calendar, and NOT by the
// code under test. It used to ask GivingRetention::keepUntil(), so when that
// function was made a year short on purpose the dates this section set up
// moved with it and every check below still passed.
$plusYears = static fn (string $date, int $years): string => (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify('+' . $years . ' years')->format('Y-m-d');
$boundary  = static function (string $today, int $yearsAfterTheGift) use ($plusYears): array {
    $day = new DateTimeImmutable($today, new DateTimeZone('UTC'));
    for ($back = 366 * ($yearsAfterTheGift + 2); $back >= 0; $back--) {
        $candidate = $day->modify('-' . $back . ' days')->format('Y-m-d');
        if ($plusYears($candidate, $yearsAfterTheGift) >= $today) {
            return ['due' => $day->modify('-' . ($back + 1) . ' days')->format('Y-m-d'), 'notDue' => $candidate];
        }
    }
    throw new RuntimeException('no last-gift date is kept until ' . $today);
};
$edge  = $boundary($todayG1, 2);
$keptH = (int) $keptG1['keptDonorID'];
$keptM = (int) ge_one('SELECT keptDonorID FROM tblGivingKeptDonors WHERE siteID = ? AND donorID = ?', 'ii', [SITE_G1, P_M])['keptDonorID'];
$keptLid = (int) ge_one('SELECT keptDonorID FROM tblGivingKeptDonors WHERE siteID = ? AND donorID = ?', 'ii', [SITE_G1, P_L])['keptDonorID'];
ge_q('UPDATE tblGivingKeptDonors SET lastGiftDate = ? WHERE keptDonorID = ?', 'si', [$edge['due'], $keptH]);
ge_q('UPDATE tblGivingKeptDonors SET lastGiftDate = ? WHERE keptDonorID IN (?, ?)', 'sii', [$edge['notDue'], $keptM, $keptLid]);
// CHANGED after the independent check of 12 October 2026. The clearing now
// counts from the LATER of the date on the kept row and the newest gift still
// linked to the donor, so dating the kept row alone no longer sets up the
// boundary: the gifts themselves have to be that old. (That it does count
// from a newer gift is section 14's subject.)
$twoHundredBefore = (new DateTimeImmutable($edge['due'], new DateTimeZone('UTC')))->modify('-200 days')->format('Y-m-d');
ge_q('UPDATE tblGiftAidDeclaration SET validFrom = ? WHERE siteID = ? AND donorID IN (?, ?, ?)', 'siiii', ['2001-01-01', SITE_G1, P_H, P_M, P_L]);
ge_q('UPDATE tblGivingEntry SET donatedAt = ? WHERE entryID = ?', 'si', [$twoHundredBefore, ge_id($h['giftOne'])]);
ge_q('UPDATE tblGivingEntry SET donatedAt = ? WHERE entryID = ?', 'si', [$edge['due'], ge_id($h['giftTwo'])]);
ge_q('UPDATE tblGivingEntry SET donatedAt = ? WHERE entryID IN (?, ?)', 'sii', [$edge['notDue'], ge_id($m['gift']), ge_id($l['gift'])]);
// All seven of the organisation's own settings exist before anything is
// counted, so that the only rows the job can add are its own two lines.
ge_setting(SITE_G1, GivingRetention::KEY_COUNTRY, '');
ge_setting(SITE_G1, GivingRetention::KEY_SCOPE, GivingRetention::SCOPE_RECEIPTED);
ge_setting(SITE_G1, GivingRetention::KEY_YEARS, '1');
ge_setting(SITE_G1, GivingRetention::KEY_YEAR_END, '');
ge_setting(SITE_G1, GivingRetention::KEY_CONFIRMED, 'false');
ge_setting(SITE_G1, GivingRetention::KEY_CONFIRMED_ON, '');
ge_setting(SITE_G1, GivingRetention::KEY_HOLD, 'false');

$tableCounts = static function (): array {
    $counts = [];
    foreach (ge_q("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME") as $one) {
        $counts[(string) $one['t']] = (int) ge_one('SELECT COUNT(*) AS n FROM `' . $one['t'] . '`')['n'];
    }

    return $counts;
};
$uncleared = static fn (?int $siteId = null): int => (int) ge_one(
    'SELECT COUNT(*) AS n FROM tblGivingKeptDonors k JOIN tblSites s ON s.siteID = k.siteID WHERE k.clearedAt IS NULL' . ($siteId === null ? '' : ' AND k.siteID = ' . $siteId)
)['n'];
$stateH = GivingRetention::stateFor(GivingRetention::ruleForSite(SITE_G1), $edge['due'], $todayG1);
ge_ok('the set-up: three erased donors\' names are kept at organisation 3; one is a day past its keep-until date, the others are kept until today or later; and the portal\'s own date for each agrees with a date worked out independently',
    is_array($resultM) === true && $resultM['failed'] === 0 && $uncleared(SITE_G1) === 3 && $stateH['keepUntil'] < $todayG1
    && $stateH['keepUntil'] === $plusYears($edge['due'], 2)
    && GivingRetention::keepUntil($edge['notDue'], '', 1)['keepUntil'] === $plusYears($edge['notDue'], 2) && $plusYears($edge['notDue'], 2) >= $todayG1
    && (new DateTimeImmutable($edge['due']))->modify('+1 day')->format('Y-m-d') === $edge['notDue'], json_encode([$edge, $stateH]));

$printsBeforeSweep = ge_snapshot();
$countsBeforeSweep = $tableCounts();

// (a) NOT CONFIRMED. The date has passed; nothing may be cleared.
$sweepA = run_giving_identity_sweep();
ge_ok('NOT CONFIRMED: the job clears nothing, though the date has passed, and counts every kept record as waiting; not one row in the database changed',
    $sweepA['cleared'] === 0 && $sweepA['failed'] === 0 && $sweepA['onHold'] === 0 && $sweepA['waiting'] === $uncleared() && $sweepA['waiting'] >= 3
    && GdprEraser::clearKeptGivingIdentity($keptH) === 'not-confirmed'
    && ge_unexpectedChanges($printsBeforeSweep, ge_snapshot(), []) === [] && is_file($issuedH['file']) === true, json_encode($sweepA));

// (b) Confirmed, but ON HOLD.
ge_setting(SITE_G1, GivingRetention::KEY_CONFIRMED, 'true');
ge_setting(SITE_G1, GivingRetention::KEY_CONFIRMED_ON, $todayG1);
ge_setting(SITE_G1, GivingRetention::KEY_HOLD, 'true');
$settingRows = static fn (): array => ge_q("SELECT settingID, siteID, settingKey, settingValue FROM tblSettings WHERE settingKey LIKE 'giving.retention.%' ORDER BY settingID");
$allowSettings = static function () use ($settingRows): array {
    $allowed = [];
    foreach ($settingRows() as $row) {
        $allowed[ge_keyText('tblSettings', ['settingID' => (int) $row['settingID']])] = true;
    }

    return $allowed;
};
$sweepB = run_giving_identity_sweep();
ge_ok('ON HOLD: confirmed and past its date, and still the job clears nothing; the three records are counted as held',
    $sweepB['cleared'] === 0 && $sweepB['failed'] === 0 && $sweepB['onHold'] === 3 && $sweepB['waiting'] === $uncleared() - 3
    && GdprEraser::clearKeptGivingIdentity($keptH) === 'on-hold'
    && ge_unexpectedChanges($printsBeforeSweep, ge_snapshot(), $allowSettings()) === [] && is_file($issuedH['file']) === true, json_encode($sweepB));
// A hold typed some other way than this feature's page writes it still holds.
ge_setting(SITE_G1, GivingRetention::KEY_HOLD, 'TRUE');
$holdOdd = GdprEraser::clearKeptGivingIdentity($keptH);
ge_setting(SITE_G1, GivingRetention::KEY_HOLD, 'false');
ge_setting(SITE_G1, GivingRetention::KEY_CONFIRMED, 'yes');
$confirmedOdd = GdprEraser::clearKeptGivingIdentity($keptH);
ge_ok('a setting typed in an unexpected way falls on the side that clears nothing: a hold of "TRUE" still holds, and only the exact word "true" counts as confirmed',
    $holdOdd === 'on-hold' && $confirmedOdd === 'not-confirmed' && $uncleared(SITE_G1) === 3, json_encode([$holdOdd, $confirmedOdd]));

// (c) Confirmed, no hold, but ONE YEAR MORE: a year early.
ge_setting(SITE_G1, GivingRetention::KEY_CONFIRMED, 'true');
ge_setting(SITE_G1, GivingRetention::KEY_YEARS, '2');
$sweepC = run_giving_identity_sweep();
ge_ok('A YEAR EARLY: with two years set, the same record has a year still to run and the job clears nothing',
    $sweepC['cleared'] === 0 && $sweepC['failed'] === 0 && $sweepC['onHold'] === 0 && GdprEraser::clearKeptGivingIdentity($keptH) === 'not-due'
    && $uncleared(SITE_G1) === 3 && ge_unexpectedChanges($printsBeforeSweep, ge_snapshot(), $allowSettings()) === [], json_encode($sweepC));

// (d) Confirmed, no hold, one year: Hana's record is a day past its date.
ge_setting(SITE_G1, GivingRetention::KEY_YEARS, '1');
$hBeforeClear   = array_map('ge_row', $h);
$trailBefore    = count(ge_audit(REQ_H));
$activityBefore = (int) ge_one("SELECT COUNT(*) AS n FROM tblActivityLogs WHERE activityType = 'GivingIdentityCleared'")['n'];
$sweepD = run_giving_identity_sweep();
$hCleared = array_map('ge_row', $h);
$keptNow  = ge_one('SELECT * FROM tblGivingKeptDonors WHERE keptDonorID = ?', 'i', [$keptH]);
ge_ok('THE DAY AFTER THE KEEP-UNTIL DATE, confirmed and with no hold: the job clears that one record and no other',
    $sweepD['cleared'] === 1 && $sweepD['failed'] === 0 && $sweepD['onHold'] === 0 && $uncleared(SITE_G1) === 2
    && $keptNow['clearedAt'] !== null, json_encode([$sweepD, $keptNow]));
ge_ok('THE NAME, THE ADDRESS AND THE LINK GO TOGETHER: the kept row is emptied and stamped; both kept gifts lose the donor and keep amount, date and method; the declaration loses address, postcode, sender address, signature and notes and keeps its dates',
    $keptNow['fullName'] === null && $keptNow['address'] === null && $keptNow['postcode'] === null && (int) $keptNow['donorID'] === P_H
    && ge_same($hBeforeClear['giftOne'], $hCleared['giftOne'], ['donorID', 'donorName']) === true && $hCleared['giftOne']['donorID'] === null
    && ge_same($hBeforeClear['giftTwo'], $hCleared['giftTwo'], ['donorID', 'donorName']) === true && $hCleared['giftTwo']['donorID'] === null
    && (int) $hCleared['giftTwo']['amountPence'] === 4000 && $hCleared['giftTwo']['donatedAt'] === $edge['due']
    && ge_same($hBeforeClear['declaration'], $hCleared['declaration'], ['address', 'postcode', 'acceptedIP', 'signaturePath', 'notes']) === true
    && $hCleared['declaration']['address'] === null && $hCleared['declaration']['postcode'] === null && $hCleared['declaration']['acceptedIP'] === null
    && $hCleared['declaration']['signaturePath'] === null && $hCleared['declaration']['notes'] === null
    && $hBeforeClear['declaration']['acceptedIP'] !== null && $hCleared['declaration']['validFrom'] === '2001-01-01',
    json_encode([$keptNow, $hCleared['giftTwo'], $hCleared['declaration']]));
ge_ok('...and THE KEPT STATEMENT FILES go with them, the one the donor made for themselves included: deleted from disk, and the record no longer points at a file; the record itself, its totals and its period stay',
    is_file($issuedH['file']) === false && is_file($ownAllKeptH) === false && ge_same($hBeforeClear['statement'], $hCleared['statement'], ['pdfPath']) === true
    && $hCleared['statement']['pdfPath'] === null && $hCleared['statement']['totalPence'] === $hBeforeClear['statement']['totalPence'],
    json_encode([is_file($issuedH['file']), $hCleared['statement']]));
$trail     = ge_audit(REQ_H);
$clearLine = $trail[count($trail) - 1];
ge_ok('WITH AN AUDIT LINE, on the ORIGINAL erasure request\'s trail: it says the date has passed, what the name was removed from, that nothing was deleted, and how the date was worked out; it names nobody; and the chain still verifies',
    count($trail) === $trailBefore + 1 && $clearLine['action'] === 'anonymise' && $clearLine['tableName'] === 'GivingRecords'
    && $clearLine['recordKey'] === 'organisation #' . SITE_G1
    && str_contains((string) $clearLine['details'], 'was ' . GivingRetention::dateInWords($stateH['keepUntil']) . ' and has passed')
    && str_contains((string) $clearLine['details'], 'removed from 2 gifts, 1 tax declaration and the record that held them, and 2 kept statement files were deleted')
    && str_contains((string) $clearLine['details'], 'no record was deleted')
    && str_contains((string) $clearLine['details'], '1 year, confirmed on ' . GivingRetention::dateInWords($todayG1))
    && preg_match('/Hana|Kept Lane/', (string) $clearLine['details']) !== 1 && GdprEraser::verifyAuditChain(REQ_H) === true,
    json_encode($clearLine));
$activity = ge_q("SELECT activityDescription FROM tblActivityLogs WHERE activityType = 'GivingIdentityCleared' ORDER BY logID DESC LIMIT 1");
ge_ok('...and one line in the activity log for that organisation, with the count and no name',
    (int) ge_one("SELECT COUNT(*) AS n FROM tblActivityLogs WHERE activityType = 'GivingIdentityCleared'")['n'] === $activityBefore + 1
    && str_contains((string) ($activity[0]['activityDescription'] ?? ''), 'Organisation #' . SITE_G1 . ': the keep-until date had passed for 1 erased donor(s)')
    && str_contains((string) ($activity[0]['activityDescription'] ?? ''), 'Hana') === false, json_encode($activity));
ge_ok('A RECORD KEPT UNTIL TODAY IS NOT CLEARED TODAY: the two donors whose date is today still have their names, their declarations and their gifts exactly as they were',
    GdprEraser::clearKeptGivingIdentity($keptM) === 'not-due' && array_map('ge_row', $m)['declaration']['address'] === '7 Morrow Mews'
    && (int) array_map('ge_row', $m)['gift']['donorID'] === P_M
    && ge_one('SELECT fullName FROM tblGivingKeptDonors WHERE keptDonorID = ?', 'i', [$keptM])['fullName'] === 'Mo Tomorrow'
    && ge_one('SELECT fullName FROM tblGivingKeptDonors WHERE keptDonorID = ?', 'i', [$keptLid])['fullName'] === 'Lee Stopped');
$keptG2Now = ge_one('SELECT * FROM tblGivingKeptDonors WHERE siteID = ? AND donorID = ?', 'ii', [SITE_G2, P_H]);
ge_ok('ANOTHER ORGANISATION\'S RECORDS of the same donor are untouched: its kept row still holds the name, its gifts still carry the donor, and its statement file is still there',
    $keptG2Now === $keptG2 && $hCleared['giftAllOne'] === $hBeforeClear['giftAllOne'] && $hCleared['giftAllTwo'] === $hBeforeClear['giftAllTwo']
    && (int) $hCleared['giftAllTwo']['donorID'] === P_H && is_file($fileAllH) === true, json_encode($keptG2Now));

// Row counts in every table: nothing was deleted anywhere.
$countsAfterSweep = $tableCounts();
$differ = [];
foreach ($countsAfterSweep as $table => $count) {
    if ($count !== ($countsBeforeSweep[$table] ?? null)) {
        $differ[$table] = $count - (int) ($countsBeforeSweep[$table] ?? 0);
    }
}
ge_ok('NOTHING WAS DELETED: across all ' . count($countsAfterSweep) . ' tables the number of rows is the same as before the job ran, apart from one new audit line and one new activity-log line',
    $differ === ['tblActivityLogs' => 1, 'tblErasureAudit' => 1] && count($countsAfterSweep) > 200, json_encode($differ));
// And nothing else changed: only Hana's rows at organisation 3.
$allowedSweep = $allowSettings() + [ge_keyText('tblGivingKeptDonors', ['keptDonorID' => $keptH]) => true];
foreach (['giftOne', 'giftTwo', 'declaration', 'statement'] as $name) {
    $allowedSweep[ge_keyText($h[$name]['table'], $h[$name]['key'])] = true;
}
$afterSweep       = ge_snapshot();
$unexpectedSweep  = array_values(array_filter(
    ge_unexpectedChanges($printsBeforeSweep, $afterSweep, $allowedSweep),
    static fn (string $line): bool => str_starts_with($line, 'tblActivityLogs#') === false
));
ge_ok('...and across the whole database nothing changed but that donor\'s two gifts, declaration, statement record and kept row at that one organisation',
    $unexpectedSweep === [], implode('; ', array_slice($unexpectedSweep, 0, 8)));

// (e) A second run changes nothing.
$sweepE = run_giving_identity_sweep();
ge_ok('A SECOND RUN changes nothing: nothing is cleared twice, no second line is written, and asking for that record again says it is already cleared',
    $sweepE['cleared'] === 0 && $sweepE['failed'] === 0 && count(ge_audit(REQ_H)) === $trailBefore + 1
    && GdprEraser::clearKeptGivingIdentity($keptH) === 'already-cleared' && GdprEraser::clearKeptGivingIdentity(999999999) === 'gone'
    && ge_unexpectedChanges($afterSweep, ge_snapshot(), []) === [], json_encode($sweepE));
ge_ok('once cleared, the gifts are no longer in the tax claim file under any name, and the donor is still not offered a statement',
    str_contains(Giving::buildHmrcCsv(SITE_G1, $ago(800), $todayG1), '12 Kept Lane') === false
    && str_contains(Giving::buildHmrcCsv(SITE_G1, $ago(800), $todayG1), 'Deleted') === false
    && Giving::isErasedDonor(SITE_G1, P_H) === true && Giving::renderStatementPdf(SITE_G1, P_H, $ago(330), $ago(250), 'x') === false);

// (f) A kept file that cannot be deleted: nothing is cleared, and the next run tries again.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_insert('tblSites', [], ['siteID' => 5, 'timezone' => 'America/New_York']);
$catG3 = ge_id(ge_insert('tblGivingCategory', [], ['siteID' => 5]));
ge_user(P_N, 'Nia Stuck', 'nia.stuck@selftest.invalid');
$nGift = ge_gift(5, P_N, $ago(5), 300, $catG3);
$nDecl = ge_declaration(5, P_N, $ago(900), '1 Stuck Row', 'SR1 1SR');
$issuedN = ge_statement(5, P_N, $ago(40), $ago(1), 'nia.stuck@selftest.invalid');
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_request(REQ_N, P_N, 'nia.stuck@selftest.invalid', 'Nia Stuck', 'processing');
$resultN = ge_execute(REQ_N, P_N);
$keptN   = (int) (ge_one('SELECT keptDonorID FROM tblGivingKeptDonors WHERE siteID = 5 AND donorID = ?', 'i', [P_N])['keptDonorID'] ?? 0);
// The date the clearing goes by is the same for every organisation: the last place on Earth's. (This worked out
// New York's OWN date, which is what the clearing asked before #604.)
$todayG3 = $todayG1;
ge_q('UPDATE tblGivingKeptDonors SET lastGiftDate = ? WHERE keptDonorID = ?', 'si', [$boundary($todayG3, 2)['due'], $keptN]);
ge_q('UPDATE tblGivingEntry SET donatedAt = ? WHERE entryID = ?', 'si', [$boundary($todayG3, 2)['due'], ge_id($nGift)]);
ge_setting(5, GivingRetention::KEY_YEARS, '1');
ge_setting(5, GivingRetention::KEY_CONFIRMED, 'true');
$dirN = dirname($issuedN['file']);
chmod($dirN, 0500);
$stuck   = GdprEraser::clearKeptGivingIdentity($keptN);
$sweepF  = run_giving_identity_sweep();
chmod($dirN, 0700);
$nRow = ge_one('SELECT fullName, address, clearedAt FROM tblGivingKeptDonors WHERE keptDonorID = ?', 'i', [$keptN]);
ge_ok('A KEPT FILE THAT CANNOT BE DELETED: nothing is cleared for that donor (the name, the gift\'s donor and the file all stay) and the job reports it as failed, not as done',
    is_array($resultN) === true && $resultN['failed'] === 0 && $stuck === 'failed' && $sweepF['failed'] === 1 && $sweepF['cleared'] === 0
    && $nRow['fullName'] === 'Nia Stuck' && $nRow['clearedAt'] === null && (int) ge_row($nGift)['donorID'] === P_N
    && ge_row($nDecl)['address'] === '1 Stuck Row' && is_file($issuedN['file']) === true,
    ($isRoot === true ? 'THIS RUN IS ROOT, which a read-only folder cannot stop: run it as an ordinary user. ' : '') . json_encode([$stuck, $sweepF, $nRow]));

// The WHOLE sweep, as the scheduled job calls it. Its other parts delete old
// log rows and old registrations, which is why it is run once and last.
// (The made-up events carry a made-up number of days to keep registrations
// for, which is far too large for the database's date arithmetic. That part
// of the sweep is not what is being tested here, so it is given a real one.)
ge_q('UPDATE tblEvents SET registrationRetentionDays = NULL');
$full = run_retention_sweep();
$nRow = ge_one('SELECT fullName, clearedAt FROM tblGivingKeptDonors WHERE keptDonorID = ?', 'i', [$keptN]);
ge_ok('THE JOB ITSELF: with the file now deletable, the whole sweep clears that record, reports it under its own name, and does NOT count it as a deletion',
    $full['givingIdentityCleared'] === 1 && $full['givingIdentityFailed'] === 0 && $nRow['fullName'] === null && $nRow['clearedAt'] !== null
    && is_file($issuedN['file']) === false && array_key_exists('givingIdentityOnHold', $full) === true && array_key_exists('givingIdentityWaiting', $full) === true
    && $full['totalDeleted'] === $full['activityDeleted'] + $full['errorsDeleted'] + $full['registrationsDeleted'], json_encode($full));
// A donor whose last gift is so old that the keep-until date has ALREADY
// passed on the day they are erased, at an organisation that has confirmed
// its figure and has no hold. Nothing has to be kept any longer, so the
// erasure clears it there and then, by the same guarded routine the job uses.
// (Otherwise an organisation that never switched the nightly job on would
// keep such a name for ever.)
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_user(P_O, 'Ola Overdue', 'ola.overdue@selftest.invalid');
$o = ['declaration' => ge_declaration(5, P_O, $ago(3000), '4 Overdue Oval', 'OV4 4OV'), 'gift' => ge_gift(5, P_O, $ago(2000), 450, $catG3)];
$issuedO = ge_statement(5, P_O, $ago(2100), $ago(1900), 'ola.overdue@selftest.invalid');
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_request(REQ_O, P_O, 'ola.overdue@selftest.invalid', 'Ola Overdue', 'processing');
$oWas    = array_map('ge_row', $o);
$resultO = ge_execute(REQ_O, P_O);
$oNow    = array_map('ge_row', $o);
$keptO   = ge_one('SELECT fullName, address, postcode, clearedAt, lastGiftDate FROM tblGivingKeptDonors WHERE siteID = 5 AND donorID = ?', 'i', [P_O]);
$linesO  = array_values(array_filter(ge_audit(REQ_O), static fn (array $l): bool => $l['tableName'] === 'GivingRecords'));
ge_ok('ALREADY PAST ITS DATE AT ERASURE (a last gift about five and a half years ago, one year kept, confirmed, no hold): the erasure records what would have been kept and clears it in the same run, with both lines on the trail, in that order; the gift keeps its amount and date',
    is_array($resultO) === true && $resultO['failed'] === 0
    && (ge_one('SELECT status FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_O])['status'] ?? '') === 'completed'
    && $keptO !== null && $keptO['fullName'] === null && $keptO['address'] === null && $keptO['clearedAt'] !== null && $keptO['lastGiftDate'] === $ago(2000)
    && $oNow['gift']['donorID'] === null && (int) $oNow['gift']['amountPence'] === 450 && $oNow['gift']['donatedAt'] === $ago(2000)
    && $oNow['declaration']['address'] === null && $oWas['declaration']['address'] === '4 Overdue Oval' && is_file($issuedO['file']) === false
    && count($linesO) === 2 && $linesO[0]['action'] === 'retain' && str_contains((string) $linesO[0]['details'], 'keep this donor\'s name and address until')
    && $linesO[1]['action'] === 'anonymise' && str_contains((string) $linesO[1]['details'], 'and has passed')
    && preg_match('/Ola|Overdue Oval/', (string) $linesO[0]['details'] . (string) $linesO[1]['details']) !== 1
    && GdprEraser::verifyAuditChain(REQ_O) === true,
    $resultO instanceof Throwable ? $resultO->getMessage() : json_encode([$keptO, $oNow['gift'], $linesO]));

$previewCounts = preview_retention_counts(365, 365);
ge_ok('the page\'s preview counts the same records by the same rule: none due now, and the waiting, held and not-yet-due figures add up to every name still kept',
    $previewCounts['givingIdentityDue'] === 0
    && $previewCounts['givingIdentityOnHold'] + $previewCounts['givingIdentityWaiting'] + $previewCounts['givingIdentityNotDue'] === $uncleared()
    && $previewCounts['givingIdentityNotDue'] >= 2, json_encode($previewCounts));

// =============================================================================
ge_section('13', 'The settings: a country per organisation, and a save that cannot clear records by a slip (roll-out package A2b)');
// =============================================================================
$givingSettings = static fn (?int $siteId): array => array_column(ge_q(
    "SELECT settingKey, settingValue FROM tblSettings WHERE settingKey LIKE 'giving.retention.%' AND siteID " . ($siteId === null ? 'IS NULL' : '= ' . $siteId) . ' ORDER BY settingKey'
), 'settingValue', 'settingKey');
$real = GivingRetention::data();
$foundCode  = '';
$raisedCode = '';
foreach (array_keys($real['countries']) as $code) {
    $kind = GivingRetention::suggestionFor((string) $code)['kind'];
    if ($kind === 'found' && $foundCode === '') {
        $foundCode = (string) $code;
    } elseif ($kind === 'raised' && $raisedCode === '') {
        $raisedCode = (string) $code;
    }
}
$form = static fn (array $over = []): array => $over + ['countryCode' => '', 'scope' => 'receipted', 'years' => '', 'confirmed' => '', 'yearEndMonth' => '', 'yearEndDay' => '', 'hold' => '', 'understood' => ''];

// THE COUNTRY TRAP. The installation's one organisation address says "GB"
// unless changed. An organisation that has not set its own country must not
// be treated as British because of it.
$ruleG2 = GivingRetention::ruleForSite(SITE_G2);
ge_ok('AN ORGANISATION WITH NO COUNTRY SET gets the flagged placeholder (' . $real['placeholderYears'] . ' years), even though the installation\'s own address says "GB"',
    App::settingForSite('org.address.countryCode', SITE_G2) === 'GB' && $ruleG2['countryCode'] === '' && $ruleG2['suggestion']['kind'] === 'placeholder'
    && $ruleG2['suggestion']['why'] === 'country-not-set' && $ruleG2['years'] === $real['placeholderYears'] && $ruleG2['confirmed'] === false,
    json_encode([$ruleG2['countryCode'], $ruleG2['suggestion']['kind'], $ruleG2['years']]));

$before = [$givingSettings(SITE_G2), $givingSettings(SITE_G1), $givingSettings(null)];
$refusals = [
    'a country not in the list' => $form(['countryCode' => 'QQ']),
    'a country that is not two letters' => $form(['countryCode' => 'GBR']),
    'a "which gifts" value outside the two' => $form(['scope' => 'none']),
    'nought years' => $form(['years' => '0']),
    'fifty-one years' => $form(['years' => '51']),
    'years in words' => $form(['years' => 'six']),
    'a year end of 29 February' => $form(['yearEndMonth' => '2', 'yearEndDay' => '29']),
    'a year end of 31 April' => $form(['yearEndMonth' => '4', 'yearEndDay' => '31']),
    'a month with no day' => $form(['yearEndMonth' => '4']),
    'a thirteenth month' => $form(['yearEndMonth' => '13', 'yearEndDay' => '1']),
];
$accepted = [];
foreach ($refusals as $what => $posted) {
    $answer = GivingRetention::saveForSite($mysqli, SITE_G2, $posted);
    if ($answer['saved'] !== false || $answer['type'] !== 'danger' || str_contains($answer['message'], 'Nothing was saved') === false) {
        $accepted[] = $what;
    }
}
ge_ok('A VALUE OUTSIDE ITS LIST REFUSES THE WHOLE SAVE (' . count($refusals) . ' kinds tried), with a message, and not one setting of any organisation changes',
    $accepted === [] && [$givingSettings(SITE_G2), $givingSettings(SITE_G1), $givingSettings(null)] === $before, implode(', ', $accepted));

$saved   = GivingRetention::saveForSite($mysqli, SITE_G2, $form(['countryCode' => strtolower($foundCode), 'scope' => 'all', 'yearEndMonth' => '4', 'yearEndDay' => '5']));
$ruleG2  = GivingRetention::ruleForSite(SITE_G2);
$wanted  = GivingRetention::suggestionFor($foundCode);
ge_ok('choosing a country follows the portal\'s suggestion for it (' . $foundCode . ': ' . $wanted['years'] . ' years), without confirming anything; the year end is stored as month and day',
    $saved['saved'] === true && $ruleG2['countryCode'] === $foundCode && $ruleG2['years'] === $wanted['years'] && $ruleG2['yearsOwn'] === null
    && $ruleG2['confirmed'] === false && $ruleG2['yearEnd'] === '04-05' && $ruleG2['scope'] === 'all'
    && [$givingSettings(SITE_G1), $givingSettings(null)] === [$before[1], $before[2]], json_encode([$saved, $givingSettings(SITE_G2)]));
if ($raisedCode !== '') {
    GivingRetention::saveForSite($mysqli, SITE_G2, $form(['countryCode' => $raisedCode, 'scope' => 'all', 'yearEndMonth' => '4', 'yearEndDay' => '5']));
}
$raisedRule = GivingRetention::ruleForSite(SITE_G2);
ge_ok('a country whose figure is under the minimum is followed at the MINIMUM (' . $raisedCode . ': found ' . ($raisedRule['suggestion']['yearsFound'] ?? '?') . ', suggested ' . $raisedRule['years'] . ')',
    $raisedCode !== '' && $raisedRule['suggestion']['kind'] === 'raised' && $raisedRule['years'] === $real['minimumSuggestedYears']
    && $raisedRule['suggestion']['yearsFound'] < $real['minimumSuggestedYears'], json_encode($raisedRule['suggestion']['kind']));

// "Confirmed on" is a date PEOPLE READ, so it is the organisation's OWN date (Auckland's), not the last place on
// Earth's, which is a day or two behind it at every hour. Auckland's date is read here just before the save and
// just after it, and the stored date must be one of those two (they differ only when its midnight falls between).
// (This allowed "today or the day before" in Auckland, and the day before is exactly what the last place's date
// is there: so when #604 stored THAT date by mistake, this check passed. Found by the second check.)
$ownDateG2 = static fn (): string => ge_now()->setTimezone(new DateTimeZone('Pacific/Auckland'))->format('Y-m-d'); // worked out here, not by the code under test
$todayG2   = $ownDateG2();
$ticked    = GivingRetention::saveForSite($mysqli, SITE_G2, $form(['countryCode' => $foundCode, 'scope' => 'all', 'confirmed' => '1', 'yearEndMonth' => '4', 'yearEndDay' => '5']));
$ruleG2    = GivingRetention::ruleForSite(SITE_G2);
ge_ok('TICKING "CONFIRMED" fixes the figure: the suggestion on screen is written into the organisation\'s own setting, with today\'s date where the organisation IS (Auckland\'s own, which is ahead of the date the clearing goes by)',
    $ticked['saved'] === true && $ruleG2['confirmed'] === true && $ruleG2['yearsOwn'] === $wanted['years'] && $ruleG2['years'] === $wanted['years']
    && in_array($ruleG2['confirmedOn'], [$todayG2, $ownDateG2()], true) === true && $ruleG2['confirmedOn'] > $todayG1
    && $givingSettings(SITE_G2)[GivingRetention::KEY_YEARS] === (string) $wanted['years'],
    json_encode([$ticked, $givingSettings(SITE_G2), 'Auckland\'s own date' => $todayG2, 'the last place\'s' => $todayG1]));
$changed = GivingRetention::saveForSite($mysqli, SITE_G2, $form(['countryCode' => $foundCode, 'scope' => 'all', 'years' => (string) ($wanted['years'] + 3), 'confirmed' => '1', 'yearEndMonth' => '4', 'yearEndDay' => '5']));
$ruleG2  = GivingRetention::ruleForSite(SITE_G2);
ge_ok('CHANGING THE FIGURE UN-TICKS IT, even with the box still ticked: saved, not confirmed any more, and the message says to tick it again once the new figure is confirmed',
    $changed['saved'] === true && $changed['type'] === 'warning' && $ruleG2['confirmed'] === false && $ruleG2['confirmedOn'] === ''
    && $ruleG2['yearsOwn'] === $wanted['years'] + 3 && str_contains($changed['message'], 'no longer marked as confirmed')
    && str_contains($changed['message'], 'nothing will be cleared until it is'), json_encode([$changed, $givingSettings(SITE_G2)]));
// CHANGING THE COUNTRY UN-TICKS IT TOO, with the figure left exactly as it
// was. The figure was confirmed against one country's rules; it says nothing
// about another's. (Added after the independent check of 12 October 2026: it
// took this out of the save and no check noticed.)
$otherCode = '';
foreach (array_keys($real['countries']) as $code) {
    if ((string) $code !== $foundCode) {
        $otherCode = (string) $code;
        break;
    }
}
$nine      = $form(['countryCode' => $foundCode, 'scope' => 'all', 'years' => '9', 'confirmed' => '1', 'yearEndMonth' => '4', 'yearEndDay' => '5']);
$confirmed = GivingRetention::saveForSite($mysqli, SITE_G2, $nine);
$ruleNine  = GivingRetention::ruleForSite(SITE_G2);
$moved     = GivingRetention::saveForSite($mysqli, SITE_G2, ['countryCode' => $otherCode] + $nine);
$ruleMoved = GivingRetention::ruleForSite(SITE_G2);
ge_ok('CHANGING THE COUNTRY UN-TICKS IT, with the same figure and the box still ticked (' . $foundCode . ' to ' . $otherCode . ', 9 years both times): saved, the new country is stored, and it is no longer confirmed',
    $otherCode !== '' && $confirmed['saved'] === true && $ruleNine['confirmed'] === true && $ruleNine['yearsOwn'] === 9 && $ruleNine['countryCode'] === $foundCode
    && $moved['saved'] === true && $moved['type'] === 'warning' && $ruleMoved['countryCode'] === $otherCode && $ruleMoved['yearsOwn'] === 9
    && $ruleMoved['confirmed'] === false && $ruleMoved['confirmedOn'] === '' && str_contains($moved['message'], 'no longer marked as confirmed'),
    json_encode([$confirmed, $moved, $givingSettings(SITE_G2)]));
$lower = GivingRetention::saveForSite($mysqli, SITE_G2, $form(['countryCode' => $foundCode, 'scope' => 'all', 'years' => '1', 'yearEndMonth' => '4', 'yearEndDay' => '5']));
ge_ok('a figure below the suggestion is saved, not blocked, and the message says what the suggestion is',
    $lower['saved'] === true && $lower['type'] === 'warning' && GivingRetention::ruleForSite(SITE_G2)['yearsOwn'] === 1
    && str_contains($lower['message'], 'You have set 1 year. Our suggestion is ' . $wanted['years'] . '.'), json_encode($lower));

// A SLIP OF THE FINGER MUST NOT CLEAR RECORDS. Organisation 3 still keeps two
// names. Make their dates long past and the rule unconfirmed: nothing is due.
// Confirming would make both due at once.
ge_q('UPDATE tblGivingKeptDonors SET lastGiftDate = ? WHERE keptDonorID IN (?, ?)', 'sii', [$ago(4000), $keptM, $keptLid]);
ge_q('UPDATE tblGivingEntry SET donatedAt = ? WHERE entryID IN (?, ?)', 'sii', [$ago(4000), ge_id($m['gift']), ge_id($l['gift'])]);
ge_setting(SITE_G1, GivingRetention::KEY_CONFIRMED, 'false');
ge_setting(SITE_G1, GivingRetention::KEY_CONFIRMED_ON, '');
$beforeG1 = $givingSettings(SITE_G1);
$slip     = GivingRetention::saveForSite($mysqli, SITE_G1, $form(['years' => '1', 'confirmed' => '1']));
ge_ok('A SAVE THAT WOULD CLEAR KEPT RECORDS IS REFUSED until the administrator ticks that they understand: it says how many (2), saves nothing, and the names are still kept',
    $slip['saved'] === false && $slip['newlyDue'] === 2 && str_contains($slip['message'], 'remove the name and address of 2 erased donors')
    && str_contains($slip['message'], 'Nothing was saved') && $givingSettings(SITE_G1) === $beforeG1
    && run_giving_identity_sweep()['cleared'] === 0 && $uncleared(SITE_G1) === 2, json_encode($slip));
// The same through the hold switch: confirmed and held, then the hold taken off.
ge_setting(SITE_G1, GivingRetention::KEY_CONFIRMED, 'true');
ge_setting(SITE_G1, GivingRetention::KEY_CONFIRMED_ON, $todayG1);
ge_setting(SITE_G1, GivingRetention::KEY_HOLD, 'true');
$beforeG1 = $givingSettings(SITE_G1);
$unhold   = GivingRetention::saveForSite($mysqli, SITE_G1, $form(['years' => '1', 'confirmed' => '1']));
ge_ok('...and so is switching a HOLD off when records are past their date',
    $unhold['saved'] === false && $unhold['newlyDue'] === 2 && $givingSettings(SITE_G1) === $beforeG1 && $uncleared(SITE_G1) === 2, json_encode($unhold));
$g2Before   = [$givingSettings(SITE_G2), $givingSettings(null)];
$understood = GivingRetention::saveForSite($mysqli, SITE_G1, $form(['years' => '1', 'confirmed' => '1', 'understood' => '1']));
ge_ok('with the box ticked it saves, says how many records will be cleared at the next run, and ANOTHER ORGANISATION\'S settings and the installation-wide defaults are untouched',
    $understood['saved'] === true && $understood['newlyDue'] === 2 && str_contains($understood['message'], '2 kept donor records are now past the keep-until date')
    && GivingRetention::ruleForSite(SITE_G1)['hold'] === false && GivingRetention::ruleForSite(SITE_G1)['confirmed'] === true
    && [$givingSettings(SITE_G2), $givingSettings(null)] === $g2Before && $understood['changes'] !== [], json_encode($understood));
// A VALUE SET FOR THE WHOLE INSTALLATION IS NOT AN ORGANISATION'S OWN. A
// global administrator can edit the installation-wide rows in the general
// settings editor. If an organisation inherited them, one such edit would
// "confirm" a figure for every organisation that had never opened the page.
$defaultsWere = $givingSettings(null);
$bare         = SITE_2; // an organisation with no giving-records settings of its own
foreach ([GivingRetention::KEY_CONFIRMED => 'true', GivingRetention::KEY_CONFIRMED_ON => $todayG1, GivingRetention::KEY_YEARS => '1',
    GivingRetention::KEY_COUNTRY => $foundCode, GivingRetention::KEY_SCOPE => 'all', GivingRetention::KEY_YEAR_END => '01-01'] as $key => $value) {
    ge_q('UPDATE tblSettings SET settingValue = ? WHERE settingKey = ? AND siteID IS NULL', 'ss', [$value, $key]);
}
$inherited = GivingRetention::ruleForSite($bare);
$dueAnyway = GivingRetention::stateFor($inherited, $ago(4000))['state'];
ge_q("UPDATE tblSettings SET settingValue = 'true' WHERE settingKey = ? AND siteID IS NULL", 's', [GivingRetention::KEY_HOLD]);
$heldEverywhere = [GivingRetention::ruleForSite($bare)['hold'], GivingRetention::ruleForSite(SITE_G1)['hold'], GivingRetention::ruleForSite(SITE_G2)['hold']];
foreach ($defaultsWere as $key => $value) {
    ge_q('UPDATE tblSettings SET settingValue = ? WHERE settingKey = ? AND siteID IS NULL', 'ss', [$value, $key]);
}
ge_ok('AN INSTALLATION-WIDE "CONFIRMED" CONFIRMS NOBODY: with the installation-wide rows set to confirmed, one year, a country, every gift and a year end, an organisation with no settings of its own is still unconfirmed, with no country, the placeholder, the narrower rule and no year end, and a record ten years old is not due; only an installation-wide HOLD reaches every organisation',
    $givingSettings($bare) === [] && $inherited['confirmed'] === false && $inherited['confirmedOn'] === '' && $inherited['yearsOwn'] === null
    && $inherited['countryCode'] === '' && $inherited['years'] === $real['placeholderYears'] && $inherited['scope'] === 'receipted'
    && $inherited['yearEnd'] === '' && $inherited['hold'] === false && $dueAnyway === 'not-confirmed'
    && $heldEverywhere === [true, true, true] && $givingSettings(null) === $defaultsWere
    && GivingRetention::ruleForSite(SITE_G1)['hold'] === false,
    json_encode([$inherited['confirmed'], $inherited['yearsOwn'], $inherited['countryCode'], $inherited['scope'], $dueAnyway, $heldEverywhere]));
$keptLinks = array_column(ge_q(
    "SELECT CONSTRAINT_NAME AS name, DELETE_RULE AS rule FROM information_schema.REFERENTIAL_CONSTRAINTS "
    . "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'tblGivingKeptDonors' ORDER BY CONSTRAINT_NAME"
), 'rule', 'name');
$deleteAccount = 'deleted';
try {
    ge_q('DELETE FROM tblUsers WHERE userID = ?', 'i', [P_M]);
} catch (mysqli_sql_exception $e) {
    $deleteAccount = (int) $e->getCode() === 1451 ? 'refused' : 'another error: ' . $e->getMessage();
}
ge_ok('THE DATABASE ITSELF GUARDS THE KEPT NAME: deleting an account row that a kept name points at is refused, never passed on to the kept row; and losing the erasure request would leave the kept row in place',
    in_array($keptLinks['fk_gkd_donor'] ?? '', ['RESTRICT', 'NO ACTION'], true) === true && ($keptLinks['fk_gkd_request'] ?? '') === 'SET NULL'
    && in_array($keptLinks['fk_gkd_site'] ?? '', ['RESTRICT', 'NO ACTION'], true) === true && count($keptLinks) === 3
    && $deleteAccount === 'refused' && ge_one('SELECT fullName FROM tblGivingKeptDonors WHERE keptDonorID = ?', 'i', [$keptM])['fullName'] === 'Mo Tomorrow',
    json_encode([$keptLinks, $deleteAccount]));
ge_ok('the installation-wide defaults are the cautious ones: no country, nothing confirmed, no hold, the narrower rule',
    $givingSettings(null) === [
        GivingRetention::KEY_CONFIRMED => 'false', GivingRetention::KEY_CONFIRMED_ON => '', GivingRetention::KEY_COUNTRY => '',
        GivingRetention::KEY_HOLD => 'false', GivingRetention::KEY_SCOPE => 'receipted', GivingRetention::KEY_YEAR_END => '',
        GivingRetention::KEY_YEARS => '',
    ], json_encode($givingSettings(null)));

// =============================================================================
ge_section('14', 'A run that stops part way, a cancelled request, envelopes in an open count, and the guards around them (fix round, 12 October 2026)');
// =============================================================================
// WHAT THIS SECTION HOLDS THE CODE TO. The independent check of 12 October
// 2026 found that a kept record made by a run that did not finish was never
// brought up to date, and that nothing checked the account had really been
// emptied before a name was cleared. The four rules that close it:
//
//   1. A kept name is cleared, and a donor is treated as erased, ONLY when
//      the account it points at has really been emptied.
//   2. The keep-until date is counted from the LATER of the date on the kept
//      row and the newest gift (or envelope in a count) still linked to the
//      donor at that organisation.
//   3. Every later run works the kept row out again, and never writes a
//      made-up name over a real one.
//   4. A request whose giving step has run cannot be cancelled. (The page's
//      part of that is checked in tools/erasure-pages-selftest.php.)
// Section 13 ended with two records at organisation 3 past their date and
// confirmed. The job is run once here to clear them, so that every "the job
// clears nothing" below is about this section's records and nothing else.
$leftOver = run_giving_identity_sweep();
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
$catF = [];
foreach ([SITE_F1, SITE_F2, SITE_F3, SITE_F4] as $siteId) {
    ge_insert('tblSites', [], ['siteID' => $siteId, 'timezone' => 'Europe/London']);
    $catF[$siteId] = ge_id(ge_insert('tblGivingCategory', [], ['siteID' => $siteId]));
}
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
$keptAt   = static fn (int $siteId, int $donor): ?array => ge_one('SELECT * FROM tblGivingKeptDonors WHERE siteID = ? AND donorID = ?', 'ii', [$siteId, $donor]);
$accountF = static fn (int $id): array => ge_one('SELECT fullName, emailAddress, isActive FROM tblUsers WHERE userID = ?', 'i', [$id]);
$statusF  = static fn (int $requestId): string => (string) (ge_one('SELECT status FROM tblErasureRequest WHERE requestID = ?', 'i', [$requestId])['status'] ?? '');
$donorOf  = static fn (array $gift): ?int => (static fn (?array $row): ?int => $row === null || $row['donorID'] === null ? null : (int) $row['donorID'])(ge_row($gift));
// Stops the giving step at ONE organisation, so that the organisations before
// it are already dealt with when the run stops.
$stopAt = static function (int $siteId): void {
    ge_trigger('selftest_gdpr_stop_kept', 'CREATE TRIGGER `selftest_gdpr_stop_kept` BEFORE INSERT ON `tblGivingKeptDonors` FOR EACH ROW BEGIN '
        . 'IF NEW.siteID = ' . $siteId . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'planted by the self-test'; END IF; END");
};

// (a) THE CHECKER'S SCENARIO 3. Rita gives at two organisations. The run
//     deals with the first and stops at the second. Before it is run again a
//     standing-order gift arrives, in the NEXT financial year; a back-dated
//     gift from before her declaration is entered; and she changes her name
//     and the address on her declaration.
$yearEndDay = substr($ago(3), 5) === '02-29' ? $ago(4) : $ago(3);   // the financial year ended this day
ge_setting(SITE_F1, GivingRetention::KEY_YEAR_END, substr($yearEndDay, 5));
ge_setting(SITE_F1, GivingRetention::KEY_YEARS, '6');
ge_setting(SITE_F1, GivingRetention::KEY_CONFIRMED, 'true');
ge_setting(SITE_F1, GivingRetention::KEY_CONFIRMED_ON, $todayG1);
ge_setting(SITE_F1, GivingRetention::KEY_HOLD, 'true');
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_user(P_R, 'Rita Rerun', 'rita.rerun@selftest.invalid');
$r = [];
$r['declaration'] = ge_declaration(SITE_F1, P_R, $ago(400), '8 Rita Road', 'RR8 8RR');
$r['giftOld']     = ge_gift(SITE_F1, P_R, $ago(500), 900, $catF[SITE_F1]);    // before the declaration: not kept
$r['giftOne']     = ge_gift(SITE_F1, P_R, $ago(10), 1000, $catF[SITE_F1]);    // kept, in the year that has just ended
$r['declTwo']     = ge_declaration(SITE_F2, P_R, $ago(100), '8 Rita Road', 'RR8 8RR');
$r['giftOther']   = ge_gift(SITE_F2, P_R, $ago(50), 1100, $catF[SITE_F2]);
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_request(REQ_R, P_R, 'rita.rerun@selftest.invalid', 'Rita Rerun', 'processing');
ge_q('UPDATE tblErasureRequest SET siteID = ? WHERE requestID = ?', 'ii', [SITE_F1, REQ_R]);
$stopAt(SITE_F2);
$firstR = ge_execute(REQ_R, P_R);
ge_dropTrigger('selftest_gdpr_stop_kept');
$keptR1 = $keptAt(SITE_F1, P_R);
ge_ok('THE FIRST RUN STOPS AT THE SECOND ORGANISATION: the account still names her, the request is back at "pending review", and the first organisation is already dealt with (her name kept, counted from the gift of 10 days ago; the gift from before her declaration has lost its donor)',
    $firstR instanceof Throwable && $accountF(P_R) === ['fullName' => 'Rita Rerun', 'emailAddress' => 'rita.rerun@selftest.invalid', 'isActive' => 1]
    && $statusF(REQ_R) === 'pending_review' && $keptR1 !== null && $keptR1['fullName'] === 'Rita Rerun' && $keptR1['lastGiftDate'] === $ago(10)
    && (int) $keptR1['keptGiftCount'] === 1 && $keptR1['clearedAt'] === null && $donorOf($r['giftOld']) === null && $donorOf($r['giftOne']) === P_R
    && $keptAt(SITE_F2, P_R) === null && $donorOf($r['giftOther']) === P_R,
    $firstR instanceof Throwable ? json_encode([$accountF(P_R), $statusF(REQ_R), $keptR1]) : 'the run did not stop: ' . json_encode($firstR));
// Rule 1, between the runs: she is still a member.
ge_ok('BETWEEN THE RUNS SHE IS NOT AN ERASED DONOR: the giving code does not treat her as one, the clearing refuses her kept record because the account is not emptied, and the job does not count it as waiting, held or failed',
    Giving::isErasedDonor(SITE_F1, P_R) === false && GdprEraser::accountIsEmptied($mysqli, P_R) === false
    && GdprEraser::clearKeptGivingIdentity((int) $keptR1['keptDonorID']) === 'not-erased'
    && GivingRetention::stateOfKeptRow($mysqli, GivingRetention::ruleForSite(SITE_F1), SITE_F1, P_R, (string) $keptR1['lastGiftDate'])['accountEmptied'] === false,
    json_encode([Giving::isErasedDonor(SITE_F1, P_R), GdprEraser::clearKeptGivingIdentity((int) $keptR1['keptDonorID'])]));
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
$r['giftNew']      = ge_gift(SITE_F1, P_R, $ago(1), 5000, $catF[SITE_F1]);    // kept, and in the NEW financial year
$r['giftBackdate'] = ge_gift(SITE_F1, P_R, $ago(450), 700, $catF[SITE_F1]);   // entered late, from before the declaration: not kept
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_q("UPDATE tblUsers SET fullName = 'Rita Rerun-Wed' WHERE userID = ?", 'i', [P_R]);
ge_q("UPDATE tblGiftAidDeclaration SET address = '9 New Road', postcode = 'NR9 9NR' WHERE declarationID = ?", 'i', [ge_id($r['declaration'])]);
ge_q("UPDATE tblErasureRequest SET status = 'processing' WHERE requestID = ?", 'i', [REQ_R]);
$secondR = ge_execute(REQ_R, P_R);
$keptR2  = $keptAt(SITE_F1, P_R);
// Worked out HERE, not by the code under test. The gift of yesterday falls in
// the financial year that ends one year after the year end just gone; six
// years after that is seven years after the year end just gone. The first
// run's date was one year earlier, which is the fault.
$rightR  = $plusYears($yearEndDay, 7);
$stateR  = GivingRetention::stateOfKeptRow($mysqli, GivingRetention::ruleForSite(SITE_F1), SITE_F1, P_R, (string) ($keptR2['lastGiftDate'] ?? ''));
ge_ok('THE SECOND RUN WORKS THE KEPT RECORD OUT AGAIN (rule 3): it completes; the record now counts from the gift of YESTERDAY, with 2 kept gifts, so the name is kept until ' . $rightR . ' and not a year less',
    is_array($secondR) === true && $secondR['failed'] === 0 && $statusF(REQ_R) === 'completed'
    && $keptR2['lastGiftDate'] === $ago(1) && (int) $keptR2['keptGiftCount'] === 2 && (int) $keptR2['keptDonorID'] === (int) $keptR1['keptDonorID']
    && $stateR['keepUntil'] === $rightR && $stateR['keepUntil'] !== $plusYears($yearEndDay, 6) && $stateR['countedFromGift'] === $ago(1),
    $secondR instanceof Throwable ? $secondR->getMessage() : json_encode([$keptR2['lastGiftDate'], $keptR2['keptGiftCount'], $stateR]));
$lineR = array_values(array_filter(ge_audit(REQ_R), static fn (array $l): bool => $l['tableName'] === 'GivingRecords' && $l['recordKey'] === 'organisation #' . SITE_F1));
ge_ok('...the name and address were READ AGAIN from the account as they are now (she had changed both), the back-dated gift that is not kept lost its donor, both kept gifts still carry her, and the line says the earlier run stopped before the account was emptied, and names nobody',
    $keptR2['fullName'] === 'Rita Rerun-Wed' && $keptR2['address'] === '9 New Road' && $keptR2['postcode'] === 'NR9 9NR'
    && $donorOf($r['giftBackdate']) === null && $donorOf($r['giftNew']) === P_R && $donorOf($r['giftOne']) === P_R
    && count($lineR) === 2 && $lineR[0]['action'] === 'retain' && $lineR[1]['action'] === 'retain'
    && str_contains((string) $lineR[1]['details'], 'were worked out again by this one')
    && str_contains((string) $lineR[1]['details'], 'The earlier run stopped before the account was emptied, so the name and address held were read again from the account')
    && str_contains((string) $lineR[1]['details'], '1 other gift keeps the amount, date and method and no longer names the donor')
    && preg_match('/Rita|Rerun|New Road|Rita Road/', (string) $lineR[1]['details']) !== 1,
    json_encode([$keptR2, $lineR[1] ?? null]));
ge_ok('...and NOW she is an erased donor: the account is emptied, the giving code says so, the second organisation has its own kept record, and the clearing answers by the rule (a hold is on) instead of refusing',
    GdprEraser::accountIsEmptied($mysqli, P_R) === true && Giving::isErasedDonor(SITE_F1, P_R) === true && Giving::isErasedDonor(SITE_F2, P_R) === true
    && ($keptAt(SITE_F2, P_R)['fullName'] ?? '') === 'Rita Rerun-Wed' && GdprEraser::clearKeptGivingIdentity((int) $keptR2['keptDonorID']) === 'on-hold'
    && GdprEraser::verifyAuditChain(REQ_R) === true,
    json_encode([$accountF(P_R), GdprEraser::clearKeptGivingIdentity((int) $keptR2['keptDonorID'])]));

// (b) THE CHECKER'S SCENARIO 2. Stan's run stops at his second organisation
//     too. This time the request is CANCELLED and he carries on as a member.
//     (The page now refuses that cancel. It is done straight in the database
//     here, as the page did until 12 October 2026, because a database that
//     was upgraded with such a request already in it holds exactly this.)
foreach ([GivingRetention::KEY_YEARS => '6', GivingRetention::KEY_CONFIRMED => 'true', GivingRetention::KEY_CONFIRMED_ON => $todayG1,
    GivingRetention::KEY_HOLD => 'true', GivingRetention::KEY_YEAR_END => ''] as $key => $value) {
    ge_setting(SITE_F3, $key, $value);
}
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_user(P_ST, 'Stan Stopped', 'stan.stopped@selftest.invalid');
$st = [];
$st['declaration'] = ge_declaration(SITE_F3, P_ST, $ago(4000), '4 Stan Street', 'SS4 4SS');
$st['giftKept']    = ge_gift(SITE_F3, P_ST, $ago(3900), 1200, $catF[SITE_F3]);   // kept; over ten years old
$st['giftUnkept']  = ge_gift(SITE_F3, P_ST, $ago(4500), 1300, $catF[SITE_F3]);   // before the declaration: not kept
$st['declTwo']     = ge_declaration(SITE_F4, P_ST, $ago(100), '4 Stan Street', 'SS4 4SS');
$st['giftOther']   = ge_gift(SITE_F4, P_ST, $ago(50), 1400, $catF[SITE_F4]);
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_request(REQ_ST, P_ST, 'stan.stopped@selftest.invalid', 'Stan Stopped', 'processing');
ge_q('UPDATE tblErasureRequest SET siteID = ? WHERE requestID = ?', 'ii', [SITE_F3, REQ_ST]);
$neverRun = GdprEraser::givingStepHasRun(REQ_ST);
$stopAt(SITE_F4);
$firstSt = ge_execute(REQ_ST, P_ST);
ge_dropTrigger('selftest_gdpr_stop_kept');
$keptSt  = $keptAt(SITE_F3, P_ST);
ge_ok('RULE 4, THE QUESTION THE CANCEL PAGE ASKS: before any run the giving step "has not run"; after a run that stopped with one organisation dealt with, it has (a gift there has already lost its donor, and nothing records which)',
    $neverRun === false && $firstSt instanceof Throwable && $statusF(REQ_ST) === 'pending_review' && GdprEraser::givingStepHasRun(REQ_ST) === true
    && $keptSt !== null && $keptSt['fullName'] === 'Stan Stopped' && $donorOf($st['giftUnkept']) === null && $accountF(P_ST)['fullName'] === 'Stan Stopped',
    json_encode([$neverRun, GdprEraser::givingStepHasRun(REQ_ST), $statusF(REQ_ST)]));
ge_q("UPDATE tblErasureRequest SET status = 'cancelled' WHERE requestID = ?", 'i', [REQ_ST]);
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
$st['giftNew'] = ge_gift(SITE_F3, P_ST, $ago(5), 7777, $catF[SITE_F3]);          // a NEW gift from a live member, under the same declaration
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
// He has also changed his name since. The kept record still holds the old one.
ge_q("UPDATE tblUsers SET fullName = 'Stan Carrieson' WHERE userID = ?", 'i', [P_ST]);
$claimSt = Giving::buildHmrcCsv(SITE_F3, $ago(30), $todayG1);
ge_ok('RULE 1, A LIVE MEMBER WITH A KEPT RECORD BESIDE HIM IS NOT AN ERASED DONOR (the checker\'s first probe): the giving code says he is not, and his new gift goes in the tax claim file under the name on his account NOW, not the older name on the kept record',
    Giving::isErasedDonor(SITE_F3, P_ST) === false && str_contains($claimSt, 'Stan,Carrieson') === true && str_contains($claimSt, 'Stan,Stopped') === false
    && str_contains($claimSt, '77.77') === true,
    json_encode([Giving::isErasedDonor(SITE_F3, P_ST), $claimSt]));
ge_setting(SITE_F3, GivingRetention::KEY_HOLD, 'false');
$stWas   = array_map('ge_row', $st);
$guardSt = GdprEraser::clearKeptGivingIdentity((int) $keptSt['keptDonorID']);
$sweepSt = run_giving_identity_sweep();
$stNow   = array_map('ge_row', $st);
ge_ok('RULE 1, NOTHING OF A LIVE MEMBER\'S IS CLEARED (the checker\'s second probe): confirmed, no hold, and a kept gift over ten years old, yet the clearing refuses because the account is not emptied; the job clears nothing; his gift of five days ago still carries him, his declaration keeps its address, and the kept record is as it was',
    $guardSt === 'not-erased' && $sweepSt['cleared'] === 0 && $sweepSt['failed'] === 0 && $stNow === $stWas && $donorOf($st['giftNew']) === P_ST
    && $stNow['declaration']['address'] === '4 Stan Street' && $keptAt(SITE_F3, P_ST) === $keptSt,
    json_encode([$guardSt, $sweepSt]));
// "EMPTIED" MEANS ALL THREE: switched off, the made-up name, the made-up
// address. An administrator can switch a member's account off without
// erasing anybody, so "switched off" alone must not count.
ge_q('UPDATE tblUsers SET isActive = 0 WHERE userID = ?', 'i', [P_ST]);
$offErased = Giving::isErasedDonor(SITE_F3, P_ST);
$offGuard  = GdprEraser::clearKeptGivingIdentity((int) $keptSt['keptDonorID']);
$offSweep  = run_giving_identity_sweep();
$offClaim  = Giving::buildHmrcCsv(SITE_F3, $ago(30), $todayG1);
ge_q('UPDATE tblUsers SET isActive = 1 WHERE userID = ?', 'i', [P_ST]);
// EACH OF THE THREE, ON ITS OWN (asked for by the narrow check of 12 October
// 2026: these were protected only by a test that read the source text). Three
// accounts that each miss exactly one of the three, and one that has all
// three. Only the last is an emptied account.
$madeUpName    = '[Deleted User]';
$madeUpAddress = 'deleted-' . P_ST . '@example.invalid';
$nearMisses    = [
    'switched off with the made-up name, but its own address'  => [0, $madeUpName, 'stan.stopped@selftest.invalid', false],
    'switched off with the made-up address, but its own name'  => [0, 'Stan Carrieson', $madeUpAddress, false],
    'the made-up name and address, but still switched ON'      => [1, $madeUpName, $madeUpAddress, false],
    'all three: switched off, made-up name, made-up address'   => [0, $madeUpName, $madeUpAddress, true],
];
$wrongAbout = [];
foreach ($nearMisses as $what => [$active, $name, $address, $isEmptied]) {
    ge_q('UPDATE tblUsers SET isActive = ?, fullName = ?, emailAddress = ? WHERE userID = ?', 'issi', [$active, $name, $address, P_ST]);
    $asked = [
        GdprEraser::accountIsEmptied($mysqli, P_ST),
        Giving::isErasedDonor(SITE_F3, P_ST),
        GivingRetention::stateOfKeptRow($mysqli, GivingRetention::ruleForSite(SITE_F3), SITE_F3, P_ST, (string) $keptSt['lastGiftDate'])['state'] !== 'not-erased',
    ];
    if ($asked !== [$isEmptied, $isEmptied, $isEmptied]) {
        $wrongAbout[] = $what . ' answered ' . json_encode($asked);
    }
}
ge_q("UPDATE tblUsers SET isActive = 1, fullName = 'Stan Carrieson', emailAddress = 'stan.stopped@selftest.invalid' WHERE userID = ?", 'i', [P_ST]);
ge_ok('RULE 1, EACH OF THE THREE PARTS OF "EMPTIED" COUNTS ON ITS OWN: an account that is switched off with the made-up name but its own address, one with the made-up address but its own name, and one with both made-up but still switched on are each NOT emptied; only all three together are. Asked three ways: the account question, the giving code, and the clearing guard',
    $wrongAbout === [] && array_map('ge_row', $st) === $stWas && $keptAt(SITE_F3, P_ST) === $keptSt, implode('; ', $wrongAbout));
ge_ok('RULE 1, AN ACCOUNT THAT IS ONLY SWITCHED OFF IS NOT AN EMPTIED ONE: with his account switched off and his name still on it, he is still not an erased donor, the clearing still refuses, the job clears nothing, his gifts and declaration are untouched, and the claim file still reads his name from his account',
    $offErased === false && $offGuard === 'not-erased' && $offSweep['cleared'] === 0 && array_map('ge_row', $st) === $stWas
    && GdprEraser::accountIsEmptied($mysqli, P_ST) === false && str_contains($offClaim, 'Stan,Carrieson') === true && $keptAt(SITE_F3, P_ST) === $keptSt,
    json_encode([$offErased, $offGuard, $offSweep]));
// He asks again later, and this request is run to its end.
ge_request(REQ_ST2, P_ST, 'stan.stopped@selftest.invalid', 'Stan Stopped', 'processing');
ge_q('UPDATE tblErasureRequest SET siteID = ? WHERE requestID = ?', 'ii', [SITE_F3, REQ_ST2]);
ge_setting(SITE_F3, GivingRetention::KEY_HOLD, 'true');
$laterSt  = ge_execute(REQ_ST2, P_ST);
ge_setting(SITE_F3, GivingRetention::KEY_HOLD, 'false');
$keptSt2  = $keptAt(SITE_F3, P_ST);
$keptStId = (int) $keptSt2['keptDonorID'];
// No financial year end at this organisation: one year after the gift, then
// six. Worked out here.
$rightSt  = $plusYears($ago(5), 7);
$stateSt  = GivingRetention::stateOfKeptRow($mysqli, GivingRetention::ruleForSite(SITE_F3), SITE_F3, P_ST, (string) $keptSt2['lastGiftDate']);
ge_ok('A LATER REQUEST OF HIS, RUN TO ITS END, brings the old kept record up to date (rule 3): it holds his name as it is now, counts from his gift of five days ago, with 2 kept gifts, is tied to the request that really erased him, and is not due until ' . $rightSt,
    is_array($laterSt) === true && $laterSt['failed'] === 0 && $statusF(REQ_ST2) === 'completed' && GdprEraser::accountIsEmptied($mysqli, P_ST) === true
    && $keptSt2['lastGiftDate'] === $ago(5) && (int) $keptSt2['keptGiftCount'] === 2 && (int) $keptSt2['erasureRequestID'] === REQ_ST2
    && $keptSt2['fullName'] === 'Stan Carrieson' && $stateSt['state'] === 'not-due' && $stateSt['keepUntil'] === $rightSt
    && run_giving_identity_sweep()['cleared'] === 0,
    $laterSt instanceof Throwable ? $laterSt->getMessage() : json_encode([$keptSt2, $stateSt]));
// RULE 2 ON ITS OWN. Put the date on the kept row back to the old gift, as a
// record made before this fix would have it. The gift of five days ago still
// carries the donor, so the date must still be counted from that gift.
ge_q('UPDATE tblGivingKeptDonors SET lastGiftDate = ? WHERE keptDonorID = ?', 'si', [$ago(3900), $keptStId]);
$staleState = GivingRetention::stateOfKeptRow($mysqli, GivingRetention::ruleForSite(SITE_F3), SITE_F3, P_ST, $ago(3900));
$staleGuard = GdprEraser::clearKeptGivingIdentity($keptStId);
$staleSweep = run_giving_identity_sweep();
ge_ok('RULE 2, THE DATE IS COUNTED FROM THE LATER OF THE STORED DATE AND THE NEWEST GIFT STILL LINKED: with the kept row saying "last gift over ten years ago" and a gift of five days ago still carrying the donor, the record is NOT due, the clearing says so, and the job clears nothing',
    $staleState['state'] === 'not-due' && $staleState['keepUntil'] === $rightSt && $staleState['countedFromGift'] === $ago(5)
    && GivingRetention::stateFor(GivingRetention::ruleForSite(SITE_F3), $ago(3900))['state'] === 'due'
    && $staleGuard === 'not-due' && $staleSweep['cleared'] === 0 && $donorOf($st['giftNew']) === P_ST && $keptAt(SITE_F3, P_ST)['fullName'] === 'Stan Carrieson',
    json_encode([$staleState, $staleGuard, $staleSweep]));
// ...and the other way round: the stored date is the later one.
ge_q('UPDATE tblGivingEntry SET donatedAt = ? WHERE entryID = ?', 'si', [$ago(3950), ge_id($st['giftNew'])]);
ge_q('UPDATE tblGivingKeptDonors SET lastGiftDate = ? WHERE keptDonorID = ?', 'si', [$ago(5), $keptStId]);
$storedLater = GdprEraser::clearKeptGivingIdentity($keptStId);
ge_q('UPDATE tblGivingKeptDonors SET lastGiftDate = ? WHERE keptDonorID = ?', 'si', [$ago(3900), $keptStId]);
$trailSt2    = count(ge_audit(REQ_ST2));
$trailSt1    = count(ge_audit(REQ_ST));
$dueSweep    = run_giving_identity_sweep();
$lastSt      = ge_audit(REQ_ST2)[count(ge_audit(REQ_ST2)) - 1];
ge_ok('...a LATER STORED date holds it back just the same; and once every gift and the stored date are old, the job clears it, with the line on the trail of the request that erased him (not the cancelled one), counted from the newest of them',
    $storedLater === 'not-due' && $dueSweep['cleared'] === 1 && $keptAt(SITE_F3, P_ST)['clearedAt'] !== null && $keptAt(SITE_F3, P_ST)['fullName'] === null
    && $donorOf($st['giftNew']) === null && $donorOf($st['giftKept']) === null
    && count(ge_audit(REQ_ST2)) === $trailSt2 + 1 && count(ge_audit(REQ_ST)) === $trailSt1 && $lastSt['action'] === 'anonymise'
    && str_contains((string) $lastSt['details'], 'last gift ' . GivingRetention::dateInWords($ago(3900)))
    && GdprEraser::verifyAuditChain(REQ_ST2) === true && GdprEraser::verifyAuditChain(REQ_ST) === true,
    json_encode([$storedLater, $dueSweep, $lastSt]));

// (c) A run that stops at its FIRST organisation has changed nothing, so the
//     question the cancel page asks is answered "no" and a cancel is safe.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_user(P_U, 'Una Untouched', 'una.untouched@selftest.invalid');
$u = ['declaration' => ge_declaration(SITE_F3, P_U, $ago(300), '5 Una Way', 'UN5 5UN'),
    'giftKept' => ge_gift(SITE_F3, P_U, $ago(20), 1500, $catF[SITE_F3]), 'giftUnkept' => ge_gift(SITE_F3, P_U, $ago(350), 1600, $catF[SITE_F3])];
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_request(REQ_U, P_U, 'una.untouched@selftest.invalid', 'Una Untouched', 'processing');
$uWas = array_map('ge_row', $u);
$stopAt(SITE_F3);
$firstU = ge_execute(REQ_U, P_U);
ge_dropTrigger('selftest_gdpr_stop_kept');
ge_ok('A RUN THAT STOPS AT ITS FIRST ORGANISATION HAS CHANGED NOTHING: no kept record, both gifts still carry her (the one that would not be kept included), and the giving step "has not run", so this request may still be cancelled',
    $firstU instanceof Throwable && $statusF(REQ_U) === 'pending_review' && $keptAt(SITE_F3, P_U) === null && array_map('ge_row', $u) === $uWas
    && GdprEraser::givingStepHasRun(REQ_U) === false && GdprEraser::runHasChangedAnything(REQ_U) === false
    && $accountF(P_U)['fullName'] === 'Una Untouched',
    json_encode([$statusF(REQ_U), $keptAt(SITE_F3, P_U), GdprEraser::givingStepHasRun(REQ_U)]));

// (d) A DATABASE THAT HAS NOT HAD MIGRATION 209, and a person with no giving
//     records at all. The step used to meet the missing table only for a
//     donor, so anybody else's erasure carried on.
ge_user(P_V, 'Vic Nogifts', 'vic.nogifts@selftest.invalid');
ge_request(REQ_V, P_V, 'vic.nogifts@selftest.invalid', 'Vic Nogifts', 'processing');
$mysqli->query('RENAME TABLE `tblGivingKeptDonors` TO `selftest_hidden_kept_donors`');
$firstV = ge_execute(REQ_V, P_V);
$mysqli->query('RENAME TABLE `selftest_hidden_kept_donors` TO `tblGivingKeptDonors`');
$lineV   = ge_audit(REQ_V);
$statusV = $statusF(REQ_V);
$acctV   = $accountF(P_V);
ge_q("UPDATE tblErasureRequest SET status = 'processing' WHERE requestID = ?", 'i', [REQ_V]);
$secondV = ge_execute(REQ_V, P_V);
ge_ok('WITHOUT THE KEPT-NAMES TABLE, EVERY ERASURE STOPS AT THE GIVING STEP, a person with no giving records included: one "failed" line that says to run the database upgrade, the account untouched, the request back at "pending review"; and once the table is there the same request completes',
    $firstV instanceof Throwable && str_contains($firstV->getMessage(), 'run the database upgrade, then run this request again')
    && count($lineV) === 1 && $lineV[0]['action'] === 'failed' && $lineV[0]['tableName'] === 'GivingRecords'
    && str_contains((string) $lineV[0]['details'], 'The database has not been upgraded for this version: run the database upgrade')
    && $statusV === 'pending_review' && $acctV === ['fullName' => 'Vic Nogifts', 'emailAddress' => 'vic.nogifts@selftest.invalid', 'isActive' => 1]
    && is_array($secondV) === true && $secondV['failed'] === 0 && $statusF(REQ_V) === 'completed' && GdprEraser::accountIsEmptied($mysqli, P_V) === true,
    $firstV instanceof Throwable ? json_encode([$firstV->getMessage(), $lineV, $statusV, $secondV instanceof Throwable ? $secondV->getMessage() : $secondV]) : 'the run did not stop');

// (e) ENVELOPES IN A COUNT. An envelope in a count that is not yet closed
//     becomes a gift when the count is closed. If it had lost its giver, that
//     gift would have no donor and would drop out of the tax claim.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_user(P_W, 'Wim Envelope', 'wim.envelope@selftest.invalid');
$w = ['declaration' => ge_declaration(SITE_F3, P_W, $ago(300), '6 Wim Walk', 'WW6 6WW'), 'gift' => ge_gift(SITE_F3, P_W, $ago(20), 1700, $catF[SITE_F3])];
// A statement the organisation issued him, with its file: section (f) needs
// a kept FILE, because files are deleted before the database is changed.
$issuedW        = ge_statement(SITE_F3, P_W, $ago(40), $ago(10), 'wim.envelope@selftest.invalid');
$w['statement'] = $issuedW['ref'];
$count = static fn (int $siteId, string $date, string $state): int => ge_id(ge_insert('tblCountSessions', [], [
    'siteID' => $siteId, 'serviceDate' => $date, 'categoryID' => $catF[$siteId], 'status' => $state,
]));
$envelope = static fn (int $session, string $amount): array => ge_insert('tblCountEnvelopes', ['giverID' => P_W], [
    'countSessionID' => $session, 'giverName' => null, 'amount' => $amount, 'method' => 'cash',
]);
$env = [
    'openKept'    => $envelope($count(SITE_F3, $ago(2), 'counting'), '20.00'),     // open, and covered by his declaration: KEPT
    'closed'      => $envelope($count(SITE_F3, $ago(30), 'closed'), '21.00'),      // closed: its gift already exists
    'openBefore'  => $envelope($count(SITE_F3, $ago(400), 'open'), '22.00'),       // open, but from before his declaration
    'openNoRule'  => $envelope($count(SITE_F4, $ago(3), 'discrepancy'), '23.00'),  // open, at an organisation where nothing of his is kept
];
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_request(REQ_W, P_W, 'wim.envelope@selftest.invalid', 'Wim Envelope', 'processing');
ge_q('UPDATE tblErasureRequest SET siteID = ? WHERE requestID = ?', 'ii', [SITE_F3, REQ_W]);
ge_setting(SITE_F3, GivingRetention::KEY_HOLD, 'true');
$envWas  = array_map('ge_row', $env);
$resultW = ge_execute(REQ_W, P_W);
$envNow  = array_map('ge_row', $env);
$keptW   = $keptAt(SITE_F3, P_W);
$linesW  = ge_audit(REQ_W);
$siteW   = array_values(array_filter($linesW, static fn (array $l): bool => $l['tableName'] === 'GivingRecords'));
$envW    = array_values(array_filter($linesW, static fn (array $l): bool => $l['tableName'] === 'CountEnvelopes'));
ge_ok('AN ENVELOPE IN A COUNT THAT IS NOT YET CLOSED KEEPS ITS GIVER when the gift it becomes will be a kept gift; a closed count\'s envelope, one from before the declaration, and one at an organisation that keeps nothing of his all lose theirs; every envelope keeps its amount',
    is_array($resultW) === true && $resultW['failed'] === 0 && (int) $envNow['openKept']['giverID'] === P_W && ge_same($envWas['openKept'], $envNow['openKept']) === true
    && $envNow['closed']['giverID'] === null && $envNow['openBefore']['giverID'] === null && $envNow['openNoRule']['giverID'] === null
    && ge_same($envWas['closed'], $envNow['closed'], ['giverID', 'giverName']) === true && $envNow['openNoRule']['amount'] === '23.00',
    $resultW instanceof Throwable ? $resultW->getMessage() : json_encode(array_map(static fn (?array $e): mixed => $e['giverID'] ?? null, $envNow)));
ge_ok('...the kept record counts from the envelope\'s service date when that is later than his last gift, and the trail says so: the organisation\'s line names the envelope, the other organisation\'s line says nothing is kept there, and one envelope line gives "3 rows" and the one kept',
    $keptW !== null && $keptW['lastGiftDate'] === $ago(2) && (int) $keptW['keptGiftCount'] === 1 && $keptAt(SITE_F4, P_W) === null
    && count($siteW) === 2 && $siteW[0]['recordKey'] === 'organisation #' . SITE_F3 && $siteW[0]['action'] === 'retain'
    && str_contains((string) $siteW[0]['details'], '1 gift, 1 tax declaration, 1 giving statement and 1 envelope in a count that is not yet closed keep this donor\'s name and address until')
    && $siteW[1]['recordKey'] === 'organisation #' . SITE_F4 && $siteW[1]['action'] === 'anonymise'
    && count($envW) === 1 && $envW[0]['action'] === 'anonymise' && $envW[0]['recordKey'] === '3 rows'
    && str_contains((string) $envW[0]['details'], '1 envelope(s) in a count that is not yet closed keep the giver')
    && preg_match('/Wim|Wim Walk/', (string) $siteW[0]['details'] . (string) $envW[0]['details']) !== 1,
    json_encode([$keptW['lastGiftDate'] ?? null, $siteW, $envW]));
// The count is closed: the portal makes a gift from the envelope, for the
// giver on it, dated the service date.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
$w['fromEnvelope'] = ge_gift(SITE_F3, P_W, $ago(2), 2000, $catF[SITE_F3]);
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
$claimW = Giving::buildHmrcCsv(SITE_F3, $ago(10), $todayG1);
ge_ok('...so when the count is closed, the gift made from that envelope is in the tax claim file under the KEPT name and address, which is the whole point of keeping the giver',
    str_contains($claimW, 'Wim,Envelope,"6 Wim Walk"') === true || str_contains($claimW, 'Wim,Envelope,6 Wim Walk') === true,
    $claimW);

// (f) NOTHING IS CLEARED WITH NOWHERE TO WRITE THAT IT WAS DONE, and when it
//     is cleared the envelope's giver goes with the rest. Everything of his is
//     made old, so that the record is due.
ge_q('UPDATE tblGiftAidDeclaration SET validFrom = ? WHERE declarationID = ?', 'si', ['2001-01-01', ge_id($w['declaration'])]);
ge_q('UPDATE tblGivingEntry SET donatedAt = ? WHERE siteID = ? AND donorID = ?', 'sii', [$ago(3000), SITE_F3, P_W]);
ge_q('UPDATE tblCountSessions SET serviceDate = ? WHERE countSessionID = ?', 'si', [$ago(3000), (int) $envNow['openKept']['countSessionID']]);
ge_q('UPDATE tblGivingKeptDonors SET lastGiftDate = ? WHERE keptDonorID = ?', 'si', [$ago(3000), (int) $keptW['keptDonorID']]);
ge_setting(SITE_F3, GivingRetention::KEY_HOLD, 'false');
// The erasure request has gone (the database empties the link when a request
// row is deleted). Set here by hand, to leave the request's trail in place
// for the second half.
ge_q('UPDATE tblGivingKeptDonors SET erasureRequestID = NULL WHERE keptDonorID = ?', 'i', [(int) $keptW['keptDonorID']]);
$trailW   = count(ge_audit(REQ_W));
$allAudit = (int) ge_one('SELECT COUNT(*) AS n FROM tblErasureAudit')['n'];
$wWas     = array_map('ge_row', $w);
$noTrail  = GdprEraser::clearKeptGivingIdentity((int) $keptW['keptDonorID']);
$sweepNo  = run_giving_identity_sweep();
ge_ok('A RECORD THAT IS DUE BUT HAS NO REQUEST LEFT TO WRITE THE LINE ON IS NOT CLEARED: the clearing answers "failed", the job counts it as failed and clears nothing, no audit line is written anywhere, and the name, the gifts, the declaration, the envelope\'s giver AND THE KEPT STATEMENT FILE are all as they were',
    GivingRetention::stateOfKeptRow($mysqli, GivingRetention::ruleForSite(SITE_F3), SITE_F3, P_W, $ago(3000))['state'] === 'due'
    && $noTrail === 'failed' && $sweepNo['cleared'] === 0 && $sweepNo['failed'] === 1
    && (int) ge_one('SELECT COUNT(*) AS n FROM tblErasureAudit')['n'] === $allAudit && array_map('ge_row', $w) === $wWas
    && $keptAt(SITE_F3, P_W)['fullName'] === 'Wim Envelope' && $keptAt(SITE_F3, P_W)['clearedAt'] === null
    && (int) ge_row($env['openKept'])['giverID'] === P_W
    // The file is the part only this guard protects: files are deleted
    // before the database is changed, so without the guard the file would be
    // gone by the time the audit line was refused.
    && is_file($issuedW['file']) === true && file_get_contents($issuedW['file']) === 'made-up statement for ' . P_W,
    json_encode([$noTrail, $sweepNo, $keptAt(SITE_F3, P_W), is_file($issuedW['file'])]));
ge_q('UPDATE tblGivingKeptDonors SET erasureRequestID = ? WHERE keptDonorID = ?', 'ii', [REQ_W, (int) $keptW['keptDonorID']]);
$sweepW = run_giving_identity_sweep();
ge_ok('...with the request back, the job clears it, writes the one line, and THE ENVELOPE\'S GIVER GOES WITH THE NAME, the address and the gifts; the envelope keeps its amount',
    $sweepW['cleared'] === 1 && $sweepW['failed'] === 0 && count(ge_audit(REQ_W)) === $trailW + 1 && $keptAt(SITE_F3, P_W)['fullName'] === null
    && $keptAt(SITE_F3, P_W)['clearedAt'] !== null && ge_row($env['openKept'])['giverID'] === null && ge_row($env['openKept'])['amount'] === '20.00'
    && $donorOf($w['gift']) === null && $donorOf($w['fromEnvelope']) === null && ge_row($w['declaration'])['address'] === null
    && is_file($issuedW['file']) === false && GdprEraser::verifyAuditChain(REQ_W) === true,
    json_encode([$sweepW, ge_row($env['openKept'])]));

// (g) THE TWO HOLDS ARE TWO SWITCHES. An installation-wide hold holds every
//     organisation. The page used to show it as the organisation's own tick,
//     so pressing Save switched the organisation's own hold on, and it stayed
//     on after the installation-wide one was lifted.
ge_q("UPDATE tblSettings SET settingValue = 'true' WHERE settingKey = ? AND siteID IS NULL", 's', [GivingRetention::KEY_HOLD]);
$heldRule = GivingRetention::ruleForSite(SITE_F3);
// What the page now posts for an administrator who touched nothing: the
// organisation's own box as the page shows it, which is its OWN hold.
$asShown  = $form(['years' => '6', 'confirmed' => '1', 'hold' => $heldRule['holdOwn'] === true ? '1' : '']);
$savedH   = GivingRetention::saveForSite($mysqli, SITE_F3, $asShown);
$ownAfter = $givingSettings(SITE_F3)[GivingRetention::KEY_HOLD] ?? '(no row)';
ge_q("UPDATE tblSettings SET settingValue = 'false' WHERE settingKey = ? AND siteID IS NULL", 's', [GivingRetention::KEY_HOLD]);
$liftedRule = GivingRetention::ruleForSite(SITE_F3);
ge_ok('AN INSTALLATION-WIDE HOLD IS SHOWN APART FROM THE ORGANISATION\'S OWN, AND A SAVE DOES NOT TURN ONE INTO THE OTHER: held (true) with the organisation\'s own hold off and the installation-wide one on; saving what the page shows leaves the organisation\'s own hold off, records no change to it, and once the installation-wide hold is lifted the organisation is not held',
    $heldRule['hold'] === true && $heldRule['holdOwn'] === false && $heldRule['holdEverybody'] === true
    && $savedH['saved'] === true && $ownAfter === 'false' && preg_match('/hold/i', json_encode($savedH['changes'])) !== 1
    && $liftedRule['hold'] === false && $liftedRule['holdOwn'] === false && $liftedRule['holdEverybody'] === false,
    json_encode([$heldRule['hold'], $heldRule['holdOwn'], $heldRule['holdEverybody'], $savedH, $ownAfter, $liftedRule['hold']]));

// (h) THE GENERAL SETTINGS EDITOR MAY NOT CHANGE THESE ROWS. It has no
//     un-tick and no second tick. (The pages that ask this are checked in
//     tools/erasure-pages-selftest.php; this is the question itself.)
$lockedKeys = [GivingRetention::KEY_COUNTRY, GivingRetention::KEY_SCOPE, GivingRetention::KEY_YEARS, GivingRetention::KEY_CONFIRMED,
    GivingRetention::KEY_CONFIRMED_ON, GivingRetention::KEY_YEAR_END, GivingRetention::KEY_HOLD];
$open = [];
foreach ($lockedKeys as $key) {
    foreach ([[false, false], [false, true], [true, false], [true, true]] as [$portalWide, $globalAdmin]) {
        $mayEdit = $key === GivingRetention::KEY_HOLD && $portalWide === true && $globalAdmin === true;
        if (GivingRetention::lockedInGeneralEditor($key, $portalWide, $globalAdmin) === $mayEdit) {
            $open[] = $key . ' ' . json_encode([$portalWide, $globalAdmin]);
        }
    }
}
ge_ok('ALL SEVEN GIVING-RECORDS SETTINGS ARE LOCKED IN THE GENERAL SETTINGS EDITOR, an organisation\'s own row and the installation-wide one alike, whatever the capitals; the one row left open is the installation-wide hold, for a global administrator; and no other setting is locked',
    $open === [] && count($lockedKeys) === 7 && GivingRetention::lockedInGeneralEditor('GIVING.Retention.Confirmed', false, true) === true
    && GivingRetention::lockedInGeneralEditor('giving.retention.somethingNew', true, true) === true
    && GivingRetention::lockedInGeneralEditor('giving.statement.footer', false, false) === false
    && GivingRetention::lockedInGeneralEditor('org.address.countryCode', true, true) === false
    && str_contains(GivingRetention::LOCKED_MESSAGE, 'Giving records') === true,
    implode('; ', $open));

// =============================================================================
ge_section('15', 'A run that stops AFTER the account is emptied, the clearing under a lock, and a cancel after any change (second fix round, 12 October 2026)');
// =============================================================================
// THE FACT THIS SECTION RESTS ON: the table walk empties the account about
// half way through a run, not at its end. So a run can stop with the account
// emptied and the request unfinished. The narrow check of 12 October 2026
// found that the first fix round had not allowed for that.
$walk      = GdprEraser::catalogue();
$accountAt = null;
foreach ($walk as $at => $instruction) {
    if ((string) $instruction['table'] === 'tblUsers' && (string) $instruction['action'] === 'anonymise') {
        $accountAt = $at;
    }
}
// The first table the walk reaches AFTER the account, and nowhere before it:
// a run made to stop at that table's audit line has emptied the account.
$stopTable = '';
if ($accountAt !== null) {
    $before = array_map(static fn (array $i): string => (string) $i['table'], array_slice($walk, 0, $accountAt + 1));
    foreach (array_slice($walk, $accountAt + 1) as $instruction) {
        if (in_array((string) $instruction['table'], $before, true) === false) {
            $stopTable = (string) $instruction['table'];
            break;
        }
    }
}
ge_ok('THE ACCOUNT IS NOT EMPTIED LAST: the walk reaches it at instruction ' . (($accountAt ?? -1) + 1) . ' of ' . count($walk) . ', with instructions and four more steps still to come (so a run can stop with the account emptied and the request unfinished)',
    $accountAt !== null && $accountAt < count($walk) - 10 && $stopTable !== '', json_encode([$accountAt, count($walk), $stopTable]));
// Stops ONE request's run at the audit line of one table. A step whose line
// cannot be written is undone and the run stops (section 7).
$stopAtLine = static function (int $requestId, string $table): void {
    ge_trigger('selftest_gdpr_stop_line', 'CREATE TRIGGER `selftest_gdpr_stop_line` BEFORE INSERT ON `tblErasureAudit` FOR EACH ROW BEGIN '
        . 'IF NEW.requestID = ' . $requestId . " AND NEW.tableName = '" . $table . "' THEN SIGNAL SQLSTATE '45000' "
        . "SET MESSAGE_TEXT = 'planted by the self-test'; END IF; END");
};

// (a) THE CHECKER'S SCENARIO E3. An organisation keeps EVERY gift, six years,
//     confirmed, with a HOLD on (an audit is open). Will has no tax
//     declaration. His run empties his account and then stops. Wendy, the
//     control, is erased to the end under the same rule. Nell gives at
//     organisation 8, which keeps only tax gifts; her run stops the same way.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_insert('tblSites', [], ['siteID' => SITE_F5, 'timezone' => 'Europe/London']);
$catF[SITE_F5] = ge_id(ge_insert('tblGivingCategory', [], ['siteID' => SITE_F5]));
ge_user(P_WW, 'Will Wide', 'will.wide@selftest.invalid');
ge_user(P_WD, 'Wendy Done', 'wendy.done@selftest.invalid');
ge_user(P_NN, 'Nell Nothing', 'nell.nothing@selftest.invalid');
$ww = ['giftOne' => ge_gift(SITE_F5, P_WW, $ago(30), 1100, $catF[SITE_F5]), 'giftTwo' => ge_gift(SITE_F5, P_WW, $ago(20), 1200, $catF[SITE_F5])];
$wd = ['gift' => ge_gift(SITE_F5, P_WD, $ago(30), 1300, $catF[SITE_F5])];
$nn = ['declaration' => ge_declaration(SITE_F3, P_NN, $ago(300), '7 Nell Nook', 'NN7 7NN'), 'gift' => ge_gift(SITE_F3, P_NN, $ago(40), 1400, $catF[SITE_F3])];
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
// A statement Wendy made for herself. At an "every gift" organisation it is
// kept at erasure; it is the kept FILE that part (b) watches.
$fileWd = ge_file('_uploads/giving/statements/' . SITE_F5 . '/statement-' . P_WD . '-' . $ago(60) . '_' . $ago(10) . '.pdf', 'made-up statement for the race');
foreach ([GivingRetention::KEY_COUNTRY => '', GivingRetention::KEY_SCOPE => GivingRetention::SCOPE_ALL, GivingRetention::KEY_YEARS => '6',
    GivingRetention::KEY_YEAR_END => '', GivingRetention::KEY_CONFIRMED => 'true', GivingRetention::KEY_CONFIRMED_ON => $todayG1,
    GivingRetention::KEY_HOLD => 'true'] as $key => $value) {
    ge_setting(SITE_F5, $key, $value);
}
foreach ([[REQ_WW, P_WW, 'will.wide', 'Will Wide'], [REQ_WD, P_WD, 'wendy.done', 'Wendy Done'], [REQ_NN, P_NN, 'nell.nothing', 'Nell Nothing']] as [$requestId, $person, $mail, $name]) {
    ge_request($requestId, $person, $mail . '@selftest.invalid', $name, 'processing');
    ge_q('UPDATE tblErasureRequest SET siteID = ? WHERE requestID = ?', 'ii', [$person === P_NN ? SITE_F3 : SITE_F5, $requestId]);
}
$doneWd = ge_execute(REQ_WD, P_WD);
$stopAtLine(REQ_WW, $stopTable);
$firstWw = ge_execute(REQ_WW, P_WW);
$stopAtLine(REQ_NN, $stopTable);
$firstNn = ge_execute(REQ_NN, P_NN);
ge_dropTrigger('selftest_gdpr_stop_line');
$keptWw1 = $keptAt(SITE_F5, P_WW);
$keptWd1 = $keptAt(SITE_F5, P_WD);
ge_ok('SET-UP, a run that stops just after the account is emptied: his account is emptied, his request is back at "pending review", his name is kept under the "every gift" rule with both gifts still his; the control\'s erasure is complete and her name is kept the same way',
    $firstWw instanceof Throwable && GdprEraser::accountIsEmptied($mysqli, P_WW) === true && $statusF(REQ_WW) === 'pending_review'
    && $keptWw1 !== null && $keptWw1['fullName'] === 'Will Wide' && $keptWw1['scope'] === 'all' && $keptWw1['clearedAt'] === null
    && $keptWw1['lastGiftDate'] === $ago(20) && (int) $keptWw1['keptGiftCount'] === 2 && $donorOf($ww['giftOne']) === P_WW && $donorOf($ww['giftTwo']) === P_WW
    && is_array($doneWd) === true && $doneWd['failed'] === 0 && $statusF(REQ_WD) === 'completed' && $keptWd1['fullName'] === 'Wendy Done'
    && $firstNn instanceof Throwable && GdprEraser::accountIsEmptied($mysqli, P_NN) === true && $keptAt(SITE_F3, P_NN)['fullName'] === 'Nell Nothing'
    && $keptAt(SITE_F3, P_NN)['scope'] === 'receipted' && $donorOf($nn['gift']) === P_NN,
    json_encode([$firstWw instanceof Throwable ? $firstWw->getMessage() : $firstWw, $keptWw1, $statusF(REQ_WW)]));
// An administrator narrows "which gifts" on the Giving records page. The
// hold stays on.
$narrowed = GivingRetention::saveForSite($mysqli, SITE_F5, $form(['scope' => 'receipted', 'years' => '6', 'confirmed' => '1', 'hold' => '1']));
$ruleF5   = GivingRetention::ruleForSite(SITE_F5);
// Two more things happen before the request is run again. The date on Will's
// newest gift is corrected to five days EARLIER. And Nell's tax declaration,
// the only thing that made her gift a kept one, is deleted (entered by
// mistake, say). By the rule stored on her record nothing is kept any more.
ge_q('UPDATE tblGivingEntry SET donatedAt = ? WHERE entryID = ?', 'si', [$ago(25), ge_id($ww['giftTwo'])]);
ge_q('DELETE FROM tblGiftAidDeclaration WHERE declarationID = ?', 'i', [ge_id($nn['declaration'])]);
$sweepHeld = run_giving_identity_sweep();
ge_q("UPDATE tblErasureRequest SET status = 'processing' WHERE requestID IN (?, ?)", 'ii', [REQ_WW, REQ_NN]);
$secondWw = ge_execute(REQ_WW, P_WW);
$secondNn = ge_execute(REQ_NN, P_NN);
$keptWw2  = $keptAt(SITE_F5, P_WW);
$lineWw   = array_values(array_filter(ge_audit(REQ_WW), static fn (array $l): bool => $l['tableName'] === 'GivingRecords'));
ge_ok('THE CHECKER\'S E3, RUN AGAIN AFTER "WHICH GIFTS" WAS NARROWED: the rule saved and is now "tax gifts only" with the hold still on; the request completes; and his kept name, BOTH his gifts and the rule stored on his record ("every gift") are exactly as the earlier run left them, like the control donor whose erasure had finished',
    $narrowed['saved'] === true && $ruleF5['scope'] === 'receipted' && $ruleF5['hold'] === true && $sweepHeld['cleared'] === 0
    && is_array($secondWw) === true && $secondWw['failed'] === 0 && $statusF(REQ_WW) === 'completed'
    && $keptWw2['fullName'] === 'Will Wide' && $keptWw2['clearedAt'] === null && $keptWw2['scope'] === 'all' && (int) $keptWw2['keptGiftCount'] === 2
    && $donorOf($ww['giftOne']) === P_WW && $donorOf($ww['giftTwo']) === P_WW
    && $keptAt(SITE_F5, P_WD) === $keptWd1 && $donorOf($wd['gift']) === P_WD,
    json_encode([$secondWw instanceof Throwable ? $secondWw->getMessage() : $secondWw, $keptWw2, $donorOf($ww['giftOne']), $donorOf($ww['giftTwo'])]));
ge_ok('...and the trail says so: the later run\'s line says the rule used is the one in force when the name was kept, that what was kept was not overwritten, and that a hold is on; it names nobody',
    count($lineWw) === 2 && $lineWw[1]['action'] === 'retain'
    && str_contains((string) $lineWw[1]['details'], 'this organisation keeps the donor\'s name on every gift')
    && str_contains((string) $lineWw[1]['details'], 'That is the rule that was in force when the name was kept')
    && str_contains((string) $lineWw[1]['details'], 'what was kept was not overwritten')
    && str_contains((string) $lineWw[1]['details'], 'A hold is switched on') && preg_match('/Will|Wide/', (string) $lineWw[1]['details']) !== 1
    && GdprEraser::verifyAuditChain(REQ_WW) === true,
    json_encode($lineWw[1] ?? null));
ge_ok('THE DATE ON A KEPT RECORD IS NEVER MOVED EARLIER by a later run: his newest gift was re-dated five days earlier between the runs, and the record still counts from the date the earlier run recorded',
    $keptWw2['lastGiftDate'] === $ago(20) && ge_row($ww['giftTwo'])['donatedAt'] === $ago(25)
    && str_contains((string) ($lineWw[1]['details'] ?? ''), 'the date recorded by the earlier run, ' . GivingRetention::dateInWords($ago(20))),
    json_encode([$keptWw2['lastGiftDate'], $lineWw[1]['details'] ?? null]));
$keptNn  = $keptAt(SITE_F3, P_NN);
$lineNn  = array_values(array_filter(ge_audit(REQ_NN), static fn (array $l): bool => $l['tableName'] === 'GivingRecords'));
ge_ok('A LATER RUN NEVER EMPTIES A KEPT NAME ITSELF, even when nothing has to be kept any more: her declaration is gone, so by the rule stored on her record her gift is not a kept one and loses its donor; the request completes; and her name and address are still kept, not stamped as cleared, with the date the earlier run recorded; the line says they are left for the retention job',
    is_array($secondNn) === true && $secondNn['failed'] === 0 && $statusF(REQ_NN) === 'completed'
    && $keptNn['fullName'] === 'Nell Nothing' && $keptNn['address'] === '7 Nell Nook' && $keptNn['clearedAt'] === null && $keptNn['scope'] === 'receipted'
    && $keptNn['lastGiftDate'] === $ago(40) && (int) $keptNn['keptGiftCount'] === 0
    && $donorOf($nn['gift']) === null && count($lineNn) === 2 && $lineNn[1]['action'] === 'retain'
    && str_contains((string) $lineNn[1]['details'], 'Nothing there now keeps this donor\'s name')
    && str_contains((string) $lineNn[1]['details'], 'only the retention job removes them')
    && preg_match('/Nell|Nook/', (string) $lineNn[1]['details']) !== 1
    && run_giving_identity_sweep()['cleared'] === 0 && $keptAt(SITE_F3, P_NN)['fullName'] === 'Nell Nothing',
    json_encode([$secondNn instanceof Throwable ? $secondNn->getMessage() : $secondNn, $keptNn, $lineNn[1] ?? null]));

// (a2) THE CHECKER'S C3 ATTACK, the mirror of E3. The organisation keeps TAX
//      GIFTS ONLY when Pat's run stops just after his account is emptied.
//      It then WIDENS its rule to "every gift". After that a gift from after
//      his declaration ended is matched to his number, and so is an envelope
//      in a count that is not yet closed. Quin, the control, was erased to
//      the end under the narrower rule; nothing runs for her again.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_insert('tblSites', [], ['siteID' => SITE_F6, 'timezone' => 'Europe/London']);
$catF[SITE_F6] = ge_id(ge_insert('tblGivingCategory', [], ['siteID' => SITE_F6]));
$c3 = [];
foreach ([P_PS => ['Pat Stored', 'pat.stored'], P_QC => ['Quin Control', 'quin.control']] as $person => [$name, $mail]) {
    ge_user($person, $name, $mail . '@selftest.invalid');
    $c3[$person] = [
        'declaration' => ge_declaration(SITE_F6, $person, $ago(400), '8 Declared Road', 'ST8 8ST'),
        'covered'     => ge_gift(SITE_F6, $person, $ago(30), 2100, $catF[SITE_F6]),   // inside the declaration: kept
        'before'      => ge_gift(SITE_F6, $person, $ago(500), 2200, $catF[SITE_F6]),  // before it: not kept under "tax gifts only"
    ];
    // The declaration ENDED fifteen days ago.
    ge_q('UPDATE tblGiftAidDeclaration SET validTo = ? WHERE declarationID = ?', 'si', [$ago(15), ge_id($c3[$person]['declaration'])]);
}
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
foreach ([GivingRetention::KEY_COUNTRY => '', GivingRetention::KEY_SCOPE => GivingRetention::SCOPE_RECEIPTED, GivingRetention::KEY_YEARS => '6',
    GivingRetention::KEY_YEAR_END => '', GivingRetention::KEY_CONFIRMED => 'true', GivingRetention::KEY_CONFIRMED_ON => $todayG1,
    GivingRetention::KEY_HOLD => 'true'] as $key => $value) {
    ge_setting(SITE_F6, $key, $value);
}
foreach ([[REQ_PS, P_PS, 'pat.stored', 'Pat Stored'], [REQ_QC, P_QC, 'quin.control', 'Quin Control']] as [$requestId, $person, $mail, $name]) {
    ge_request($requestId, $person, $mail . '@selftest.invalid', $name, 'processing');
    ge_q('UPDATE tblErasureRequest SET siteID = ? WHERE requestID = ?', 'ii', [SITE_F6, $requestId]);
}
$doneQc = ge_execute(REQ_QC, P_QC);
$stopAtLine(REQ_PS, $stopTable);
$firstPs = ge_execute(REQ_PS, P_PS);
ge_dropTrigger('selftest_gdpr_stop_line');
$keptPs1 = $keptAt(SITE_F6, P_PS);
ge_ok('SET-UP FOR THE MIRROR: his account is emptied and his request unfinished; his name is kept under the STORED rule "tax gifts only" with one kept gift; the gift from before his declaration has lost him; the control is completed the same way',
    $firstPs instanceof Throwable && GdprEraser::accountIsEmptied($mysqli, P_PS) === true && $statusF(REQ_PS) === 'pending_review'
    && $keptPs1 !== null && $keptPs1['fullName'] === 'Pat Stored' && $keptPs1['scope'] === 'receipted' && (int) $keptPs1['keptGiftCount'] === 1
    && $donorOf($c3[P_PS]['covered']) === P_PS && $donorOf($c3[P_PS]['before']) === null
    && is_array($doneQc) === true && $doneQc['failed'] === 0 && $keptAt(SITE_F6, P_QC)['fullName'] === 'Quin Control' && $donorOf($c3[P_QC]['before']) === null,
    json_encode([$firstPs instanceof Throwable ? $firstPs->getMessage() : $firstPs, $keptPs1]));
$widened = GivingRetention::saveForSite($mysqli, SITE_F6, $form(['scope' => 'all', 'years' => '6', 'confirmed' => '1', 'hold' => '1']));
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
foreach ([P_PS, P_QC] as $person) {
    $c3[$person]['lateUncovered'] = ge_gift(SITE_F6, $person, $ago(5), 5500, $catF[SITE_F6]);   // after the declaration ended
    $c3[$person]['lateCovered']   = ge_gift(SITE_F6, $person, $ago(20), 2300, $catF[SITE_F6]);  // inside it
}
$openCountPs = ge_id(ge_insert('tblCountSessions', [], ['siteID' => SITE_F6, 'serviceDate' => $ago(4), 'categoryID' => $catF[SITE_F6], 'status' => 'counting']));
$envelopePs  = ge_insert('tblCountEnvelopes', ['giverID' => P_PS], ['countSessionID' => $openCountPs, 'giverName' => null, 'amount' => '31.00', 'method' => 'cash']);
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_q("UPDATE tblErasureRequest SET status = 'processing' WHERE requestID = ?", 'i', [REQ_PS]);
$secondPs = ge_execute(REQ_PS, P_PS);
$keptPs2  = $keptAt(SITE_F6, P_PS);
$linePs   = array_values(array_filter(ge_audit(REQ_PS), static fn (array $l): bool => $l['tableName'] === 'GivingRecords'));
ge_ok('THE CHECKER\'S C3, RUN AGAIN AFTER "WHICH GIFTS" WAS WIDENED: beside an emptied account the WIDER of the stored rule and today\'s sorts the gifts, so the gift from after his declaration ended KEEPS HIM, exactly like the control donor\'s same gift; the two gifts the stored rule keeps still carry him; his name and address are untouched; and the rule recorded with his name is still "tax gifts only"',
    $widened['saved'] === true && GivingRetention::ruleForSite(SITE_F6)['scope'] === 'all'
    && is_array($secondPs) === true && $secondPs['failed'] === 0 && $statusF(REQ_PS) === 'completed'
    && $donorOf($c3[P_PS]['lateUncovered']) === P_PS && $donorOf($c3[P_QC]['lateUncovered']) === P_QC
    && $donorOf($c3[P_PS]['covered']) === P_PS && $donorOf($c3[P_PS]['lateCovered']) === P_PS && $donorOf($c3[P_PS]['before']) === null
    && $keptPs2['fullName'] === 'Pat Stored' && $keptPs2['address'] === '8 Declared Road' && $keptPs2['clearedAt'] === null
    && $keptPs2['scope'] === 'receipted' && (int) $keptPs2['keptGiftCount'] === 3
    && count($linePs) === 2 && str_contains((string) $linePs[1]['details'], 'this organisation keeps the donor\'s name on every gift')
    && str_contains((string) $linePs[1]['details'], 'The name was first kept under the narrower rule')
    && str_contains((string) $linePs[1]['details'], 'The rule recorded with the name is not changed')
    && preg_match('/Pat|Stored|Declared Road/', (string) $linePs[1]['details']) !== 1 && GdprEraser::verifyAuditChain(REQ_PS) === true,
    json_encode([$secondPs instanceof Throwable ? $secondPs->getMessage() : $secondPs, 'his late gift' => $donorOf($c3[P_PS]['lateUncovered']),
        'the control\'s' => $donorOf($c3[P_QC]['lateUncovered']), 'kept row' => $keptPs2, 'line' => $linePs[1]['details'] ?? null]));
ge_ok('...AND THE ENVELOPES ARE SORTED BY THE SAME WIDER RULE: his envelope in a count that is not yet closed, dated after his declaration ended, keeps its giver and its amount, and the record counts from its service date',
    (int) (ge_row($envelopePs)['giverID'] ?? 0) === P_PS && ge_row($envelopePs)['amount'] === '31.00' && $keptPs2['lastGiftDate'] === $ago(4),
    json_encode([ge_row($envelopePs), $keptPs2['lastGiftDate']]));

// (b) THE CHECKER'S RACE. The clearing used to decide, delete the kept
//     files, and only then open its transaction. A gift recorded in that gap
//     lost its donor. Held open here without changing any code: a second
//     connection holds the kept row, which is the first thing the clearing
//     asks for. While it waits, that connection records a gift of TODAY.
//     Wendy's record is made due first: no hold, and her gift eleven years old.
ge_setting(SITE_F5, GivingRetention::KEY_HOLD, 'false');
ge_q('UPDATE tblGivingEntry SET donatedAt = ? WHERE entryID = ?', 'si', [$ago(4000), ge_id($wd['gift'])]);
ge_q('UPDATE tblGivingKeptDonors SET lastGiftDate = ? WHERE keptDonorID = ?', 'si', [$ago(4000), (int) $keptWd1['keptDonorID']]);
$dueFirst = GivingRetention::stateOfKeptRow($mysqli, GivingRetention::ruleForSite(SITE_F5), SITE_F5, P_WD, $ago(4000))['state'];
$connect  = static fn (): mysqli => new mysqli(
    getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    $dbName,
    getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
);
$other = $connect();
$other->begin_transaction();
$other->query('SELECT keptDonorID FROM tblGivingKeptDonors WHERE keptDonorID = ' . (int) $keptWd1['keptDonorID'] . ' FOR UPDATE');
// "The retention job": the REAL clearing routine, in a process of its own,
// on a connection of its own.
$jobCode = 'declare(strict_types=1); define("PORTAL_ROOT", getenv("SELFTEST_RACE_ROOT")); $core = getenv("SELFTEST_RACE_CORE");'
    . ' foreach (["App", "GivingRetention", "GdprEraser", "Giving", "Site", "Logger"] as $class) { require_once $core . $class . ".php"; }'
    . ' mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);'
    . ' $mysqli = new mysqli(getenv("SELFTEST_RACE_HOST"), getenv("SELFTEST_RACE_USER"), getenv("SELFTEST_RACE_PASS"), getenv("SELFTEST_RACE_NAME"), (int) getenv("SELFTEST_RACE_PORT"));'
    . ' $mysqli->set_charset("utf8mb4");'
    . ' echo "JOB ANSWER: " . Portal\\Core\\GdprEraser::clearKeptGivingIdentity((int) $argv[1]) . "\n";';
$job = proc_open([PHP_BINARY, '-r', $jobCode, (string) $keptWd1['keptDonorID']], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $jobPipes, null, getenv() + [
    'SELFTEST_RACE_ROOT' => PORTAL_ROOT, 'SELFTEST_RACE_CORE' => $coreDir,
    'SELFTEST_RACE_HOST' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    'SELFTEST_RACE_USER' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    'SELFTEST_RACE_PASS' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    'SELFTEST_RACE_NAME' => $dbName,
    'SELFTEST_RACE_PORT' => getenv('SELFTEST_DB_PORT') !== false ? (string) getenv('SELFTEST_DB_PORT') : '3306',
]);
// Wait until the database itself says a connection is waiting for a lock in
// THIS database (not for a fixed time: that would make this test a matter
// of luck). Twenty seconds at most.
$jobWaited = false;
for ($tries = 0; $tries < 200 && $jobWaited === false; $tries++) {
    usleep(100000);
    $jobWaited = (int) ge_one(
        'SELECT COUNT(*) AS n FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l '
        . 'ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID WHERE l.OBJECT_SCHEMA = DATABASE() AND l.OBJECT_NAME = ?',
        's',
        ['tblGivingKeptDonors']
    )['n'] > 0;
    if (proc_get_status($job)['running'] === false) {
        break;
    }
}
$other->query('INSERT INTO tblGivingEntry (siteID, donorID, categoryID, amountPence, donatedAt, method) VALUES ('
    . SITE_F5 . ', ' . P_WD . ', ' . (int) $catF[SITE_F5] . ", 9900, '" . $todayG1 . "', 'bank-transfer')");
$raceGift = (int) $other->insert_id;
$other->commit();
$other->close();
$jobSaid = trim((string) stream_get_contents($jobPipes[1]) . (string) stream_get_contents($jobPipes[2]));
$jobExit = proc_close($job);
$keptWdRace  = $keptAt(SITE_F5, P_WD);
$raceDonor   = ge_one('SELECT donorID FROM tblGivingEntry WHERE entryID = ?', 'i', [$raceGift])['donorID'];
ge_ok('THE CHECKER\'S RACE, THE CLEARING DECIDES AGAIN UNDER ITS LOCK: her record was due; the job was made to wait for the kept row, and a gift of TODAY was recorded for her while it waited; the job then answers "not-due", her name is still kept, the gift of today and her old gift still carry her, no line was added to her trail, and THE KEPT STATEMENT FILE IS STILL ON DISK (files are deleted only after the decision)',
    $dueFirst === 'due' && $jobWaited === true && $jobExit === 0 && $jobSaid === 'JOB ANSWER: not-due'
    && $keptWdRace['fullName'] === 'Wendy Done' && $keptWdRace['clearedAt'] === null && $raceDonor !== null && (int) $raceDonor === P_WD
    && $donorOf($wd['gift']) === P_WD && is_file($fileWd) === true && file_get_contents($fileWd) === 'made-up statement for the race',
    json_encode(['due first' => $dueFirst, 'job waited' => $jobWaited, 'job said' => $jobSaid, 'exit' => $jobExit, 'kept name' => $keptWdRace['fullName'], 'gift donor' => $raceDonor, 'file' => is_file($fileWd)]));
// ...and with nothing in its way the same routine does clear a due record,
// file included, so the lock has not simply stopped it working.
ge_q('UPDATE tblGivingEntry SET donatedAt = ? WHERE entryID = ?', 'si', [$ago(3990), $raceGift]);
$clearedWd = GdprEraser::clearKeptGivingIdentity((int) $keptWd1['keptDonorID']);
ge_ok('...and with nothing in its way the same routine clears her record once every gift is old: name, both gifts and the kept file together, with its line',
    $clearedWd === 'cleared' && $keptAt(SITE_F5, P_WD)['fullName'] === null && $keptAt(SITE_F5, P_WD)['clearedAt'] !== null
    && $donorOf($wd['gift']) === null && ge_one('SELECT donorID FROM tblGivingEntry WHERE entryID = ?', 'i', [$raceGift])['donorID'] === null
    && is_file($fileWd) === false && GdprEraser::verifyAuditChain(REQ_WD) === true
    && $keptAt(SITE_F5, P_WW)['fullName'] === 'Will Wide',
    json_encode([$clearedWd, $keptAt(SITE_F5, P_WD)]));

// (c) THE CANCEL QUESTION, WIDENED. Xena has no giving records at all. Her
//     run stops at the account's own audit line: the account is not emptied,
//     but the walk has already deleted things of hers.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_user(P_XN, 'Xena Nogifts', 'xena.nogifts@selftest.invalid');
$xn = ['membership' => ge_insert('tblUserSites', ['userID' => P_XN], ['siteID' => SITE_F5, 'isActive' => 1])];
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_request(REQ_XN, P_XN, 'xena.nogifts@selftest.invalid', 'Xena Nogifts', 'processing');
$askedBefore = [GdprEraser::runHasChangedAnything(REQ_XN), GdprEraser::givingStepHasRun(REQ_XN)];
$stopAtLine(REQ_XN, 'tblUsers');
$firstXn = ge_execute(REQ_XN, P_XN);
ge_dropTrigger('selftest_gdpr_stop_line');
$changedXn = ge_q("SELECT tableName FROM tblErasureAudit WHERE requestID = ? AND action IN ('delete', 'anonymise', 'partial')", 'i', [REQ_XN]);
ge_ok('A RUN THAT HELD NO GIVING RECORDS BUT DELETED SOMETHING: before any run the cancel question answers "nothing changed"; after a run that stopped with her account still hers and her organisation membership already deleted, it answers "something changed" (so the page refuses the cancel), though the narrower giving question still answers "no"',
    $askedBefore === [false, false] && $firstXn instanceof Throwable && $statusF(REQ_XN) === 'pending_review'
    && $accountF(P_XN) === ['fullName' => 'Xena Nogifts', 'emailAddress' => 'xena.nogifts@selftest.invalid', 'isActive' => 1]
    && ge_row($xn['membership']) === null && count($changedXn) >= 1
    && GdprEraser::runHasChangedAnything(REQ_XN) === true && GdprEraser::givingStepHasRun(REQ_XN) === false,
    json_encode([$askedBefore, $statusF(REQ_XN), ge_row($xn['membership']), count($changedXn), GdprEraser::runHasChangedAnything(REQ_XN)]));

// =============================================================================
ge_section('16', 'An organisation far ahead of UTC: a name is cleared by the last place on Earth\'s date, and "Confirmed on" is its own (#604, second check, 13 October 2026)');
// =============================================================================
// Kiritimati is fourteen hours ahead of UTC and the last place on Earth is
// twelve behind, so Kiritimati's own date is one or two days AHEAD of the
// date the clearing goes by, at every hour of every day. That makes this the
// one part of the giving sections that does not depend on when it is run.
//
// Kiri's record is kept until TODAY at the last place on Earth: not yet due.
// On Kiritimati's own calendar that date has already passed. A clearing that
// asked "today" where the organisation is would call the record due and
// empty the name a day or two early, and that cannot be undone. The second
// check planted exactly that at the place that decides
// (GivingRetention::stateOfKeptRow()) and every test passed.
// WHAT THIS CANNOT DO: on 29 February the keep-until date found below can be
// the day AFTER today (no date two years earlier lands on a 29th), and for
// most of that one day Kiritimati's own date is that same day after. The
// check still passes on correct code then, but could not see the fault.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_insert('tblSites', [], ['siteID' => SITE_K, 'timezone' => 'Pacific/Kiritimati']);
$catK = ge_id(ge_insert('tblGivingCategory', [], ['siteID' => SITE_K]));
ge_user(P_Q, 'Kiri Ahead', 'kiri.ahead@selftest.invalid');
$q = ['declaration' => ge_declaration(SITE_K, P_Q, $ago(900), '9 Dateline Drive', 'DL9 9DL'), 'gift' => ge_gift(SITE_K, P_Q, $ago(5), 300, $catK)];
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_request(REQ_Q, P_Q, 'kiri.ahead@selftest.invalid', 'Kiri Ahead', 'processing');
$resultQ = ge_execute(REQ_Q, P_Q);
$keptQ   = (int) (ge_one('SELECT keptDonorID FROM tblGivingKeptDonors WHERE siteID = ? AND donorID = ?', 'ii', [SITE_K, P_Q])['keptDonorID'] ?? 0);
// The same line as section 12: one year kept, no year end, so "keep until" is
// the last gift plus two calendar years. `notDue` is the earliest last-gift
// date whose record is kept until today (the last place's) or later.
ge_q('UPDATE tblGivingKeptDonors SET lastGiftDate = ? WHERE keptDonorID = ?', 'si', [$edge['notDue'], $keptQ]);
ge_q('UPDATE tblGivingEntry SET donatedAt = ? WHERE entryID = ?', 'si', [$edge['notDue'], ge_id($q['gift'])]);
$ownDateK = static fn (): string => ge_now()->setTimezone(new DateTimeZone('Pacific/Kiritimati'))->format('Y-m-d'); // worked out here, not by the code under test
$ownK     = $ownDateK();
// The administrator there ticks "confirmed", through the real save. Its rule
// 3 ("a slip of the finger must not clear records") is decided on the date
// the clearing goes by: on that date nothing becomes due, so the save goes
// through without the "I understand" box. Decided on Kiritimati's own date
// it would be refused, as about to clear one record.
$tickK = GivingRetention::saveForSite($mysqli, SITE_K, $form(['years' => '1', 'confirmed' => '1']));
$ruleK = GivingRetention::ruleForSite(SITE_K);
ge_ok('"CONFIRMED ON" IS THE ORGANISATION\'S OWN DATE, for one fourteen hours ahead of UTC: the tick is saved with Kiritimati\'s date, which is later than the date the clearing goes by; and the save is not refused as "about to clear a record", because that is decided on the last place on Earth\'s date',
    is_array($resultQ) === true && $resultQ['failed'] === 0 && $keptQ > 0 && $tickK['saved'] === true && $tickK['newlyDue'] === 0 && $ruleK['confirmed'] === true && $ruleK['years'] === 1
    && in_array($ruleK['confirmedOn'], [$ownK, $ownDateK()], true) === true && $ruleK['confirmedOn'] > $todayG1 && $ruleK['timezone'] === ''
    && \Portal\Core\Site::timezone(SITE_K) === 'Pacific/Kiritimati',
    json_encode([$tickK, 'confirmedOn' => $ruleK['confirmedOn'], 'Kiritimati\'s own date' => $ownK, 'the last place\'s' => $todayG1, 'the rule\'s zone' => $ruleK['timezone'],
        'the pages\' zone for it' => \Portal\Core\Site::timezone(SITE_K)]));
$stateK   = GivingRetention::stateOfKeptRow($mysqli, $ruleK, SITE_K, P_Q, $edge['notDue']);
$askedK   = GdprEraser::clearKeptGivingIdentity($keptQ);
$keptKNow = ge_one('SELECT fullName, address, clearedAt FROM tblGivingKeptDonors WHERE keptDonorID = ?', 'i', [$keptQ]);
ge_ok('KEPT UNTIL TODAY AT THE LAST PLACE ON EARTH IS NOT CLEARED, though in Kiritimati that date has already passed: confirmed, no hold, the account emptied, and the clearing still answers "not-due"; the name, the address and the gift\'s donor are all still there. It answers so at every hour of the day',
    $stateK['state'] === 'not-due' && $stateK['today'] === $todayG1 && $stateK['accountEmptied'] === true && $stateK['keepUntil'] >= $todayG1
    && $askedK === 'not-due' && $keptKNow['fullName'] === 'Kiri Ahead' && $keptKNow['address'] === '9 Dateline Drive' && $keptKNow['clearedAt'] === null
    && (int) ge_row($q['gift'])['donorID'] === P_Q && ge_row($q['declaration'])['address'] === '9 Dateline Drive',
    json_encode(['state' => $stateK, 'the clearing said' => $askedK, 'kept row' => $keptKNow, 'Kiritimati\'s own date' => $ownK]));

ge_finish();
