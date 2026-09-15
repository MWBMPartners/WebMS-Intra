<?php
// Path: web/_apps/admin/maintenance/demo-data.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Demo Data 🧪
 * -----------------------------------------------------------------------------
 * Puts a small set of made-up people and announcements into the portal for
 * training or a demonstration (Load), and later takes exactly those rows out
 * again (Wipe).
 *
 * WHO MAY USE THIS PAGE, AND WHY IT IS A GLOBAL ADMINISTRATOR ONLY
 * -----------------------------------------------------------------------------
 * Both actions write to the one database underneath every organisation on the
 * installation. Loading adds rows to shared tables (accounts are not owned by
 * any one organisation), and a wipe deletes from them. So the decision belongs
 * to whoever is responsible for the whole installation. An administrator of a
 * single organisation is refused the whole page, told why in words, and a
 * refused ACTION is written to the activity log. This matches the backup page
 * (web/_apps/admin/maintenance/backup.php), which refuses in the same way.
 *
 * THE ON/OFF SWITCH IS READ FROM THE PORTAL-WIDE ROW ONLY
 * -----------------------------------------------------------------------------
 * The page does nothing unless `portal.demo_mode.enabled` is '1' on the
 * portal-wide row: the row with no organisation against it (siteID IS NULL).
 * A row of the same name saved for one organisation is ignored, on purpose.
 * App::settings() lets an organisation's own row override the portal-wide
 * one, so reading the switch through it would let one organisation's row
 * switch demo mode on for an action that affects everybody. The query below
 * is the same small direct query web/_core/Gatekeeper.php uses for its own
 * portal-wide-only settings (Gatekeeper::portalWideSetting()); that method is
 * private, so it is repeated here rather than reused.
 *
 * WHAT "LOAD" DOES
 * -----------------------------------------------------------------------------
 * Adds five made-up people as members of the organisation the administrator
 * is currently working in, and three announcements marked [DEMO] in that
 * organisation. Specifically:
 *   - The database hands out every identity number. Nothing is given a fixed
 *     number, and nothing uses ON DUPLICATE KEY UPDATE, so no existing row can
 *     be overwritten, changed, or have anything attached to it. (The demo rows
 *     do point AT the organisation they are loaded into; that is unavoidable
 *     and changes nothing in the organisation itself.)
 *   - Straight after each row is created, inside the same transaction, the
 *     row is read back and written into tblDemoDataRegister with two things:
 *     the organisation it belongs to (where the table has one), and a
 *     FINGERPRINT of its content. See "THE FINGERPRINT" below.
 *   - All or nothing: one transaction. If anything fails part way, everything
 *     the load did is undone.
 *   - It refuses if anything is already recorded as loaded.
 *   - The made-up people cannot sign in. Their accounts are switched off
 *     (isActive = 0); they get no row in tblLocalAccounts (so no password),
 *     tblLinkedAccounts (so no Microsoft or Google sign-in) or
 *     tblWebAuthnCredentials (so no passkey); and their email addresses end in
 *     ".invalid", a name reserved by the internet standards (RFC 2606) that can
 *     never belong to a real mailbox or be issued by a sign-in provider. Each
 *     address also carries a random part, so it cannot collide with an
 *     address already in the portal.
 *
 * THE FINGERPRINT, AND WHY ONE VALUE WAS NOT ENOUGH
 * -----------------------------------------------------------------------------
 * The first version of this rebuild recorded ONE value per row (an email
 * address, an announcement's web address, or a person's number) and deleted
 * any row that still held it. Codex found, and a test on MySQL 8.0.36
 * confirmed, that this deleted real content in two ordinary situations:
 *   - The announcement API (web/_apps/announcements/api/update.php) replaces
 *     the title and body but never the web address. A demo announcement
 *     rewritten into a real notice still held its recorded value, so Wipe
 *     deleted the real notice.
 *   - If a demo announcement was removed by hand and its number was later
 *     used again (a restore, or an import that sets numbers) for a real
 *     announcement with the same web address in ANOTHER organisation, the
 *     check passed, because it never looked at the organisation, and Wipe
 *     deleted the other organisation's announcement.
 * A random part in the value does not help: anything can be copied.
 *
 * So each row now has a fingerprint: a SHA-256 hash of the table name, the
 * row's number, and the value of EVERY column the row had straight after Load
 * created it, except a column the database changes by itself (see "WHICH
 * COLUMNS ARE LEFT OUT" below). demo_data_fingerprint() shows exactly how the
 * text that is hashed is built. The creation time is one of those columns,
 * and it is what makes a replacement row different: the database fills it in
 * when a row is inserted, so a row made later carries a different one unless
 * somebody deliberately copies it.
 *
 * Why a hash rather than a copy of the values: an announcement body can be
 * tens of thousands of characters, and a copy of names and email addresses in
 * the list would be one more place personal-looking information sits. A hash
 * is 64 characters whatever the row holds, compares in one step, and cannot
 * be turned back into the values. SHA-256 is built into PHP's hash(), so it
 * needs nothing extra on shared hosting.
 *
 * WHY EVERY COLUMN, AND NOT ONLY THE ONES LOAD FILLED IN
 * -----------------------------------------------------------------------------
 * The second version fingerprinted only the columns Load itself set (name,
 * email address, flags, creation time and so on). Codex found, and a test on
 * MySQL 8.0.36 confirmed on 14 September 2026, that this still deleted real
 * information. A demo person was given a home address, a biography and a map
 * position, all in columns Load leaves empty. None of those was in the
 * fingerprint, so it still matched, and Wipe deleted the person together with
 * everything that had been added. Any column that a person or an import can
 * fill in can carry real information. So the fingerprint now covers all of
 * them, for every table Load writes to, and leaves a column out only when the
 * database changes it by itself.
 *
 * The second version also left out an announcement's deleted marker
 * (isDeleted) and its "last edited by" column (updatedByID), so that an
 * administrator who tidied demo announcements away on the Announcements page
 * could still Wipe them. That was reversed at the same time. Both are set by a
 * person acting on the row, and the rule is now simply that any such change
 * leaves the row in place. The cost: a demo announcement deleted on the
 * Announcements page is now left in place by Wipe. The page says so, and asks
 * the administrator to use Wipe instead of tidying up by hand first.
 *
 * WHICH COLUMNS ARE LEFT OUT, AND HOW THAT WAS ESTABLISHED
 * -----------------------------------------------------------------------------
 * Only tblAnnouncements.updatedAt (see 'automatic' in demo_data_tables()). It
 * is declared ON UPDATE CURRENT_TIMESTAMP, so the database rewrites it by
 * itself whenever something else in the row changes. It can only change
 * alongside a column that IS fingerprinted, or when somebody sets that time on
 * its own, which puts no information into the row. So leaving it out never
 * lets a real change through, and keeping it in would add nothing.
 * Checked on 14 September 2026 against a MySQL 8.0.36 database built from
 * full_schema.sql plus every migration: information_schema lists no other
 * column in the three tables that the database fills in on update, no
 * generated (calculated) column, and no trigger on any of them. Every UPDATE
 * of these tables in the PHP code was read too. Each one is somebody editing
 * the row, or runs only for the signed-in account holder (language choice,
 * calendar feed address, two-step sign-in), which a demo person can never be.
 * If a future change adds a column the database updates by itself, add it to
 * 'automatic'; until then, such a column makes rows be left in place, which is
 * the safe way to be wrong.
 *
 * HOW THE COLUMNS ARE READ, AND WHY NOT "SELECT *"
 * -----------------------------------------------------------------------------
 * Every read of a demo row names its columns one by one, taking the names from
 * information_schema.COLUMNS, the database's own list of a table's columns.
 * The third version read rows with SELECT *. Codex found on 14 September 2026
 * that SELECT * silently leaves out INVISIBLE columns (MySQL 8.0.23 and later,
 * MariaDB 10.3 and later): a column that exists and holds data, but is only
 * returned when a query names it. A column added as invisible after Load and
 * filled with real information was therefore in neither the fingerprint nor
 * the "added since Load" check, and Wipe deleted the row together with that
 * information. information_schema lists invisible columns like any other, and
 * naming a column returns it whether it is invisible or not, so Load and Wipe
 * now both see every column.
 *
 * Refusing to wipe any table that has an invisible column was considered and
 * rejected. The column can be read, so refusing would gain nothing, and it
 * would block a safe wipe for ever on a database where some tool had added
 * one.
 *
 * The order of the reads matters (demo_data_read_row()). The row is touched
 * first, then the column list is read, then the row is read again by name.
 * Once a transaction has used a table, MySQL and MariaDB hold a lock on the
 * table's definition until the transaction ends, so no upgrade can add or
 * remove a column between reading the list and reading the row. Reading the
 * list first would leave a moment in which a column could be added and missed.
 *
 * What this cannot do: it relies on information_schema listing every column.
 * It does for the portal's own database account, which owns these tables. An
 * account allowed to see only some columns would be shown a shorter list.
 *
 * A FLOAT or DOUBLE column is read differently again (round 5 of #498, 15
 * September 2026, Codex): as "(col * 1e0)" rather than plainly. With a
 * prepared statement, the driver hands a DOUBLE value over as its exact 8
 * bytes, but rounds a FLOAT value to 6 significant digits before PHP ever
 * sees it (measured 15 September 2026: 1.23456 and 1.234561 in a FLOAT
 * column arrived as identical bits). Multiplying by exactly 1 turns the
 * value into a DOUBLE without changing it (negative zero included), so
 * whatever the column's own type, it arrives at PHP exact.
 * What was tried and rejected for this: CAST(col AS CHAR) (measured lossy
 * for FLOAT; and a DOUBLE's text form is a server detail, not something to
 * depend on); CAST(col AS DOUBLE) (needs MySQL 8.0.17 or later and a
 * matching MariaDB version, neither guaranteed on shared hosting); changing
 * PHP's ini_set('precision', ...) or relying on serialize_precision
 * (settings for the whole PHP process, which a host can override, and
 * neither undoes the driver's own FLOAT rounding); sprintf('%.17g') under
 * the existing "S" text form (FLOAT is still rounded before PHP sees it,
 * and it changes the fingerprint of every earlier text-form entry, which is
 * exactly the kind of drift "WHAT HAPPENS WHEN AN UPGRADE CHANGES ONE OF
 * THESE TABLES" below exists to avoid); and refusing to Wipe any table with
 * such a column at all, which would block a safe wipe for ever.
 * What this cannot do: it relies on the database driver PHP ships with
 * (mysqlnd, which get_result() already requires) sending a DOUBLE result in
 * binary form over a prepared statement. If that ever changed, the check in
 * demo_data_read_row() throws rather than fingerprint a value it cannot be
 * sure is exact, so nothing would be wrongly deleted; it would simply stop
 * Wipe on the affected table until this is looked at again.
 *
 * WHAT HAPPENS WHEN AN UPGRADE CHANGES ONE OF THESE TABLES
 * -----------------------------------------------------------------------------
 * The names of the fingerprinted columns are stored with each list entry
 * (tblDemoDataRegister.fingerprintColumns). Wipe works the fingerprint out
 * again over exactly those columns, not over whatever the table has now. So:
 *   - A column ADDED by a later upgrade does not break the fingerprint. It is
 *     checked on its own instead. While it is empty (NULL), nothing has been
 *     put in it, and the row can still be wiped. If it holds anything, the row
 *     is left in place, because this page cannot tell whether a person filled
 *     it in. That includes a new column the upgrade itself fills with a
 *     starting value: such rows are left in place and listed.
 *   - A column REMOVED by an upgrade means the fingerprint cannot be worked
 *     out again, so the row is left in place and listed.
 *   - A column added as INVISIBLE is treated exactly like any other added
 *     column (see "HOW THE COLUMNS ARE READ" above).
 *   - A recorded column converted to FLOAT/DOUBLE, or away from it, after
 *     Load makes those rows count as changed even where the value did not
 *     really change: the fingerprint stored at Load was worked out on the
 *     column's old type. They are left in place, which is the safe way to
 *     be wrong (round 5 of #498).
 * The alternative, fingerprinting whatever columns the table has at wipe time,
 * was rejected: the first upgrade to add a column to tblUsers would have left
 * every demo person in place, with no way to tell why.
 *
 * LIST ENTRIES FROM AN EARLIER DEVELOPMENT DRAFT
 * -----------------------------------------------------------------------------
 * Before this page was released, two earlier drafts of tblDemoDataRegister
 * existed on development databases: one kept a single check value per row,
 * the next kept a fingerprint of only the columns Load filled in. Migration
 * 194 upgrades either shape to the current one and keeps every entry. It
 * cannot give those entries a complete fingerprint, because the values the
 * rows held at Load were never kept, so it marks each one instead (see
 * demo_data_incomplete_marker()). Wipe never deletes the row of a marked
 * entry: the row is left in place and listed with the reason, together with
 * any demo row linked to it, exactly like a changed row. Re-using the old
 * check value or the old partial fingerprint was rejected, because those are
 * exactly the two designs that were shown to delete real information.
 *
 * WHAT "WIPE" DOES
 * -----------------------------------------------------------------------------
 *   - Considers only the rows named in tblDemoDataRegister. Never a range of
 *     numbers, and never a single value.
 *   - Reads each one, every column (locked until the wipe ends), and deletes
 *     it ONLY if it still belongs to the recorded organisation, its
 *     fingerprint still matches exactly, and any column added by an upgrade
 *     since Load is still empty. A row that fails any test is "changed since
 *     it was loaded": it is left in place, stays on the list, and is shown to
 *     the administrator to decide about, with the reason. It may have been edited into real use,
 *     or its number may now belong to something else entirely; the page
 *     cannot tell which, so it does not guess.
 *   - Demo rows that point at each other are kept or removed TOGETHER. If a
 *     changed row is linked (through a foreign key the database declares) to
 *     other demo rows, those are left in place too, and so is anything linked
 *     to them. Why: deleting a demo person would make the database blank the
 *     author of a changed announcement they wrote (ON DELETE SET NULL), and
 *     deleting the unchanged membership of a changed person would quietly
 *     take a possibly-now-real person out of their organisation. Keeping the
 *     whole linked group avoids both.
 *   - Then looks for REAL rows linked to any row it is about to delete, for
 *     example a task assigned to a demo person. It finds them through every
 *     foreign key the database itself declares, read from information_schema
 *     at the moment of the wipe, so tables added in future are covered without
 *     changing this page. A demo row being left in place counts as real here.
 *     If it finds any, it refuses, deletes nothing, and lists them.
 *     Why refuse rather than carry on: deleting the demo row would make the
 *     database delete those real rows too (ON DELETE CASCADE), blank the link
 *     in them (SET NULL), or stop the delete half way (RESTRICT). Every one of
 *     those either changes real data or fails. The administrator can remove or
 *     change the listed rows and wipe again.
 *   - All or nothing: one transaction.
 *   - A recorded row that somebody has already removed by hand is crossed off
 *     the list.
 *
 * WHAT WAS WRONG BEFORE (found 13 September 2026, issue #498)
 * -----------------------------------------------------------------------------
 *   - Any administrator of any single organisation could load and wipe.
 *   - The switch was read through App::settings(), so an organisation's own
 *     row could turn it on.
 *   - Load ran web/_sql/demo_data.sql through multi_query with no transaction.
 *     That file gave the demo accounts the fixed numbers 9000 to 9004 and used
 *     ON DUPLICATE KEY UPDATE, so it would have overwritten real accounts
 *     with those numbers. (It named tblUsers columns that do not exist, so on
 *     this database it actually failed at its first statement.) The file has
 *     been removed; the demo content now lives in demo_data_load() below.
 *   - Wipe ran "DELETE ... WHERE createdByID / userID / assignedToID >= 9000"
 *     on five tables with no organisation filter. Account numbers are handed
 *     out one after another, so a growing installation's real accounts reach
 *     9000, and from then on Wipe deleted real people and everything the
 *     database removes with them.
 *   - This header said "real records are untouched". That stopped being true
 *     as soon as real account numbers passed 9000.
 *   - The first rebuild checked one recorded value per row; see "THE
 *     FINGERPRINT" above for why that still deleted real content (14 September
 *     2026).
 *   - Round 5 (15 September 2026, Codex): the fingerprint turned every value
 *     into text with PHP's default of 14 significant digits before hashing
 *     it, so 1.0 and 1.000000000000001 in a DOUBLE column gave the same
 *     fingerprint, and Wipe deleted a row that had genuinely changed. A
 *     FLOAT column was worse still, because the database driver had already
 *     rounded the value to 6 significant digits before PHP received it. See
 *     "HOW THE COLUMNS ARE READ" and demo_data_fingerprint()'s docblock for
 *     the fix. Entries recorded before this change are unaffected wherever
 *     their row holds no FLOAT/DOUBLE value, because their fingerprint text
 *     is byte-identical either way. Where a row DOES hold one, its stored
 *     line was in the old "S..." text form; the new code never produces
 *     that form for such a column, so it can never newly match, and the row
 *     is left in place. That is only ever fewer deletions than before this
 *     change, never more. (The tables this page uses today have no such
 *     column, so this affects nobody until one is added.)
 *
 * DEMO ROWS LEFT BY THE OLD VERSION
 * -----------------------------------------------------------------------------
 * Rows from the old version are not in tblDemoDataRegister, so this page never
 * touches them. That is deliberate: the only thing that identified them was a
 * number, and a number is exactly what cannot be trusted. The page explains
 * how to recognise them (fixed addresses such as demo.pastor@example.invalid
 * with no random part) so an administrator can check and remove them by hand.
 *
 * WHAT THIS PAGE CANNOT DO
 * -----------------------------------------------------------------------------
 *   - It cannot see a link to a demo row that the database does not declare
 *     as a foreign key. Some tables store a person's number in an ordinary
 *     column with no declared link; for example tblExpenseClaimPayments.paidByID
 *     records who paid an expense claim. If a real row like that holds a demo
 *     person's number, Wipe does not find it. What happens then, exactly: the
 *     real row is not deleted and not changed in any way, but once the demo
 *     person is gone it points at a number that no longer belongs to anybody.
 *     Pages that look that number up show no name, or an "unknown" one, for
 *     it. If the number is ever handed out again (the database does not
 *     normally reuse numbers) it would point at whoever gets it.
 *   - A row that is an exact copy of a demo row, with every column including
 *     its creation time, at the same number, in the same organisation, cannot
 *     be told apart from the demo row and would be wiped. Only a deliberate
 *     copy or restore of the demo row itself produces that, and then it is the
 *     demo content.
 *   - It cannot say which column of a changed row was changed, because only
 *     the fingerprint was kept, not the values.
 *   - A row left in place stays on the list. There is no button to take it
 *     off, so Load stays unavailable until it is dealt with: if it is demo
 *     data after all, delete it by hand and Wipe again (a row already gone is
 *     crossed off); if it is now real, its list entry has to be removed from
 *     tblDemoDataRegister by hand in the database.
 *   - It does not reset the database's number counters. After a wipe the next
 *     new account gets a higher number than it would have had without the
 *     demo load. Nothing depends on those numbers being unbroken.
 *   - Demo people are counted in two places while loaded, because their
 *     memberships are active: the member total on Admin → Reports, and the
 *     "Members" source in the report builder. Everything that sends email or
 *     notifications, the member directory and the dashboard total ignore
 *     switched-off accounts, so they leave demo people out.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.4.0
 * @see       Issues #242 and #498 in the MWBMPartners/WebMS-Intra repository
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

// 🚧 Global administrators only — see "WHO MAY USE THIS PAGE" above. This sits
//    before the action handler and before anything is read, so nothing below
//    runs for anybody else.
if (App::isRootAdmin() === false) {
    // 📝 A refused ACTION is recorded, but only when the form token is valid:
    //    otherwise a forged request from another website could fill the
    //    activity log with refusals. Simply opening the page is not recorded.
    //    Same rule as backup.php.
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && Auth::verifyCsrf($_POST['csrf_token'] ?? '') === true
    ) {
        Logger::activity(
            'DemoDataActionRefused',
            sprintf(
                'Refused: demo data action "%s" may only be used by a global administrator',
                substr(preg_replace('/[^a-z_]/', '', (string) ($_POST['action'] ?? '')) ?? '', 0, 30)
            ),
            $_SESSION['user_id'] ?? null
        );
    }

    http_response_code(403);
    $pageTitle   = 'Demo Data';
    $pageSection = 'admin';
    $breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Maintenance' => '/admin/maintenance', 'Demo Data' => ''];
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
    ?>
    <h1 class="mb-3"><i class="fa-solid fa-flask me-2"></i>Demo Data</h1>
    <div class="alert alert-danger">
        Demo data is loaded into, and wiped from, the database that every
        organisation on this installation shares, not only yours. So only a
        global administrator can use this page. Nothing has been changed.
    </div>
    <?php
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
    return;
}

// =============================================================================
// 🧰 Helpers
// =============================================================================

/**
 * The only tables a demo load writes to, and for each one:
 *   id          its identity number column;
 *   site        its organisation column, or null when it has none;
 *   noun        what one row is, in words, for messages;
 *   label       a column shown to the administrator to recognise the row;
 *   labelFormat how that column is worded in a message;
 *   automatic   columns the database changes BY ITSELF, which are the only
 *               ones the fingerprint leaves out. Every other column is
 *               fingerprinted. See "WHICH COLUMNS ARE LEFT OUT" in the file
 *               header for how this list was established. Changing it
 *               changes the fingerprint of rows loaded afterwards only; rows
 *               already loaded keep the column list stored with them.
 *
 * Every identifier Wipe puts into SQL comes from this list, never from the
 * database or the request, so a hand-edited tblDemoDataRegister row naming any
 * other table is refused rather than acted on.
 *
 * @return array<string, array{id: string, site: ?string, noun: string, label: string, labelFormat: string, automatic: list<string>}>
 */
function demo_data_tables(): array
{
    return [
        'tblUsers' => [
            'id'          => 'userID',
            'site'        => null,
            'noun'        => 'person',
            'label'       => 'fullName',
            'labelFormat' => 'named "%s"',
            // None. tblUsers has no column the database updates by itself.
            'automatic'   => [],
        ],
        'tblUserSites' => [
            'id'          => 'userSiteID',
            'site'        => 'siteID',
            'noun'        => 'membership',
            'label'       => 'userID',
            'labelFormat' => 'for person number %s',
            // None. tblUserSites has no column the database updates by itself.
            'automatic'   => [],
        ],
        'tblAnnouncements' => [
            'id'          => 'announcementID',
            'site'        => 'siteID',
            'noun'        => 'announcement',
            'label'       => 'title',
            'labelFormat' => 'titled "%s"',
            // updatedAt is ON UPDATE CURRENT_TIMESTAMP: it only ever changes
            // together with another column, which is fingerprinted.
            'automatic'   => ['updatedAt'],
        ],
    ];
}

/**
 * Prepare, bind and run one statement, throwing on any failure.
 *
 * The portal sets mysqli to throw on errors already (bootstrap.php). The
 * explicit checks are here so a failure can never pass silently even if that
 * setting changes, because a quiet failure half way through a load or a wipe
 * is exactly what the transaction is meant to prevent.
 *
 * @param \mysqli           $db     Connection.
 * @param string            $sql    Statement with ? placeholders.
 * @param string            $types  bind_param type letters, '' for none.
 * @param array<int, mixed> $params Values, in placeholder order.
 *
 * @return \mysqli_stmt The executed statement; the caller closes it.
 */
function demo_data_query(\mysqli $db, string $sql, string $types = '', array $params = []): \mysqli_stmt
{
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        throw new \RuntimeException('The database could not prepare a statement: ' . $db->error);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    if ($stmt->execute() === false) {
        $error = $stmt->error;
        $stmt->close();
        throw new \RuntimeException('The database refused a statement: ' . $error);
    }
    return $stmt;
}

/**
 * Undo the open transaction without letting a second failure hide the first.
 *
 * If the connection itself has gone, MySQL discards the unfinished transaction
 * on its own, so there is nothing more useful to do than note it.
 *
 * @param \mysqli $db Connection.
 *
 * @return void
 */
function demo_data_rollback(\mysqli $db): void
{
    try {
        $db->rollback();
    } catch (\Throwable $problem) {
        error_log('[WebMS-Intra] Demo data: rollback failed: ' . $problem->getMessage());
    }
}

/**
 * Is demo mode switched on, according to the PORTAL-WIDE row only?
 *
 * The same query as Gatekeeper::portalWideSetting() (web/_core/Gatekeeper.php);
 * see "THE ON/OFF SWITCH" in the file header for why an organisation's own row
 * must not count. A missing row means off.
 *
 * Inside a load or wipe the row is read FOR UPDATE. That makes two
 * administrators pressing Load at the same moment take turns, so both cannot
 * see "nothing loaded yet" and load twice. It also means the switch is
 * checked again at the moment of acting, not only when the page was drawn.
 *
 * @param \mysqli $db      Connection.
 * @param bool    $lockRow True inside a transaction that is about to act.
 *
 * @return bool True only when the portal-wide value is exactly '1'.
 */
function demo_data_switch_is_on(\mysqli $db, bool $lockRow): bool
{
    $settingKey = 'portal.demo_mode.enabled';
    $stmt = demo_data_query(
        $db,
        'SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL LIMIT 1'
            . ($lockRow === true ? ' FOR UPDATE' : ''),
        's',
        [$settingKey]
    );
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return is_array($row) === true && (string) $row['settingValue'] === '1';
}

/**
 * The mark migration 194 writes into fingerprintColumns for a list entry it
 * upgraded from an earlier development draft of tblDemoDataRegister.
 *
 * Such an entry has no complete fingerprint, so Wipe never deletes its row
 * (see "LIST ENTRIES FROM AN EARLIER DEVELOPMENT DRAFT" in the file header).
 * web/_sql/194_demo_data_register.sql writes exactly this text. The colon in
 * it is something demo_data_recorded_columns() refuses, so if the two texts
 * ever drifted apart, a marked entry would be treated as a damaged column list
 * and the whole Wipe refused: still nothing deleted.
 *
 * @return string
 */
function demo_data_incomplete_marker(): string
{
    return 'earlier-draft:no-complete-fingerprint';
}

/**
 * Every column a demo table has, in table order, INVISIBLE columns included,
 * together with each column's data type.
 *
 * Read from information_schema.COLUMNS rather than taken from what SELECT *
 * returns, because SELECT * leaves invisible columns out; see "HOW THE COLUMNS
 * ARE READ" in the file header. The caller must already have used the table
 * inside the current transaction, so its definition cannot change before the
 * row is read by these names.
 *
 * The data type is returned as well as the name (round 5, #498) so
 * demo_data_read_row() can read a FLOAT or DOUBLE column in a way that
 * survives round-tripping through PHP exactly, instead of through PHP's
 * text form, which loses precision. See "HOW THE COLUMNS ARE READ" in the
 * file header for why that matters.
 *
 * Each name ends up between backticks in a query, so a name that is not plain
 * letters, digits and underscores makes this throw (and so the load or wipe
 * change nothing) rather than be written into SQL. So does a list without the
 * table's identity column, which would mean the database did not answer
 * properly.
 *
 * @param \mysqli $db    Connection.
 * @param string  $table One of demo_data_tables().
 *
 * @return array<string, string> Column name => lower-case information_schema
 *   DATA_TYPE (e.g. 'int', 'varchar', 'double'), in table order.
 */
function demo_data_table_columns(\mysqli $db, string $table): array
{
    $stmt = demo_data_query(
        $db,
        'SELECT COLUMN_NAME AS columnName, DATA_TYPE AS dataType FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
        's',
        [$table]
    );
    $columns = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $listed) {
        $name = (string) $listed['columnName'];
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            $stmt->close();
            throw new \RuntimeException('The column name "' . $name . '" in ' . $table . ' cannot be read safely.');
        }
        $columns[$name] = strtolower((string) $listed['dataType']);
    }
    $stmt->close();

    if (isset($columns[demo_data_tables()[$table]['id']]) === false) {
        throw new \RuntimeException('The database did not list the columns of ' . $table . ', so its rows cannot be checked.');
    }
    return $columns;
}

/**
 * Is this information_schema DATA_TYPE an approximate number (FLOAT or
 * DOUBLE)? Those are the only column types whose values can reach PHP
 * rounded; see "HOW THE COLUMNS ARE READ" in the file header. 'real' is
 * included only because it is another name for one of them; MySQL itself
 * reports 'double'.
 *
 * @param string $dataType Lower-case DATA_TYPE.
 *
 * @return bool
 */
function demo_data_is_approximate_number(string $dataType): bool
{
    return in_array($dataType, ['float', 'double', 'real'], true);
}

/**
 * Read EVERY column of one demo-table row, invisible columns included.
 *
 * Every column, because the fingerprint covers every column (see "WHY EVERY
 * COLUMN" and "HOW THE COLUMNS ARE READ" in the file header). The table and
 * identity column names come from demo_data_tables(); the other column names
 * come from demo_data_table_columns(), which checks each one.
 *
 * Four steps, in this order on purpose:
 *   1. Touch the row by its number (locking it when asked). This is also the
 *      moment the transaction starts holding the table's definition steady.
 *   2. Read the table's full column list, and each column's data type.
 *   3. Read the row again, naming every column in that list. A FLOAT or
 *      DOUBLE column is read as "(column * 1e0)" rather than plainly, so it
 *      reaches PHP as an exact 8-byte number rather than rounded; see "HOW
 *      THE COLUMNS ARE READ" in the file header.
 *   4. Refuse rather than guess: if an approximate-number column ever comes
 *      back as anything other than a PHP float (for example because a future
 *      driver change stops sending it in binary form), this throws instead
 *      of silently fingerprinting a rounded or mangled value.
 * Reading the list before touching the table would leave a gap in which an
 * upgrade could add a column that step 3 then never asks for.
 *
 * @param \mysqli $db     Connection.
 * @param string  $table  One of demo_data_tables().
 * @param int     $rowId  Identity number.
 * @param bool    $lock   True to lock the row until the transaction ends.
 *
 * @return array<string, mixed>|null The row, or null when there is no such row.
 */
function demo_data_read_row(\mysqli $db, string $table, int $rowId, bool $lock): ?array
{
    $idColumn = demo_data_tables()[$table]['id'];
    $where    = ' FROM `' . $table . '` WHERE `' . $idColumn . '` = ? LIMIT 1' . ($lock === true ? ' FOR UPDATE' : '');

    $touch  = demo_data_query($db, 'SELECT `' . $idColumn . '`' . $where, 'i', [$rowId]);
    $exists = is_array($touch->get_result()->fetch_row());
    $touch->close();
    if ($exists === false) {
        return null;
    }

    $columns = demo_data_table_columns($db, $table);
    $select  = [];
    foreach ($columns as $name => $dataType) {
        // An approximate number is multiplied by exactly 1, which makes MySQL
        // send it as an 8-byte DOUBLE that reaches PHP bit for bit. Read plainly,
        // a FLOAT arrives rounded to 6 significant digits. See the file header.
        $select[] = demo_data_is_approximate_number($dataType) === true
            ? '(`' . $name . '` * 1e0) AS `' . $name . '`'
            : '`' . $name . '`';
    }
    $stmt = demo_data_query($db, 'SELECT ' . implode(', ', $select) . $where, 'i', [$rowId]);
    $row  = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (is_array($row) === false) {
        return null;
    }

    // Refuse rather than guess: if an approximate number ever came back as
    // anything other than a PHP float, its fingerprint line could hide a change.
    foreach ($columns as $name => $dataType) {
        if (demo_data_is_approximate_number($dataType) === true
            && $row[$name] !== null
            && is_float($row[$name]) === false
        ) {
            throw new \RuntimeException('The column ' . $name . ' in ' . $table . ' was not read back as an exact number, so the row cannot be checked.');
        }
    }
    return $row;
}

/**
 * The columns a newly created row's fingerprint covers.
 *
 * Every column the row has, in the order the database returns them, except
 * the ones demo_data_tables() lists as 'automatic'. Worked out once, at Load,
 * and stored with the list entry, so Wipe checks exactly the same columns
 * even if an upgrade has since added or removed some (see "WHAT HAPPENS WHEN
 * AN UPGRADE CHANGES ONE OF THESE TABLES" in the file header).
 *
 * A column name must be plain letters, digits and underscores. The list is
 * stored separated by commas, and each name is written into the hashed text
 * followed by "=", so a name containing a comma, an "=" or a line break would
 * make both ambiguous. No table here has such a name; if one ever appears,
 * Load fails and changes nothing rather than record something unclear.
 *
 * @param string               $table One of demo_data_tables().
 * @param array<string, mixed> $row   The row, as returned by demo_data_read_row().
 *
 * @return list<string>
 */
function demo_data_fingerprint_columns(string $table, array $row): array
{
    $automatic = demo_data_tables()[$table]['automatic'];
    $columns   = [];
    foreach (array_keys($row) as $column) {
        $column = (string) $column;
        if (in_array($column, $automatic, true) === true) {
            continue;
        }
        if (preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1) {
            throw new \RuntimeException('The column name "' . $column . '" in ' . $table . ' cannot be recorded safely.');
        }
        $columns[] = $column;
    }
    return $columns;
}

/**
 * Turn a stored column list back into names, or null if it has been damaged.
 *
 * Refuses anything that demo_data_fingerprint_columns() could not have
 * written: an empty list, an empty or unusual name, or a name listed twice.
 *
 * @param string $stored tblDemoDataRegister.fingerprintColumns.
 *
 * @return list<string>|null
 */
function demo_data_recorded_columns(string $stored): ?array
{
    $columns = explode(',', $stored);
    foreach ($columns as $column) {
        if (preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1) {
            return null;
        }
    }
    return count(array_unique($columns)) === count($columns) ? $columns : null;
}

/**
 * The fingerprint of a row: SHA-256, as 64 lowercase hex characters.
 *
 * The text that is hashed is the table name, the row number, then one line
 * per column in the order of $columns, in one of three forms:
 *     columnName=NULL                       for an empty (NULL) value
 *     columnName=D<16 hex characters>       for a FLOAT or DOUBLE value: the
 *                                            value's exact 8 bytes (see
 *                                            demo_data_read_row()), independent
 *                                            of PHP's own `precision` and
 *                                            `serialize_precision` settings
 *     columnName=S<length in bytes>:<value> for a whole number or text
 * Any other kind of value throws a LogicException rather than being guessed
 * at; demo_data_read_row() only ever hands back null, float, int or string
 * for these tables, so this should never be reached.
 *
 * Why "D" exists at all: turning a FLOAT or DOUBLE into text with (string)
 * uses PHP's `precision` setting, which defaults to 14 significant digits.
 * 1.0 and 1.000000000000001 both become the text "1", so two different
 * DOUBLE values that ARE different, bit for bit, would fingerprint the same.
 * Round 5 of #498 (15 September 2026, Codex) found and confirmed this: on a
 * DOUBLE column, Wipe deleted a row that had genuinely changed. "D" packs the
 * value's raw bytes instead of its decimal text, so it cannot lose precision
 * that way.
 *
 * The length in front of an "S" value is what makes the text unambiguous:
 * without it, a title ending in "\nbody=S3:abc" could be made to produce the
 * same text as a different title and body. It also means a value that is
 * literally the word NULL can never be confused with an empty one. The
 * column names are part of the text, so a stored column list with a name
 * removed no longer matches.
 *
 * This is not a secret-keyed hash, and does not need to be: its job is to
 * notice a change or a replacement, not to resist somebody with direct access
 * to the database, who could change the list itself anyway.
 *
 * @param string               $table   One of demo_data_tables().
 * @param int                  $rowId   Identity number.
 * @param array<string, mixed> $row     The row, as returned by demo_data_read_row().
 * @param list<string>         $columns The columns to cover, in order.
 *
 * @return string
 */
function demo_data_fingerprint(string $table, int $rowId, array $row, array $columns): string
{
    $text = $table . "\n" . $rowId . "\n";
    foreach ($columns as $column) {
        if (array_key_exists($column, $row) === false) {
            throw new \LogicException('Cannot fingerprint ' . $table . ': the column ' . $column . ' was not read.');
        }
        $value = $row[$column];
        if ($value === null) {
            $line = 'NULL';
        } elseif (is_float($value) === true) {
            // pack('E', ...) is IEEE 754 double, big-endian, on every machine
            // PHP runs on — a fixed byte order, not "whatever this machine's
            // native order happens to be" — so the same value always produces
            // the same 16 hex characters regardless of host.
            $line = 'D' . bin2hex(pack('E', $value));
        } elseif (is_int($value) === true || is_string($value) === true) {
            $line = 'S' . strlen((string) $value) . ':' . (string) $value;
        } else {
            throw new \LogicException('Cannot fingerprint ' . $table . ': the column ' . $column . ' holds a ' . get_debug_type($value) . '.');
        }
        $text .= $column . '=' . $line . "\n";
    }
    return hash('sha256', $text);
}

/**
 * Why this row is no longer exactly what Load created, or null if it still is.
 *
 * Four tests, each of which leaves the row in place when it fails:
 *   1. It still belongs to the organisation recorded. (The organisation is
 *      inside the fingerprint as well; it is compared on its own so the rule
 *      reads plainly, and so a hand-edited list entry can never pass.)
 *   2. Every column fingerprinted at Load still exists. If an upgrade removed
 *      one, the fingerprint cannot be worked out again.
 *   3. The fingerprint over those columns matches.
 *   4. Every column that is NOT in the stored list (so added by an upgrade
 *      since Load), other than one the database changes by itself, is still
 *      empty (NULL). Anything in it may have been put there by a person.
 * The answer is a short phrase for the administrator. It cannot say WHICH
 * fingerprinted column changed, because only the hash was kept.
 *
 * @param string               $table   One of demo_data_tables().
 * @param array<string, mixed> $entry   The tblDemoDataRegister row.
 * @param list<string>         $columns The stored column list, already checked.
 * @param array<string, mixed> $row     The current demo-table row, every column.
 *
 * @return string|null
 */
function demo_data_change_reason(string $table, array $entry, array $columns, array $row): ?string
{
    $map        = demo_data_tables()[$table];
    $siteColumn = $map['site'];
    $siteNow    = ($siteColumn === null || isset($row[$siteColumn]) === false) ? null : (int) $row[$siteColumn];
    $siteThen   = $entry['siteID'] === null ? null : (int) $entry['siteID'];
    if ($siteNow !== $siteThen) {
        return 'it no longer belongs to the organisation it was loaded into';
    }

    $removed = array_values(array_diff($columns, array_keys($row)));
    if ($removed !== []) {
        return 'the database no longer has the column ' . implode(', ', $removed)
            . ' it had when loaded, so its fingerprint cannot be checked';
    }

    if (hash_equals((string) $entry['fingerprint'], demo_data_fingerprint($table, (int) $entry['rowID'], $row, $columns)) === false) {
        return 'its content is no longer exactly what was loaded';
    }

    $filledSince = [];
    foreach ($row as $column => $value) {
        $column = (string) $column;
        if ($value !== null
            && in_array($column, $columns, true) === false
            && in_array($column, $map['automatic'], true) === false
        ) {
            $filledSince[] = $column;
        }
    }
    if ($filledSince !== []) {
        return 'the column ' . implode(', ', $filledSince)
            . ' was added by an upgrade after loading and holds a value, which may have been put there by a person';
    }

    return null;
}

/**
 * Create one demo row and record it in tblDemoDataRegister.
 *
 * The new row is READ BACK, and every column the caller wrote must hold the
 * value it meant to write. That proves the new number really is a row in
 * $table carrying demo content, so a mistake that inserted into one table but
 * named another can never put a real row on the list. The organisation, the
 * column list and the fingerprint recorded are taken from what was read back,
 * not from what was intended, so they describe the row exactly as the
 * database stored it, including every column the INSERT did not name.
 *
 * @param \mysqli              $db       Connection, inside the load's transaction.
 * @param string               $table    One of demo_data_tables().
 * @param string               $sql      The INSERT for that table.
 * @param string               $types    bind_param type letters.
 * @param array<int, mixed>    $params   Values.
 * @param array<string, mixed> $expected Column => value the new row must now hold.
 *
 * @return int The identity number the database gave the new row.
 */
function demo_data_insert(\mysqli $db, string $table, string $sql, string $types, array $params, array $expected): int
{
    $map = demo_data_tables();
    if (isset($map[$table]) === false) {
        throw new \LogicException('Demo data may only be written to ' . implode(', ', array_keys($map)) . '.');
    }

    $stmt  = demo_data_query($db, $sql, $types, $params);
    $newId = (int) $stmt->insert_id;
    $stmt->close();
    if ($newId <= 0) {
        throw new \RuntimeException('The database did not give the new ' . $table . ' row a number.');
    }

    $row = demo_data_read_row($db, $table, $newId, false);
    if ($row === null) {
        throw new \RuntimeException('The new ' . $table . ' row number ' . $newId . ' could not be read back.');
    }
    foreach ($expected as $column => $value) {
        if (array_key_exists($column, $row) === false || (string) $row[$column] !== (string) $value) {
            throw new \RuntimeException('The new ' . $table . ' row number ' . $newId . ' does not hold the demo value just written to ' . $column . '.');
        }
    }

    // Every column the new row has (less the automatic ones), stored with
    // the fingerprint so Wipe checks exactly these later.
    $siteColumn = $map[$table]['site'];
    $columns    = demo_data_fingerprint_columns($table, $row);
    demo_data_query(
        $db,
        'INSERT INTO tblDemoDataRegister (tableName, rowID, siteID, fingerprintColumns, fingerprint) VALUES (?, ?, ?, ?, ?)',
        'siiss',
        [$table, $newId, $siteColumn === null ? null : (int) $row[$siteColumn], implode(',', $columns), demo_data_fingerprint($table, $newId, $row, $columns)]
    )->close();

    return $newId;
}

/**
 * Load the demo data into one organisation, all or nothing.
 *
 * The content is the same as the old web/_sql/demo_data.sql: five people and
 * three announcements. Two things were deliberately NOT carried over. The old
 * file gave "Demo Pastor" an administrator role and the others volunteer or
 * user roles, through columns that never existed; a demo account is given no
 * administrator flag and no role at all, because an account with rights is a
 * risk even while switched off. And it hard-coded organisation 1; this loads
 * into the organisation the administrator is working in.
 *
 * @param \mysqli $db     Connection.
 * @param int     $siteId Organisation to load into.
 *
 * @return array{type: string, message: string, items: list<string>, log: string}
 */
function demo_data_load(\mysqli $db, int $siteId): array
{
    $people = [
        'pastor'    => 'Demo Pastor',
        'elder'     => 'Demo Elder',
        'deacon'    => 'Demo Deacon',
        'treasurer' => 'Demo Treasurer',
        'member'    => 'Demo Member',
    ];
    // title, start of the web address, body, pinned (1/0), written by
    $announcements = [
        ['[DEMO] Welcome to the portal', 'demo-welcome', 'This is a demo announcement. Real content goes here.', 1, 'pastor'],
        ['[DEMO] Sabbath school schedule', 'demo-sabbath-school', 'Sabbath school resumes at 9:30am. All welcome.', 0, 'pastor'],
        ['[DEMO] Community potluck this weekend', 'demo-potluck', 'Join us after the service for a community potluck.', 0, 'elder'],
    ];

    $db->begin_transaction();
    try {
        if (demo_data_switch_is_on($db, true) === false) {
            demo_data_rollback($db);
            return ['type' => 'warning', 'message' => 'Demo mode is switched off, so nothing was loaded.', 'items' => [], 'log' => 'DemoDataLoadRefused'];
        }

        $count = demo_data_query($db, 'SELECT COUNT(*) AS n FROM tblDemoDataRegister');
        $alreadyLoaded = (int) ($count->get_result()->fetch_assoc()['n'] ?? 0);
        $count->close();
        if ($alreadyLoaded > 0) {
            demo_data_rollback($db);
            return [
                'type'    => 'warning',
                'message' => 'Demo data is already loaded (' . $alreadyLoaded . ' rows are recorded). Wipe it before loading again. Nothing was changed.',
                'items'   => [],
                'log'     => 'DemoDataLoadRefused',
            ];
        }

        $site = demo_data_query($db, 'SELECT siteName FROM tblSites WHERE siteID = ? LIMIT 1', 'i', [$siteId]);
        $siteRow = $site->get_result()->fetch_assoc();
        $site->close();
        if (is_array($siteRow) === false) {
            demo_data_rollback($db);
            return ['type' => 'danger', 'message' => 'The organisation you are working in could not be found, so nothing was loaded.', 'items' => [], 'log' => 'DemoDataLoadRefused'];
        }

        // 🎲 A random part in every address. emailAddress is unique across the
        //    whole portal and a slug is unique within an organisation, so a
        //    fixed value could clash with a row that already exists. A clash
        //    would only make the load fail (there is no "update if it exists"
        //    any more), but it would block it for no good reason. This is NOT
        //    what protects Wipe; the fingerprint is.
        $token = bin2hex(random_bytes(4));

        $personIds = [];
        foreach ($people as $key => $name) {
            $email = 'demo.' . $key . '.' . $token . '@example.invalid';
            $personIds[$key] = demo_data_insert(
                $db,
                'tblUsers',
                'INSERT INTO tblUsers (fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, 0, 0, 0)',
                'ss',
                [$name, $email],
                ['fullName' => $name, 'emailAddress' => $email, 'isActive' => 0, 'isAdmin' => 0, 'isRootAdmin' => 0]
            );
            demo_data_insert(
                $db,
                'tblUserSites',
                'INSERT INTO tblUserSites (userID, siteID, isSiteAdmin, isSiteRootAdmin, isActive) VALUES (?, ?, 0, 0, 1)',
                'ii',
                [$personIds[$key], $siteId],
                ['userID' => $personIds[$key], 'siteID' => $siteId, 'isSiteAdmin' => 0, 'isSiteRootAdmin' => 0, 'isActive' => 1]
            );
        }

        foreach ($announcements as [$title, $slugStart, $body, $pinned, $writtenBy]) {
            $slug = $slugStart . '-' . $token;
            demo_data_insert(
                $db,
                'tblAnnouncements',
                "INSERT INTO tblAnnouncements (siteID, title, slug, body, priority, isPinned, isPublished, createdByID) VALUES (?, ?, ?, ?, 'normal', ?, 1, ?)",
                'isssii',
                [$siteId, $title, $slug, $body, $pinned, $personIds[$writtenBy]],
                [
                    'siteID' => $siteId, 'title' => $title, 'slug' => $slug, 'body' => $body, 'priority' => 'normal',
                    'isPinned' => $pinned, 'isPublished' => 1, 'createdByID' => $personIds[$writtenBy],
                ]
            );
        }

        $db->commit();
    } catch (\Throwable $problem) {
        demo_data_rollback($db);
        return [
            'type'    => 'danger',
            'message' => 'Loading failed part way, so everything it had done was undone. Nothing was changed. The database said: ' . $problem->getMessage(),
            'items'   => [],
            'log'     => 'DemoDataLoadFailed',
        ];
    }

    return [
        'type'    => 'success',
        'message' => sprintf(
            'Demo data loaded into %s: %d people and %d announcements.',
            (string) $siteRow['siteName'],
            count($people),
            count($announcements)
        ),
        'items'   => [],
        'log'     => 'DemoDataLoaded',
    ];
}

/**
 * Every foreign key the database declares that points at one of $parents.
 *
 * Read from information_schema at the moment of the wipe, so a table added
 * later is covered without changing this page. Names from information_schema
 * come from the database, not from anybody's input, but are still checked to
 * be plain names before being used, and anything this code cannot check
 * safely makes the wipe fail (and so change nothing).
 *
 * @param \mysqli      $db      Connection, inside the wipe's transaction.
 * @param list<string> $parents Demo tables.
 *
 * @return list<array{childTable: string, childColumn: string, parentTable: string}>
 */
function demo_data_foreign_keys(\mysqli $db, array $parents): array
{
    if ($parents === []) {
        return [];
    }
    $map = demo_data_tables();

    $links = demo_data_query(
        $db,
        'SELECT TABLE_NAME AS childTable, COLUMN_NAME AS childColumn, '
            . 'REFERENCED_TABLE_NAME AS parentTable, REFERENCED_COLUMN_NAME AS parentColumn '
            . 'FROM information_schema.KEY_COLUMN_USAGE '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_SCHEMA = DATABASE() '
            . 'AND REFERENCED_TABLE_NAME IN (' . implode(', ', array_fill(0, count($parents), '?')) . ') '
            . 'ORDER BY TABLE_NAME, COLUMN_NAME',
        str_repeat('s', count($parents)),
        $parents
    );
    $rows = $links->get_result()->fetch_all(MYSQLI_ASSOC);
    $links->close();

    $found = [];
    foreach ($rows as $fk) {
        $child        = (string) $fk['childTable'];
        $childColumn  = (string) $fk['childColumn'];
        $parent       = (string) $fk['parentTable'];
        $parentColumn = (string) $fk['parentColumn'];

        foreach ([$child, $childColumn, $parent, $parentColumn] as $name) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
                throw new \RuntimeException('A foreign key has a name this page cannot check safely: ' . $name);
            }
        }
        if (isset($map[$parent]) === false || $parentColumn !== $map[$parent]['id']) {
            throw new \RuntimeException(sprintf(
                '%s.%s points at %s.%s rather than at its identity number, so this page cannot check it safely.',
                $child,
                $childColumn,
                $parent,
                $parentColumn
            ));
        }
        $found[] = ['childTable' => $child, 'childColumn' => $childColumn, 'parentTable' => $parent];
    }

    return $found;
}

/**
 * Which demo rows must stay, because they changed or are linked to one that did.
 *
 * Builds the links between the demo rows still present, through every
 * declared foreign key between two demo tables (a membership points at its
 * person; an announcement points at its author), using the values the rows
 * hold NOW, because those are what the database would act on during a delete.
 * Then keeps every row that can be reached from a changed row along those
 * links, in either direction. See "Demo rows that point at each other are
 * kept or removed TOGETHER" in the file header for why.
 *
 * @param \mysqli                             $db      Connection, inside the wipe's transaction.
 * @param array<string, array<string, mixed>> $present Register entries still present, keyed "table:number".
 * @param array<string, string>               $changed Rows that changed: key => why.
 *
 * @return array<string, string> Key => 'changed' or 'linked'.
 */
function demo_data_rows_to_keep(\mysqli $db, array $present, array $changed): array
{
    if ($changed === []) {
        return [];
    }
    $map = demo_data_tables();

    $idsByTable = [];
    foreach ($present as $entry) {
        $idsByTable[(string) $entry['tableName']][] = (int) $entry['rowID'];
    }

    $neighbours = [];
    foreach (demo_data_foreign_keys($db, array_keys($idsByTable)) as $fk) {
        $child  = $fk['childTable'];
        $parent = $fk['parentTable'];
        if (isset($idsByTable[$child]) === false) {
            continue; // Not a link between two demo rows; demo_data_find_attached() handles it.
        }
        $childIds  = $idsByTable[$child];
        $parentIds = $idsByTable[$parent];
        $stmt = demo_data_query(
            $db,
            'SELECT `' . $map[$child]['id'] . '` AS childId, `' . $fk['childColumn'] . '` AS parentId FROM `' . $child . '` '
                . 'WHERE `' . $map[$child]['id'] . '` IN (' . implode(', ', array_fill(0, count($childIds), '?')) . ') '
                . 'AND `' . $fk['childColumn'] . '` IN (' . implode(', ', array_fill(0, count($parentIds), '?')) . ')',
            str_repeat('i', count($childIds) + count($parentIds)),
            array_merge($childIds, $parentIds)
        );
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $link) {
            $from = $child . ':' . (int) $link['childId'];
            $to   = $parent . ':' . (int) $link['parentId'];
            $neighbours[$from][] = $to;
            $neighbours[$to][]   = $from;
        }
        $stmt->close();
    }

    $keep  = [];
    $queue = [];
    foreach (array_keys($changed) as $key) {
        $keep[$key] = 'changed';
        $queue[]    = $key;
    }
    while ($queue !== []) {
        $key = array_shift($queue);
        foreach ($neighbours[$key] ?? [] as $next) {
            if (isset($keep[$next]) === false) {
                $keep[$next] = 'linked';
                $queue[]     = $next;
            }
        }
    }

    return $keep;
}

/**
 * Find rows that point at any demo row about to be deleted, other than rows
 * that are themselves about to be deleted.
 *
 * A demo row that is being left in place counts as real here, because it is
 * not being deleted and the database would act on it just the same.
 *
 * @param \mysqli                             $db       Connection, inside the wipe's transaction.
 * @param array<string, array<string, mixed>> $toDelete Register entries about to be deleted.
 *
 * @return list<string> One plain-English line per link that has other rows on it.
 */
function demo_data_find_attached(\mysqli $db, array $toDelete): array
{
    $map = demo_data_tables();
    $idsByTable = [];
    foreach ($toDelete as $entry) {
        $idsByTable[(string) $entry['tableName']][] = (int) $entry['rowID'];
    }

    $found = [];
    foreach (demo_data_foreign_keys($db, array_keys($idsByTable)) as $fk) {
        $child       = $fk['childTable'];
        $childColumn = $fk['childColumn'];
        $parent      = $fk['parentTable'];

        $parentIds = $idsByTable[$parent];
        $where  = '`' . $childColumn . '` IN (' . implode(', ', array_fill(0, count($parentIds), '?')) . ')';
        $types  = str_repeat('i', count($parentIds));
        $params = $parentIds;
        if (isset($idsByTable[$child]) === true) {
            $where  .= ' AND `' . $map[$child]['id'] . '` NOT IN (' . implode(', ', array_fill(0, count($idsByTable[$child]), '?')) . ')';
            $types  .= str_repeat('i', count($idsByTable[$child]));
            $params  = array_merge($params, $idsByTable[$child]);
        }

        $countStmt = demo_data_query($db, 'SELECT COUNT(*) AS n FROM `' . $child . '` WHERE ' . $where, $types, $params);
        $realRows  = (int) ($countStmt->get_result()->fetch_assoc()['n'] ?? 0);
        $countStmt->close();
        if ($realRows === 0) {
            continue;
        }

        // 🔢 Name the rows where the table has a single-column identity
        //    number, so the administrator can go straight to them.
        $pkStmt = demo_data_query(
            $db,
            "SELECT COLUMN_NAME AS pk FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY'",
            's',
            [$child]
        );
        $pkRows = $pkStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $pkStmt->close();

        $which = '';
        if (count($pkRows) === 1 && preg_match('/^[A-Za-z0-9_]+$/', (string) $pkRows[0]['pk']) === 1) {
            $pk = (string) $pkRows[0]['pk'];
            $sample = demo_data_query(
                $db,
                'SELECT `' . $pk . '` AS rowKey, `' . $childColumn . '` AS pointsAt FROM `' . $child . '` WHERE ' . $where . ' ORDER BY `' . $pk . '` LIMIT 10',
                $types,
                $params
            );
            $parts = [];
            foreach ($sample->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $parts[] = $pk . ' ' . $row['rowKey'] . ' (points at ' . $parent . ' number ' . $row['pointsAt'] . ')';
            }
            $sample->close();
            $which = ': ' . implode(', ', $parts) . ($realRows > count($parts) ? ', and more' : '');
        }

        $found[] = sprintf(
            '%s: %d %s linked through the column %s to demo rows in %s that would be deleted%s',
            $child,
            $realRows,
            $realRows === 1 ? 'row that is not being deleted is' : 'rows that are not being deleted are',
            $childColumn,
            $parent,
            $which
        );
    }

    return $found;
}

/**
 * Wipe the recorded rows that are still exactly as loaded, all or nothing.
 *
 * @param \mysqli $db Connection.
 *
 * @return array{type: string, message: string, items: list<string>, log: string}
 */
function demo_data_wipe(\mysqli $db): array
{
    $map         = demo_data_tables();
    $keep        = [];
    $toDelete    = [];
    $alreadyGone = [];

    $db->begin_transaction();
    try {
        if (demo_data_switch_is_on($db, true) === false) {
            demo_data_rollback($db);
            return ['type' => 'warning', 'message' => 'Demo mode is switched off, so nothing was wiped.', 'items' => [], 'log' => 'DemoDataWipeRefused'];
        }

        // 📋 Newest first: a row is always created after anything it points
        //    at (a membership after its person), so deleting in reverse order
        //    removes the pointing row before the row it points at.
        $list = demo_data_query($db, 'SELECT registerID, tableName, rowID, siteID, fingerprintColumns, fingerprint FROM tblDemoDataRegister ORDER BY registerID DESC FOR UPDATE');
        $registered = $list->get_result()->fetch_all(MYSQLI_ASSOC);
        $list->close();

        if ($registered === []) {
            demo_data_rollback($db);
            return [
                'type'    => 'info',
                'message' => 'There is no demo data to wipe: this page has no record of loading any. Nothing was changed.',
                'items'   => [],
                'log'     => 'DemoDataWipeNothing',
            ];
        }

        // 1️⃣ Every recorded row: is it still there, and still exactly as
        //    loaded? Read FOR UPDATE, which also stops anybody changing it, or
        //    linking a new row to it, until this transaction ends (the
        //    database must briefly lock a row to let something point at it).
        //    Every row is judged BEFORE anything is deleted, so no delete in
        //    this wipe can change what a later check sees.
        $present     = [];
        $changed     = [];
        $unchecked   = [];
        $alreadyGone = [];
        $problems    = [];
        foreach ($registered as $entry) {
            $table = (string) $entry['tableName'];
            if (isset($map[$table]) === false) {
                $problems[] = 'The list names a row in ' . $table . ', which is not a table this page creates.';
                continue;
            }
            // An entry migration 194 upgraded from an earlier development
            // draft has no complete fingerprint, so there is nothing to prove
            // its row is still demo data. It is kept, never deleted; see
            // "LIST ENTRIES FROM AN EARLIER DEVELOPMENT DRAFT" in the header.
            $incomplete = (string) $entry['fingerprintColumns'] === demo_data_incomplete_marker();
            $columns    = [];
            if ($incomplete === false) {
                $columns = demo_data_recorded_columns((string) $entry['fingerprintColumns']);
                if ($columns === null) {
                    $problems[] = 'The list entry for ' . $table . ' number ' . (int) $entry['rowID'] . ' has a damaged list of columns.';
                    continue;
                }
            }
            $row = demo_data_read_row($db, $table, (int) $entry['rowID'], true);
            if ($row === null) {
                // Nothing left to delete, so it is safe to cross off even
                // when its entry could not have been checked.
                $alreadyGone[] = $entry;
                continue;
            }
            $key = $table . ':' . (int) $entry['rowID'];
            $present[$key] = $entry + ['current' => $row];
            if ($incomplete === true) {
                $unchecked[$key] = true;
                $changed[$key]   = 'it was recorded by an earlier development version of this page, which kept no complete fingerprint, so nothing can prove it is still exactly what was loaded';
                continue;
            }
            $reason = demo_data_change_reason($table, $entry, $columns, $row);
            if ($reason !== null) {
                $changed[$key] = $reason;
            }
        }
        if ($problems !== []) {
            demo_data_rollback($db);
            return [
                'type'    => 'danger',
                'message' => 'Wipe refused, so nothing was deleted. The list of demo rows has been altered:',
                'items'   => $problems,
                'log'     => 'DemoDataWipeRefused',
            ];
        }

        // 2️⃣ Changed rows, and every demo row linked to one, stay.
        $keep     = demo_data_rows_to_keep($db, $present, $changed);
        $toDelete = array_diff_key($present, $keep);

        $keptItems = [];
        foreach ($keep as $key => $why) {
            $entry = $present[$key];
            $table = (string) $entry['tableName'];
            $label = (string) ($entry['current'][$map[$table]['label']] ?? '');
            $keptItems[] = sprintf(
                '%s: %s number %d in %s%s, %s.%s',
                $why === 'changed'
                    ? (isset($unchecked[$key]) === true ? 'Cannot be checked, left in place' : 'Changed since it was loaded, left in place')
                    : 'Left in place because it is linked to a row that changed or cannot be checked',
                $map[$table]['noun'],
                (int) $entry['rowID'],
                $table,
                $map[$table]['site'] !== null ? ' (organisation number ' . (int) ($entry['current'][$map[$table]['site']] ?? 0) . ')' : '',
                sprintf($map[$table]['labelFormat'], mb_strimwidth($label, 0, 80, '...', 'UTF-8')),
                $why === 'changed' ? ' Why: ' . $changed[$key] . '.' : ''
            );
        }

        // 3️⃣ Rows not being deleted that are linked to rows that would be:
        //    refuse and list them.
        $attached = demo_data_find_attached($db, $toDelete);
        if ($attached !== []) {
            demo_data_rollback($db);
            return [
                'type'    => 'danger',
                'message' => 'Wipe refused, so nothing was deleted. Records that are not being deleted are linked to demo rows that would be, and deleting those demo rows would make the database delete or change them too. Remove or change these records, then wipe again:',
                'items'   => array_merge($attached, $keptItems),
                'log'     => 'DemoDataWipeRefused',
            ];
        }

        // 4️⃣ Delete exactly the rows that passed, each matched on its number
        //    and, where the table has one, the recorded organisation. Each was
        //    locked in step 1, so it cannot have changed since it was checked.
        foreach ($toDelete as $entry) {
            $table     = (string) $entry['tableName'];
            $siteCol   = $map[$table]['site'];
            $sql       = 'DELETE FROM `' . $table . '` WHERE `' . $map[$table]['id'] . '` = ?';
            $types     = 'i';
            $params    = [(int) $entry['rowID']];
            if ($siteCol !== null) {
                $sql      .= ' AND `' . $siteCol . '` = ?';
                $types    .= 'i';
                $params[]  = (int) $entry['siteID'];
            }
            $delete  = demo_data_query($db, $sql, $types, $params);
            $deleted = $delete->affected_rows;
            $delete->close();
            if ($deleted !== 1) {
                throw new \RuntimeException(sprintf('Expected to delete one row from %s (number %d) but %d were affected.', $table, (int) $entry['rowID'], $deleted));
            }
        }
        // Rows left in place keep their list entry, so they are still shown
        // and a later wipe looks at them again.
        foreach (array_merge(array_values($toDelete), $alreadyGone) as $entry) {
            demo_data_query($db, 'DELETE FROM tblDemoDataRegister WHERE registerID = ?', 'i', [(int) $entry['registerID']])->close();
        }

        $db->commit();
    } catch (\Throwable $problem) {
        demo_data_rollback($db);
        return [
            'type'    => 'danger',
            'message' => 'Wipe failed part way, so everything it had done was undone. Nothing was deleted. The database said: ' . $problem->getMessage(),
            'items'   => [],
            'log'     => 'DemoDataWipeFailed',
        ];
    }

    // Counts are written out in words that fit the number, so a single row
    // reads "1 recorded row was", not "1 recorded rows were".
    $rowsPhrase = static function (int $n): string {
        return $n === 1 ? '1 recorded row' : $n . ' recorded rows';
    };
    $goneCount = count($alreadyGone);
    $gone = '';
    if ($goneCount === 1) {
        $gone = ' 1 recorded row had already been removed by somebody else, and was crossed off the list.';
    } elseif ($goneCount > 1) {
        $gone = sprintf(' %d recorded rows had already been removed by somebody else, and were crossed off the list.', $goneCount);
    }
    if ($keep === []) {
        return [
            'type'    => 'success',
            'message' => 'Demo data wiped: ' . $rowsPhrase(count($toDelete)) . ' deleted. Nothing else was touched.' . $gone,
            'items'   => [],
            'log'     => 'DemoDataWiped',
        ];
    }

    $keepCount = count($keep);
    $keptText  = $keepCount === 1
        ? '1 was left in place, because it is no longer exactly what was loaded, or is linked to a row that is not. It may now be real, so nothing about it was changed. It stays on this page\'s list, and Load stays unavailable until it is dealt with. Check it: if it is demo data after all, delete it by hand and press Wipe again.'
        : $keepCount . ' were left in place, because they are no longer exactly what was loaded, or are linked to a row that is not. They may now be real, so nothing about them was changed. They stay on this page\'s list, and Load stays unavailable until they are dealt with. Check each one: if it is demo data after all, delete it by hand and press Wipe again.';

    return [
        'type'    => 'warning',
        'message' => 'Demo data partly wiped: ' . $rowsPhrase(count($toDelete)) . ' deleted. ' . $keptText . $gone,
        'items'   => $keptItems,
        'log'     => 'DemoDataWipedPartly',
    ];
}

// =============================================================================
// 🛠️ Action handler
// =============================================================================

$db        = App::db();
$flash     = '';
$flashType = 'info';
$flashItems = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (Auth::verifyCsrf((string) ($_POST['csrf_token'] ?? '')) === false) {
        $flash     = 'The form had expired, so nothing was changed. Please try again.';
        $flashType = 'danger';
    } elseif ($action === 'load' || $action === 'wipe') {
        $result = $action === 'load'
            ? demo_data_load($db, Site::id())
            : demo_data_wipe($db);
        $flash      = $result['message'];
        $flashType  = $result['type'];
        $flashItems = $result['items'];

        // 📝 Logged AFTER the transaction has ended. Logger writes through the
        //    same connection, so a line written inside the transaction would
        //    vanish with a rollback, which is exactly when it matters most.
        try {
            Logger::activity(
                $result['log'],
                $result['message'] . ($result['items'] !== [] ? ' ' . implode(' | ', $result['items']) : ''),
                isset($_SESSION['user_id']) === true ? (int) $_SESSION['user_id'] : null
            );
        } catch (\Throwable $problem) {
            error_log('[WebMS-Intra] Demo data: could not write the activity log: ' . $problem->getMessage());
        }
    }
}

// =============================================================================
// 🔎 Current state, for display
// =============================================================================

$demoEnabled    = false;
$registerReady  = true;
$recorded       = [];
$loadedAt       = '';
$loadedInto     = [];
try {
    $demoEnabled = demo_data_switch_is_on($db, false);

    $state = demo_data_query($db, 'SELECT tableName, COUNT(*) AS n, MIN(createdAt) AS loadedAt FROM tblDemoDataRegister GROUP BY tableName');
    foreach ($state->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $recorded[(string) $row['tableName']] = (int) $row['n'];
        $loadedAt = (string) $row['loadedAt'];
    }
    $state->close();

    if ($recorded !== []) {
        // The organisation recorded at load, not whatever a row points at now.
        $where = demo_data_query(
            $db,
            'SELECT DISTINCT s.siteName FROM tblDemoDataRegister r JOIN tblSites s ON s.siteID = r.siteID ORDER BY s.siteName'
        );
        foreach ($where->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $loadedInto[] = (string) $row['siteName'];
        }
        $where->close();
    }
} catch (\Throwable $problem) {
    // Most likely the database has not been upgraded to include
    // tblDemoDataRegister (migration 194). Load and Wipe would both fail
    // safely inside their transactions, but say so plainly instead.
    $registerReady = false;
    error_log('[WebMS-Intra] Demo data: could not read the current state: ' . $problem->getMessage());
}

$currentSite = Site::current();
$currentSiteName = is_array($currentSite) === true ? (string) ($currentSite['siteName'] ?? '') : '';

$pageTitle   = 'Demo Data';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Maintenance' => '/admin/maintenance', 'Demo Data' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-flask me-2"></i>Demo Data</h1>
        <p class="text-secondary mb-0">Put a few made-up people and announcements into the portal for training, then take exactly those out again.</p>
    </div>
    <a href="/admin/maintenance" class="btn btn-outline-secondary btn-sm">&larr; Maintenance</a>
</div>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>" role="status">
        <?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?>
        <?php if ($flashItems !== []): ?>
            <ul class="mb-0 mt-2">
                <?php foreach ($flashItems as $item): ?>
                    <li><?php echo htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($registerReady === false): ?>
    <div class="alert alert-danger">
        This page could not read its list of demo rows. The database probably
        needs upgrading first (Admin &rarr; Upgrade). Load and Wipe will not
        change anything until it has been.
    </div>
<?php endif; ?>

<?php if ($demoEnabled === false): ?>
    <div class="alert alert-warning">
        <p class="mb-2">
            Demo mode is switched off, so this page will not load or wipe anything.
        </p>
        <p class="mb-0">
            To switch it on, a global administrator sets the portal-wide setting
            <code>portal.demo_mode.enabled</code> to <code>1</code> in
            <a href="/admin/settings">Settings</a>. A value saved for a single
            organisation is ignored on purpose, because this page acts on the
            database every organisation shares.
        </p>
    </div>
<?php else: ?>
    <div class="card mb-3 border-warning">
        <div class="card-body">
            <h2 class="h5"><i class="fa-solid fa-triangle-exclamation me-1 text-warning"></i>This works on the live database</h2>
            <p class="mb-2">
                Demo rows go into the same tables as your real data. Wipe removes
                only the rows this page recorded when it created them, and only
                while each is still exactly as it was created. It refuses rather
                than touch anything else. Even so, take a backup first.
            </p>
            <a href="/admin/maintenance/backup" class="btn btn-outline-primary btn-sm">Take a backup first</a>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h5">What is loaded now</h2>
            <?php if ($recorded === []): ?>
                <p class="mb-0">No demo data is recorded as loaded.</p>
            <?php else: ?>
                <p class="mb-2">
                    Loaded <?php echo htmlspecialchars($loadedAt, ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($loadedInto !== []): ?>
                        into <?php echo htmlspecialchars(implode(', ', $loadedInto), ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>:
                </p>
                <ul class="mb-0">
                    <li><?php echo (int) ($recorded['tblUsers'] ?? 0); ?> made-up people</li>
                    <li><?php echo (int) ($recorded['tblUserSites'] ?? 0); ?> memberships of an organisation</li>
                    <li><?php echo (int) ($recorded['tblAnnouncements'] ?? 0); ?> announcements</li>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h5">Load demo data</h2>
                    <ul class="small">
                        <li>
                            Adds five made-up people (Demo Pastor, Demo Elder,
                            Demo Deacon, Demo Treasurer and Demo Member) as
                            members of
                            <strong><?php echo htmlspecialchars($currentSiteName !== '' ? $currentSiteName : 'the organisation you are working in', ENT_QUOTES, 'UTF-8'); ?></strong>,
                            and three announcements marked [DEMO] there.
                        </li>
                        <li>
                            Every row gets a new number from the database. Nothing
                            already in the portal is overwritten, changed or linked to.
                        </li>
                        <li>
                            For each row it records the organisation and a
                            fingerprint of every column, so Wipe can later tell
                            whether the row is still exactly what was loaded.
                        </li>
                        <li>
                            The made-up people cannot sign in. Their accounts are
                            switched off, they have no password or linked sign-in,
                            and their email addresses end in <code>.invalid</code>,
                            which can never receive mail.
                        </li>
                        <li>
                            All or nothing: if any part fails, everything it did is
                            undone. It will not load a second time while anything is
                            still on the list.
                        </li>
                    </ul>
                    <form method="post" data-confirm="Load demo data into the live database?">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="load">
                        <button type="submit" class="btn btn-primary"<?php echo $recorded !== [] ? ' disabled' : ''; ?>>Load demo data</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100 border-danger">
                <div class="card-body">
                    <h2 class="h5 text-danger">Wipe demo data</h2>
                    <ul class="small">
                        <li>
                            Deletes only the rows this page recorded when it created
                            them, and only if each is still in the same organisation
                            with exactly the same content. It never deletes by a
                            range of numbers or by a single value.
                        </li>
                        <li>
                            A row that has changed since it was loaded, for example
                            an announcement rewritten into a real notice, is left in
                            place and listed so you can decide. Demo rows linked to
                            it (such as its author) are left in place with it.
                        </li>
                        <li>
                            Any change counts, in any part of the row: a demo person
                            given an address or a biography, or a demo announcement
                            deleted on the Announcements page. So use Wipe, rather
                            than tidying demo rows away by hand first.
                        </li>
                        <li>
                            If any other record is linked to demo data that would be
                            deleted, for example a task assigned to a demo person,
                            it refuses and lists those records. Otherwise the
                            database would delete or change them along with the
                            demo rows.
                        </li>
                        <li>
                            It cannot see every link. Some records keep a person's
                            number in an ordinary column the database does not know
                            is a link, for example who paid an expense claim. Such a
                            record is not deleted or changed, but after the wipe it
                            points at a number that no longer belongs to anybody, so
                            it may show no name.
                        </li>
                        <li>All or nothing: if any part fails, nothing is deleted.</li>
                    </ul>
                    <form method="post" data-confirm="Delete the demo rows this page recorded that are still exactly as loaded? Nothing else will be deleted." data-confirm-destructive="true">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="wipe">
                        <button type="submit" class="btn btn-outline-danger">Wipe demo data</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <h2 class="h5">Demo rows from an older version of this page</h2>
        <p class="small mb-2">
            Before 13 September 2026 this page gave demo people the fixed account
            numbers 9000 to 9004, with email addresses that had no random part,
            such as <code>demo.pastor@example.invalid</code>, and announcements
            with web addresses such as <code>demo-welcome</code>. That version
            could not actually load on this database, so such rows are unlikely
            to exist.
        </p>
        <p class="small mb-0">
            If you do find any, Wipe will never touch them, because they are not
            on this page's list, and a number alone cannot prove a row is demo
            data. Check each one, then remove it by hand in
            <a href="/admin/users">Users</a> or Announcements.
        </p>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
