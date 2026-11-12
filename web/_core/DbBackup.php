<?php
// Path: _core/DbBackup.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — Database Backup & Restore 📦
 * -----------------------------------------------------------------------------
 * Takes a copy ("snapshot") of every `tbl*` table and puts one back. Used by:
 *
 *   • _install/upgrade.php — takes a snapshot BEFORE running migrations, so the
 *     administrator has a way back if a migration misbehaves.
 *   • admin/maintenance/backup — list, inspect, restore and delete snapshots.
 *   • cron/_backup-freshness.php and cron/_health-probes.php — report how old
 *     the newest FINISHED snapshot is.
 *
 * WHAT A SNAPSHOT LOOKS LIKE ON DISK ("form 2", since #577 / #579)
 *
 *   web/_backups/upgrade-YYYYMMDD-HHMMSS/
 *     ├── _manifest.json     written LAST; a snapshot without one is unfinished
 *     ├── tblUsers.jsonl     one table per file, one row per line
 *     └── ...
 *
 *   Each table file is three kinds of line (described, property by property, in
 *   _core/data/backup-table-file.schema.json; the manifest is described in
 *   _core/data/backup-manifest.schema.json):
 *
 *     {"format":2,"table":"tblX","columns":["a","b"],"base64":["b"]}   first
 *     ["1","aGVsbG8="]                                                  one per row
 *     {"end":true,"rows":1}                                             last
 *
 * WHY THIS FORM, AND WHAT IT REPLACED
 *   Until October 2026 each table was ONE pretty-printed JSON document
 *   (`tblX.json`, "form 1"). That had two faults, both found by running it:
 *
 *   1. JSON can only hold text. An imported calendar event keeps 32 raw bytes
 *      in a BINARY column, so the first imported event made every snapshot
 *      fail, and with it every upgrade (#577). Now a column that holds bytes is
 *      written as base64 (a way of writing any bytes using only letters and
 *      digits) and the first line of the file says which columns those are.
 *   2. One document means the whole table in memory at once, twice over. A
 *      200,000-row activity log needed 434 MB to save and 533 MB to put back;
 *      a shared host typically allows 128 MB (#579). One row per line means a
 *      row is read, written and forgotten, so memory no longer grows with the
 *      table.
 *
 *   The file ending changed from .json to .jsonl ON PURPOSE. An older release
 *   given a new snapshot looks for `tblX.json`, does not find it, and refuses
 *   before touching anything. Had the ending stayed the same it would have
 *   tried to read a form it does not understand.
 *
 *   Form 1 snapshots still restore (see rows()). They cost what they always
 *   cost in memory, because one JSON document cannot be read in pieces.
 *
 * WHAT THIS CANNOT DO
 *   • Carry a BIT or FLOAT column faithfully. The database hands a FLOAT over
 *     rounded to six digits and a BIT as digits it then refuses to take back.
 *     Neither is on the lists below, so a snapshot of a table holding one is
 *     REFUSED rather than taken wrongly. None exists in the schema today;
 *     tools/audit-checks/check_backup_column_types.py fails if one is added.
 *   • Hold one single very large row cheaply. A row is still held whole,
 *     several times over, while it is turned into a line of text and back:
 *     about five times its own size to save and about four to put back
 *     (measured on 12 October 2026, MySQL 8.4.11, PHP 8.5: a row of 24 MB of
 *     bytes peaked at 122 MB saving and 100 MB going back; one of 48 MB at
 *     242 MB and 196 MB). Putting back used to cost six times, more than
 *     saving, so a row could be saved under a limit it could not go back
 *     under; see "HOW ROWS ARE GROUPED" in loadTable() for what changed.
 *     Putting ONE table back (restoreTable()) still costs about six times: it
 *     writes each row while still holding the line the row came from (a
 *     50 MB row peaked at 301 MB). Only a rehearsal proves that a particular
 *     snapshot fits a particular server.
 *   • Survive the web server ending the request. A restore that is cut off
 *     changes nothing (the database undoes it), but it has not happened either.
 *   • Undo anything in a table that is not InnoDB. The whole "all or nothing"
 *     promise rests on the database being able to undo, and only InnoDB
 *     tables can. A restore that would touch any other kind of table is
 *     refused before it starts (see notUndoableProblem()).
 *   • Notice SOME kinds of change the database makes WITHOUT telling anyone,
 *     when a column was changed after the snapshot was taken. No error, no
 *     warning and no note is raised for these, so nothing here can refuse
 *     them, and a restore that meets one says "success". The kinds found so
 *     far, each tried on MySQL 8.0.36 and 8.4.11 (see pinStrictness()), are
 *     listed below; there may be others.
 *       - a date-and-time put into a column that now keeps fewer digits of a
 *         second is rounded;
 *       - a number with a decimal part put into a column that is now a whole
 *         number is rounded;
 *       - bytes put into a fixed-width BINARY column that is now wider are
 *         padded with zero bytes;
 *       - a text with spaces at its end put into a column that is now CHAR
 *         loses those spaces;
 *       - a DOUBLE put into a column that is now FLOAT, or a BIGINT into one
 *         that is now DOUBLE, loses digits;
 *       - a two-digit number put into a column that is now YEAR becomes a
 *         four-digit year (26 becomes 2026);
 *       - and, only on a server with `explicit_defaults_for_timestamp`
 *         switched off (tried on 8.0.36 only), "no value" put into a
 *         TIMESTAMP column that may no longer be empty becomes the present
 *         time.
 *     (This used to say "three kinds". The second independent check of
 *     12 October 2026 found the other four.) The lasting answer is to record
 *     each column's type in the manifest and refuse when it has changed; that
 *     is not built.
 *   • Put back, whole, a snapshot holding a value that is NOT ON ITS COLUMN'S
 *     LIST OF CHOICES. Only a database set to accept anything stores such a
 *     value, and a restore refuses it (see pinStrictness()). Since 12 October
 *     2026 the portal's own connection refuses such a value too, so a new one
 *     can only arrive from before that date, from a server that refuses the
 *     portal's strict setting (the health page says so), or from something
 *     written outside the portal (a hosting panel, an import). snapshot() looks
 *     for these and says so at once, in its result and in the manifest, so
 *     that nobody first hears of it on the day the restore is needed; see
 *     unlistedWarning().
 *   • PROVE that a snapshot was read at one moment in time on an engine that
 *     will not say. The tables are read one after another inside one
 *     transaction that asks the database for a single consistent view, and the
 *     snapshot then asks whether that view was really given and writes the
 *     answer into the manifest as `one_moment` (#581). On MySQL the answer is
 *     real: the server warns when it ignores the request, and the restore
 *     repeats the answer to whoever is putting the snapshot back. On MariaDB
 *     11.4.13 the server says nothing in that case (measured), so there the
 *     answer is always "yes" and means only "nothing was said". Three things
 *     this does NOT do: refuse a snapshot over it (the snapshot is still worth
 *     keeping, and one taken while nobody is using the portal is unaffected);
 *     refuse a RESTORE over it (the fault shows up as a row pointing at a row
 *     that is not in the snapshot, which the restore already refuses by
 *     itself); or claim that an engine which says nothing was consistent.
 *   • Tested on MariaDB: it never has been.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.3.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/472
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/577
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/578
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/579
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use Generator;
use mysqli;
use RuntimeException;

class DbBackup
{
    /** The form this release writes. A manifest with no `format` is form 1. */
    public const FORMAT = 2;

    /**
     * 🧹 Columns a migration has REMOVED from the database, which a snapshot
     * taken before that migration still carries: table, then the columns.
     * Their saved values are NOT written back, and that is all that is done
     * with them.
     *
     * WHY A LIST, AND NOT "skip any column the database does not have". A
     * column missing from the database usually means the snapshot belongs to
     * a different release, and writing the row without it would lose data in
     * silence. So an unknown column still stops the restore, and only a
     * removal somebody has thought about, and written down here with why its
     * values may go, is let through.
     *
     *   tblEvents.eventTimezone (#604, migration 217): a second copy of an
     *   event's time zone that only the calendar importer wrote, always with
     *   the same value as `timezone`, so it never held anything `timezone`
     *   lacks. Without this entry every snapshot taken before that migration
     *   would be refused by a database that has run it (tried on 13 October
     *   2026: "Unknown column"). (Its value used to be moved into `timezone`
     *   where that was empty. For an event saved with its zone box emptied
     *   the old column held only its default, "Europe/London", so that was a
     *   guess; migration 217 no longer makes it either.)
     *
     * While the database still HAS the column (new files, migration not yet
     * run), nothing here applies and the value is written back as before.
     *
     * @var array<string, list<string>>
     */
    private const REMOVED_COLUMNS = [
        'tblEvents' => ['eventTimezone'],
        // #708, migration 235. Two free-text note columns that arrived with
        // the stocktake tables (4764c06) and that no version of the portal has
        // ever written to - established by TWO commands, both quoted in that
        // migration's header, where the second is not optional because the
        // first cannot see a clause on a continuation line. So in a snapshot
        // of a database the PORTAL filled,
        // both columns are NULL in every row and discarding their saved values
        // loses nothing.
        //
        // SAID EXACTLY, because this is the one place somebody looks to decide
        // whether discarding a saved value is safe: that is a claim about this
        // repository's history, NOT about any particular database. A note typed
        // in by hand in a database client WOULD be lost here, and the drop
        // itself cannot be undone either. No CUSTOMER's data is at risk,
        // because there is no live installation of this portal yet (the owner,
        // 2 November 2026); a developer's or a test database is the only place
        // such a note could be, and that has not been checked - it cannot be,
        // from the repository.
        //
        // They are listed here, not skipped silently, because an unknown column
        // still stops a restore and only a removal somebody has thought about
        // may pass.
        'tblAssetStocktakes'     => ['notes'],
        'tblAssetStocktakeItems' => ['notes'],
        // #712, migration 236. The live chat's throttle log used to hold the
        // viewer's network address raw in `senderIP`; the column is now
        // `senderFingerprint`. Listed here because WITHOUT IT a snapshot taken
        // before that migration BLOCKS A WHOLE-DATABASE RESTORE outright: the
        // unknown column reaches the INSERT and `loadTable()` throws, which
        // stops the entire restore rather than one table. Found by an
        // independent check; the rule was known and applied two entries above
        // and forgotten here.
        //
        // Discarding the saved values loses nothing anybody would miss: a
        // throttle row older than twice the chat's window can change no answer
        // the throttle gives, and these are raw addresses that must not come
        // back into a column whose name says it holds fingerprints.
        'tblLiveRateLimits'      => ['senderIP'],
    ];

    /**
     * Column types whose values travel as TEXT, and column types whose values
     * travel as BYTES (base64). Each type here was put through a snapshot and a
     * restore on a real database and came back identical; the self-test
     * (tools/backup-restore-selftest.php) does that again for every one.
     *
     * A type on NEITHER list is refused when the snapshot is taken, naming the
     * column. Refusing beats guessing: the first version of this fix sent
     * "anything not known to be text" as bytes, and a FLOAT then came back as a
     * different number with the restore reporting success.
     *
     * tools/audit-checks/check_backup_column_types.py reads these two lists
     * straight out of this file, so keep each entry a plain quoted word.
     */
    public const TEXT_TYPES = [
        'tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'double',
        'date', 'datetime', 'timestamp', 'time', 'year',
        'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext',
        'enum', 'set', 'json',
    ];
    public const BYTE_TYPES = [
        'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob',
        'geometry', 'point',
    ];

    /**
     * 🎭 What restoreAll() does when asked to "check only".
     *
     * true  = a full REHEARSAL: every row is really written, inside a
     *         transaction that is then undone. Its answer is the restore's own
     *         answer, which is what #578 asked for: the old check only read the
     *         files, and said "fine" about a snapshot that could never go back.
     *         The cost: it takes as long as the restore, and other people's
     *         writes wait while it runs, so close the portal first.
     * false = the quick check: the files are read and nothing is written. It
     *         then says plainly that the database was not tried.
     *
     * This constant is the ONE place that choice is made (see restoreAll()).
     */
    public const CHECK_ONLY_IS_REHEARSAL = true;

    /**
     * The only folder names this class makes, and so the only ones prune() may
     * remove.
     *
     * Every pattern in this file ends with `\z`, the TRUE end of the text. They
     * used to end with `$`, which also matches just before a line break at the
     * very end, so a folder called "upgrade-20261001-090000" followed by a line
     * break counted as one of the portal's own and prune() removed it.
     */
    private const SNAPSHOT_NAME = '/\Aupgrade-\d{8}-\d{6}\z/';

    /**
     * A snapshot's name as a browser may send it: letters, digits, hyphens and
     * underscores, and nothing else. That is every character the portal's own
     * names use, plus the underscore for a folder a person made. No dot, so
     * never "." or ".."; no slash, so never a path. See isSnapshotName().
     */
    private const NAME_FROM_BROWSER = '/\A[A-Za-z0-9_\-]+\z/';

    /**
     * 🛑 Folders inside `_backups/` that are NOT snapshots and must never be
     * treated as one.
     *
     * `incoming` is where an administrator puts a backup FILE brought back from
     * somewhere else, using their hosting company's file manager
     * (`_apps/admin/maintenance/backup-import.php` tells them to). It holds
     * files, not a snapshot.
     *
     * WHY THIS LIST EXISTS, because it was a real fault found by the
     * independent check of #569 and the consequence was data loss: this class
     * lists EVERY folder in `_backups/`, so `incoming` appeared on the Backup
     * page as a row reading "Unfinished — not a backup" with a Delete button
     * beside it. `isSnapshotName('incoming')` was true, so Delete accepted it
     * and removed the folder AND EVERYTHING IN IT — which may be the only copy
     * of the database somebody has just brought back after losing their server.
     * The row actively invited the press.
     *
     * One list, consulted by `isSnapshotName()` (so no action from a browser
     * can name it) and by `listSnapshots()` (so it never appears). Before
     * adding a name, ask whether it is a folder somebody might legitimately
     * want to restore or delete through the page.
     */
    public const NOT_SNAPSHOTS = ['incoming'];

    /**
     * How many times snapshot() tries for a folder name, one second apart,
     * before giving up. See the comment where it is used.
     */
    private const NAME_TRIES = 3;

    /**
     * 🔒 The strictness a restore gives its OWN session, in place of whatever
     * the host set. See pinStrictness() for what each word is there for.
     */
    private const RESTORE_SQL_MODE = 'STRICT_ALL_TABLES,NO_AUTO_VALUE_ON_ZERO,ALLOW_INVALID_DATES';

    /**
     * The database's numbers for "I will not take this value as it stands":
     * may not be empty, out of range, cut short, not a valid value, too long.
     * (0 is this class's own: the database took the value and then said it had
     * changed it.) Used only to add a sentence of explanation to the message.
     */
    private const VALUE_REFUSED = [0, 1048, 1264, 1265, 1292, 1366, 1406];

    /**
     * How long an unfinished folder is left alone before prune() removes it:
     * 24 hours, counted in seconds since anything in it last changed. This is
     * an exact length of time, not "a calendar day", so the two nights a year
     * when the clocks change make no difference to it.
     */
    private const UNFINISHED_KEPT_SECONDS = 86400;

    /**
     * Rows written per INSERT. One row per statement took 19 seconds for
     * 21,000 rows in the trial; 200 per statement took 2. A statement may hold
     * at most 65,535 values, so wide tables get fewer rows (60,000 leaves
     * room). A statement of SEVERAL rows is also kept under a quarter of the
     * largest message the database accepts; a single row bigger than that goes
     * in a statement of its own, so it is refused only if the database would
     * refuse that row by itself. (This used to say every statement was kept
     * under a quarter. It was not: see loadTable().)
     */
    private const ROWS_PER_STATEMENT   = 200;
    private const VALUES_PER_STATEMENT = 60000;

    /**
     * Reading a form 1 file whole needed 3.3 times its size in one trial (458 MB
     * for a 138 MB file) and 3.9 times in another (51 MB for a 13 MB file of
     * shorter rows). 4.5 leaves room. This was 3.5 at first, which the second
     * trial showed would let a file through that then ran out of memory. See
     * legacyMemoryProblem().
     */
    private const LEGACY_MEMORY_FACTOR = 4.5;

    private const IDENTIFIER = '/\A[A-Za-z_][A-Za-z0-9_]*\z/';

    /**
     * 🚫 Tables that may ONLY be put back as part of a whole-database restore.
     *
     * WHY THERE IS A LIST AT ALL. The "restore one table" button empties one
     * table and fills it from a snapshot. That is safe when the table's rows
     * stand on their own. It is NOT safe when the REST OF THE DATABASE has
     * moved on in a way that only makes sense beside the old contents of that
     * one table. Nothing above this list can see that: the check just before
     * it tests the SHAPE OF THE NAME (see IDENTIFIER), and the check below it
     * tests whether other tables POINT AT this one. Neither knows what a table
     * MEANS. These three mean something, and an independent check of the
     * restore on 26 October 2026 watched two of them lose real data:
     *
     *   • tblMigrations — the record of which database upgrades have run.
     *     Put an older copy back and the portal believes an upgrade it has
     *     already done is still waiting, so the Upgrade page runs it AGAIN
     *     over data that upgrade already changed. Proved, not guessed: the
     *     check restored this table alone from an older snapshot and a
     *     two-factor backup code made after the upgrade was DELETED (one row
     *     became none). Nothing warned anybody; the restore said "success".
     *
     *   • tblTotpBackupCodes — the single-use codes for signing in without a
     *     phone. Every code is spent by being marked used. Put an older copy
     *     back and codes somebody has already used are alive again, so a code
     *     read off a screenshot or an old printout signs that person in. The
     *     same check restored this table alone and watched two spent codes
     *     come back usable, and usable they stayed.
     *
     *   • tblSettings — every setting the portal runs on, the recorded
     *     database version among them, and the marks migrations leave behind.
     *     An older copy brings back the old version number, which again makes
     *     upgrades that have run look as though they have not; it also
     *     replaces secrets, mail settings and the portal's open-or-closed
     *     state wholesale. IT TOO WAS WATCHED FAILING, twice: restoring this
     *     table alone brought back a settings row migration 217 had deleted,
     *     and the re-run that followed OVERWROTE AN ORGANISATION'S TIME ZONE
     *     chosen after that upgrade (Asia/Tokyo became Pacific/Auckland) --
     *     real loss of work done since the snapshot. The independent check
     *     reproduced it. (This note used to say the table was here "as a
     *     judgement, not from a watched failure", which was already untrue
     *     when it was written: the measurement is in that round's own
     *     evidence. It matters because this sentence is what a future reader
     *     uses to decide whether the table has to stay on the list.) It also
     *     carries the same "a migration has not run" trap as tblMigrations,
     *     because Maintenance::recordVersion() keeps the version here.
     *
     * THE TEST FOR ADDING A TABLE. Ask: if this table alone went back to how
     * it was, would the rest of the database still make sense beside it? If
     * the answer depends on what has happened SINCE the snapshot — an upgrade,
     * a code being spent, a counter moving on — the table belongs here.
     *
     * WHAT IS STILL ALLOWED, deliberately. A WHOLE-database restore puts all
     * three back and is untouched by this list. That is the right tool: it
     * puts every table back to the same moment, so nothing is left out of
     * step. Refusing these tables in restoreAll() as well was considered and
     * REJECTED — it would make the pre-upgrade snapshot impossible to undo,
     * which is the one thing it exists for.
     *
     * Rejected alternative: a warning on the button instead of a refusal. An
     * administrator presses that button when something has already gone
     * wrong, in a hurry, and the damage is silent and cannot be undone — the
     * restore itself reports success. A refusal that names the right tool is
     * worth more than a warning nobody reads.
     *
     * This list lives HERE and nowhere else. The backup page asks
     * isWholeDatabaseOnly() both to hide the button and to refuse a posted
     * request, and names the tables from array_keys() of this very list; it
     * never keeps its own copy of these names.
     *
     * The value beside each name is the ONE SENTENCE an administrator is shown
     * when they ask for that table on its own, in the plainest words it can be
     * put in. It lives here rather than in the message builder so that adding a
     * table means writing its reason in the same breath: a shared, vaguer
     * sentence was written first and REJECTED, because "this table keeps track
     * of things the rest of the database has moved on from" is true of all
     * three and tells the reader nothing about the one in front of them.
     *
     * @var array<string, string>
     */
    public const WHOLE_DATABASE_ONLY = [
        'tblMigrations'      => 'It is the record of which database upgrades have already run. Putting an older copy back makes the portal believe an upgrade it has already done is still waiting, so it closes itself to everyone and runs that upgrade again over data the upgrade has already changed.',
        'tblTotpBackupCodes' => 'It is the single-use codes for signing in without a phone, and a code is spent by being marked used. Putting an older copy back would make codes that have already been used work again, so a code from an old printout or screenshot would let someone in.',
        'tblSettings'        => 'It holds every setting the portal runs on, including the database version it believes it is on and the marks upgrades leave behind. Putting an older copy back makes upgrades that have run look as though they have not, and replaces mail settings, secrets and the portal\'s open-or-closed state with whatever they were when the snapshot was taken.',
        // 🛟 Added for #697. A whole-database restore is a different matter and
        //    is handled rather than refused: it CARRIES the waiting rows
        //    through (see readWaitingHeldRows()). This entry is about the
        //    one-table button, where there is nothing to carry them past.
        'tblRestoreHeldRows' => 'It is the record of the rows a restore could not put back, kept so somebody can put them back or discard them deliberately. Putting an older copy back on its own throws away every row still waiting — and those rows are the only copy of themselves outside the snapshot you would be restoring from. It also offers rows that have already been returned, and brings back the copy of each one that was deliberately emptied when it went home, which for a sign-in code is a second copy of something somebody signs in with.',
    ];

    /**
     * The tables whose rows are KEPT when a restore is about to lose them to a
     * cascade (#694), and what each one is called in a sentence an
     * administrator reads.
     *
     * HOW A ROW COMES TO BE LOST. A whole-database restore from an OLD-FORM
     * snapshot holds back everything on WHOLE_DATABASE_ONLY, because each of
     * those records what has already happened. But it still replaces
     * `tblSites` and `tblUsers`, and two of the held-back tables are their
     * children with ON DELETE CASCADE — so the cascade takes them and nothing
     * puts them back. Measured on MySQL 8.0.36: one per-organisation settings
     * row before, none after, and the restore reported success.
     *
     * WHY AN ALLOW-LIST AND NOT "every held-back table with a cascade". The
     * holding table keeps whole rows, and the privacy of what lands in it was
     * thought through for exactly these two shapes — one of them is a hash of
     * somebody's sign-in code. A table joining WHOLE_DATABASE_ONLY later would
     * otherwise start depositing rows of unreviewed content into it. Adding a
     * name here is a decision; it should look like one.
     *
     * `tblMigrations` is deliberately absent: it has no foreign key at all, so
     * no cascade can ever reach it. So is `tblRestoreHeldRows`, which joined
     * WHOLE_DATABASE_ONLY for #697 and has no foreign key either — on purpose,
     * because a row lands in it precisely BECAUSE its parent has just gone. The
     * rows IT can lose are lost to the restore writing over them rather than to
     * a cascade, and readWaitingHeldRows() is what keeps those.
     *
     * 🚪 `tblCapabilityMap` AND `tblCapabilityMapRole` ARE DELIBERATELY ABSENT
     *    (#727, decided 3 November 2026), and the decision is written down here
     *    because an absence looks exactly like an oversight — #706's lesson.
     *
     *    WHAT IS LOST, stated plainly. Both carry ON DELETE CASCADE from
     *    `tblSites`, and neither is on WHOLE_DATABASE_ONLY. So a whole-database
     *    restore from a snapshot taken BEFORE migration 243 replaces
     *    `tblSites`, the cascade empties both, and nothing puts them back:
     *    every organisation's "who can open what" choice is reset to the roles
     *    each capability SHIPS with. Seventeen other tables behave the same way
     *    for the same reason, and the older generic warning — "N table(s) that
     *    are NOT in this snapshot were changed as well" — does fire, so it is
     *    not silent. It is also not nothing: an organisation that had
     *    deliberately NARROWED a capability has it widened back to the shipped
     *    role, which is a quiet widening of access rather than a lock-out. The
     *    remedy is the roles page, and `Capabilities::holderCount()` makes the
     *    reset visible there.
     *
     *    WHY NOT RESCUE THEM, and this is a reason rather than a preference.
     *    These two are a PARENT and CHILD joined by `mapID`, which is an
     *    AUTO_INCREMENT surrogate key. The rescue keeps whole rows as JSON and
     *    puts them back by their own key, taking a NEW number where a different
     *    row holds the old one (#697). So a rescued `tblCapabilityMapRole` row
     *    could come back pointing at a `mapID` that now belongs to a different
     *    (organisation, capability) pair — which is not "the choice was lost"
     *    but "a role now opens a door nobody pointed it at". And rescuing the
     *    PARENT alone is worse still: a map row with no role rows under it is
     *    exactly how "chosen, and deliberately nobody" is stored, so every
     *    capability would read as a confirmed EMPTY set and every door would
     *    shut. Losing the choice fails safe; half-rescuing it does not.
     *
     *    If this is ever wanted, the rescue would have to carry the pair
     *    TOGETHER and rewrite the child's `mapID` to the parent's new one — a
     *    different feature from keeping independent rows, and it needs its own
     *    test. Do not add either name to this list on its own.
     *
     *    NOT CHANGED, and worth knowing: a ONE-TABLE restore of
     *    `tblCapabilityMap` is already refused, because `tblCapabilityMapRole`
     *    points at it and restoreTable() refuses any table another points at.
     *    `tblCapabilityMapRole` is not refused, for the same reason as every
     *    other childless table in the schema — nothing points at it. Putting
     *    its rows back on their own against `mapID`s that have since moved
     *    would have the mismatch described above, so it belongs on
     *    WHOLE_DATABASE_ONLY if anybody judges that reachable; it is left alone
     *    here because that is a change to restore BEHAVIOUR and needs
     *    `tools/backup-restore-selftest.php` behind it, not a comment.
     *
     * @var array<string, array{parent: string, column: string, describe: string}>
     */
    public const CASCADE_RESCUE = [
        'tblSettings' => [
            'parent'   => 'tblSites',
            'column'   => 'siteID',
            // ⚠️ NOT "its postal address, map position, what3words address and
            //    support e-mail", which this said first and which is UNTRUE.
            //    The rescue takes EVERY row with a `siteID`, whatever its key.
            //    The four address keys migration 231 (#677) copied down are
            //    only the commonest; `attend.detailRetentionDays`,
            //    `giving.retention.years` and the four `mail.queue.*` numbers
            //    are per-organisation too. Naming four address fields to
            //    somebody whose retention periods had just gone would be worse
            //    than saying nothing, and they read it at the worst possible
            //    moment. The keys themselves are named by
            //    rowsWaitingToBePutBack(), which lifts them out of the kept
            //    rows, so the page can be exact where this cannot.
            //
            //    SEVEN files under `web/` can write such a row, and the first
            //    version of this comment said 32 — which is the count of files
            //    anywhere under `web/` holding the TEXT `INSERT INTO
            //    tblSettings` at all (30 under `web/_apps` and `web/_core`,
            //    the scope of the command the notes cite), nearly all of which
            //    bind `siteID` as the literal NULL. TWO further FILES are
            //    missed by a plain-text search altogether, because they write
            //    `INSERT INTO \`tblSettings\`` with backticks
            //    (`_core/Maintenance.php` and `_apps/admin/apps/index.php`,
            //    four statements between them); every one binds NULL, so the
            //    seven-writer list is not short. This read "four more" inside a
            //    paragraph counting FILES, which made four statements look like
            //    four files.
            //    `.claude/CLAUDE.md` already said "seven per-organisation
            //    writers" from #668 and I contradicted it. Establish it by
            //    reading each INSERT's column list against its VALUES, never by
            //    counting files that mention the table: `attendance/save.php`,
            //    `organisation/save.php`, `repeats/save.php`,
            //    `_apps/settings/save.php`, `venues/settings.php`,
            //    `_core/GivingRetention.php`, `_core/Recordings.php`.
            //    Kept SHORT, with the detail bracketed by dashes so the subject
            //    still reaches its verb: the long version ran to 22 words
            //    between "setting(s)" and "were removed", which no reader
            //    follows. The exact keys are on the page, from `names`.
            'describe' => 'setting(s) belonging to an organisation — its own choices, such as its '
                        . 'address or a limit it has set for itself —',
        ],
        'tblTotpBackupCodes' => [
            'parent'   => 'tblUsers',
            'column'   => 'userID',
            // "backup code(s)", not "UNUSED backup code(s)", which five places
            // said. The cascade takes every row with a `userID`, and
            // `auth/2fa/verify.php` marks a spent code `isUsed = 1` and KEEPS
            // the row — so the record of which codes have been spent goes too,
            // and is kept too. An understatement rather than a falsehood, but
            // it is the record of a code having been used that matters most
            // here: putting an older copy back is what WHOLE_DATABASE_ONLY
            // exists to stop.
            // Bracketed by em dashes, as the settings entry is and for the same
            // reason: on commas the apposition never closed, and the assembled
            // sentence read "... their authenticator, spent ones included were
            // removed by ...". No test can see this; print the sentence.
            'describe' => 'two-factor backup code(s) — the single-use codes people sign in with when they '
                        . 'have lost their authenticator, spent ones included —',
        ],
    ];

    /**
     * The most rows this will hold in one restore.
     *
     * The rows are read into memory before the restore and written afterwards,
     * so there has to be a ceiling. Past it, NOTHING is held and the answer
     * says so with the count: half a rescue would be worse than none, because
     * an administrator would believe the rest had been kept.
     */
    public const CASCADE_RESCUE_MAX_ROWS = 20000;


    private mysqli $db;
    private string $backupsRoot;

    /**
     * True when a restore could NOT put this connection's own settings back
     * afterwards and so closed the connection. Set by putStrictnessBack(),
     * read by sayIfSessionNotPutBack(), cleared at the start of each restore.
     */
    private bool $sessionNotPutBack = false;

    public function __construct(mysqli $db, string $backupsRoot = '')
    {
        $this->db          = $db;
        $this->backupsRoot = $backupsRoot !== ''
            ? rtrim($backupsRoot, DIRECTORY_SEPARATOR)
            : PORTAL_ROOT . DIRECTORY_SEPARATOR . '_backups';
    }

    // =========================================================================
    // 🏷️ Names that come from a browser
    // =========================================================================

    /**
     * Is this a snapshot name a page may act on? The ONE test for a name that
     * arrives from a browser, used by every action of the backup page (inspect,
     * check, restore all, restore one table, delete) BEFORE it closes the
     * portal, builds a path or deletes anything.
     *
     * WHY ONE TEST. Each action used to have its own. Delete's was the weakest
     * ("no slash and no two dots"), so the name "." got through and Delete
     * removed the loose files in the backups folder; and the two Restore
     * actions closed the portal before theirs had finished, so a name holding
     * a zero byte left the portal closed.
     *
     * Anything that is not a plain string is refused too: a browser can send
     * `name[]=x`, which arrives as a list.
     */
    public static function isSnapshotName(mixed $name): bool
    {
        return is_string($name) === true
            && preg_match(self::NAME_FROM_BROWSER, $name) === 1
            // 🛑 A reserved folder is not a snapshot, so no action that takes a
            //    snapshot name may touch it — inspect, rehearse, restore or
            //    DELETE. See NOT_SNAPSHOTS for the data loss that caused.
            && in_array($name, self::NOT_SNAPSHOTS, true) === false;
    }

    /**
     * 🏷️ Was this snapshot TAKEN BY THIS INSTALLATION, rather than imported?
     *
     * A snapshot this installation took is named `upgrade-YYYYMMDD-HHMMSS`; one
     * brought in from a file is named `imported-…` (see
     * `_apps/admin/maintenance/backup-import.php`). The difference matters in
     * two places and the same rule has to answer both:
     *
     *   • `prune()` only ever removes a snapshot this installation took, so
     *     that a backup somebody went to the trouble of importing is never
     *     tidied away by the scheduled job.
     *   • `cron/backup-run` decides whether a backup is DUE from the newest one
     *     this installation took. An imported snapshot must not count: it is a
     *     copy brought back from somewhere else, not evidence that the current
     *     database has been saved. Without this, importing a recent file would
     *     silently suppress tonight's backup — found by section N of
     *     `tools/backup-page-selftest.php`.
     *
     * The pattern is this class's own, so the two places cannot drift apart.
     */
    public static function isTakenHere(mixed $name): bool
    {
        return is_string($name) === true && preg_match(self::SNAPSHOT_NAME, $name) === 1;
    }

    /** Is this a table name a page may pass to restoreTable()? Same reasoning as isSnapshotName(). */
    public static function isTableName(mixed $name): bool
    {
        return is_string($name) === true && preg_match(self::IDENTIFIER, $name) === 1;
    }

    /**
     * Is this one of the tables that may only be put back as part of a whole
     * database? The ONE place WHOLE_DATABASE_ONLY is compared against a name;
     * see that constant for why each table is on it.
     *
     * The comparison IGNORES CAPITAL LETTERS on purpose. Whether MySQL treats
     * two table names that differ only in capitals as the same table is a
     * SERVER setting (lower_case_table_names), and it differs between a Linux
     * shared host and a developer's Mac. A case-sensitive comparison here
     * would let "tblmigrations" past the guard on a server where that is the
     * very same table. Being stricter than the server is safe; being looser is
     * not.
     *
     * Anything that is not a plain string is refused, as in isTableName(): a
     * browser can send `table[]=x`, which arrives as a list.
     */
    public static function isWholeDatabaseOnly(mixed $table): bool
    {
        if (is_string($table) === false) {
            return false;
        }
        foreach (array_keys(self::WHOLE_DATABASE_ONLY) as $onlyWhole) {
            if (strcasecmp($table, (string) $onlyWhole) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is $table one of the names in $names, ignoring capitals?
     *
     * 🔎 WHY IGNORING CAPITALS, AND WHY THIS IS NOT FUSSINESS. Whether MySQL
     *    treats two table names differing only in capitals as the same table is
     *    a SERVER setting, `lower_case_table_names`, and on a server set to 1
     *    every name `SHOW TABLES` reports comes back in lower case.
     *    isWholeDatabaseOnly() has compared with strcasecmp() for that reason
     *    since it was written.
     *
     *    The lists these comparisons are made against — a snapshot's table
     *    list, and the tables a restore is replacing — hold names exactly as
     *    the server reported them. Comparing them to a name typed in this file
     *    with `in_array(..., true)` therefore answered FALSE on such a server,
     *    which made BOTH safety nets inert: #694's cascade rescue kept nothing
     *    and printed no warning, and #697's carry never ran, so a waiting row
     *    was lost with the restore reporting success. Measured by the
     *    independent check on `mysql:8.0.36 --lower-case-table-names=1`: one
     *    per-organisation settings row before, none after, no warning. The
     *    tables WERE still held back, because that path went through
     *    strcasecmp() — so the two halves disagreed about what a table is.
     *
     *    Being stricter than the server is safe here: a server set to 0 has
     *    exact names anyway, so this changes nothing there.
     *
     * @param list<string> $names
     */
    private static function namedIn(string $table, array $names): bool
    {
        foreach ($names as $name) {
            if (strcasecmp($table, (string) $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * One table's entry out of an array KEYED by table name, ignoring capitals.
     *
     * 🔑 AN ARRAY SUBSCRIPT IS A COMPARISON WRITTEN ANOTHER WAY, and that is
     *    how the first fix for this went wrong. namedIn() was added to make the
     *    `in_array()` comparisons ignore capitals, and four documents then said
     *    "all five sites use it" — while `$facts['tblRestoreHeldRows']` and
     *    `self::REMOVED_COLUMNS[$table]` went on matching exactly.
     *
     *    What that cost, measured on `mysql:8.0.36 --lower-case-table-names=1`:
     *    `columnFacts()` keys its answer by information_schema's own TABLE_NAME,
     *    which is LOWER CASE there, so the subscript found nothing, the carry
     *    threw its "the shape could not be read" exception, and EVERY
     *    whole-database restore — the rehearsal included — was refused outright
     *    while any row was waiting. Making the carry run had turned a silent
     *    loss into a blocked recovery, which this file elsewhere refuses to
     *    create ("refusing it would block a recovery at the one moment somebody
     *    is recovering"). And the message named the wrong cause: the shape HAD
     *    been read, just under another key.
     *
     *    So: never subscript a table-keyed array with a name from anywhere but
     *    that same array. Use this.
     *
     * @param array<string, mixed> $byTableName
     *
     * @return array<mixed> The entry, or an empty array when there is none.
     */
    private static function forTable(array $byTableName, string $table): array
    {
        foreach ($byTableName as $name => $entry) {
            if (strcasecmp($table, (string) $name) === 0) {
                return is_array($entry) === true ? $entry : [];
            }
        }

        return [];
    }

    /**
     * The words shown when one of those tables is asked for on its own. Kept
     * beside the list so the page and the class say exactly the same thing,
     * and so there is one place to improve the wording.
     *
     * It must say three things, because an administrator reading it is usually
     * mid-emergency: that nothing has been changed, what could have gone wrong,
     * and which button to press instead.
     */
    public static function wholeDatabaseOnlyReason(string $table): string
    {
        // The name is matched ignoring capitals, as isWholeDatabaseOnly() does,
        // so the words never come back empty for a name that guard refused.
        $why = '';
        foreach (self::WHOLE_DATABASE_ONLY as $onlyWhole => $sentence) {
            if (strcasecmp($table, (string) $onlyWhole) === 0) {
                $why = $sentence;
                break;
            }
        }

        return $table . ' cannot be put back on its own. ' . ($why === '' ? '' : $why . ' ')
             . 'Use "Restore ALL tables" instead: that puts every table back to the same moment, '
             . 'so nothing is left out of step, and it is the supported way to put this table back. '
             . 'Nothing has been changed.';
    }

    /**
     * Where the snapshot called $name is, or would be. The name must already
     * have passed isSnapshotName(); anything else is a programming mistake and
     * stops here rather than becoming a path.
     */
    public function pathOf(string $name): string
    {
        if (self::isSnapshotName($name) === false) {
            throw new RuntimeException('That is not a snapshot name.');
        }
        return $this->backupsRoot . DIRECTORY_SEPARATOR . $name;
    }

    /**
     * 🗑️ Delete one folder from the backups folder, by name.
     *
     * A LINK IS REMOVED AS A LINK AND NEVER FOLLOWED. The page used to do this
     * itself with a loop of its own, which followed a link and deleted every
     * file in the folder it pointed at, outside the backups folder.
     *
     * WHAT IT WILL NOT DO: remove a loose file. Only a folder (or a link) is a
     * snapshot; the logger keeps files of its own in the backups folder.
     *
     * @return bool True only if there was such a folder or link and it is gone.
     */
    public function deleteSnapshot(string $name): bool
    {
        if (self::isSnapshotName($name) === false) {
            return false;
        }
        $path = $this->pathOf($name);
        if (is_link($path) === false && is_dir($path) === false) {
            return false;
        }
        return $this->rmdirRecursive($path);
    }

    // =========================================================================
    // 🔎 What the live database says about itself
    // =========================================================================

    /**
     * Run one statement and insist on an answer.
     *
     * The portal sets mysqli to throw on failure, but a tool or a host may not,
     * and then a failed statement returns false. Everything below destroys or
     * replaces data, so a false must stop the work exactly as an exception
     * would, never be read as "done".
     *
     * @return \mysqli_result|true
     */
    private function ask(string $sql, int $mode = MYSQLI_STORE_RESULT): \mysqli_result|bool
    {
        $answer = $this->db->query($sql, $mode);
        if ($answer === false) {
            throw new RuntimeException($this->db->error !== '' ? $this->db->error : 'The database gave no answer.');
        }
        return $answer;
    }

    /**
     * Every column of every table: its type, whether it may be empty, whether
     * the database works its value out for itself, and whether the database
     * re-stamps it with the current time whenever the row changes.
     *
     * Asked afresh at the start of each snapshot and each restore, not kept
     * for the life of the object: a restore that acted on yesterday's shape of
     * a table could leave a column out.
     *
     * @return array<string, array<string, array{type: string, nullable: bool, generated: bool, restamped: bool}>>
     *         Table name, then column name in the table's own column order.
     */
    private function columnFacts(): array
    {
        // ⚠️ "generated" is read from GENERATION_EXPRESSION, NOT from
        //    `EXTRA LIKE '%GENERATED%'`.
        //
        //    That looks like the obvious test and is badly wrong. MySQL puts
        //    the word GENERATED in EXTRA for ordinary columns too: a column
        //    declared `DEFAULT CURRENT_TIMESTAMP` gets
        //    EXTRA = 'DEFAULT_GENERATED', and one that also has
        //    `ON UPDATE CURRENT_TIMESTAMP` gets
        //    'DEFAULT_GENERATED on update CURRENT_TIMESTAMP'. Matching on the
        //    word alone would treat every `createdAt` and `updatedAt` column in
        //    the product as something to skip, quietly dropping them from every
        //    backup and every restore. (Confirmed on MySQL 8.0.36.)
        //
        //    GENERATION_EXPRESSION holds the formula and is empty for
        //    everything except a genuinely derived column. Such a column
        //    (`tblSettings.siteScope`, migration 187, is the first here) cannot
        //    be written to: an INSERT that names one is refused outright.
        $answer = $this->ask(
            'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, IS_NULLABLE, GENERATION_EXPRESSION, EXTRA '
            . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() '
            . 'ORDER BY TABLE_NAME, ORDINAL_POSITION'
        );

        $facts = [];
        while (($row = $answer->fetch_row()) !== null) {
            $facts[(string) $row[0]][(string) $row[1]] = [
                'type'      => strtolower((string) $row[2]),
                'nullable'  => strtoupper((string) $row[3]) === 'YES',
                'generated' => (string) $row[4] !== '',
                'restamped' => stripos((string) $row[5], 'on update') !== false,
            ];
        }
        $answer->free();
        return $facts;
    }

    /**
     * One saved row without the columns of REMOVED_COLUMNS that the database
     * no longer has. Their values are dropped: see the list for why each may
     * be.
     *
     * Both ways of putting rows back call this for every row, before they
     * decide which columns to write, so the two cannot differ.
     *
     * @param array<string, string|null>          $row
     * @param array<string, array<string, mixed>> $tableFacts The table's columns as the database has them now.
     *
     * @return array<string, string|null>
     */
    private static function withoutRemovedColumns(string $table, array $row, array $tableFacts): array
    {
        // forTable(), not a subscript: `$table` comes from the snapshot's
        // manifest, which comes from SHOW TABLES, which is lower case on a
        // server with lower_case_table_names = 1 - where this would then strip
        // nothing and put a removed column back. See forTable().
        foreach (self::forTable(self::REMOVED_COLUMNS, $table) as $gone) {
            if (isset($tableFacts[$gone]) === false) {
                unset($row[$gone]);
            }
        }

        return $row;
    }

    /** 'text', 'bytes', or null for a type this class has not been taught. */
    private static function carriedAs(string $type): ?string
    {
        if (in_array($type, self::TEXT_TYPES, true) === true) {
            return 'text';
        }
        if (in_array($type, self::BYTE_TYPES, true) === true) {
            return 'bytes';
        }
        return null;
    }

    /**
     * 🔗 Every link from one table to another (or to itself), as the LIVE
     * database lists them at this moment: which columns do the pointing, at
     * which table and columns, and what the link itself says happens to the
     * pointer when the row it points at is deleted (`onDelete`: SET NULL,
     * CASCADE, RESTRICT or NO ACTION).
     *
     * Read from the database, never from a hand-written list, so a link added
     * by next month's migration is covered without anybody remembering this.
     * A link into a different database is left out: nothing is ever guessed
     * about one of those.
     *
     * @return array<string, array<int, array{name: string, columns: array<int, string>, parent: string,
     *               parentColumns: array<int, string>, onDelete: string}>> Keyed by the table that points.
     */
    private function links(): array
    {
        $answer = $this->ask(
            'SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, '
            . '       k.REFERENCED_COLUMN_NAME, r.DELETE_RULE '
            . 'FROM information_schema.KEY_COLUMN_USAGE k '
            . 'JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
            . '  ON r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME '
            . ' AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME '
            . 'WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_SCHEMA = k.TABLE_SCHEMA '
            . 'ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION'
        );
        $links = [];
        while (($row = $answer->fetch_row()) !== null) {
            [$table, $name, $column, $parent, $parentColumn, $onDelete] = array_map('strval', $row);
            $links[$table][$name] ??= ['name' => $name, 'columns' => [], 'parent' => $parent, 'parentColumns' => [], 'onDelete' => strtoupper($onDelete)];
            $links[$table][$name]['columns'][]       = $column;
            $links[$table][$name]['parentColumns'][] = $parentColumn;
        }
        $answer->free();
        return array_map('array_values', $links);
    }

    /**
     * 🔁 Every table that points at rows of ITSELF: which columns do the
     * pointing, and which columns identify a row (its primary key).
     *
     * Superseding a venue agreement is the everyday case: the old agreement
     * points at the new one through `supersededByID`. See loadTable() for what
     * is done about it.
     *
     * @param array<string, array<int, array<string, mixed>>> $links From links().
     *
     * @return array<string, array{pointers: array<int, string>, key: array<int, string>}>
     */
    private function selfPointers(array $links): array
    {
        $found = [];
        foreach ($links as $table => $tableLinks) {
            foreach ($tableLinks as $link) {
                if ($link['parent'] === $table) {
                    $found[$table]['pointers'] = array_merge($found[$table]['pointers'] ?? [], $link['columns']);
                }
            }
        }
        if ($found === []) {
            return [];
        }

        $answer = $this->ask(
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE '
            . "WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'PRIMARY' "
            . 'ORDER BY TABLE_NAME, ORDINAL_POSITION'
        );
        while (($row = $answer->fetch_row()) !== null) {
            if (isset($found[(string) $row[0]]) === true) {
                $found[(string) $row[0]]['key'][] = (string) $row[1];
            }
        }
        $answer->free();

        foreach ($found as $table => $info) {
            $found[$table] = [
                'pointers' => array_values(array_unique($info['pointers'])),
                'key'      => $info['key'] ?? [],
            ];
        }
        return $found;
    }

    /**
     * Can this self-pointing table be put back the way loadTable() does it?
     *
     * Checked BEFORE anything is touched. The method needs to write each row
     * with its pointer empty, so the pointer must be allowed to be empty; and
     * it needs to find each row again to fill the pointer in, so the table
     * must have a primary key.
     *
     * @return string Empty when it can, otherwise a sentence saying why not.
     */
    private function selfPointerProblem(string $table, array $tableFacts, array $self): string
    {
        if ($self['key'] === []) {
            return $table . ' holds rows that point at other rows of the same table, and it has no '
                 . 'primary key to find those rows by, so they cannot be put back safely.';
        }
        foreach ($self['pointers'] as $pointer) {
            if (($tableFacts[$pointer]['nullable'] ?? false) === false) {
                return $table . '.' . $pointer . ' points at other rows of the same table and is not '
                     . 'allowed to be empty, so those rows cannot be put back one at a time.';
            }
        }
        return '';
    }

    /**
     * ", `updatedAt` = `updatedAt`" for every column of this table that the
     * database would otherwise re-stamp with the current time.
     *
     * ⚠️ THE TRAP THIS EXISTS FOR. Filling a pointer in is an UPDATE, and an
     * UPDATE re-stamps every `ON UPDATE CURRENT_TIMESTAMP` column on the row.
     * Five of the nine self-pointing tables have one (`updatedAt`). Without
     * this clause a restore reported success and those five tables came back
     * with today's date on every row that had a pointer (tried: 6c in the
     * plan's evidence). Naming the column in the UPDATE, set to itself, tells
     * the database to leave it alone.
     */
    private function keepStamps(array $tableFacts): string
    {
        $sql = '';
        foreach ($tableFacts as $column => $fact) {
            if ($fact['restamped'] === true && $fact['generated'] === false) {
                $sql .= ', `' . $column . '` = `' . $column . '`';
            }
        }
        return $sql;
    }

    /**
     * Run a prepared statement. Null when it worked; otherwise the database's
     * own error number and words, whichever way this connection reports
     * failure (by throwing, as the portal sets it, or by returning false).
     *
     * ⚠️ A STATEMENT THAT "WORKED" WITH A WARNING HAS NOT WORKED. Even in the
     * strict session a restore runs in (see pinStrictness()), the database
     * takes some values by changing them and only leaves a note: a decimal
     * with more places than the column now keeps is rounded, and spaces at the
     * end of a text that is now too long are cut off (both tried). A restore
     * must never knowingly put back a changed value and call it a success, so
     * any warning or note at all is handed back as a problem, with code 0, and
     * the caller stops and undoes everything.
     *
     * This relies on the database COUNTING its notes, which a session can
     * switch off (`sql_notes = 0`). pinStrictness() switches it on for the
     * length of the restore for exactly that reason.
     *
     * WHAT IT CANNOT SEE: a change the database makes without even a note.
     * Those are listed in this file's header.
     *
     * @return array{code: int, message: string}|null
     */
    private function tryStatement(\mysqli_stmt $statement, array $values): ?array
    {
        try {
            if ($statement->execute($values) === false) {
                return ['code' => (int) $statement->errno, 'message' => (string) $statement->error];
            }
            if ($this->db->warning_count === 0) {
                return null;
            }
            $answer = $this->ask('SHOW WARNINGS');
            $first  = $answer->fetch_row();
            $answer->free();
            return [
                'code'    => 0,
                'message' => 'the database took the value only by changing it: '
                           . (string) ($first[2] ?? 'it raised a warning and would not say which'),
            ];
        } catch (\mysqli_sql_exception $e) {
            return ['code' => (int) $e->getCode(), 'message' => $e->getMessage()];
        }
    }

    /**
     * The database's words for why a write failed, with a sentence of
     * explanation added when the reason is that a VALUE would not fit.
     *
     * @param array{code: int, message: string} $problem From tryStatement().
     */
    private static function whyRefused(array $problem): string
    {
        if (in_array($problem['code'], self::VALUE_REFUSED, true) === false) {
            return $problem['message'];
        }
        // "Knowingly" is there on purpose. It used to say "never puts back a
        // changed value", which is more than this class can promise: see
        // "WHAT THIS CANNOT DO" in the header for the changes the database
        // makes without a word.
        return rtrim($problem['message'], '.') . '. (The database would not take this value as it was saved, or '
             . 'could only take it by changing it. That happens when a column was changed after the snapshot '
             . 'was taken, or when the value was first saved by a database set to accept anything. A restore '
             . 'never knowingly puts back a changed value, so it stopped.)';
    }

    /**
     * 📏 Is this row too large for the database to take in one message?
     *
     * The database refuses any single message larger than its
     * `max_allowed_packet` setting, and it refuses by ENDING THE CONNECTION.
     * A restore that sent such a row was undone (a closed connection always
     * is), but all it could say was "MySQL server has gone away", and the
     * backup page was left on its bare page with the portal closed. So the
     * row is measured first and refused HERE, in words, before it is sent:
     * the connection stays open and the ordinary undo runs.
     *
     * A row travels as its values plus a few bytes of wrapping for each one
     * (at most 11: two for its type, up to nine for its length) and a short
     * heading. The allowance below is that, rounded up, so a row within a
     * few hundred bytes of the limit is refused although the database might
     * just have taken it. That way round is the safe one.
     *
     * WHAT IT CANNOT DO: know about a limit set anywhere other than this
     * setting (a proxy in front of the database, for example).
     *
     * @param int $rowBytes The row's values, added up.
     * @param int $columns  How many values the row has.
     * @param int $packet   The session's max_allowed_packet; 0 when unknown.
     *
     * @return string Empty when the row can be sent, otherwise why not and what to change.
     */
    private static function tooLargeProblem(string $table, int $rowBytes, int $columns, int $packet): string
    {
        $needed = $rowBytes + 64 + 12 * $columns;
        if ($packet <= 0 || $needed <= $packet) {
            return '';
        }
        return sprintf(
            'The saved copy of %s holds a row of about %s MB, and this database server takes at most %s MB in one '
            . 'message (its max_allowed_packet setting is %d bytes), so the row was not sent. What to change: ask '
            . 'the host to raise max_allowed_packet to at least %d MB, or put this snapshot back on a server that '
            . 'allows that much, and then try again.',
            $table,
            number_format($rowBytes / 1048576, 1),
            number_format($packet / 1048576, 1),
            $packet,
            (int) ceil($needed / 1048576)
        );
    }

    /** The largest message this connection's database takes (`max_allowed_packet`), in bytes. */
    private function largestMessage(): int
    {
        $answer = $this->ask('SELECT @@session.max_allowed_packet');
        $packet = (int) ($answer->fetch_row()[0] ?? 0);
        $answer->free();
        return $packet;
    }

    /** Is there a row of $parent whose $column holds $value? Asked inside the restore's own transaction. */
    private function rowExists(string $parent, string $column, string $value): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM `' . $parent . '` WHERE `' . $column . '` = ? LIMIT 1');
        if ($statement === false || $statement->execute([$value]) === false) {
            throw new RuntimeException('Could not look for a row in ' . $parent . '.');
        }
        $found = $statement->get_result()->fetch_row() !== null;
        $statement->close();
        return $found;
    }

    /**
     * 🕳️ A row being put back points at a row that is NOT THERE. May the
     * pointer be emptied? Returns quietly if so; otherwise throws, which
     * stops the restore and undoes it.
     *
     * HOW THIS HAPPENS. A migration can add a link that did not exist when a
     * snapshot was taken (the first was `tblAttendanceSessions.eventID`, which
     * gained its link to `tblEvents` long after the column). The upgrade page
     * takes its snapshot BEFORE the migrations run, so that snapshot can hold
     * a session naming an event deleted years ago. Once the link exists the
     * database refuses that row, and so the snapshot taken for the very
     * purpose of undoing a bad upgrade could never be put back.
     *
     * THE RULE, AND WHY IT IS SAFE TO APPLY WITHOUT ASKING. The pointer is
     * emptied only when the link's OWN rule in the database is "empty the
     * pointer when the row it points at is deleted" (ON DELETE SET NULL) and
     * the column is allowed to be empty. That is exactly what the database
     * would have done, by itself, had the link existed on the day the other
     * row was deleted. Every pointer emptied is counted and named in the
     * result, so nothing is changed silently.
     *
     * For a link with ANY other rule nothing is guessed. "Delete the child
     * too" (CASCADE) would mean a restore quietly leaving rows out, and
     * "refuse" (RESTRICT, NO ACTION) means the designer said such a row must
     * never exist. Both are refused, naming the table, the column and the
     * missing row. A link of more than one column is never emptied either.
     *
     * WHAT THIS CANNOT TELL: why the other row is missing. A pointer left
     * dangling by an old snapshot's fault looks the same as one left by a
     * deleted row. The count in the result is there so a person can judge.
     *
     * @param array{columns: array<int, string>, parent: string, parentColumns: array<int, string>, onDelete: string} $link
     */
    private function refuseUnlessMayEmpty(string $table, array $link, array $tableFacts, string $value): void
    {
        $column = $link['columns'][0];
        if ($link['onDelete'] === 'SET NULL' && ($tableFacts[$column]['nullable'] ?? false) === true) {
            return;
        }
        throw new RuntimeException(sprintf(
            'A row of %s points, through its column %s, at the row of %s whose %s is %s, and that row is not in '
            . 'the snapshot. The database\'s rule for this link does not allow the pointer simply to be emptied, '
            . 'so nothing was guessed.',
            $table,
            $column,
            $link['parent'],
            $link['parentColumns'][0],
            preg_match('//u', $value) === 1 ? '"' . $value . '"' : '0x' . bin2hex($value)
        ));
    }

    /**
     * Called only after the database has refused a batch of rows for pointing
     * at a row that does not exist. Finds which pointers those are and empties
     * the ones that may be emptied (see refuseUnlessMayEmpty(), which stops
     * the restore for any that may not).
     *
     * It costs nothing when no row is refused, which is every ordinary
     * restore. Parents are always written before their children, so "not in
     * the database at this point" means "not in the snapshot".
     *
     * @param array<int, string>                    $columns The batch's columns, in order.
     * @param array<int, array<int, ?string>>       $batch   The rows; changed in place.
     * @param array<int, array<string, mixed>>      $tableLinks This table's links, from links().
     * @param array<string, int>                    $emptied Running count per "table.column"; added to.
     *
     * @return bool True when at least one pointer was emptied, so the batch is worth trying again.
     */
    private function emptyDeadPointers(string $table, array $columns, array &$batch, array $tableLinks, array $tableFacts, array &$emptied): bool
    {
        $changed = false;
        foreach ($tableLinks as $link) {
            // A table's pointers at ITSELF went in empty and are dealt with in
            // fillPointers(). A link of several columns is never guessed at.
            if (count($link['columns']) !== 1 || $link['parent'] === $table) {
                continue;
            }
            $at = array_search($link['columns'][0], $columns, true);
            if ($at === false) {
                continue;
            }
            $known = [];   // value => is its row there?
            foreach ($batch as $i => $row) {
                $value = $row[$at];
                if ($value === null) {
                    continue;
                }
                $known[$value] ??= $this->rowExists($link['parent'], $link['parentColumns'][0], $value);
                if ($known[$value] === false) {
                    $this->refuseUnlessMayEmpty($table, $link, $tableFacts, $value);
                    $batch[$i][$at] = null;
                    $emptied[$table . '.' . $link['columns'][0]] = ($emptied[$table . '.' . $link['columns'][0]] ?? 0) + 1;
                    $changed = true;
                }
            }
        }
        return $changed;
    }

    /**
     * 🕰️ Set this connection's time zone, returning what it was.
     *
     * WHY. A TIMESTAMP column holds a moment, but the database hands it over
     * as clock time in the CONNECTION's time zone, and reads what it is given
     * the same way. The portal never sets that zone, so it is whatever the
     * host's database uses. A snapshot taken on a host at -07:00 and put back
     * on one at +00:00 moved `tblGroups.dateAdded` and `dateLastUpdated` seven
     * hours, and said "success" (tried: 08 in the plan's evidence). So form 2
     * writes and reads every moment in UTC, and says so in the manifest.
     *
     * DATETIME, DATE and TIME columns are clock times with no zone, and are
     * not affected by this at all.
     */
    private function pinTimeZone(string $zone): string
    {
        $answer = $this->ask('SELECT @@session.time_zone');
        $was    = (string) ($answer->fetch_row()[0] ?? 'SYSTEM');
        $answer->free();
        $this->ask("SET time_zone = '" . $this->db->real_escape_string($zone) . "'");
        return $was;
    }

    /** Put the connection's time zone back. Never throws: it runs in `finally` blocks. */
    private function putTimeZoneBack(?string $was): void
    {
        if ($was === null) {
            return;
        }
        try {
            $this->db->query("SET time_zone = '" . $this->db->real_escape_string($was) . "'");
        } catch (\Throwable $ignored) {
            // The connection has gone. There is nothing left to put back.
            unset($ignored);
        }
    }

    /**
     * 🔒 Make this connection strict for the length of a restore, returning
     * what its setting (`sql_mode`) was so that it can be put back.
     *
     * WHY. Until 12 October 2026 the portal ran with the host's `sql_mode`,
     * and so did a restore. The portal now makes its own connection strict
     * (Portal\Core\DbServer::connect()), but with a different list from the
     * one a restore needs: the portal's list refuses the date 0000-00-00, and
     * a restore must put such a date back. So a restore still sets its own
     * list for its length and then puts the portal's back.
     *
     * What went wrong before a restore had a list of its own: some shared
     * hosts run with `sql_mode` empty, and the database then "helps" by
     * changing any value that does not fit instead of refusing it.
     * Putting back the snapshot taken before an upgrade, after a migration had
     * changed a column, said "success" and brought back `a lon` for a
     * 36-letter value, 32767 for 70000, an empty text for "no value", and an
     * empty text for a value not on a column's new list of choices (the
     * independent check of 12 October 2026, both MySQL versions).
     *
     * THE SETTING IS REPLACED, NOT ADDED TO, so a restore behaves the same on
     * every server whatever the host chose. Each word is there for a reason:
     *
     *   STRICT_ALL_TABLES      a value too long, too large, empty where it may
     *                          not be, or not on a list of choices is REFUSED.
     *   NO_AUTO_VALUE_ON_ZERO  a row numbered 0 in a self-numbering column goes
     *                          back as 0. Without it the database gives the row
     *                          a new number and says nothing.
     *   ALLOW_INVALID_DATES    a date such as 31 February, which only a server
     *                          set this way could have saved, goes back as it
     *                          was instead of stopping the whole restore.
     *
     * Two words are LEFT OUT on purpose: NO_ZERO_DATE and NO_ZERO_IN_DATE.
     * With them a strict server refuses the date 0000-00-00. A database that
     * was ever run loosely can hold such dates, a snapshot of it holds them
     * too, and they must go back exactly as they were (tried: they do).
     *
     * WHAT STRICTNESS COSTS. A value the database stored only BECAUSE it was
     * loose, a text that is not on a column's list of choices (kept as an
     * empty text), is refused by a strict session (tried). Only a loose
     * session takes it back, and a loose session also changes every other
     * value that does not fit, with nothing to tell the two cases apart. So
     * such a snapshot is now refused, with the database's reason, where it
     * used to go back. That is the price of never changing a value quietly.
     * The rehearsal shows it beforehand, and snapshot() says so the moment
     * such a snapshot is taken (see unlistedWarning()).
     *
     * 📝 THE DATABASE'S NOTES ARE SWITCHED ON AS WELL (`sql_notes = 1`).
     * Rounding a decimal and cutting spaces off the end of a text are
     * reported only as NOTES, and tryStatement() refuses on any. A session
     * with notes switched off does not count them, so on such a connection a
     * restore said "success" and brought back `1.23` for `1.2345` (the second
     * independent check, 12 October 2026, both MySQL versions). Both settings
     * are changed in ONE statement, which the database carries out wholly or
     * not at all, and both are put back afterwards.
     *
     * WHAT IT CANNOT CATCH is listed in this file's header: the kinds of
     * change the database makes with no error, no warning and no note.
     *
     * Setting these needs no special right (tried as a user with rights on
     * one database only). They are set before the transaction starts and put
     * back after it has ended.
     *
     * @return array{mode: string, notes: string} What the two settings were.
     */
    private function pinStrictness(): array
    {
        $answer = $this->ask('SELECT @@session.sql_mode, @@session.sql_notes');
        $row    = (array) $answer->fetch_row();
        $answer->free();
        $was    = ['mode' => (string) ($row[0] ?? ''), 'notes' => (string) ($row[1] ?? '')];
        // The old values are written back into SQL later, so they must be what
        // the database always gives here: a list of plain words, and 0 or 1.
        if (preg_match('/\A[A-Za-z0-9_,]*\z/', $was['mode']) !== 1 || in_array($was['notes'], ['0', '1'], true) === false) {
            throw new RuntimeException('This connection\'s sql_mode and sql_notes could not be read in a form that can be put back.');
        }
        $this->ask("SET SESSION sql_mode = '" . self::RESTORE_SQL_MODE . "', sql_notes = 1");

        // Believe the database, not the statement: read both back. If the
        // reading itself fails the settings may already be changed, and the
        // caller has not yet been told what they were, so they are put back
        // from here before the failure is passed on.
        $strict = false;
        try {
            $strict = $this->strictnessIs(['mode' => self::RESTORE_SQL_MODE, 'notes' => '1']);
        } catch (\Throwable $e) {
            $this->putStrictnessBack($was);
            throw $e;
        }
        if ($strict === false) {
            $this->putStrictnessBack($was);
            throw new RuntimeException('The database would not make this connection strict, so nothing was written.');
        }
        return $was;
    }

    /**
     * Are this session's `sql_mode` and `sql_notes` exactly these, as the
     * database itself reports them? The words of a mode are compared as a
     * set, because the database lists them in an order of its own.
     *
     * @param array{mode: string, notes: string} $wanted
     */
    private function strictnessIs(array $wanted): bool
    {
        $answer = $this->ask('SELECT @@session.sql_mode, @@session.sql_notes');
        $row    = (array) $answer->fetch_row();
        $answer->free();
        $now  = explode(',', (string) ($row[0] ?? ''));
        $want = explode(',', $wanted['mode']);
        sort($now);
        sort($want);
        return $now === $want && (string) ($row[1] ?? '') === $wanted['notes'];
    }

    /**
     * Put the connection's `sql_mode` and `sql_notes` back, and be SURE of it.
     * Never throws: it runs in `finally` blocks.
     *
     * IF THEY CANNOT BE PUT BACK, THE CONNECTION IS CLOSED. This used to try
     * once and ignore a failure, so a restore could answer "success" and hand
     * back a connection still in the restore's own strictness: everything the
     * page did next (reopen the portal, write the activity log) would then
     * have run under the restore's own settings, which are not the ones the
     * portal asks for on every other request. A closed connection
     * cannot do that. The caller is told through $sessionNotPutBack, and
     * restoreAll() and restoreTable() say so in their results (see
     * sayIfSessionNotPutBack()).
     *
     * @param array{mode: string, notes: string}|null $was From pinStrictness(); null = nothing to put back.
     */
    private function putStrictnessBack(?array $was): void
    {
        if ($was === null) {
            return;
        }
        $back = false;
        try {
            $back = $this->db->query("SET SESSION sql_mode = '" . $was['mode'] . "', sql_notes = " . $was['notes']) !== false
                 && $this->strictnessIs($was) === true;
        } catch (\Throwable $ignored) {
            unset($ignored);
        }
        if ($back === true) {
            return;
        }
        $this->sessionNotPutBack = true;
        try {
            $this->db->close();
        } catch (\Throwable $ignored) {
            // Already closed, which is all that was wanted.
            unset($ignored);
        }
    }

    /**
     * Add to a restore's result, in words, that the connection's settings
     * could not be put back and the connection was closed (see
     * putStrictnessBack()). Returns the result untouched when they were.
     *
     * `connection_closed` becomes true, which every caller already treats as
     * "do not use this connection again in this request".
     *
     * @param array<string, mixed> $result From restoreAllWork() or restoreTableWork().
     * @param string               $what   'restore' or 'rehearsal', for the sentence.
     * @param string               $key    Where a sentence for a SUCCESSFUL result goes:
     *                                     'warnings' (a list) or 'notice' (one text).
     *
     * @return array<string, mixed>
     */
    private function sayIfSessionNotPutBack(array $result, string $what, string $key): array
    {
        if ($this->sessionNotPutBack === false) {
            return $result;
        }
        $done = 'Nothing in the database has changed.';
        if ($result['success'] === true) {
            $done = $what === 'rehearsal'
                ? 'The rehearsal itself finished, and it changed nothing.'
                : 'The restore itself FINISHED and is permanent.';
        }
        $sentence = 'After the ' . $what . ' ended, the settings of the connection to the database (how strictly it '
                  . 'treats values) could not be put back as they were, so the connection was closed, to be sure '
                  . 'nothing else runs under the ' . $what . '\'s own settings. ' . $done . ' Reload the page before '
                  . 'doing anything else.';

        $result['connection_closed'] = true;
        if ($result['success'] === false) {
            $result['error'] = trim((string) ($result['error'] ?? '') . ' ' . $sentence);
        } elseif ($key === 'warnings') {
            $result['warnings'][] = $sentence;
        } else {
            $result[$key] = trim((string) ($result[$key] ?? '') . ' ' . $sentence);
        }
        return $result;
    }

    /**
     * 🧱 Can the database UNDO work done on every one of these tables?
     *
     * Everything a restore and a rehearsal promise rests on that: "if anything
     * fails, nothing has changed" is the database undoing a transaction, and
     * only a table kept by the InnoDB engine takes part in one. A table of any
     * other kind (MyISAM is the usual one on an old host) is changed for good
     * the moment a statement runs, and a rehearsal of it would be a real
     * restore. Every table is InnoDB today and the project's rule is that they
     * stay so, but a rule is not a check, and a host can convert a table.
     *
     * A table that is in the snapshot and not in the database is left for the
     * restore itself to refuse, as it always has.
     *
     * @param array<int, string> $tables
     *
     * @return string Empty when every table can be undone, otherwise why not.
     */
    private function notUndoableProblem(array $tables): string
    {
        $answer = $this->ask('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        $engine = [];
        while (($row = $answer->fetch_row()) !== null) {
            $engine[(string) $row[0]] = (string) $row[1];
        }
        $answer->free();
        foreach ($tables as $table) {
            if (isset($engine[$table]) === true && strcasecmp($engine[$table], 'InnoDB') !== 0) {
                return 'The table ' . $table . ' is kept by the database\'s "' . $engine[$table] . '" engine, which '
                     . 'cannot undo a change. A restore or a rehearsal that failed part way could not be taken '
                     . 'back, so it was not started. The table needs converting to InnoDB first.';
            }
        }
        return '';
    }

    /**
     * 🧲 Tables that are NOT in the snapshot but can still be changed by
     * putting it back, each with the columns that link it to a table that is.
     *
     * HOW. Putting a snapshot back empties its tables first. A table outside
     * the snapshot (one added by a migration after the snapshot was taken is
     * the everyday case) may point at rows of one inside it, and the link's
     * own rule then applies as the rows go: "delete with it" deletes the
     * outside rows, and "empty the pointer" empties their pointers. Nothing
     * brings them back, because they were never in the snapshot. That is the
     * right result for "the database as it stood then", but it used to happen
     * without a word. (A link whose rule is "refuse" stops the restore, with
     * the database unchanged.)
     *
     * Followed onward as well: a table that points at an outside table that
     * points at one in the snapshot is included. So are an outside table's
     * pointers at ITSELF: when some of its rows are deleted, rows of the same
     * table that pointed at them have those pointers emptied. (Those used to
     * be left out of the count, so "2 rows held a value and 1 does now" in an
     * outside table's own pointer went unsaid.)
     *
     * @param array<int, string>                              $tables The snapshot's tables.
     * @param array<string, array<int, array<string, mixed>>> $links  From links().
     *
     * @return array<string, array<int, string>> Outside table => its linking columns.
     */
    private function outsideTables(array $tables, array $links): array
    {
        $reached = array_fill_keys($tables, true);
        $outside = [];
        do {
            $added = false;
            foreach ($links as $table => $tableLinks) {
                if (isset($reached[$table]) === true) {
                    continue;
                }
                foreach ($tableLinks as $link) {
                    if (isset($reached[$link['parent']]) === true) {
                        $outside[$table] = [];
                        $reached[$table] = true;
                        $added           = true;
                        break;
                    }
                }
            }
        } while ($added === true);

        foreach (array_keys($outside) as $table) {
            foreach ($links[$table] as $link) {
                // Every outside table is in $reached by now, so this takes in
                // a link to a restored table, to another outside table, and
                // to the table itself.
                if (isset($reached[$link['parent']]) === true) {
                    $outside[$table] = array_values(array_unique(array_merge($outside[$table], $link['columns'])));
                }
            }
        }
        ksort($outside);
        return $outside;
    }

    /**
     * How many rows each outside table holds, and how many of them hold a
     * value in each linking column. Asked inside the restore's transaction,
     * once before anything is emptied and once after everything is refilled.
     *
     * @param array<string, array<int, string>> $outside From outsideTables().
     *
     * @return array<string, array{rows: int, pointers: array<string, int>}>
     */
    private function countOutside(array $outside): array
    {
        // These names come from the database's own list, not from a file, and
        // are quoted the way the database quotes them.
        $quote  = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';
        $counts = [];
        foreach ($outside as $table => $columns) {
            $sql = 'SELECT COUNT(*)';
            foreach ($columns as $column) {
                $sql .= ', COUNT(' . $quote($column) . ')';
            }
            $answer = $this->ask($sql . ' FROM ' . $quote($table));
            $row    = array_map('intval', (array) $answer->fetch_row());
            $answer->free();
            $counts[$table] = ['rows' => $row[0] ?? 0, 'pointers' => array_combine($columns, array_slice($row, 1))];
        }
        return $counts;
    }

    /**
     * What changed in the outside tables, in words and as numbers.
     *
     * The numbers are before-and-after counts, not a list of rows: "3 rows
     * before, 1 now". A pointer count can fall for either reason (the pointer
     * was emptied, or its row was deleted), so the sentence says what was
     * counted and does not claim to know which.
     *
     * @return array{0: string, 1: array<string, array<string, mixed>>} The
     *         sentence (empty when nothing changed) and the changed tables.
     */
    private static function outsideChanges(array $before, array $after, bool $dryRun): array
    {
        $changed = [];
        $named   = [];
        foreach ($before as $table => $was) {
            $now = $after[$table] ?? $was;
            if ($now === $was) {
                continue;
            }
            $changed[$table] = ['rows_before' => $was['rows'], 'rows_after' => $now['rows'], 'pointers' => []];
            if ($now['rows'] !== $was['rows']) {
                $named[] = $table . ' held ' . $was['rows'] . ' row(s) and ' . ($dryRun === true ? 'would hold ' : 'now holds ') . $now['rows'];
            }
            foreach ($was['pointers'] as $column => $count) {
                if ($now['pointers'][$column] !== $count) {
                    $changed[$table]['pointers'][$column] = ['before' => $count, 'after' => $now['pointers'][$column]];
                    $named[] = 'in ' . $table . ', ' . $count . ' row(s) held a value in ' . $column . ' and '
                             . $now['pointers'][$column] . ($dryRun === true ? ' would' : ' do now');
                }
            }
        }
        if ($changed === []) {
            return ['', []];
        }
        return [
            count($changed) . ' table(s) that are NOT in this snapshot ' . ($dryRun === true ? 'would be' : 'were')
            . ' changed as well, because their rows are linked to rows of tables that are, and the database\'s own '
            . 'rule for each link deletes those rows or empties their pointers when the rows they point at are '
            . 'replaced: ' . implode('; ', $named) . '. A snapshot cannot bring these back: they were never in it.',
            $changed,
        ];
    }

    // =========================================================================
    // 🚩 Values that are not on their column's list of choices
    // =========================================================================

    /**
     * The "values not on their column's list" a manifest records, checked
     * and tidied. The manifest is a file a person can edit, and what comes
     * out of here is shown on pages, so anything not in the proper form is
     * dropped rather than trusted.
     *
     * @param array<string, mixed> $manifest A decoded `_manifest.json`.
     *
     * @return array<int, array{table: string, column: string, cells: int}>
     *         Empty when the manifest records none, or was written before
     *         snapshots looked for them (which cannot be told apart here).
     */
    public static function unlistedIn(array $manifest): array
    {
        $found = [];
        foreach ((array) ($manifest['unlisted_values'] ?? []) as $one) {
            if (is_array($one) === true
                && is_string($one['table'] ?? null) === true && preg_match(self::IDENTIFIER, $one['table']) === 1
                && is_string($one['column'] ?? null) === true && preg_match(self::IDENTIFIER, $one['column']) === 1
                && is_int($one['cells'] ?? null) === true && $one['cells'] > 0
            ) {
                $found[] = ['table' => $one['table'], 'column' => $one['column'], 'cells' => $one['cells']];
            }
        }
        return $found;
    }

    /**
     * 🚩 The ONE sentence for "this snapshot cannot be put back whole", used
     * by snapshot()'s result, the backup page, the upgrade page and the
     * health page, so that all four say the same thing.
     *
     * WHY IT EXISTS. A column with a list of choices (an ENUM) on a database
     * set to accept anything stores a value that is not on the list as an
     * empty text, without a word. (Since 12 October 2026 the portal's own
     * connection refuses such a value, so a new one can only arrive from
     * before that date, from a server that refuses the portal's strict
     * setting, which the health page reports, or from something written
     * outside the portal such as a hosting panel or an import.) A restore
     * refuses that value (see
     * pinStrictness() for why it must), so ONE such cell makes the whole
     * snapshot impossible to put back, and until October 2026 nobody was told
     * until they pressed Check or needed the restore. The snapshot is still
     * TAKEN: it is good for reading, and for putting back a single table that
     * holds no such value.
     *
     * @param array<int, array{table: string, column: string, cells: int}> $unlisted From unlistedIn().
     *
     * @return string Empty when there is nothing to say.
     */
    public static function unlistedWarning(array $unlisted): string
    {
        if ($unlisted === []) {
            return '';
        }
        $named = [];
        $cells = 0;
        foreach ($unlisted as $one) {
            $cells  += $one['cells'];
            $named[] = $one['table'] . '.' . $one['column'] . ' (' . $one['cells'] . ')';
        }
        $first = $unlisted[0];
        return 'This snapshot CANNOT BE PUT BACK WHOLE as it stands. It holds ' . $cells . ' value(s) that are not on '
             . 'their column\'s list of choices: ' . implode(', ', array_slice($named, 0, 10))
             . (count($named) > 10 ? ', and ' . (count($named) - 10) . ' more column(s)' : '') . '. The database kept each '
             . 'as an empty text because it was set to accept anything when the value was saved, and a restore refuses '
             . 'such a value rather than change it. What to do: mend those rows in the live database, giving each a '
             . 'value from the column\'s list (or emptying it, where the column may be empty), then take a NEW snapshot. '
             . 'This statement finds the rows of the first one: SELECT * FROM `' . $first['table'] . '` WHERE `'
             . $first['column'] . '` = 0; (0 is the number the database gives a value that is not on the list). Until '
             . 'then this snapshot is still good for looking at, and for putting back a single table that holds no '
             . 'such value.';
    }

    // =========================================================================
    // 📸 Taking a snapshot
    // =========================================================================

    /**
     * 🧊 The three answers to "was this snapshot taken at one moment?" (#581).
     *
     * Three and not two, because "the database said nothing" and "this code
     * could not find out" are different facts and reading them as one is how a
     * reassurance comes to be printed over a snapshot nobody checked. They are
     * written into the manifest and are the words a reader compares against, so
     * changing one changes what every snapshot already on disk means: don't.
     */
    public const ONE_MOMENT_YES         = 'yes';
    public const ONE_MOMENT_NO          = 'no';
    public const ONE_MOMENT_CANNOT_TELL = 'cannot tell';

    /**
     * 🧊 A FOURTH word, which a snapshot is never TAKEN with and which only a
     * RESTORE ever uses: the manifest holds no answer at all.
     *
     * It is a separate word from ONE_MOMENT_CANNOT_TELL on purpose, and the
     * distinction is not a nicety. "It looked and could not tell" and "it
     * never looked, because it was taken before snapshots looked" are
     * different facts about different snapshots, and the first draft of the
     * sentence below said "an older one never looked" for BOTH — which is
     * untrue, in the reassuring direction, of a snapshot that really did look.
     * It is never written into a manifest.
     */
    public const ONE_MOMENT_NOT_RECORDED = 'not recorded';

    /**
     * 🧊 Did the database really give this connection one consistent view of
     * every table, or did it ignore being asked? (#581)
     *
     * Called by snapshot() IMMEDIATELY after
     * `START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY`, and nowhere
     * else, because what it reads is that statement's own answer.
     *
     * ⚠️ `@@transaction_isolation` IS THE WRONG THING TO READ, AND READING IT
     *    WOULD HAVE BEEN THE OBVIOUS WAY. Measured on mysql:8.0.36 started
     *    with `--transaction-isolation=READ-COMMITTED`: inside a transaction
     *    that the `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ` line above
     *    had already put right, and which really did give a consistent view,
     *    `SELECT @@transaction_isolation` still answers READ-COMMITTED —
     *    because `SET TRANSACTION` with no scope word sets the NEXT
     *    transaction only and never touches the session value that variable
     *    reports. So a check built on it would have condemned a working
     *    snapshot on every such host. The statement's own warning is the only
     *    thing measured to answer the real question.
     *
     * HOW THE WARNING IS READ, and why "any warning" is safe here. MySQL says
     * `Warning 138: InnoDB: WITH CONSISTENT SNAPSHOT was ignored because this
     * phrase can only be used with REPEATABLE READ isolation level.` A clean
     * server says nothing at all, and `START TRANSACTION WITH CONSISTENT
     * SNAPSHOT` CLEARS the warning list (measured: a `SELECT 1/0` immediately
     * before it leaves `@@warning_count` at 0 and `SHOW WARNINGS` empty), so a
     * leftover warning from an earlier statement cannot be mistaken for this
     * one. A warning that does NAME the phrase is a plain no; any OTHER
     * warning means the database said something about this statement that this
     * code has not been taught, which is "cannot tell" and not "no", because a
     * check that cries wolf gets switched off (#483).
     *
     * ⚠️ WHAT THIS CANNOT TELL YOU, measured rather than assumed. MariaDB
     *    11.4.13 prints NO WARNING when it ignores the phrase: asked for
     *    `SET TRANSACTION ISOLATION LEVEL READ COMMITTED` and then the
     *    consistent snapshot, it answered with an empty warning list. So on
     *    MariaDB this method answers ONE_MOMENT_YES whatever happened, and
     *    that answer means only "nothing was said". What was NOT measured is
     *    whether MariaDB's read is really inconsistent in that case — only
     *    that it would not say so. MySQL is the engine this portal is built
     *    for, and on MySQL the answer is real.
     *
     * @return string One of the three ONE_MOMENT_* words above.
     */
    private function consistentReadVerdict(): string
    {
        try {
            $answer = $this->db->query('SHOW WARNINGS');
        } catch (\Throwable $e) {
            // Never the database's own words anywhere this class reports: a
            // message can quote a value. "Could not find out" is the honest
            // answer, and it is not "no".
            return self::ONE_MOMENT_CANNOT_TELL;
        }
        if ($answer === false) {
            return self::ONE_MOMENT_CANNOT_TELL;
        }

        $said = [];
        while (($row = $answer->fetch_assoc()) !== null) {
            $said[] = (string) ($row['Message'] ?? '');
        }
        $answer->free();

        if ($said === []) {
            return self::ONE_MOMENT_YES;
        }
        foreach ($said as $message) {
            if (stripos($message, 'CONSISTENT SNAPSHOT') !== false) {
                return self::ONE_MOMENT_NO;
            }
        }

        return self::ONE_MOMENT_CANNOT_TELL;
    }

    /**
     * 🧊 What a manifest says about being read at one moment, tidied (#581).
     *
     * The same reasoning as unlistedIn(): a manifest is a file a person can
     * edit and what comes out of here is shown on pages, so a word that is not
     * one of the three a snapshot can be written with is treated as no answer
     * rather than trusted or echoed back.
     *
     * @param array<string, mixed> $manifest A decoded `_manifest.json`.
     *
     * @return string ONE_MOMENT_YES, ONE_MOMENT_NO, ONE_MOMENT_CANNOT_TELL, or
     *         ONE_MOMENT_NOT_RECORDED when the manifest holds no usable answer.
     */
    public static function oneMomentIn(array $manifest): string
    {
        $said = $manifest['one_moment'] ?? null;
        if (is_string($said) === true
            && in_array($said, [self::ONE_MOMENT_YES, self::ONE_MOMENT_NO, self::ONE_MOMENT_CANNOT_TELL], true) === true
        ) {
            return $said;
        }

        return self::ONE_MOMENT_NOT_RECORDED;
    }

    /**
     * 🧊 What to tell somebody about a snapshot that was not taken at one
     * moment, or that cannot say whether it was (#581).
     *
     * ⚠️ FIVE COMPLETE SENTENCES, ONE PER BRANCH THAT CAN BE REACHED, and
     *    deliberately not one sentence with the differences spliced into it.
     *    #694 collected four separate wording faults inside a single
     *    ninety-line assembled sentence, every one of them a ternary in the
     *    middle of a clause and not one of them findable by any check. So each
     *    case is written out whole, here, where it can be read.
     *
     *    The five are: no/taking, no/restoring, cannot tell/taking,
     *    cannot tell/restoring, and not recorded/restoring. There is no
     *    not-recorded/taking case — a snapshot being taken always has an
     *    answer — and ONE_MOMENT_YES has no sentence at all, because there is
     *    nothing to warn about and a reassurance printed on every snapshot is
     *    how a real warning stops being read (#694's "a check that pins a
     *    reassurance" shape, in prose).
     *
     * @param string $verdict  One of the ONE_MOMENT_* words.
     * @param bool   $restoring True when this is said to somebody PUTTING the
     *                          snapshot back, false when it is said to
     *                          somebody who has just taken it. The facts are
     *                          the same; what they can do about them is not.
     */
    public static function oneMomentWarning(string $verdict, bool $restoring): string
    {
        if ($verdict === self::ONE_MOMENT_NOT_RECORDED && $restoring === true) {
            return 'This snapshot does not say whether it was read as one moment in time. It was taken '
                 . 'before snapshots recorded that, so it never looked, and nothing can work it out after '
                 . 'the event. Nothing is lost by trying: a restore that is refused changes nothing at all. '
                 . 'If it is refused with a message about a row pointing at a row that is not in the '
                 . 'snapshot, that is the fault this answer would have warned you about.';
        }
        if ($verdict === self::ONE_MOMENT_NO && $restoring === false) {
            return 'This snapshot was NOT read as one moment in time. The database would not give this '
                 . 'connection a single consistent view, so the tables were read one after another: if '
                 . 'anybody saved anything while the snapshot ran, it can hold one half of a record and '
                 . 'not the other, and putting it back would then be refused. The snapshot is still worth '
                 . 'keeping, and a snapshot taken while nobody is using the portal is unaffected. Ask your '
                 . 'hosting company to let the portal read at the REPEATABLE READ isolation level, which is '
                 . 'MySQL\'s own default.';
        }
        if ($verdict === self::ONE_MOMENT_NO && $restoring === true) {
            return 'This snapshot says it was NOT read as one moment in time: its tables were read one '
                 . 'after another, so it may hold one half of a record saved while it was being taken. '
                 . 'That is why a restore can be refused partway with a message about a row pointing at a '
                 . 'row that is not in the snapshot. Nothing is lost by trying — a restore that is refused '
                 . 'changes nothing at all — but if it is refused for that reason, this snapshot cannot be '
                 . 'put back whole, and the one to use is a later snapshot whose own answer is "yes".';
        }
        if ($verdict === self::ONE_MOMENT_CANNOT_TELL && $restoring === false) {
            return 'Whether this snapshot was read as one moment in time could not be established. The '
                 . 'portal asked the database for a single consistent view of every table and the database '
                 . 'answered with something the portal has not been taught to read, so this is "not known" '
                 . 'rather than "no". Most likely it was fine. If it was not, the snapshot can hold one '
                 . 'half of a record saved while it ran, and putting it back would be refused rather than '
                 . 'go wrong quietly.';
        }
        if ($verdict === self::ONE_MOMENT_CANNOT_TELL && $restoring === true) {
            return 'This snapshot looked at whether it was being read as one moment in time and could not '
                 . 'establish it: the database answered with something the portal has not been taught to '
                 . 'read. So this is "not known" rather than "no", and most likely it was fine. Nothing is '
                 . 'lost by trying: a restore that is refused changes nothing at all. If it is refused '
                 . 'with a message about a row pointing at a row that is not in the snapshot, that is what '
                 . 'this answer could not rule out.';
        }

        return '';
    }

    /**
     * Take a snapshot of every `tbl*` table.
     *
     * A snapshot that fails removes its own folder, so a failure can never be
     * mistaken for a backup. (PHP cannot do that when the process is killed or
     * runs out of memory; prune() removes such a folder once it has sat
     * unchanged for 24 hours, and until then it is listed as unfinished.)
     *
     * A snapshot that SUCCEEDS can still carry a warning: see
     * unlistedWarning() and oneMomentWarning(). `warnings` is then not empty,
     * and the caller must show it.
     *
     * @param string $reason Short label kept in the manifest
     *                       (e.g. 'pre-upgrade-1.4.0').
     *
     * @return array{success: bool, directory: string, tables: int, rows: int, error?: string,
     *               warnings: array<int, string>, one_moment: string,
     *               unlisted_values: array<int, array{table: string, column: string, cells: int}>}
     *         one_moment is one of ONE_MOMENT_YES, ONE_MOMENT_NO or
     *         ONE_MOMENT_CANNOT_TELL; see consistentReadVerdict() for what
     *         each one does and does not promise. On a failure it is
     *         ONE_MOMENT_CANNOT_TELL, because no snapshot was read.
     */
    public function snapshot(string $reason = ''): array
    {
        $fail = static function (string $why): array {
            return ['success' => false, 'directory' => '', 'tables' => 0, 'rows' => 0, 'error' => $why,
                    'warnings' => [], 'one_moment' => self::ONE_MOMENT_CANNOT_TELL, 'unlisted_values' => []];
        };

        // 🗂️ Make sure the backups folder exists.
        if (is_dir($this->backupsRoot) === false) {
            if (mkdir($this->backupsRoot, 0750, true) === false
                && is_dir($this->backupsRoot) === false
            ) {
                return $fail('Could not create the backups folder: ' . $this->backupsRoot);
            }
        }

        // 🔍 Ask the database what it holds, and refuse BEFORE writing anything
        //    if there is a column this class does not know how to carry.
        try {
            $facts  = $this->columnFacts();
            $tables = [];
            $answer = $this->ask("SHOW TABLES LIKE 'tbl%'");
            while (($row = $answer->fetch_row()) !== null) {
                $tables[] = (string) $row[0];
            }
            $answer->free();
        } catch (\Throwable $e) {
            return $fail('The database would not describe its tables, so no snapshot was taken: ' . $e->getMessage());
        }

        foreach ($tables as $table) {
            // The names go into SQL and into file names below.
            if (preg_match(self::IDENTIFIER, $table) !== 1) {
                return $fail('No snapshot was taken, because a table has a name this backup cannot handle: ' . $table);
            }
            if (isset($facts[$table]) === false) {
                return $fail('Could not read the shape of ' . $table . ' from the database, so no snapshot was taken.');
            }
            foreach ($facts[$table] as $column => $fact) {
                if ($fact['generated'] === true) {
                    continue;
                }
                if (preg_match(self::IDENTIFIER, $column) !== 1) {
                    return $fail('No snapshot was taken, because ' . $table . ' has a column whose name this '
                               . 'backup cannot handle: ' . $column);
                }
                if (self::carriedAs($fact['type']) === null) {
                    return $fail('No snapshot was taken, because the column ' . $table . '.' . $column
                               . ' has the type "' . $fact['type'] . '", which this backup has not been taught '
                               . 'to copy faithfully. Guessing could bring back different values, so it '
                               . 'refuses instead. The portal needs updating to handle this column.');
                }
            }
        }

        // 📁 One folder per snapshot, named by the second. A folder that is
        //    already there belongs to another snapshot: the old code wrote into
        //    it, mixing two snapshots' files under one manifest.
        //
        //    🕑 THE NIGHT THE CLOCKS GO BACK. The name is local clock time, and
        //    on that night every second between 01:00 and 02:00 comes round
        //    twice. A snapshot taken exactly an hour after another found "its"
        //    name taken and was refused as "the same second". So a taken name
        //    is now waited out: one second later the name is free, still in the
        //    same form, and still the true local time the folder was made.
        //    Tried a few times and then refused as before, so that a name which
        //    stays taken never becomes a loop. (Naming folders in UTC instead
        //    was considered and not done: the recovery guide and every
        //    administrator read the name as local time, and old and new
        //    folders would then sort against each other an hour or more out.)
        $dir = '';
        for ($try = 1; $try <= self::NAME_TRIES; $try++) {
            $dir = $this->backupsRoot . DIRECTORY_SEPARATOR . 'upgrade-' . date('Ymd-His');
            if (file_exists($dir) === false) {
                break;
            }
            if ($try === self::NAME_TRIES) {
                return $fail('A snapshot folder with this second\'s name is already there, and so were the '
                           . 'ones before it, so this snapshot was not taken. Wait a moment and try again.');
            }
            sleep(1);
        }
        if (mkdir($dir, 0750) === false) {
            // Nothing of ours to tidy up: the folder is not ours (another
            // request made it between the two lines above) or was never made.
            return $fail('Could not create the snapshot folder: ' . $dir);
        }

        $finished = false;
        $zoneWas  = null;
        try {
            $zoneWas = $this->pinTimeZone('+00:00');

            // 🧊 ONE moment for every table. The tables are read one after
            //    another, and without this a record saved while the snapshot
            //    ran could have one half copied (its child row, read late) and
            //    not the other (its parent row, read early): a snapshot that can
            //    never go back. Inside this kind of transaction the database
            //    shows every table as it stood at the moment the transaction
            //    began, however long the reading takes. It takes no locks, so
            //    nobody else is held up. Allowed for a database user with
            //    rights on one database only (tried).
            $this->ask('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->ask('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
            // 🔎 AND ASK WHETHER THE DATABASE REALLY GAVE ONE (#581). The two
            //    lines above USED TO BE TRUSTED, and the first of them is the
            //    one that does the work on a host whose own setting is READ
            //    COMMITTED: measured on mysql:8.0.36 started with
            //    `--transaction-isolation=READ-COMMITTED`, deleting it alone
            //    made every snapshot stop being taken at one moment, the
            //    snapshot still answered "success", and the restore then
            //    refused it as a child row with no parent. On a server whose
            //    setting is REPEATABLE READ — which is MySQL's default and what
            //    every self-test in this repository runs on — that same
            //    deletion changes nothing, so no test here could have seen it.
            //    See consistentReadVerdict() for what the answer can and
            //    cannot tell you.
            $oneMoment = $this->consistentReadVerdict();

            $manifest = [
                'format'         => self::FORMAT,
                'created_at'     => date('c'),
                'reason'         => $reason,
                'php_version'    => PHP_VERSION,
                'mysql_server'   => (string) $this->db->server_info,
                'portal_version' => defined('PORTAL_VERSION') === true ? (string) PORTAL_VERSION : '',
                'time_zone'      => '+00:00',
                // 🧾 THIS SNAPSHOT COVERS THE WHOLE DATABASE, and says so.
                //    Added 27 October 2026 (owner's decision) after the Codex
                //    review found that restoreAll() took its table list from
                //    the manifest and nothing else — so a manifest edited down
                //    to one name turned "restore ALL" into a one-table restore
                //    of a table the one-table path refuses by name.
                //
                //    Every snapshot this method takes IS whole: the list comes
                //    from `SHOW TABLES LIKE 'tbl%'` a few lines below. The flag
                //    is not here to record a choice; it is here so a RESTORE can
                //    tell a snapshot this portal wrote from a manifest somebody
                //    has edited. `table_count` is the same fact in a form that
                //    survives somebody deleting entries from `tables`.
                //
                //    A snapshot written BEFORE this flag existed does not carry
                //    it, and cannot be told from a tampered one — see
                //    restoreAll(), which skips the protected tables for those
                //    and says so rather than guessing.
                'whole_database' => true,
                'table_count'    => count($tables),
                // 🧊 WAS THIS SNAPSHOT TAKEN AT ONE MOMENT? One of the three
                //    words consistentReadVerdict() answers. Written for the
                //    person who has to put it back: a snapshot that was read
                //    table by table can hold half a record, and the restore
                //    finds that out only when it refuses the second half.
                //    NOT in the schema's `required` list, for the same reason
                //    `whole_database` is not: a snapshot written before this
                //    existed does not carry it, and must still restore.
                'one_moment'     => $oneMoment,
                'tables'         => [],
            ];
            $totalRows = 0;
            // 🚩 Values that are not on their column's list of choices, found
            //    as the tables are read (see dumpTable() and unlistedWarning()).
            $unlisted  = [];
            foreach ($tables as $table) {
                $written = $this->dumpTable($dir, $table, $facts[$table], $unlisted);
                $manifest['tables'][$table] = $written;
                $totalRows += $written['rows'];
            }
            $this->ask('COMMIT');
            // Written whether or not any were found: an empty list says "this
            // snapshot looked and found none", which a manifest without the
            // key (one written before snapshots looked) cannot say.
            $manifest['unlisted_values'] = $unlisted;

            // 📝 The manifest is what marks a snapshot FINISHED, so it is
            //    written last, under another name, and then renamed. A rename
            //    happens all at once: the manifest is either wholly there or
            //    not there. Written straight to its real name (as it used to
            //    be), a process killed mid-write left half a manifest, and the
            //    off-site copy script, which only tests that the file exists,
            //    would have taken the snapshot as finished.
            $json = json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
            $temporary = $dir . DIRECTORY_SEPARATOR . '_manifest.json.tmp';
            if ($json === false
                || file_put_contents($temporary, $json) !== strlen($json)
                || rename($temporary, $dir . DIRECTORY_SEPARATOR . '_manifest.json') === false
            ) {
                throw new RuntimeException('The manifest could not be written (is the disk full?).');
            }

            $finished = true;
            $warning  = self::unlistedWarning($unlisted);
            $warnings = $warning === '' ? [] : [$warning];
            // 🧊 SAY IT HERE TOO, not only in the manifest. Nobody reads a
            //    manifest until the day they need the backup, which is the one
            //    day this is no use to them.
            $oneMomentWarning = self::oneMomentWarning($oneMoment, false);
            if ($oneMomentWarning !== '') {
                $warnings[] = $oneMomentWarning;
            }
            return [
                'success'         => true,
                'directory'       => $dir,
                'tables'          => count($tables),
                'rows'            => $totalRows,
                'warnings'        => $warnings,
                'one_moment'      => $oneMoment,
                'unlisted_values' => $unlisted,
            ];
        } catch (\Throwable $e) {
            return $fail('The snapshot was not taken, and its unfinished folder has been removed. '
                       . 'The reason: ' . $e->getMessage());
        } finally {
            try {
                // Ends the read-only transaction if it is still open. It wrote
                // nothing, so there is nothing to lose either way.
                $this->db->query('ROLLBACK');
            } catch (\Throwable $ignored) {
                unset($ignored);
            }
            $this->putTimeZoneBack($zoneWas);
            if ($finished === false) {
                $this->rmdirRecursive($dir);
            }
        }
    }

    /**
     * Write one table to `{dir}/{table}.jsonl`, a row at a time.
     *
     * 🚩 IT ALSO LOOKS FOR VALUES THAT ARE NOT ON THEIR COLUMN'S LIST OF
     * CHOICES (see unlistedWarning() for why they matter). The database hands
     * such a value over as an empty text, so:
     *
     *   1. while the rows go past anyway, it notes which list-of-choices
     *      (ENUM) columns handed over an empty text at all. That costs one
     *      comparison per empty cell and no statement;
     *   2. only for a table where one was seen, it asks the database ONE
     *      question, inside the same frozen moment as the read: how many
     *      cells of each of those columns hold value number 0? That is the
     *      database's own number for "not on the list", so an empty text that
     *      IS one of a column's choices is not counted.
     *
     * A database with no such value therefore pays for no extra statement.
     * Asking every table that has such a column, every time, was considered
     * and not done: nearly half the product's tables have one (102 of 224 on
     * 12 October 2026), and each would be read through a second time for
     * nothing.
     *
     * A SET column (several choices at once) does not have this fault and is
     * not looked at: the database drops a word that is not on a SET's list
     * and keeps the rest, and what is left, even nothing at all, goes back
     * into a strict session unchanged (tried on MySQL 8.0.36 and 8.4.11; the
     * product has no SET column today).
     *
     * @param array<string, array{type: string, nullable: bool, generated: bool, restamped: bool}> $tableFacts
     * @param array<int, array{table: string, column: string, cells: int}> $unlisted Added to: one
     *        entry for each column of this table holding a value that is not on its list.
     *
     * @return array{columns: array<int, string>, rows: int, sha256: string, file: string}
     */
    private function dumpTable(string $dir, string $table, array $tableFacts, array &$unlisted): array
    {
        // 🚫 Columns the database works out for itself are left out: they
        //    cannot be written back. The columns are NAMED in the query (not
        //    `SELECT *`) so the file's column list and the values can never
        //    fall out of step.
        $columns  = [];
        $asBytes  = [];
        $isChoice = [];
        foreach ($tableFacts as $column => $fact) {
            if ($fact['generated'] === true) {
                continue;
            }
            $columns[]  = $column;
            $asBytes[]  = self::carriedAs($fact['type']) === 'bytes';
            $isChoice[] = $fact['type'] === 'enum';
        }
        $emptySeen = [];   // index of a list-of-choices column => true, once it has handed over an empty text
        if ($columns === []) {
            throw new RuntimeException($table . ' has no column that can be copied.');
        }
        $bytesColumns = [];
        foreach ($columns as $i => $column) {
            if ($asBytes[$i] === true) {
                $bytesColumns[] = $column;
            }
        }

        $name   = $table . '.jsonl';
        $handle = fopen($dir . DIRECTORY_SEPARATOR . $name, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Could not create ' . $name . ' in the snapshot folder.');
        }

        // The checksum is built as the lines are written, so the file is never
        // read back (or held) just to work it out.
        $hash  = hash_init('sha256');
        $write = static function (string $line) use ($handle, $hash, $name): void {
            if (fwrite($handle, $line) !== strlen($line)) {
                throw new RuntimeException('Could not write to ' . $name . ' (is the disk full?).');
            }
            hash_update($hash, $line);
        };
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        $count  = 0;
        $result = null;
        try {
            $write(json_encode(
                ['format' => self::FORMAT, 'table' => $table, 'columns' => $columns, 'base64' => $bytesColumns],
                $flags | JSON_THROW_ON_ERROR
            ) . "\n");

            // 🚰 MYSQLI_USE_RESULT: rows arrive one at a time instead of the
            //    whole table being fetched into memory first. While this result
            //    is open the connection can do NOTHING else, which is why
            //    every question about the table's shape was asked beforehand.
            $result = $this->ask('SELECT `' . implode('`, `', $columns) . '` FROM `' . $table . '`', MYSQLI_USE_RESULT);
            while (($row = $result->fetch_row()) !== null && $row !== false) {
                foreach ($row as $i => $value) {
                    if ($value === null) {
                        continue;
                    }
                    if (is_string($value) === false) {
                        // Only happens if somebody switches the connection to
                        // hand numbers over as numbers. A decimal would then
                        // be rounded on the way through, silently.
                        throw new RuntimeException('The database connection is handing values over as numbers, '
                                                 . 'not as text, so they cannot be copied exactly.');
                    }
                    if ($asBytes[$i] === true) {
                        $row[$i] = base64_encode($value);
                    } elseif ($value === '' && $isChoice[$i] === true) {
                        $emptySeen[$i] = true;   // counted properly below, by the database
                    }
                }

                $line = json_encode($row, $flags);
                if ($line === false) {
                    // 🛟 A TEXT column handed over as bytes that are not valid
                    //    UTF-8 text. On the portal's own connection (utf8mb4)
                    //    the database never does that: it refuses to store
                    //    such bytes in a text column (MySQL 8.0 and 8.4). It
                    //    DOES happen when the caller's connection uses another
                    //    character set, for example a column kept in latin1
                    //    read over a `binary` connection (the self-test does
                    //    exactly that, proof 19). The content of one cell must
                    //    not be able to fail the whole snapshot (that is
                    //    exactly what #577 was), so just those cells are
                    //    carried as bytes, marked, and rows() turns them back.
                    foreach ($row as $i => $value) {
                        if (is_string($value) === true && json_encode($value) === false) {
                            $row[$i] = ['b64' => base64_encode($value)];
                        }
                    }
                    $line = json_encode($row, $flags | JSON_THROW_ON_ERROR);
                }
                $write($line . "\n");
                $count++;
            }
            $result->free();
            $result = null;

            // 🧮 Did every row arrive? A connection that drops in the middle
            //    of a row-at-a-time read can look exactly like the end of the
            //    table. So the database is asked how many rows it holds - the
            //    same moment in time as the read, because both are inside the
            //    one transaction - and a file that is short is refused.
            $answer = $this->ask('SELECT COUNT(*) FROM `' . $table . '`');
            $holds  = (int) ($answer->fetch_row()[0] ?? -1);
            $answer->free();
            if ($holds !== $count) {
                throw new RuntimeException('Only ' . $count . ' of the ' . $holds . ' rows of ' . $table
                                         . ' arrived from the database.');
            }

            // 🚩 Step 2 of the look for values not on their column's list:
            //    one question for this table, only if an empty text was seen.
            //    Comparing a list-of-choices column with the NUMBER 0 asks
            //    for value number 0, which is how the database's own manual
            //    says to find these. A failure here fails the snapshot: a
            //    snapshot must not go out saying "none found" when the
            //    looking did not happen.
            if ($emptySeen !== []) {
                ksort($emptySeen);
                $sums = [];
                foreach (array_keys($emptySeen) as $i) {
                    $sums[] = 'SUM(`' . $columns[$i] . '` = 0)';
                }
                $answer = $this->ask('SELECT ' . implode(', ', $sums) . ' FROM `' . $table . '`');
                $found  = (array) $answer->fetch_row();
                $answer->free();
                foreach (array_keys($emptySeen) as $at => $i) {
                    if ((int) ($found[$at] ?? 0) > 0) {
                        $unlisted[] = ['table' => $table, 'column' => $columns[$i], 'cells' => (int) $found[$at]];
                    }
                }
            }

            $write(json_encode(['end' => true, 'rows' => $count], JSON_THROW_ON_ERROR) . "\n");
        } finally {
            if ($result instanceof \mysqli_result) {
                try {
                    $result->free();
                } catch (\Throwable $ignored) {
                    unset($ignored);
                }
            }
            $closed = fclose($handle);
        }
        if ($closed === false) {
            throw new RuntimeException('Could not finish writing ' . $name . '.');
        }

        return ['columns' => $columns, 'rows' => $count, 'sha256' => hash_final($hash), 'file' => $name];
    }

    // =========================================================================
    // 📖 Reading a snapshot
    // =========================================================================

    /**
     * Find a snapshot folder, read its manifest and work out which form it is.
     *
     * @return array{dir: string, manifest: array<string, mixed>, format: int}|string
     *         The opened snapshot, or a sentence saying why it was not opened.
     */
    private function openSnapshot(string $snapshotPath): array|string
    {
        // 🛡️ Resolve the path and refuse anything that does not land inside the
        //    backups folder (e.g. a forged name containing `../../_auth_keys`),
        //    before any file is opened.
        $resolvedRoot     = realpath($this->backupsRoot);
        $resolvedSnapshot = realpath(rtrim($snapshotPath, DIRECTORY_SEPARATOR));
        if ($resolvedRoot === false
            || $resolvedSnapshot === false
            || str_starts_with($resolvedSnapshot . DIRECTORY_SEPARATOR, $resolvedRoot . DIRECTORY_SEPARATOR) === false
            || is_dir($resolvedSnapshot) === false
        ) {
            return 'That snapshot is not in the backups folder, so it was not opened.';
        }

        $manifestFile = $resolvedSnapshot . DIRECTORY_SEPARATOR . '_manifest.json';
        if (is_readable($manifestFile) === false) {
            return 'This snapshot has no manifest, which means it was never finished, so there is no '
                 . 'way to know what it should contain.';
        }
        $manifest = json_decode((string) file_get_contents($manifestFile), true);
        if (is_array($manifest) === false || is_array($manifest['tables'] ?? null) === false) {
            return 'This snapshot\'s manifest could not be read.';
        }

        // No `format` at all is the old form: every snapshot made before this
        // key existed.
        $format = $manifest['format'] ?? 1;
        if (is_int($format) === false || $format < 1) {
            return 'This snapshot\'s manifest does not say what form it is in.';
        }
        if ($format > self::FORMAT) {
            return 'This snapshot was made by a newer release of the portal, in a form this release '
                 . 'cannot read. Update the portal first, then put it back.';
        }
        if ($format >= 2 && preg_match('/\A[+-]\d\d:\d\d\z/', (string) ($manifest['time_zone'] ?? '')) !== 1) {
            return 'This snapshot\'s manifest does not say which time zone its moments were written in.';
        }

        return ['dir' => $resolvedSnapshot, 'manifest' => $manifest, 'format' => $format];
    }

    /**
     * 🔍 The manifest of the snapshot called $name, for a page that only
     * wants to SHOW it (the backup page's Inspect).
     *
     * It goes through openSnapshot(), the same door every other action uses,
     * so a link in the backups folder that leads outside it is refused here
     * as it is for a check or a restore. Inspect used to read the file
     * itself, and followed such a link: it showed the outside folder's
     * manifest and offered its Restore buttons (which then refused).
     *
     * @return array<string, mixed>|string The manifest, or a sentence saying why it was not opened.
     */
    public function manifestOf(string $name): array|string
    {
        if (self::isSnapshotName($name) === false) {
            return 'That is not a snapshot name.';
        }
        $opened = $this->openSnapshot($this->pathOf($name));
        return is_string($opened) === true ? $opened : $opened['manifest'];
    }

    /**
     * 🔢 Who would be SIGNED OUT by putting this snapshot back whole (#922).
     *
     * Since #598 (D-104, D-108) a browser stays signed in only while the
     * account exists, is switched on, and carries the sign-in number the
     * browser was signed in under. A whole restore puts `tblUsers` back as the
     * snapshot held it, numbers included, so every account whose number
     * differs from the snapshot's, every account not in the snapshot, and
     * every account switched off in it, is signed out on its next page - the
     * administrator pressing Restore included. Until #922 nothing said so
     * beforehand, and afterwards they met a sign-in page that said nothing.
     *
     * Counts accounts that are switched ON now (a switched-off account has no
     * browser to sign out) and would not be current after the restore, in
     * THREE groups, because what each can do next differs (#922 rounds 2-3):
     *   - `changed`: the number differs (usually a password reset, changed or
     *     set since the snapshot, though an earlier restore can do it too).
     *     It must sign in again, and its password, passkeys and Microsoft or
     *     Google links are as they were when the snapshot was taken - those
     *     three tables are not on WHOLE_DATABASE_ONLY, so a restore of either
     *     kind puts them back; whether a route WORKS also depends on settings,
     *     which an old-form snapshot keeps from today, so the page states the
     *     fact and promises no route (#922 rounds 7-8).
     *   - `switchedOff`: switched off in the snapshot; cannot sign in until an
     *     administrator switches it back on.
     *   - `missing`: not in the snapshot; cannot sign in with it at all.
     * And which of those the viewer is (`you`), and whether the viewer is a
     * global administrator IN THE SNAPSHOT (`youGlobalAfter`), because only a
     * global administrator can open the backup page where the result is kept.
     * It reads the snapshot's `tblUsers` file through rows(), the same reader
     * the restore itself uses, and changes nothing.
     *
     * A DATABASE STILL WAITING FOR MIGRATION 270 has no `signInVersion`, and
     * then no browser is signed out for a moved number at all (see
     * Auth::signInFacts()): that one error (1054) is answered by counting the
     * other two groups only, never by giving up - so the page's "could not be
     * worked out" sentence is said only when something really could not be
     * read.
     *
     * WHAT IT CANNOT SAY: who is signed in at this moment (sessions are files
     * the portal does not list), so this counts ACCOUNTS whose browsers would
     * be signed out, not browsers. A snapshot taken before migration 270 has
     * no numbers: every account in it counts as 0, which is what the restore
     * will write.
     *
     * @return array{changed: int, switchedOff: int, missing: int, you: ?string, youGlobalAfter: bool}|null
     *         Null when the snapshot or the database could not be read.
     *         `you` is 'changed', 'switched_off', 'missing' or null (not
     *         signed out). All zero when the snapshot does not hold `tblUsers`
     *         at all, because then the restore leaves it alone (and
     *         `youGlobalAfter` is then true: nothing about the viewer changes).
     */
    public function signInsEndedByRestoring(string $name, int $viewerId): ?array
    {
        if (self::isSnapshotName($name) === false) {
            return null;
        }
        $opened = $this->openSnapshot($this->pathOf($name));
        if (is_string($opened) === true) {
            return null;
        }
        $usersKey = null;
        foreach (array_keys((array) $opened['manifest']['tables']) as $listed) {
            // Capitals ignored: lower_case_table_names is a server setting (#697).
            if (strcasecmp((string) $listed, 'tblUsers') === 0) {
                $usersKey = (string) $listed;
            }
        }
        if ($usersKey === null) {
            return ['changed' => 0, 'switchedOff' => 0, 'missing' => 0, 'you' => null, 'youGlobalAfter' => true];
        }
        try {
            // What the snapshot would put back, per account.
            $then = [];
            foreach ($this->rows($opened['dir'], $usersKey, $opened['format']) as $row) {
                $then[(int) ($row['userID'] ?? 0)] = [
                    'on'      => (int) ($row['isActive'] ?? 0) === 1,
                    'version' => (int) ($row['signInVersion'] ?? 0),
                    'global'  => (int) ($row['isRootAdmin'] ?? 0) === 1,
                ];
            }
            // What every switched-on account carries now. On a database with
            // no `signInVersion` yet (1054, and only that), every number is
            // treated as unchanged, because nothing compares them there.
            $versions = true;
            try {
                $result = $this->db->query('SELECT `userID`, `signInVersion` FROM `tblUsers` WHERE `isActive` = 1');
            } catch (\mysqli_sql_exception $unknownColumn) {
                if ($unknownColumn->getCode() !== 1054) {
                    throw $unknownColumn;
                }
                $versions = false;
                $result   = $this->db->query('SELECT `userID` FROM `tblUsers` WHERE `isActive` = 1');
            }
            if ($result === false) {
                return null;
            }
            $counts = ['changed' => 0, 'switched_off' => 0, 'missing' => 0];
            $you    = null;
            while ($now = $result->fetch_assoc()) {
                $id = (int) $now['userID'];
                // The same three outcomes Auth::signInVerdict() gives the
                // next request, in the same order: no account, switched
                // off, number moved.
                if (isset($then[$id]) === false) {
                    $what = 'missing';
                } elseif ($then[$id]['on'] === false) {
                    $what = 'switched_off';
                } elseif ($versions === true && $then[$id]['version'] !== (int) $now['signInVersion']) {
                    $what = 'changed';
                } else {
                    continue;
                }
                $counts[$what]++;
                if ($id === $viewerId) {
                    $you = $what;
                }
            }
            $result->free();
        } catch (\Throwable) {
            // A file the restore itself would refuse, or a database that
            // would not answer: no number.
            return null;
        }

        return [
            'changed'        => $counts['changed'],
            'switchedOff'    => $counts['switched_off'],
            'missing'        => $counts['missing'],
            'you'            => $you,
            'youGlobalAfter' => ($then[$viewerId]['global'] ?? false) === true,
        ];
    }

    /** The file holding one table, for the form the snapshot is in. */
    private static function tableFile(string $dir, string $table, int $format): string
    {
        return $dir . DIRECTORY_SEPARATOR . $table . ($format === 1 ? '.json' : '.jsonl');
    }

    /**
     * The rows of one table, one at a time, each as column name => value, with
     * bytes already turned back from base64. Every row has the same columns.
     *
     * Throws a RuntimeException, in plain words, the moment anything about the
     * file is not as it should be. restoreAll() and restoreTable() read every
     * file through this once BEFORE touching the database, so those faults
     * cost nothing.
     *
     * @return Generator<int, array<string, ?string>, mixed, int> Returns (as the
     *         generator's return value) the number of rows read.
     */
    private function rows(string $dir, string $table, int $format): Generator
    {
        $file = self::tableFile($dir, $table, $format);

        if ($format === 1) {
            // 🕰️ THE OLD FORM: one JSON document, which PHP can only read
            //    whole. So it costs what it always cost: between three and four
            //    times the file's size in memory. What changed is that only ONE
            //    table is held at a time (the old code held every table at
            //    once), and that a file too big for this server is refused in
            //    words instead of ending the request with a fatal error.
            //
            //    A reader that took the old pretty-printed form a line at a
            //    time was considered and not written: it would be code for
            //    files that age out after ten upgrades.
            $problem = $this->legacyMemoryProblem($file, $table);
            if ($problem !== '') {
                throw new RuntimeException($problem);
            }
            $payload = json_decode((string) file_get_contents($file), true);
            if (is_array($payload) === false || is_array($payload['rows'] ?? null) === false) {
                throw new RuntimeException('The saved copy of ' . $table . ' could not be read.');
            }
            $count = 0;
            $first = null;
            foreach ($payload['rows'] as $row) {
                $count++;
                if (is_array($row) === false || $row === []) {
                    throw new RuntimeException('Row ' . $count . ' of the saved copy of ' . $table . ' could not be read.');
                }
                $names = array_keys($row);
                if ($first === null) {
                    $first = $names;
                    foreach ($names as $column) {
                        if (preg_match(self::IDENTIFIER, (string) $column) !== 1) {
                            throw new RuntimeException('The saved copy of ' . $table . ' names a column in a form that is not valid.');
                        }
                    }
                } elseif ($names !== $first) {
                    // One INSERT is prepared for the whole table, from the
                    // first row's columns. A row with different columns would
                    // have a value silently dropped or left empty.
                    throw new RuntimeException('Row ' . $count . ' of the saved copy of ' . $table
                                             . ' does not have the same columns as the first row.');
                }
                foreach ($row as $column => $value) {
                    if ($value !== null && is_scalar($value) === false) {
                        throw new RuntimeException('Row ' . $count . ' of the saved copy of ' . $table . ' could not be read.');
                    }
                    $row[$column] = $value === null ? null : (string) $value;
                }
                yield $row;
            }
            return $count;
        }

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The saved copy of ' . $table . ' could not be opened.');
        }
        try {
            $head = json_decode((string) fgets($handle), true);
            if (is_array($head) === false
                || ($head['format'] ?? null) !== 2
                || ($head['table'] ?? null) !== $table
                || is_array($head['columns'] ?? null) === false
                || $head['columns'] === []
                || array_is_list($head['columns']) === false
                || is_array($head['base64'] ?? null) === false
            ) {
                throw new RuntimeException('The saved copy of ' . $table . ' does not start with a proper first line.');
            }
            $columns = $head['columns'];
            foreach ($columns as $column) {
                // The names go into SQL, so a hand-edited file must not be
                // able to put anything else there.
                if (is_string($column) === false || preg_match(self::IDENTIFIER, $column) !== 1) {
                    throw new RuntimeException('The saved copy of ' . $table . ' names a column in a form that is not valid.');
                }
            }
            if (count(array_unique($columns)) !== count($columns)) {
                throw new RuntimeException('The saved copy of ' . $table . ' names the same column twice.');
            }
            $isBytes = [];
            foreach ($columns as $i => $column) {
                $isBytes[$i] = in_array($column, $head['base64'], true);
            }
            $width = count($columns);

            $count = 0;
            $ended = false;
            while (($line = fgets($handle)) !== false) {
                if ($ended === true) {
                    throw new RuntimeException('The saved copy of ' . $table . ' has something after its last line.');
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded) === true && ($decoded['end'] ?? null) === true) {
                    // ✋ THE LAST LINE. Without it there is no telling a whole
                    //    file from one cut short by a failed copy: every line
                    //    that did arrive would be perfectly good.
                    if (($decoded['rows'] ?? null) !== $count) {
                        throw new RuntimeException('The saved copy of ' . $table . ' says it holds '
                                                 . json_encode($decoded['rows'] ?? null) . ' rows and holds ' . $count . '.');
                    }
                    $ended = true;
                    continue;
                }
                if (is_array($decoded) === false || array_is_list($decoded) === false || count($decoded) !== $width) {
                    throw new RuntimeException('Row ' . ($count + 1) . ' of the saved copy of ' . $table . ' could not be read.');
                }

                $row = [];
                foreach ($columns as $i => $column) {
                    $value = $decoded[$i];
                    if (is_array($value) === true) {
                        // One cell carried as bytes by the safety net in dumpTable().
                        $value = is_string($value['b64'] ?? null) === true ? base64_decode($value['b64'], true) : false;
                    } elseif ($value !== null && is_string($value) === false) {
                        $value = false;
                    } elseif ($value !== null && $isBytes[$i] === true) {
                        $value = base64_decode($value, true);
                    }
                    if ($value === false) {
                        throw new RuntimeException('Row ' . ($count + 1) . ' of the saved copy of ' . $table
                                                 . ' holds a value that could not be turned back.');
                    }
                    $row[$column] = $value;
                }
                $count++;
                yield $row;
            }
            if ($ended === false) {
                throw new RuntimeException('The saved copy of ' . $table . ' is cut short: its last line is missing.');
            }
            return $count;
        } finally {
            fclose($handle);
        }
    }

    /**
     * 🧾 Is this table's file present, the file that was saved, and readable
     * from its first line to its last?
     *
     * Read twice, a piece at a time: once for the checksum, once decoding every
     * line. A damaged file found half way through a restore is a disaster.
     * Found beforehand, it costs nothing at all.
     *
     * @return string Empty when the file is sound, otherwise what is wrong.
     */
    private function tableFileProblem(string $dir, string $table, array $manifestRow, int $format): string
    {
        $file = self::tableFile($dir, $table, $format);
        if (is_readable($file) === false) {
            return 'The snapshot is incomplete: it lists ' . $table . ' but that file is missing.';
        }

        // A snapshot from a much older version may not have recorded a
        // checksum. Not being able to check is not the same as failing a check.
        $expected = (string) ($manifestRow['sha256'] ?? '');
        if ($expected !== '') {
            $actual = hash_file('sha256', $file);
            if ($actual === false || hash_equals($expected, $actual) === false) {
                return 'The saved copy of ' . $table . ' is damaged: it is not the file that was saved. '
                     . 'It has been changed or cut short since the snapshot was taken, so it is not safe '
                     . 'to put back into the database.';
            }
        }

        try {
            $reader = $this->rows($dir, $table, $format);
            foreach ($reader as $unused) {
                unset($unused);
            }
            $count = $reader->getReturn();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        if (isset($manifestRow['rows']) === true && (int) $manifestRow['rows'] !== $count) {
            return 'The saved copy of ' . $table . ' holds ' . $count . ' rows and the manifest says '
                 . (int) $manifestRow['rows'] . '.';
        }
        return '';
    }

    /**
     * Would reading this old-form file whole run PHP out of memory?
     *
     * Running out of memory is a fatal error: nothing after it runs, so the
     * page that closed the portal for the restore never reopens it. Refusing
     * first, in words, is far kinder.
     *
     * WHAT THIS CANNOT DO: promise. The factor comes from two measurements,
     * and a table of very many very short values costs more per byte. A file
     * just under the line can still run out.
     *
     * @return string Empty when it should fit (or PHP has no limit), otherwise why not.
     */
    private function legacyMemoryProblem(string $file, string $table): string
    {
        $raw = trim((string) ini_get('memory_limit'));
        if (preg_match('/^(\d+)\s*([KMG]?)$/i', $raw, $parts) !== 1) {
            return ''; // -1 (no limit) or something unreadable: nothing to compare with.
        }
        // PHP writes the limit as a number of bytes, or a number followed by
        // K, M or G.
        $limit = (int) $parts[1] * match (strtoupper($parts[2])) {
            'K'     => 1024,
            'M'     => 1024 * 1024,
            'G'     => 1024 * 1024 * 1024,
            default => 1,
        };
        $size  = filesize($file);
        if ($size === false || $limit <= 0) {
            return '';
        }

        $needed = (int) ceil($size * self::LEGACY_MEMORY_FACTOR);
        if ($needed <= $limit - memory_get_usage()) {
            return '';
        }
        return sprintf(
            'The saved copy of %s is in the older form, which has to be read whole. That needs about %d MB of '
            . 'memory and this server allows PHP %d MB. Ask the host for a higher memory limit, or put this '
            . 'snapshot back from a command line.',
            $table,
            (int) ceil($needed / 1048576),
            (int) floor($limit / 1048576)
        );
    }

    // =========================================================================
    // 📋 Listing, freshness and pruning
    // =========================================================================

    /**
     * List every folder under the backups root, newest name first.
     *
     * `complete` is true only for a FINISHED snapshot: one whose manifest is
     * there and can be read. Everything else is listed too, so an
     * administrator can see it and delete it, but it is not a backup and
     * nothing may count it as one. (A folder whose manifest could not be read
     * used to be left out of the list altogether, so the page could not even
     * delete it.)
     *
     * `unlisted_values` is what the snapshot's manifest records about values
     * that are not on their column's list of choices (see unlistedWarning()):
     * not empty means this snapshot cannot be put back whole.
     *
     * @return array<int, array{name: string, path: string, created_at: string,
     *                          tables: int, rows: int, reason: string,
     *                          complete: bool,
     *                          unlisted_values: array<int, array{table: string, column: string, cells: int}>}>
     */
    public function listSnapshots(): array
    {
        if (is_dir($this->backupsRoot) === false) {
            return [];
        }
        $entries = scandir($this->backupsRoot, SCANDIR_SORT_DESCENDING);
        if ($entries === false) {
            return [];
        }
        $snapshots = [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            // 🛑 A reserved folder is not a snapshot and must not be listed as
            //    one. `incoming` holds files an administrator brought back from
            //    elsewhere; listing it put a Delete button beside them. See
            //    NOT_SNAPSHOTS.
            if (in_array($name, self::NOT_SNAPSHOTS, true) === true) {
                continue;
            }
            $path = $this->backupsRoot . DIRECTORY_SEPARATOR . $name;
            if (is_dir($path) === false) {
                continue;
            }

            $manifestPath = $path . DIRECTORY_SEPARATOR . '_manifest.json';
            $manifest     = is_readable($manifestPath) === true
                ? json_decode((string) file_get_contents($manifestPath), true)
                : null;
            if (is_array($manifest) === false || is_array($manifest['tables'] ?? null) === false) {
                // 🚧 No manifest, or one that cannot be read: a snapshot that
                //    failed, was killed, or is being written right now.
                $snapshots[] = [
                    'name'       => $name,
                    'path'       => $path,
                    'created_at' => date('c', (int) filemtime($path)),
                    'tables'     => 0,
                    'rows'       => 0,
                    'reason'     => '(unfinished: this is not a backup)',
                    'complete'   => false,
                    'unlisted_values' => [],
                    // No manifest means no answer, which is exactly what
                    // ONE_MOMENT_NOT_RECORDED is for. It is never 'yes' here:
                    // an unfinished folder is not a backup at all.
                    'one_moment' => self::ONE_MOMENT_NOT_RECORDED,
                ];
                continue;
            }

            $tableRows = 0;
            foreach ($manifest['tables'] as $t) {
                $tableRows += (int) ($t['rows'] ?? 0);
            }
            $snapshots[] = [
                'name'       => $name,
                'path'       => $path,
                'created_at' => (string) ($manifest['created_at'] ?? ''),
                'tables'     => count($manifest['tables']),
                'rows'       => $tableRows,
                'reason'     => (string) ($manifest['reason'] ?? ''),
                'complete'   => true,
                'unlisted_values' => self::unlistedIn($manifest),
                'one_moment' => self::oneMomentIn($manifest),
            ];
        }
        return $snapshots;
    }

    /**
     * Finished snapshots only, the most recently TAKEN first.
     *
     * Ordered by the moment in each manifest, not by folder name. The name is
     * local clock time, and on the night the clocks go back a snapshot taken
     * at 01:10 after the change sorts before one taken at 01:50 before it.
     *
     * @return array<int, array<string, mixed>> Entries as listSnapshots() gives them.
     */
    private function finishedNewestFirst(): array
    {
        $finished = [];
        foreach ($this->listSnapshots() as $snapshot) {
            if ($snapshot['complete'] === true) {
                $moment = strtotime($snapshot['created_at']);
                if ($moment === false) {
                    $moment = (int) filemtime($snapshot['path'] . DIRECTORY_SEPARATOR . '_manifest.json');
                }
                $finished[] = [$moment, $snapshot];
            }
        }
        usort($finished, static function (array $a, array $b): int {
            return [$b[0], $b[1]['name']] <=> [$a[0], $a[1]['name']];
        });
        return array_column($finished, 1);
    }

    /**
     * The newest FINISHED snapshot, or null when there is none.
     *
     * The ONE answer to "what is our latest backup?", used by both the
     * freshness job and the health page. They used to take the first folder in
     * the list, and a folder left behind by a FAILED snapshot is the newest
     * folder there is, so a failed backup read as "Fresh".
     *
     * @return array<string, mixed>|null An entry as listSnapshots() gives it.
     */
    public function newestFinished(): ?array
    {
        return $this->finishedNewestFirst()[0] ?? null;
    }

    /**
     * How many unfinished folders with a snapshot's name are lying in the
     * backups folder. Each one means a snapshot failed or was cut off (or is
     * being taken at this moment), which an administrator should hear about.
     */
    public function unfinishedCount(): int
    {
        $count = 0;
        foreach ($this->listSnapshots() as $snapshot) {
            if ($snapshot['complete'] === false && preg_match(self::SNAPSHOT_NAME, $snapshot['name']) === 1) {
                $count++;
            }
        }
        return $count;
    }

    // =========================================================================
    // ⏰ Is anything actually calling the scheduled backup job? (#581)
    // =========================================================================

    /**
     * The file that records the last time `cron/backup-run` was called.
     *
     * ⚠️ A FILE, AND NOT A SETTINGS ROW OR A COLUMN, ON PURPOSE. Every table
     *    in the database goes into every snapshot, so a value kept in one
     *    travels backwards whenever a snapshot is put back: restore a snapshot
     *    taken while the scheduler was working and the portal would say the
     *    scheduler is working, on a server where it has since stopped. That is
     *    a false reassurance about backups, which is the worst direction for
     *    this particular sentence to be wrong in. `cron/backup-run.php` draws
     *    the same line for the same reason and says so in its own header:
     *    "restoring the database cannot confuse it, because the answer is not
     *    in the database at all."
     *
     *    It begins with a dot so that nothing lists it as a snapshot, and it
     *    sits beside `.backup-run.lock`, which that job already keeps here.
     *    What it CANNOT survive: somebody copying a whole `_backups` folder
     *    from one server to another, which carries this file with it. That is
     *    a recovery, not an ordinary day, and the first call after it puts the
     *    answer right.
     */
    private const SCHEDULED_CALL_FILE = '.backup-run-last';

    /**
     * How recently the job must have been called to count as "being called".
     *
     * Two days, because the job is meant to be called ONCE A DAY whatever the
     * frequency setting says (see `cron/backup-run.php`), so one missed night
     * — a host's scheduler running late, a server restarted — must not read as
     * "nothing is calling it". Three days would hide a scheduler that stopped
     * on Friday until Monday; one day would cry wolf every time a host ran
     * twenty-five hours apart. `MailQueue::JOB_RECENT_SECONDS` is the
     * precedent for the idea, with a much shorter window because that job is
     * meant to run every minute.
     */
    private const SCHEDULED_CALL_RECENT_SECONDS = 172800;

    /**
     * ⏰ Write down that the scheduled backup job was called, just now.
     *
     * Called by `cron/backup-run.php` on EVERY call that gets past the token,
     * including one that decides no snapshot is due and one that finds the
     * schedule switched off. That is the whole point: the question this
     * answers is "is anything calling the portal?", which is a different
     * question from "has a backup been taken lately", and the Backup page can
     * only tell somebody the truth if it can tell those two apart.
     *
     * Never throws. A backups folder that cannot be written to is a real
     * problem, but it is the SNAPSHOT's problem to report, and a job that
     * failed because it could not write a note about itself would be absurd.
     *
     * @return bool True when the note was written. The caller may ignore it;
     *              it is returned so a test can tell "not written" from
     *              "written and then not read back".
     */
    public function recordScheduledCall(): bool
    {
        if (is_dir($this->backupsRoot) === false) {
            // Not made here: the folder is the snapshot's to create (see
            // snapshot()), and making it from a note about a job would hide a
            // misconfigured path behind a file nobody looks at.
            //
            // THE COST, said plainly: on an installation whose backups folder
            // does not exist yet, a call is not recorded, so the Backup page
            // says nothing is calling the job when something is. That is the
            // wrong answer in the SAFE direction — it under-claims, never
            // over-claims — and it lasts only until the first snapshot, which
            // makes the folder.
            return false;
        }
        $path = $this->backupsRoot . DIRECTORY_SEPARATOR . self::SCHEDULED_CALL_FILE;
        $text = (string) time();

        return @file_put_contents($path, $text) === strlen($text);
    }

    /**
     * ⏰ When was the scheduled backup job last called, as a Unix moment?
     *
     * @return int|null Null when nothing has ever called it on this server, or
     *         when the note cannot be read or does not hold a moment. All of
     *         those mean the same thing to a reader — "there is no evidence
     *         anything is calling it" — and none of them is allowed to read as
     *         evidence that something is.
     */
    public function scheduledCallLastSeen(): ?int
    {
        $path = $this->backupsRoot . DIRECTORY_SEPARATOR . self::SCHEDULED_CALL_FILE;
        if (is_file($path) === false) {
            return null;
        }
        $text = @file_get_contents($path);
        if (is_string($text) === false || preg_match('/\A\d{1,12}\z/', trim($text)) !== 1) {
            return null;
        }

        return (int) trim($text);
    }

    /**
     * ⏰ THE ONE TEST of "is anything calling the scheduled backup job?" (#581)
     *
     * `scheduleWords()` asks this, and so does the Backup page when it chooses
     * which colour to draw its answer in. It exists so that there is exactly
     * one place where "recently" is decided: the page's first version worked
     * the colour out by looking at how the sentence STARTED, which is the
     * shape of thing that turns into a wrong colour the day somebody improves
     * the wording.
     *
     * A moment in the FUTURE is not evidence: a clock put back, or a whole
     * `_backups` folder copied from another server, would otherwise read as
     * "called just now" for as long as the difference lasted.
     *
     * @param int|null $lastSeen From scheduledCallLastSeen().
     * @param int      $now      The present moment, passed in; nothing here
     *                           reads the clock.
     */
    public static function scheduleIsRunning(?int $lastSeen, int $now): bool
    {
        if ($lastSeen === null || $lastSeen > $now) {
            return false;
        }

        return ($now - $lastSeen) <= self::SCHEDULED_CALL_RECENT_SECONDS;
    }

    /**
     * ⏰ THE ONE SENTENCE that says whether backups are really being taken on a
     * timer (#581).
     *
     * WHY THIS EXISTS. The portal gained a scheduled backup job, a setting for
     * how often, and a page that offered the choices — and nothing anywhere
     * could say whether the hosting company's scheduler was calling it. The
     * page said "this only happens if your scheduler is calling the portal",
     * which is true and useless: an administrator who has not set up the
     * scheduler sees "Every day" chosen in a box and reasonably believes a
     * backup is being taken every day. The setting ships as `daily`, so that
     * is the state every new installation is in. A SCHEDULE NOBODY SET UP MUST
     * NOT LOOK LIKE A SCHEDULE THAT IS RUNNING.
     *
     * ⚠️ SIX COMPLETE SENTENCES, ONE PER BRANCH, spelled out rather than
     *    assembled from pieces — the #694 rule. Three states of the setting
     *    collapse to two here (off, or any of the three periods), times three
     *    states of the evidence (never called, called recently, called but not
     *    recently).
     *
     * ⚠️ IT NEVER SAYS A BACKUP WAS TAKEN. Being called and taking a snapshot
     *    are different things: a call that decides nothing is due answers in
     *    one query and takes nothing, which is correct behaviour. Whether
     *    there is a fresh backup is what `cron/backup-check` and the freshness
     *    line on the health page are for, and this sentence must not be read
     *    as covering it.
     *
     * @param string   $frequency The stored `backup.schedule.frequency` word.
     * @param int|null $lastSeen  From scheduledCallLastSeen().
     * @param int      $now       The present moment, passed in so a test can
     *                            choose it; nothing here reads the clock.
     */
    public static function scheduleWords(string $frequency, ?int $lastSeen, int $now): string
    {
        $isOff  = in_array($frequency, ['daily', 'weekly', 'monthly'], true) === false;
        // ONE place decides "recently": see scheduleIsRunning(). A note from
        // the future is not evidence of anything, and it reads as "called, but
        // not recently", which is the cautious one of the two.
        $recent = self::scheduleIsRunning($lastSeen, $now);
        $stale  = $lastSeen !== null && $recent === false;

        if ($isOff === true && $lastSeen === null) {
            return 'No backup is being taken on a timer. Automatic backups are switched off here, and '
                 . 'nothing has ever called the portal\'s backup job on this server either. Backups are '
                 . 'being taken only when somebody presses the button on this page, and before an upgrade.';
        }
        if ($isOff === true && $recent === true) {
            return 'Your hosting company\'s scheduler IS calling the portal\'s backup job — it last called '
                 . 'on ' . date('j F Y \a\t H:i', $lastSeen) . ' — but automatic backups are switched off '
                 . 'here, so every call answers "switched off" and takes nothing. Choose how often below to '
                 . 'make those calls take a backup.';
        }
        if ($isOff === true && $stale === true) {
            return 'No backup is being taken on a timer. Automatic backups are switched off here, and the '
                 . 'last time anything called the portal\'s backup job was ' . date('j F Y \a\t H:i', $lastSeen)
                 . ', which is not recent. Backups are being taken only when somebody presses the button on '
                 . 'this page, and before an upgrade.';
        }
        if ($isOff === false && $lastSeen === null) {
            return 'NOTHING IS CALLING THE PORTAL\'S BACKUP JOB, so no backup is being taken on a timer, '
                 . 'whatever this page is set to. The portal cannot call itself: your hosting company\'s '
                 . 'scheduler has to, once a day, at the address below. Nothing has ever called it on this '
                 . 'server. Until it does, backups are being taken only when somebody presses the button on '
                 . 'this page, and before an upgrade.';
        }
        if ($isOff === false && $recent === true) {
            return 'Your hosting company\'s scheduler is calling the portal\'s backup job. It last called on '
                 . date('j F Y \a\t H:i', $lastSeen) . '. That is what this page needs in order to work, and '
                 . 'it is happening. Whether there is a FRESH backup is a separate question, answered by the '
                 . 'list of backups below: a call that decides none is due yet takes nothing, which is '
                 . 'correct.';
        }

        return 'SOMETHING WAS CALLING THE PORTAL\'S BACKUP JOB AND HAS STOPPED. The last call was on '
             . date('j F Y \a\t H:i', (int) $lastSeen) . ', and it is meant to be called once a day. So no '
             . 'backup is being taken on a timer at the moment, whatever this page is set to. Ask your '
             . 'hosting company whether the scheduled task is still there and still working. Until it is, '
             . 'backups are being taken only when somebody presses the button on this page, and before an '
             . 'upgrade.';
    }

    /**
     * 🗄️ Keep the newest N finished snapshots and remove older ones. Pass 0 to
     * switch pruning off.
     *
     * WHAT WAS WRONG BEFORE. This kept "the first N folders in the list",
     * whatever they were. Unfinished folders are the newest, so after a run of
     * failed snapshots it kept the failures and deleted the real backups
     * (tried: two finished and four newer unfinished folders, prune(4), both
     * finished ones deleted).
     *
     * WHAT IT DOES NOW
     *   • Counts FINISHED snapshots only, and deletes finished ones beyond N.
     *   • Removes an unfinished folder only when nothing in it has changed for
     *     24 hours. A shorter wait could delete a snapshot that is being
     *     written at this moment by another request.
     *   • Touches only folders named `upgrade-YYYYMMDD-HHMMSS`, the name this
     *     class gives. Any other folder in `_backups` was put there by a
     *     person, and is never removed or counted.
     *
     * @return array{kept: int, pruned: int, unfinished_removed: int, errors: string[]}
     */
    public function prune(int $keep): array
    {
        $outcome = ['kept' => 0, 'pruned' => 0, 'unfinished_removed' => 0, 'errors' => []];
        if ($keep <= 0) {
            return $outcome;
        }

        $mine = static function (array $snapshot): bool {
            // A link is skipped as well: removing "through" one would delete
            // whatever folder it points at.
            // 🏷️ Asks the ONE method that decides this, rather than repeating
            //    its pattern. DEV_NOTES said "both this job and prune() ask it"
            //    while this line held its own copy — true in substance, false as
            //    written, and now simply true.
            return self::isTakenHere($snapshot['name']) === true && is_link($snapshot['path']) === false;
        };

        $now = time();
        foreach ($this->listSnapshots() as $snapshot) {
            if ($snapshot['complete'] === true || $mine($snapshot) === false) {
                continue;
            }
            $lastChange = $this->lastChange($snapshot['path']);
            if ($lastChange === null || $now - $lastChange < self::UNFINISHED_KEPT_SECONDS) {
                continue;
            }
            if ($this->rmdirRecursive($snapshot['path']) === true) {
                $outcome['unfinished_removed']++;
            } else {
                $outcome['errors'][] = 'Could not delete ' . $snapshot['path'];
            }
        }

        foreach (array_values(array_filter($this->finishedNewestFirst(), $mine)) as $i => $snapshot) {
            if ($i < $keep) {
                $outcome['kept']++;
                continue;
            }
            if ($this->rmdirRecursive($snapshot['path']) === true) {
                $outcome['pruned']++;
            } else {
                $outcome['errors'][] = 'Could not delete ' . $snapshot['path'];
            }
        }
        return $outcome;
    }

    /**
     * When anything in this folder last changed, as a Unix time. Null when
     * that cannot be read, which the caller must treat as "leave it alone".
     */
    private function lastChange(string $dir): ?int
    {
        $latest  = filemtime($dir);
        $entries = scandir($dir);
        if ($latest === false || $entries === false) {
            return null;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $changed = filemtime($dir . DIRECTORY_SEPARATOR . $entry);
            if ($changed === false) {
                return null;
            }
            $latest = max($latest, $changed);
        }
        return $latest;
    }

    // =========================================================================
    // 🔗 The order tables are emptied and refilled in
    // =========================================================================

    /**
     * 🔗 Work out which tables point at which, straight from the live database.
     *
     * Read from the database itself rather than from the schema file on disk,
     * because what matters during a restore is how THIS database is actually
     * put together — which may differ from the file if a migration has not been
     * run, or if somebody has changed something by hand.
     *
     * @return array<string, array<int, string>>|null  Each table, mapped to the
     *         tables it points AT (its parents). Null if the database would not
     *         say, in which case the caller must refuse to restore rather than
     *         guess at an order.
     */
    private function foreignKeyGraph(): ?array
    {
        try {
            $rs = $this->db->query(
                'SELECT TABLE_NAME, REFERENCED_TABLE_NAME '
                . 'FROM information_schema.KEY_COLUMN_USAGE '
                . 'WHERE TABLE_SCHEMA = DATABASE() '
                . '  AND REFERENCED_TABLE_NAME IS NOT NULL'
            );
        } catch (\mysqli_sql_exception $e) {
            return null;
        }
        if ($rs === false) {
            return null;
        }

        $graph = [];
        while ($row = $rs->fetch_assoc()) {
            $child  = (string) $row['TABLE_NAME'];
            $parent = (string) $row['REFERENCED_TABLE_NAME'];
            if ($child === $parent) {
                // A table pointing at itself does not affect the ORDER tables
                // are handled in — it only constrains rows within one table.
                // Those rows are dealt with in loadTable().
                continue;
            }
            $graph[$child][] = $parent;
        }
        $rs->free();
        return $graph;
    }

    /**
     * 📚 Put tables in an order where every parent comes before its children.
     *
     * Emptying and refilling tables in a sensible order is what makes a restore
     * work at all. A child row cannot be written before the parent row it
     * points at exists, and a parent cannot be emptied while children still
     * point at it.
     *
     * So: EMPTY in reverse of this order (children first), and REFILL in this
     * order (parents first).
     *
     * tools/restore-order-selftest.php calls this by name, so its name and
     * arguments must stay as they are.
     *
     * @param array<int, string>                 $tables The tables to order.
     * @param array<string, array<int, string>>  $graph  From foreignKeyGraph().
     *
     * @return array<int, string>|null  The ordered tables, or null if they
     *         cannot be ordered because some point at each other in a circle.
     */
    private function restoreOrder(array $tables, array $graph): ?array
    {
        $remaining = array_fill_keys($tables, true);
        $ordered   = [];

        // Peel off, repeatedly, every table whose parents are all already
        // placed. If a pass places nothing, the rest form a circle.
        while ($remaining !== []) {
            $ready = [];
            foreach (array_keys($remaining) as $table) {
                $parents = $graph[$table] ?? [];
                $waiting = false;
                foreach ($parents as $parent) {
                    if (isset($remaining[$parent]) === true && $parent !== $table) {
                        $waiting = true;
                        break;
                    }
                }
                if ($waiting === false) {
                    $ready[] = $table;
                }
            }

            if ($ready === []) {
                // Tables pointing at each other in a circle. The live database
                // has none today, but a future one might, and guessing an order
                // would mean a restore that half works.
                return null;
            }

            sort($ready);
            foreach ($ready as $table) {
                $ordered[] = $table;
                unset($remaining[$table]);
            }
        }

        return $ordered;
    }

    // =========================================================================
    // 🔄 Putting a snapshot back
    // =========================================================================

    /**
     * Empty one table, inside the caller's transaction.
     *
     * A table that points at itself has its pointers let go FIRST. With the
     * kind of link all nine such tables have today (ON DELETE SET NULL) a
     * plain DELETE happens to work. With the ordinary kind of link it does not:
     * the database refuses to delete a row while another row of the same table
     * still points at it, and the old code could not empty such a table at all
     * (tried: 8c in the plan's evidence).
     *
     * The dates are held still here too (keepStamps). These rows are about to
     * be deleted, so it makes no difference to the result; it is done so that
     * nobody ever has to wonder whether this UPDATE is the one that needs it.
     *
     * @param array<int, string> $pointers This table's self-pointing columns.
     */
    private function emptyTable(string $table, array $pointers, array $tableFacts): void
    {
        foreach ($pointers as $pointer) {
            $this->ask(
                'UPDATE `' . $table . '` SET `' . $pointer . '` = NULL' . $this->keepStamps($tableFacts)
                . ' WHERE `' . $pointer . '` IS NOT NULL'
            );
        }

        // 🧹 `DELETE FROM`, never `TRUNCATE`. THAT WAS THE WHOLE BUG IN #472.
        //
        //    `TRUNCATE` looks like a faster way to empty a table, and it is.
        //    But it commits by itself. The moment it runs, the old contents are
        //    gone for good and the transaction wrapped around it is already
        //    over, so a row that failed half way through the refill left the
        //    table part-filled with the original contents destroyed. MySQL also
        //    refuses `TRUNCATE` outright on any table other tables point at.
        //
        //    `DELETE FROM` takes part in the transaction properly: if anything
        //    goes wrong afterwards everything is put back exactly as it was.
        //
        //    An earlier attempt switched the database's own link checking OFF
        //    to make emptying and refilling easier. A restore could then leave
        //    records pointing at rows that no longer existed and still report
        //    success. It was reverted, rightly. NOTHING here switches that
        //    checking off, and #578 was fixed without doing so (a shared host
        //    does allow it for one session; the objection is the result, not
        //    the permission).
        $this->ask('DELETE FROM `' . $table . '`');
    }

    /**
     * Refill one table from its file, inside the caller's transaction.
     *
     * 🔁 ROWS THAT POINT AT ROWS OF THEIR OWN TABLE (#578). An old venue
     * agreement points at the newer one that replaced it. Written in file
     * order, the old one goes in first, the newer one is not there yet, and
     * the database refuses it: such a snapshot could never be restored. So:
     *
     *   1. every row is written with its pointer EMPTY;
     *   2. the file is read a second time and each pointer is filled in, by
     *      then every row it could point at exists.
     *
     * That works for a chain, a ring of rows and a row pointing at itself, and
     * the database's link checking stays ON the whole time, so a pointer to a
     * row that is not in the snapshot is still NOTICED. What then happens to
     * it is decided by refuseUnlessMayEmpty(): emptied and counted where the
     * link's own rule is "empty the pointer", refused otherwise. (This used to
     * say such a pointer "is still refused", which stopped being the whole
     * truth when that rule was added.)
     *
     * 📦 HOW ROWS ARE GROUPED INTO STATEMENTS. A group is sent BEFORE a row
     * that would take it past the size limit is added, never after. It used to
     * be sent after, so fifteen 1 MB rows followed by one 50 MB row became a
     * single 65 MB statement, which a database that accepts 64 MB refused,
     * although it would have taken the 50 MB row by itself (the independent
     * check, 12 October 2026). A row bigger than a quarter of the limit now
     * travels alone, and a row bigger than the WHOLE limit is refused before
     * it is sent (see tooLargeProblem()). It is also sent a step LATER than before, after the reader has let go
     * of the line it was read from, which is why one very large row now costs
     * about four times its size in memory to put back and not six (measured:
     * a 24 MB row peaked at 148 MB before this change and 100 MB after).
     *
     * Considered and not chosen: sorting the rows so parents come first (cannot
     * work for a ring, and needs every key in memory); and switching link
     * checking off for the session (see emptyTable()).
     *
     * @param array<int, string>               $pointers   This table's self-pointing columns.
     * @param array<int, string>               $key        Its primary-key columns.
     * @param array<int, array<string, mixed>> $tableLinks This table's links, from links().
     * @param array<string, int>               $emptied    Count of pointers emptied because the row
     *                                                     they pointed at is not in the snapshot; added to.
     * @param int                              $largestMessage The database's max_allowed_packet, in bytes.
     *
     * @return int Rows written.
     */
    private function loadTable(
        string $dir,
        string $table,
        int $format,
        array $tableFacts,
        array $pointers,
        array $key,
        int $bytesPerStatement,
        array $tableLinks,
        array &$emptied,
        int $largestMessage
    ): int {
        $columns   = null;
        $perBatch  = self::ROWS_PER_STATEMENT;
        $batch     = [];
        $batchSize = 0;
        $statement = null;
        $prepared  = 0;   // how many rows $statement was prepared for
        $count     = 0;

        $send = function () use (&$batch, &$batchSize, &$statement, &$prepared, &$columns, &$emptied, $table, $tableLinks, $tableFacts): void {
            if ($batch === []) {
                return;
            }
            if ($statement === null || $prepared !== count($batch)) {
                if ($statement !== null) {
                    $statement->close();
                }
                $oneRow    = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
                $statement = $this->db->prepare(
                    'INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`) VALUES '
                    . implode(', ', array_fill(0, count($batch), $oneRow))
                );
                if ($statement === false) {
                    throw new RuntimeException('Could not prepare an insert for ' . $table . '.');
                }
                $prepared = count($batch);
            }
            // Every value is sent as text or as bytes exactly as it was read;
            // the database turns it into the column's own type.
            $problem = $this->tryStatement($statement, array_merge(...$batch));
            if ($problem !== null && $problem['code'] === 1452) {
                // 1452 is the database's number for "this row points at a row
                // that does not exist". It refused the whole batch and wrote
                // none of it; the transaction itself carries on. See
                // refuseUnlessMayEmpty() for what is and is not done about it.
                if ($this->emptyDeadPointers($table, $columns, $batch, $tableLinks, $tableFacts, $emptied) === true) {
                    $problem = $this->tryStatement($statement, array_merge(...$batch));
                }
            }
            if ($problem !== null) {
                throw new RuntimeException('Could not write rows to ' . $table . ': ' . self::whyRefused($problem));
            }
            $batch     = [];
            $batchSize = 0;
        };

        try {
            foreach ($this->rows($dir, $table, $format) as $row) {
                // 🧹 A column a migration has since removed: see REMOVED_COLUMNS.
                $row = self::withoutRemovedColumns($table, $row, $tableFacts);
                if ($columns === null) {
                    // An old snapshot may still carry a column the database
                    // works out for itself; an INSERT naming one is refused.
                    $columns = [];
                    foreach (array_keys($row) as $column) {
                        if (($tableFacts[$column]['generated'] ?? false) === false) {
                            $columns[] = $column;
                        }
                    }
                    if ($columns === []) {
                        throw new RuntimeException('The saved copy of ' . $table . ' holds no column that can be written.');
                    }
                    $perBatch = max(1, min(self::ROWS_PER_STATEMENT, intdiv(self::VALUES_PER_STATEMENT, count($columns))));
                }

                $values  = [];
                $rowSize = 0;
                foreach ($columns as $column) {
                    $value    = in_array($column, $pointers, true) === true ? null : $row[$column];
                    $values[] = $value;
                    $rowSize += $value === null ? 0 : strlen($value);
                }
                // 📏 A row the database would end the connection over is
                //    refused here, in words, before it is sent.
                $tooLarge = self::tooLargeProblem($table, $rowSize, count($columns), $largestMessage);
                if ($tooLarge !== '') {
                    throw new RuntimeException($tooLarge);
                }
                // Send what is waiting FIRST if this row would take the group
                // past the limit (see "HOW ROWS ARE GROUPED" above).
                if ($batch !== [] && $batchSize + $rowSize > $bytesPerStatement) {
                    $send();
                }
                $batch[]    = $values;
                $batchSize += $rowSize;
                $count++;
                if (count($batch) >= $perBatch) {
                    $send();
                }
            }
            $send();
        } finally {
            if ($statement instanceof \mysqli_stmt) {
                $statement->close();
            }
        }

        if ($count > 0) {
            $this->fillPointers($dir, $table, $format, $tableFacts, array_values(array_intersect($pointers, $columns)), $key, $tableLinks, $emptied);
        }
        return $count;
    }

    /**
     * The second reading of a self-pointing table: every row exists by now, so
     * fill each pointer in. See loadTable() for why, and keepStamps() for the
     * trap in doing it.
     *
     * @param array<int, array<string, mixed>> $tableLinks This table's links, from links().
     * @param array<string, int>|null          $emptied    For a whole-snapshot restore: the running
     *             count of pointers emptied because the row they pointed at is not in the
     *             snapshot (see refuseUnlessMayEmpty()). Null for a single-table restore, which
     *             is stricter about pointers and looser about rows: it never empties a pointer,
     *             and it may have left a row out as a duplicate, so a row that cannot be found
     *             again is not an error there.
     */
    private function fillPointers(
        string $dir,
        string $table,
        int $format,
        array $tableFacts,
        array $pointers,
        array $key,
        array $tableLinks,
        ?array &$emptied
    ): void {
        if ($pointers === []) {
            return;
        }
        $wholeSnapshot = $emptied !== null;

        // Each pointer's own link, for the rule on a pointer at a missing row.
        $linkOf = [];
        foreach ($tableLinks as $link) {
            if ($link['parent'] === $table && count($link['columns']) === 1) {
                $linkOf[$link['columns'][0]] = $link;
            }
        }

        $statement = $this->db->prepare(
            'UPDATE `' . $table . '` SET `' . implode('` = ?, `', $pointers) . '` = ?' . $this->keepStamps($tableFacts)
            . ' WHERE `' . implode('` = ? AND `', $key) . '` = ?'
        );
        if ($statement === false) {
            throw new RuntimeException('Could not prepare the second pass for ' . $table . '.');
        }
        try {
            foreach ($this->rows($dir, $table, $format) as $row) {
                $values  = [];
                $pointed = false;
                foreach ($pointers as $pointer) {
                    $values[] = $row[$pointer];
                    $pointed  = $pointed || $row[$pointer] !== null;
                }
                if ($pointed === false) {
                    continue;
                }
                foreach ($key as $column) {
                    if (($row[$column] ?? null) === null) {
                        throw new RuntimeException('The saved copy of ' . $table . ' does not hold the column '
                                                 . $column . ', which is needed to find each row again.');
                    }
                    $values[] = $row[$column];
                }

                $problem = $this->tryStatement($statement, $values);
                if ($problem !== null && $problem['code'] === 1452 && $wholeSnapshot === true) {
                    // This row points at a row of its own table that is not in
                    // the snapshot. Every row is written by now, so it is not
                    // a matter of order. Same rule as for any other link.
                    $pointed = false;
                    foreach ($pointers as $i => $pointer) {
                        if ($values[$i] === null) {
                            continue;
                        }
                        if (isset($linkOf[$pointer]) === true
                            && $this->rowExists($table, $linkOf[$pointer]['parentColumns'][0], $values[$i]) === false
                        ) {
                            $this->refuseUnlessMayEmpty($table, $linkOf[$pointer], $tableFacts, $values[$i]);
                            $values[$i] = null;
                            $emptied[$table . '.' . $pointer] = ($emptied[$table . '.' . $pointer] ?? 0) + 1;
                            continue;
                        }
                        $pointed = true;
                    }
                    if ($pointed === false) {
                        continue;   // every pointer on this row was emptied; it is already empty in the table
                    }
                    $problem = $this->tryStatement($statement, $values);
                }
                if ($problem !== null) {
                    throw new RuntimeException('Could not fill in a pointer in ' . $table . ': ' . self::whyRefused($problem));
                }
                if ($wholeSnapshot === true && $statement->affected_rows !== 1) {
                    throw new RuntimeException('A row of ' . $table . ' that had just been written could not be found again.');
                }
            }
        } finally {
            $statement->close();
        }
    }

    /**
     * 🔄 Put a WHOLE snapshot back, as one all-or-nothing operation.
     *
     * This is the method to use for a real recovery. Putting tables back one at
     * a time cannot work, for two reasons that only show up on a real database:
     *
     *   1. Tables point at each other. A row in one table refers to a row in
     *      another, and the database refuses to let you empty the second while
     *      the first still points at it.
     *
     *   2. Doing them one at a time means each is finished before the next
     *      begins. If the twentieth fails, the first nineteen are already done
     *      and cannot be taken back.
     *
     * So this does the lot inside a SINGLE transaction. Everything is checked
     * before anything is touched; every table is emptied and refilled; and only
     * then is any of it made permanent. If ANYTHING goes wrong at any point —
     * a row the database refuses, PHP running out of time or memory, the
     * process being killed — the database undoes the lot and is exactly as it
     * was (#472's promise; proved by killing a restore half way).
     *
     * Tables are emptied children-first, so nothing is left pointing at a row
     * that has gone, and refilled parents-first, so nothing is written before
     * the row it points at exists.
     *
     * WHAT THIS CANNOT DO
     *   • Foresee a failure at the very last step, the commit itself. If the
     *     connection fails at that moment, nothing here can find out whether
     *     the restore took effect, and the message says exactly that. It is
     *     one or the other, never a part.
     *   • Put back a value the database would have to change to make it fit a
     *     column as it is now. Where the database SAYS it changed a value (an
     *     error, a warning or a note) the restore is REFUSED, whatever the
     *     host's own settings (see pinStrictness()). Where the database
     *     changes a value without a word, nothing here can know: this file's
     *     header lists the kinds found so far. (This used to say such a
     *     restore is refused "on every server", which claimed too much.)
     *   • Leave alone the tables that are NOT in the snapshot. Rows in them
     *     that are linked to rows being replaced are deleted, or have their
     *     pointers emptied, by the database's own rule for the link. That is
     *     counted and reported (`other_tables_changed`, and a warning); see
     *     outsideTables().
     *   • Put back rows the database now calls duplicates. That can only go
     *     back table by table (restoreTable()). The case this was written for
     *     — a snapshot of tblSettings from before migration 187 — can no
     *     longer be done at all: since 26 October 2026 tblSettings is on
     *     WHOLE_DATABASE_ONLY, so a one-table restore of it is refused.
     *     Recovering such a snapshot now means mending the duplicate rows in
     *     the snapshot's own file first. Said plainly because the old wording
     *     promised a way out that is no longer there.
     *   • Put back, unchanged, a pointer at a row that is not in the snapshot.
     *     Where the link's own rule allows it the pointer is emptied, counted
     *     and reported (`pointers_emptied`); otherwise the restore is refused.
     *     See refuseUnlessMayEmpty().
     *   • Stop the next-number counter of a table moving. A rehearsal that is
     *     undone may still leave a table's next identity number higher than it
     *     was. The database documents that; it is not data.
     *
     * @param string $snapshotPath Folder holding the snapshot.
     * @param bool   $dryRun       "Check only". What that means is decided by
     *                             CHECK_ONLY_IS_REHEARSAL: by default a full
     *                             rehearsal, which does everything the restore
     *                             does and then undoes it.
     *
     * @return array{success: bool, tables_restored: int, rows_restored: int,
     *               error: string, warnings: array<int, string>,
     *               pointers_emptied: array<string, int>,
     *               other_tables_changed: array<string, array<string, mixed>>,
     *               connection_closed: bool}
     *         pointers_emptied counts, per "table.column", the pointers that
     *         were emptied because the row they pointed at is not in the
     *         snapshot. other_tables_changed holds, for each table that is NOT
     *         in the snapshot and was changed all the same, its row count and
     *         pointer counts before and after. A rehearsal reports the counts
     *         a real restore would.
     *         connection_closed is true when this method had to CLOSE the
     *         database connection: to be sure unfinished work was undone, or
     *         because the connection's own settings could not be put back
     *         after the restore (see putStrictnessBack()). The caller must
     *         not use the connection again in that request. NOTE that it can
     *         be true on a SUCCESSFUL result: the last warning then says why.
     */
    public function restoreAll(string $snapshotPath, bool $dryRun = false): array
    {
        $this->sessionNotPutBack = false;
        return $this->sayIfSessionNotPutBack(
            $this->restoreAllWork($snapshotPath, $dryRun),
            $dryRun === true && self::CHECK_ONLY_IS_REHEARSAL === true ? 'rehearsal' : 'restore',
            'warnings'
        );
    }

    /**
     * Everything restoreAll() does, except the last word on whether the
     * connection's own settings were put back afterwards: that is only known
     * once this method's `finally` has run, so restoreAll() adds it.
     *
     * @return array<string, mixed> As restoreAll().
     */
    private function restoreAllWork(string $snapshotPath, bool $dryRun): array
    {
        $fail = static function (string $why, bool $connectionClosed = false): array {
            return [
                'success'              => false,
                'tables_restored'      => 0,
                'rows_restored'        => 0,
                'error'                => $why,
                'warnings'             => [],
                'pointers_emptied'     => [],
                'other_tables_changed' => [],
                'connection_closed'    => $connectionClosed,
            ];
        };

        $opened = $this->openSnapshot($snapshotPath);
        if (is_string($opened) === true) {
            return $fail($opened . ' Nothing has been changed.');
        }
        ['dir' => $dir, 'manifest' => $manifest, 'format' => $format] = $opened;

        $tables = array_map('strval', array_keys($manifest['tables']));
        if ($tables === []) {
            return $fail('This snapshot contains no tables. Nothing has been changed.');
        }

        // ---------------------------------------------------------------
        // 🛡️ IS THIS REALLY A WHOLE SNAPSHOT?
        //
        // The tables on WHOLE_DATABASE_ONLY may be put back only as part of a
        // whole restore (there are four; read the constant, do not count from a
        // comment): each holds a "this has already happened" mark,
        // and moving one alone loses data. Refusing them HERE as well was
        // considered and rejected long ago, for a good reason that has not
        // changed — it would make the pre-upgrade snapshot impossible to undo,
        // which is the one thing it exists for.
        //
        // But this method took its table list from the manifest and asked
        // nothing else, and a manifest is an ordinary file in the snapshot
        // folder. Edited down to a single name it turned "restore ALL" into
        // exactly the one-table restore the other path refuses. Found by the
        // Codex review, 27 October 2026.
        //
        // So a snapshot now SAYS whether it is whole (see snapshot()). If it
        // does, everything is put back as before. If it does NOT — an older
        // snapshot, or one somebody has edited — the protected tables are left
        // alone and the rest is restored, and the answer says which and why.
        // Guessing from the number of tables was rejected: a genuine older
        // snapshot legitimately lacks tables added since, and this file goes to
        // some trouble to keep such snapshots readable.
        // ---------------------------------------------------------------
        $saysItIsWhole = ($manifest['whole_database'] ?? null) === true;
        $heldBack      = [];
        if ($saysItIsWhole === false) {
            foreach ($tables as $table) {
                if (self::isWholeDatabaseOnly($table) === true) {
                    $heldBack[] = $table;
                }
            }
            if ($heldBack !== []) {
                $tables = array_values(array_filter(
                    $tables,
                    static fn (string $t): bool => self::isWholeDatabaseOnly($t) === false
                ));
                if ($tables === []) {
                    return $fail(
                        'This snapshot holds nothing but ' . implode(', ', $heldBack) . ', and it does not say '
                        . 'that it covers the whole database. Those tables can only be put back as part of a '
                        . 'whole restore, because each one records what has already happened. Nothing has been '
                        . 'changed. Use a snapshot taken by this portal.'
                    );
                }
            }
        }

        // ---------------------------------------------------------------
        // 🛶 TOO MANY KEPT ROWS TO WRITE AGAIN? THEN DO NOT PUT THAT TABLE
        //    BACK AT ALL, rather than losing every row in it (#697).
        //
        //    The carry holds the waiting rows in memory for the length of the
        //    restore, so it has a ceiling (CASCADE_RESCUE_MAX_ROWS). The first
        //    version reached that ceiling, carried NOTHING, and let the
        //    snapshot's own copy of the list replace them — measured by the
        //    independent check: 20,001 waiting rows before, ZERO after, restore
        //    successful. Meanwhile the Backup page and the help page both
        //    promised that putting a backup back cannot lose them. That is the
        //    #694 rule "check every page claim against the FALLBACK, not the
        //    happy path", broken in the package that cites it.
        //
        //    Holding the table back instead loses nothing: the rows stay
        //    exactly where they are, the snapshot's older copy of the list
        //    simply is not put back, and the answer says so. It is the same
        //    mechanism WHOLE_DATABASE_ONLY already uses a few lines above, for
        //    the same reason — a table whose own contents matter more than
        //    matching the snapshot.
        //
        //    DECIDED HERE, OUTSIDE THE TRANSACTION, AND NOWHERE ELSE. That is
        //    what lets readWaitingHeldRows() read every waiting row without a
        //    count of its own: two places deciding one ceiling is how a pair of
        //    readers come to disagree (#668's lesson), and the portal is closed
        //    to everyone while a restore runs, so the number cannot grow in
        //    between.
        $tooManyKept  = 0;
        $couldNotTell = false;
        // 🛡️ INSIDE A try, AND A FAILURE HOLDS THE TABLE BACK RATHER THAN
        //    FALLING THROUGH. Two faults the independent check named, both in the
        //    unsafe direction. These two queries ran outside any try, and
        //    restoreAll() has no catch of its own, so a throw here gave the
        //    Backup page a bare 500 instead of the honest refusal this method
        //    exists to give (the same shape as #694's put-back path). And a
        //    count that merely returned false fell through with $tooManyKept = 0,
        //    which lets the carry read every waiting row with no bound at all -
        //    the opposite of how the rest of this file is written.
        //
        //    "Could not tell" is treated as "do not put that table back":
        //    nothing is lost either way, and the answer says what happened.
        try {
            if (self::namedIn('tblRestoreHeldRows', $tables) === true && $this->holdingTableExists() === true) {
                $counted = $this->db->query(
                    'SELECT COUNT(*) AS n FROM `tblRestoreHeldRows` WHERE `putBackAtUtc` IS NULL'
                );
                if ($counted === false) {
                    $couldNotTell = true;
                } else {
                    $howManyKept = (int) ($counted->fetch_assoc()['n'] ?? 0);
                    $counted->free();
                    if ($howManyKept > self::CASCADE_RESCUE_MAX_ROWS) {
                        $tooManyKept = $howManyKept;
                    }
                }
            }
        } catch (\Throwable $e) {
            // NEVER the database's own words: they can quote a stored setting
            // value or a hashed sign-in code.
            $couldNotTell = true;
        }
        if ($tooManyKept > 0 || $couldNotTell === true) {
            $tables = array_values(array_filter(
                $tables,
                static fn (string $t): bool => strcasecmp($t, 'tblRestoreHeldRows') !== 0
            ));
        }

        // ---------------------------------------------------------------
        // 🔍 CHECK EVERYTHING FIRST. Nothing in this block writes anything.
        // ---------------------------------------------------------------
        $rowsExpected = 0;
        foreach ($tables as $table) {
            if (preg_match(self::IDENTIFIER, $table) !== 1) {
                return $fail('This snapshot names a table in a form that is not valid: '
                           . $table . '. Nothing has been changed.');
            }
            $problem = $this->tableFileProblem($dir, $table, (array) $manifest['tables'][$table], $format);
            if ($problem !== '') {
                return $fail($problem . ' Nothing has been changed.');
            }
            $rowsExpected += (int) ($manifest['tables'][$table]['rows'] ?? 0);
        }

        // 🔗 Work out the order, from how the LIVE database is put together
        //    rather than from the snapshot - the database is what the writes
        //    have to satisfy.
        $graph = $this->foreignKeyGraph();
        if ($graph === null) {
            return $fail('The database would not say how its tables are linked together, so '
                       . 'there is no safe order to put them back in. Nothing has been '
                       . 'changed.');
        }

        $order = $this->restoreOrder($tables, $graph);
        if ($order === null) {
            return $fail('These tables point at each other in a circle, so there is no order '
                       . 'that works. This needs a person to look at it. Nothing has been '
                       . 'changed.');
        }

        try {
            $facts  = $this->columnFacts();
            $links  = $this->links();
            $self   = $this->selfPointers($links);
            $packet = $this->largestMessage();
            // Tables outside the snapshot that putting it back can still change.
            $outside     = $this->outsideTables($tables, $links);
            $notUndoable = $this->notUndoableProblem(array_merge($tables, array_keys($outside)));
        } catch (\Throwable $e) {
            return $fail('The database would not describe its tables, so the restore was not started. '
                       . 'Nothing has been changed. (' . $e->getMessage() . ')');
        }
        if ($notUndoable !== '') {
            return $fail($notUndoable . ' Nothing has been changed.');
        }
        foreach ($tables as $table) {
            if (isset($self[$table]) === true) {
                $problem = $this->selfPointerProblem($table, $facts[$table] ?? [], $self[$table]);
                if ($problem !== '') {
                    return $fail($problem . ' Nothing has been changed.');
                }
            }
        }

        // 🎭 The ONE place the "check only" choice is acted on. With the
        //    constant false, the check stops here having read every file and
        //    written nothing, and says exactly that.
        if ($dryRun === true && self::CHECK_ONLY_IS_REHEARSAL === false) {
            return [
                'success'              => true,
                'tables_restored'      => count($order),
                'rows_restored'        => $rowsExpected,
                'error'                => '',
                'warnings'             => ['This was a check of the files only. Every file is present, undamaged and '
                                         . 'readable. The database was not tried, so this does not prove the restore '
                                         . 'would succeed. Nothing was changed.'],
                'pointers_emptied'     => [],
                'other_tables_changed' => [],
                'connection_closed'    => false,
            ];
        }

        // ---------------------------------------------------------------
        // 🔄 One transaction. All of it, or none of it.
        // ---------------------------------------------------------------
        $tablesDone     = 0;
        $rowsDone       = 0;
        $emptied        = [];
        $outsideChanged = [];
        $warnings       = [];

        // 🧊 SAY IF THIS SNAPSHOT WAS NOT READ AT ONE MOMENT, or does not say
        //    (#581). It belongs here rather than only in snapshot()'s own
        //    answer because the person putting a backup back is not usually
        //    the person who took it, and may be reading a snapshot taken
        //    months ago by a scheduled job nobody watched. A restore is not
        //    refused over it: the fault it warns about shows up as a row
        //    pointing at a row that is not in the snapshot, which the restore
        //    already refuses by itself and says so in those words — and
        //    refusing in advance would turn "this snapshot may not go back"
        //    into "this snapshot cannot be tried", at the one moment somebody
        //    is trying to recover.
        $oneMomentSays = self::oneMomentWarning(self::oneMomentIn($manifest), true);
        if ($oneMomentSays !== '') {
            $warnings[] = $oneMomentSays;
        }

        // 🧾 SAY what was held back, if anything. A restore that quietly skips
        //    a table is the silent kind of failure this portal keeps being
        //    caught by, so this goes in the answer the administrator reads.
        if ($heldBack !== []) {
            $warnings[] = 'This snapshot does not say that it covers the whole database, so '
                        . implode(' and ', $heldBack) . ' ' . (count($heldBack) === 1 ? 'was' : 'were')
                        . ' left exactly as ' . (count($heldBack) === 1 ? 'it is' : 'they are')
                        // ⚠️ THREE EXAMPLES, NOT FOUR, AND NOT ONE PER TABLE ON THE LIST.
                        //    A fourth was added for #697 ("which rows an earlier restore kept
                        //    for you") on the assumption that `tblRestoreHeldRows` would be
                        //    among the names in this sentence. It almost never is: a snapshot
                        //    with no `whole_database` flag was taken before that flag existed
                        //    (27 October 2026), and the holding table did not exist until
                        //    migration 233 (31 October 2026), so a genuine old-form snapshot
                        //    CANNOT contain it and there is nothing to hold back. The clause
                        //    therefore attributed to tblMigrations, tblSettings and
                        //    tblTotpBackupCodes a thing none of them records. Found by the
                        //    independent check, by printing the real output.
                        //
                        //    These are EXAMPLES of "what has already happened", so the
                        //    sentence stays true however many names `$heldBack` holds; a
                        //    gloss per table would need a branch per table, which is how
                        //    #694 collected four wording faults nothing could see.
                        . '. Each of those records what has already happened — which migrations have run, '
                        . 'which backup codes have been used, what the settings now say — and putting one '
                        . 'back without the rest of the database loses data. Everything else in the snapshot '
                        . ($dryRun === true ? 'would be' : 'was') . ' put back. Snapshots taken by this '
                        . 'portal from 27 October 2026 onwards say that they cover the whole database, and '
                        . 'for those nothing is held back.';
        }
        if ($tooManyKept > 0) {
            $warnings[] = self::keptRowsHeldBackWarning($tooManyKept, $dryRun);
        }
        if ($couldNotTell === true) {
            $warnings[] = self::couldNotCountKeptRowsWarning($dryRun);
        }
        $zoneWas        = null;
        $modeWas        = null;
        $atCommit       = false;
        $stopped        = $dryRun === true
            ? 'The rehearsal was stopped, which means this snapshot cannot be put back as things stand. '
              . 'Everything the rehearsal wrote has been taken out again. Nothing in the database has '
              . 'changed. The reason it stopped: '
            : 'The restore was stopped and everything has been put back as it was. Nothing in the '
              . 'database has changed. The reason it stopped: ';

        try {
            if ($format >= 2) {
                // Read the moments in the zone they were written in (UTC).
                // Old-form snapshots are put back as they always were.
                $zoneWas = $this->pinTimeZone((string) $manifest['time_zone']);
            }
            // Refuse a value the database SAYS it would otherwise change to
            // make it fit, whatever the host's own settings. The strictness
            // and the time zone are both put back in `finally`.
            $modeWas = $this->pinStrictness();
            if ($this->db->begin_transaction() === false) {
                throw new RuntimeException('The database would not start a transaction.');
            }

            $outsideBefore = $this->countOutside($outside);

            // 🛟 THE ROWS THIS RESTORE IS ABOUT TO LOSE (#694), read while they
            //    are still there. A held-back table that is a child of a table
            //    being replaced loses its rows to the cascade, and nothing puts
            //    them back — the restore said so, but it named the table rather
            //    than what went. They are written to the holding table after
            //    every table is back; see readRowsACascadeWouldTake().
            $rescue = $this->readRowsACascadeWouldTake($tables);

            // 🛶 THE ROWS STILL WAITING TO BE PUT BACK, read while they are
            //    still there, so this restore cannot lose them (#697).
            //
            //    `tblRestoreHeldRows` is an ordinary table from migration 233
            //    on, so a whole restore puts back whatever the snapshot holds
            //    for it — and a snapshot taken BEFORE some rows were kept holds
            //    none of them. Those waiting rows are the only copy of
            //    themselves outside the snapshot being restored FROM, so
            //    replacing them is the same silent loss #694 exists to stop,
            //    one door along. Until 31 October 2026 this only SAID so in a
            //    warning, which asks somebody mid-recovery to read a warning
            //    — exactly what #694 says not to rely on.
            //
            //    Same read-early, write-late order as the cascade rescue above,
            //    and for the same reason: the holding table is itself in the
            //    snapshot, so anything written before the restore would be
            //    emptied again when that table's turn came.
            $waitingRows = $this->readWaitingHeldRows($tables);

            // Empty children first, so nothing is ever left pointing at a row
            // that has just gone.
            foreach (array_reverse($order) as $table) {
                $this->emptyTable($table, $self[$table]['pointers'] ?? [], $facts[$table] ?? []);
            }

            // Refill parents first, so nothing is written before the row it
            // points at exists.
            foreach ($order as $table) {
                $rowsDone += $this->loadTable(
                    $dir,
                    $table,
                    $format,
                    $facts[$table] ?? [],
                    $self[$table]['pointers'] ?? [],
                    $self[$table]['key'] ?? [],
                    max(65536, intdiv($packet, 4)),
                    $links[$table] ?? [],
                    $emptied,
                    $packet
                );
                $tablesDone++;
            }

            // 🛶 THE WAITING ROWS GO BACK IN, now that this snapshot's own
            //    copy of the holding table has been written over them (#697).
            //
            //    Done in a REHEARSAL too, unlike the cascade rescue just below.
            //    That is deliberate: everything in this transaction is undone at
            //    the end of a rehearsal anyway (holdRestoredMail() and
            //    holdRestoredTexts() already write inside one), and a rehearsal
            //    that skipped this step could not tell an administrator that
            //    the carry would fail — which is the one thing a rehearsal is
            //    for. The rescue below is skipped on a rehearsal because it
            //    writes rows that only a real restore has any business keeping.
            // The table's shape comes from the `columnFacts()` this method
            // already read, rather than a second call asking information_schema
            // about all 231 tables again inside the restore's own transaction.
            $carried = $this->carryWaitingHeldRowsThrough(
                $waitingRows['rows'],
                self::forTable($facts, 'tblRestoreHeldRows')
            );
            if ($carried['carried'] > 0) {
                $warnings[] = self::carriedWarning($carried['carried'], $dryRun);
            }
            if ($carried['stoppedWaiting'] > 0) {
                $warnings[] = self::stoppedWaitingWarning($carried['stoppedWaiting'], $dryRun);
            }
            if ($waitingRows['couldNotRead'] === true) {
                $warnings[] = self::couldNotReadKeptRowsWarning($dryRun);
            }

            // 🛟 Now the rescued rows, after every table is back — including
            //    the holding table itself if the snapshot held one, which is
            //    why this cannot happen earlier.
            if ($dryRun === false && $rescue['rows'] !== [] && $rescue['canKeep'] === true) {
                $this->holdRescuedRows($rescue['rows'], basename($dir));
            }

            // 🧾 AND SAY WHAT WENT, not just which table it came out of.
            //
            //    THIS IS AN ADDITION, NOT A REPLACEMENT, and an earlier version
            //    of this comment wrongly said otherwise. The older warning,
            //    built in countOutside()/sayWhatChanged() around line 1485,
            //    still runs and still says "N table(s) that are NOT in this
            //    snapshot were changed as well", with its row counts per
            //    table. It is kept because it covers EVERY table outside the
            //    snapshot, including the ones this file cannot rescue; what it
            //    does not do is say what the rows WERE, and an administrator
            //    reads it at the worst possible moment. So both appear: the
            //    general count, and then, for the two tables on CASCADE_RESCUE,
            //    what went and where it is being kept.
            //
            //    A test that pins only one of the two sentences would pass if
            //    the other were deleted, so pin both (proof 6 of
            //    tools/backup-restore-selftest.php does).
            if ($rescue['tooMany'] > 0) {
                $warnings[] = $rescue['tooMany'] . ' row(s) in the tables this snapshot leaves alone '
                            . ($dryRun === true ? 'would be' : 'were') . ' removed by the database\'s own '
                            . 'rules, and that is more than this portal will hold on to at once (the limit is '
                            . self::CASCADE_RESCUE_MAX_ROWS . '). NONE of them '
                            . ($dryRun === true ? 'would be' : 'has been') . ' kept, deliberately: keeping some '
                            // ⚠️ "when THE SNAPSHOT was taken", never "when it was taken":
                            //    "it" was first defined LATER in the same sentence, so the
                            //    only nouns before it were "the limit" and "them". And the
                            //    hedge is about ROW CURRENCY, not table presence: a
                            //    per-organisation row created since the snapshot was taken
                            //    has no copy in it AT ALL, which the old "if that snapshot
                            //    holds those tables" did not cover. This sentence is reached
                            //    only where NOTHING was kept, so it is the administrator's
                            //    one instruction and has to be exactly true.
                            . 'would read as keeping all of them. Whether the snapshot you are restoring FROM '
                            . 'holds older copies depends on the snapshot: only for the tables it covers, and only '
                            . 'for rows that existed when it was taken.';
            }
            foreach ($rescue['rows'] as $table => $rows) {
                $warnings[] = self::cascadeWarning(
                    (string) $table,
                    self::CASCADE_RESCUE[$table],
                    count($rows),
                    $dryRun,
                    $rescue['canKeep']
                );
            }

            // 🕳️ Say so if any pointer was emptied (see refuseUnlessMayEmpty()).
            if ($emptied !== []) {
                ksort($emptied);
                $named = [];
                foreach ($emptied as $where => $howMany) {
                    $named[] = $where . ' (' . $howMany . ')';
                }
                $warnings[] = array_sum($emptied) . ' pointer(s) ' . ($dryRun === true ? 'would be' : 'were')
                            . ' emptied, because the row each pointed at is not in this snapshot and the '
                            . 'database\'s own rule for that link is to empty the pointer when that row is deleted: '
                            . implode(', ', $named) . '. This is what happens when a link between two tables was '
                            . 'added after the snapshot was taken. Every other value '
                            . ($dryRun === true ? 'would be' : 'was') . ' put back as it was saved.';
            }

            // 🧲 Say so if a table that is NOT in the snapshot lost rows or
            //    pointers (see outsideTables()). Counted here, inside the
            //    transaction, so a rehearsal reports what a restore would do.
            [$sentence, $outsideChanged] = self::outsideChanges($outsideBefore, $this->countOutside($outside), $dryRun);
            if ($sentence !== '') {
                $warnings[] = $sentence;
            }

            // ✉️ Waiting mail that came back with the snapshot is HELD (see
            //    holdRestoredMail()). Done here, inside the transaction, so
            //    a rehearsal undoes it below with everything else.
            $heldMail = $this->holdRestoredMail($order, $dryRun);
            if ($heldMail !== '') {
                $warnings[] = $heldMail;
            }

            // 📱 The same for text messages that came back HELD for quiet
            //    hours (#610): see holdRestoredTexts().
            $heldTexts = $this->holdRestoredTexts($order, $dryRun);
            if ($heldTexts !== '') {
                $warnings[] = $heldTexts;
            }

            if ($dryRun === true) {
                // 🎭 THE REHEARSAL ENDS HERE, BY UNDOING EVERYTHING. A rehearsal
                //    that failed to undo would be a restore nobody asked for,
                //    so "it probably worked" is not good enough: see
                //    undoOrClose().
                if ($this->undoOrClose() === false) {
                    $zoneWas = null;
                    $modeWas = null;
                    return $fail(
                        'The rehearsal ran, but the database did not confirm that it was undone, so '
                        . 'the connection to the database was closed to make sure of it. Nothing has '
                        . 'been changed. Reload the page before doing anything else.',
                        true
                    );
                }
                return [
                    'success'              => true,
                    'tables_restored'      => $tablesDone,
                    'rows_restored'        => $rowsDone,
                    'error'                => '',
                    'warnings'             => array_merge(
                        ['This was a rehearsal. Every row was written and then taken out again. Nothing was changed.'],
                        $warnings
                    ),
                    'pointers_emptied'     => $emptied,
                    'other_tables_changed' => $outsideChanged,
                    'connection_closed'    => false,
                ];
            }

            $atCommit = true;
            if ($this->db->commit() === false) {
                throw new RuntimeException('The database would not make the restore permanent.');
            }
        } catch (\Throwable $e) {
            // 🔙 Put everything back. Because nothing was made permanent along
            //    the way, this really does return the database to exactly the
            //    state it was in before this method was called, PROVIDED the
            //    database confirms the undo. It used to be assumed: the
            //    message said "everything has been put back" whether or not
            //    the undo had been confirmed. Now it is confirmed, exactly as
            //    the rehearsal's own ending is, or the connection is closed.
            if ($this->undoOrClose() === true) {
                return $fail($stopped . $e->getMessage());
            }
            $zoneWas = null;
            $modeWas = null;
            if ($atCommit === true) {
                // The one moment nothing can be promised about.
                return $fail(
                    'The connection to the database failed at the very moment the restore was being made '
                    . 'permanent, so it cannot be told from here whether it took effect. It is one or the other, '
                    . 'never a part: either every table was put back or nothing was changed. Reload the page and '
                    . 'look at the data before doing anything else. The connection to the database was closed. '
                    . 'What the database said: ' . $e->getMessage(),
                    true
                );
            }
            return $fail(
                ($dryRun === true ? 'The rehearsal' : 'The restore') . ' was stopped. The database did not confirm '
                . 'that the unfinished work was undone, so the connection to the database was closed, which makes '
                . 'the database undo it. Nothing in the database has changed. Reload the page before doing anything '
                . 'else. The reason it stopped: ' . $e->getMessage(),
                true
            );
        } finally {
            $this->putStrictnessBack($modeWas);
            $this->putTimeZoneBack($zoneWas);
        }

        return [
            'success'              => true,
            'tables_restored'      => $tablesDone,
            'rows_restored'        => $rowsDone,
            'error'                => '',
            'warnings'             => $warnings,
            'pointers_emptied'     => $emptied,
            'other_tables_changed' => $outsideChanged,
            'connection_closed'    => false,
        ];
    }

    /**
     * 🔙 Undo this connection's unfinished work, and be SURE of it.
     *
     * "Nothing has been changed" is only true if the undo happened. The
     * database is asked to undo; if it does not say yes (the connection has
     * dropped, or anything else), the connection is closed, because the
     * database always undoes unfinished work when a connection ends. Either
     * way the work is undone; what differs is whether the caller may go on
     * using the connection.
     *
     * @return bool True: undone, and the connection is still usable.
     *              False: the connection was closed to make sure.
     */
    private function undoOrClose(): bool
    {
        $undone = false;
        try {
            $undone = $this->db->rollback();
        } catch (\Throwable $ignored) {
            unset($ignored);
        }
        if ($undone === true) {
            return true;
        }
        try {
            $this->db->close();
        } catch (\Throwable $ignored) {
            // Already closed: the work is undone all the same.
            unset($ignored);
        }
        return false;
    }

    /**
     * ✉️ At the end of a restore: HOLD the mail that came back waiting.
     *
     * WHY. A message that was waiting when the snapshot was taken is in the
     * snapshot. If it went afterwards, putting the snapshot back brings its
     * row back, and the mail job would send it a second time: three waiting
     * messages came out as six copies when this was run. Nobody can tell
     * from the restored rows which of them went. So every one that could go
     * by itself is held for a person, with that reason on it, and none is
     * sent by itself. MailQueue::holdRestored() holds the statement, with
     * every other statement that moves a queue row.
     *
     * Called INSIDE the restore's transaction, after the tables are back:
     * a rehearsal ("check only") undoes it with everything else and changes
     * nothing, and a restore that is stopped leaves nothing held. Only when
     * the mail queue's table was among the tables just put back: a snapshot
     * from before the queue existed brings no waiting mail, and what is
     * waiting NOW was not touched by the restore. If the statement fails it
     * THROWS, and the restore is stopped and undone: a restore that left
     * mail able to go twice would be worse than no restore.
     *
     * A restore of ONE table calls this too, and there it can do nothing
     * today: putting the queue's table back by itself is refused before it
     * starts, because the table of attached files points at it (see the
     * note at that call in restoreTableWork()).
     *
     * WHAT THIS CANNOT DO: say which of the held messages really went, or
     * release them. A held message is failed after the holding period;
     * releasing one by hand is the mail queue's own page.
     *
     * @param list<string> $restored The tables just put back.
     * @param bool         $dryRun   True in a rehearsal: only the wording changes.
     *
     * @return string A sentence for the person when any message was held; '' otherwise.
     */
    private function holdRestoredMail(array $restored, bool $dryRun): string
    {
        // ⚠️ namedIn(), not in_array(..., true): `$restored` is the restore's
        //    table order, which comes from the manifest and so from SHOW TABLES.
        //    On a server with lower_case_table_names = 1 this answered false,
        //    so restored mail was NOT held - and this method's own docblock
        //    says what that costs: "three waiting messages came out as six
        //    copies when this was run". Measured on such a server: success,
        //    no warning, the message left `queued`.
        if (self::namedIn('tblMailQueue', $restored) === false) {
            return '';
        }
        // This class is also loaded BY ITSELF, with nothing that finds other
        // classes for it (tools/backup-restore-selftest.php does, and so may
        // a script that restores outside the portal). class_exists() asks
        // the portal's own loader first; only when there is none is the
        // file beside this one read. holdRestored() needs nothing but the
        // connection it is given. (Found by that self-test: without this, a
        // restore run that way was stopped with "class not found".)
        if (class_exists(MailQueue::class) === false) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'MailQueue.php';
        }
        $held = MailQueue::holdRestored($this->db);
        if ($held < 1) {
            return '';
        }

        return $held . ' message' . ($held === 1 ? '' : 's') . ' that ' . ($held === 1 ? 'was' : 'were') . ' waiting in the mail queue when this snapshot was taken '
            . ($dryRun === true ? 'would be' : ($held === 1 ? 'has been' : 'have been')) . ' HELD, and will not be sent by '
            . ($held === 1 ? 'itself' : 'themselves') . ': the portal cannot tell whether ' . ($held === 1 ? 'it has' : 'they have')
            . ' been sent since, and sending again could mean a second copy. The health page counts held mail.';
    }

    /**
     * 📱 At the end of a restore: text messages that came back HELD for
     * quiet hours are recorded as NOT SENT (#610).
     *
     * WHY. Since #610 a text held because the person was in their Sabbath
     * quiet hours is sent by the scheduled job once those have ended. A text
     * that was held when the snapshot was taken is in the snapshot as
     * "held". If it went afterwards, putting the snapshot back brings its
     * row back as "held", and the job would send it a SECOND time, to
     * somebody's phone and at the customer's cost. Nobody can tell from the
     * restored rows which of them went. It is the same trap as waiting mail
     * (holdRestoredMail() above). Mail is held for a person to release; no
     * page releases a text, so each is recorded as not sent, with the
     * reason. Sms::holdRestored() holds the statement, with every other
     * statement that moves a held text.
     *
     * Called INSIDE the restore's transaction, after the tables are back,
     * exactly as holdRestoredMail() is: a rehearsal ("check only") undoes it
     * with everything else, and a restore that is stopped changes nothing.
     * Only when the table of text messages was among the tables just put
     * back: what is held NOW was otherwise not touched by the restore. If
     * the statement fails it THROWS, and the restore is stopped and undone:
     * a restore that left texts able to go twice would be worse than no
     * restore.
     *
     * WHAT THIS CANNOT DO: say which of those texts really went, or send
     * one that did not. A text that was held and had NOT gone is lost with
     * the rest, and its record says why.
     *
     * @param list<string> $restored The tables just put back.
     * @param bool         $dryRun   True in a rehearsal: only the wording changes.
     *
     * @return string A sentence for the person when any text was changed; '' otherwise.
     */
    private function holdRestoredTexts(array $restored, bool $dryRun): string
    {
        // The same fault as holdRestoredMail() above, and the same cost in its
        // own words: a held text sent "a SECOND time, to somebody's phone and
        // at the customer's cost". Measured on a lower-case server: the row
        // stayed `queued` with `quiet-hours`, and nothing was said.
        if (self::namedIn('tblSmsMessage', $restored) === false) {
            return '';
        }
        // This class is also loaded BY ITSELF (see holdRestoredMail()).
        // Sms::holdRestored() needs nothing but the connection it is given.
        if (class_exists(Sms::class) === false) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'Sms.php';
        }
        $moved = Sms::holdRestored($this->db);
        if ($moved < 1) {
            return '';
        }

        return $moved . ' text message' . ($moved === 1 ? '' : 's') . ' that ' . ($moved === 1 ? 'was' : 'were') . ' held for quiet hours when this snapshot was taken '
            . ($dryRun === true ? 'would be' : ($moved === 1 ? 'has been' : 'have been')) . ' recorded as NOT SENT, and will not be sent: the portal cannot tell whether '
            . ($moved === 1 ? 'it has' : 'they have') . ' been sent since, and sending again could mean a second text on somebody\'s phone.';
    }

    /**
     * The rows a restore kept because it could not put them back, newest first
     * (#694). For the Backup page.
     *
     * Only rows still waiting: one that has been put back is kept as a record
     * of what happened, with `putBackAtUtc` filled in, and is not offered
     * again.
     *
     * @return list<array{heldID: int, tableName: string, siteID: ?int, userID: ?int,
     *                    snapshotName: string, heldAtUtc: string, describe: string, names: string}>
     *         `names` is what the row IS and never what it says: a settings
     *         row's key, and an empty string for anything else. The stored
     *         JSON is deliberately NOT returned — see nameInsideHeldRow().
     */
    public function rowsWaitingToBePutBack(): array
    {
        if ($this->holdingTableExists() === false) {
            return [];
        }
        $result = $this->db->query(
            'SELECT heldID, tableName, siteID, userID, snapshotName, heldAtUtc, rowJson '
            . 'FROM tblRestoreHeldRows WHERE putBackAtUtc IS NULL ORDER BY heldID DESC'
        );
        if ($result === false) {
            return [];
        }
        $out = [];
        while ($row = $result->fetch_assoc()) {
            $table = (string) $row['tableName'];
            $out[] = [
                'heldID'       => (int) $row['heldID'],
                'tableName'    => $table,
                'siteID'       => $row['siteID'] === null ? null : (int) $row['siteID'],
                'userID'       => $row['userID'] === null ? null : (int) $row['userID'],
                'snapshotName' => (string) $row['snapshotName'],
                'heldAtUtc'    => (string) $row['heldAtUtc'],
                // Named from the constant, never from the stored row, so the
                // words cannot drift from the rule that put it there.
                'describe'     => self::CASCADE_RESCUE[$table]['describe'] ?? $table,
                // 🏷️ ONE NAME OUT OF THE ROW, AND NEVER THE ROW. `describe`
                //    cannot say WHICH settings went, because the rescue takes
                //    every per-organisation row whatever its key, and naming
                //    four address fields to somebody whose retention periods
                //    had gone would be worse than saying nothing. The key is
                //    the one thing in a settings row that names what it is
                //    without being a value: `org.address.line1` identifies
                //    nobody and nothing secret, while `settingValue` can be
                //    anything at all. So the KEY is lifted here and the JSON is
                //    NOT returned, which also means no caller can print a
                //    stored value by accident.
                'names'        => self::nameInsideHeldRow($table, (string) $row['rowJson']),
            ];
        }
        $result->free();

        return $out;
    }

    /**
     * Put every waiting row of one table back (#694).
     *
     * ONE TRANSACTION for the lot, and a row whose parent is STILL missing is
     * reported rather than forced: the parent row may simply not exist in the
     * restored database, in which case putting the child back is impossible and
     * saying so is the only honest answer. The row stays waiting, so nothing is
     * lost by trying.
     *
     * The column list is taken from the DATABASE, not from the stored JSON, and
     * only columns the table really has are written — a column the schema has
     * since dropped would otherwise fail the whole insert, which is the
     * REMOVED_COLUMNS problem this file already knows about.
     *
     * @return array{success: bool, put_back: int, still_missing: int, error?: string}
     */
    public function putHeldRowsBack(string $table): array
    {
        if (self::keepsRowsFor($table) === false) {
            return ['success' => false, 'put_back' => 0, 'still_missing' => 0,
                    'error' => 'This portal does not keep rows for ' . $table . '.'];
        }
        if ($this->holdingTableExists() === false) {
            return ['success' => false, 'put_back' => 0, 'still_missing' => 0,
                    'error' => 'There is nothing being kept: this database has not had its upgrade run yet.'];
        }
        // 📐 THE TABLE'S SHAPE, FROM THE SAME PLACE EVERY OTHER WRITER HERE
        //    READS IT. columnFacts() asks information_schema and decides
        //    "generated" from GENERATION_EXPRESSION; its comment says at length
        //    why `EXTRA LIKE '%GENERATED%'` is wrong.
        //
        //    This method first had its own `SHOW COLUMNS` loop doing exactly
        //    that wrong test, and it cost a real fault: MySQL puts
        //    EXTRA = 'DEFAULT_GENERATED' on any column declared
        //    `DEFAULT CURRENT_TIMESTAMP`, so `tblSettings.updatedAt` was read as
        //    generated, left out of the INSERT, and RE-STAMPED with the time of
        //    the put-back. The row went back with the wrong "last changed" date
        //    and nothing said so. Caught by proof 27 of
        //    tools/backup-restore-selftest.php, which compares every value
        //    rather than checking the row exists.
        // ⚠️ forTable(), NOT a subscript, and this was the THIRD site of this one
        //    class — found after two rounds of fixing the ones I could see.
        //
        //    `$table` here is a name the caller posted, and keepsRowsFor() has
        //    just demanded it match `CASCADE_RESCUE` exactly, so it carries the
        //    SOURCE's spelling (`tblSettings`). `columnFacts()` keys its answer
        //    by information_schema's own TABLE_NAME, which is lower case on a
        //    server with lower_case_table_names = 1. So the subscript found
        //    nothing and this method returned before touching a row — while
        //    `discardHeldRows()`, which needs no shape, worked perfectly.
        //
        //    Measured on `mysql:8.0.36 --lower-case-table-names=1`: the Backup
        //    page offered a kept row and drew both buttons; "put it back"
        //    always failed with "The shape of tblSettings could not be read"
        //    (naming the wrong cause — the shape WAS read, under another key)
        //    and "discard" removed it. **The only button that worked was the
        //    one that throws the data away.** That is the condition #694 exists
        //    to prevent, one door along.
        //
        //    THE LESSON, because this is the third round it has cost: when a
        //    case-sensitivity fault is found, fix the CLASS, not the instance.
        //
        //    ⚠️ AND GETTING THE RULE RIGHT TOOK A FOURTH ROUND. The first
        //       attempt read "every table-keyed array subscripted with a name
        //       that came from the SOURCE is the fault; the ones indexed by a
        //       name from the SERVER are safe", and it is wrong BOTH WAYS:
        //
        //       - it CLEARS a real fault. `REMOVED_COLUMNS[$table]` was the
        //         second of these three faults, and its index comes from the
        //         MANIFEST - the server - while its keys are typed here. The
        //         rule calls that safe.
        //       - it CONDEMNS three correct sites. `CASCADE_RESCUE[$table]` is
        //         indexed by a source name and capitals DO matter there, and
        //         that is right, because the array's own keys are source-typed
        //         too. forTable() would be the wrong fix for them.
        //
        //    The rule that is actually true: a table-keyed lookup is safe when BOTH SIDES COME FROM THE SAME PLACE, and unsafe when one side is folded by the server and the other is not.
        //    Keys typed here, indexed by a name typed here: safe.
        //    Keys from information_schema, indexed by a name from SHOW TABLES:
        //    safe on one server, and the cross-server case is #698. One of each:
        //    the fault.
        $facts = self::forTable($this->columnFacts(), $table);
        if ($facts === []) {
            return ['success' => false, 'put_back' => 0, 'still_missing' => 0,
                    'error' => 'The shape of ' . $table . ' could not be read.'];
        }
        $columns = [];
        foreach ($facts as $column => $fact) {
            // A derived column is worked out by the database and cannot be
            // written: an INSERT that names one is refused outright.
            if ($fact['generated'] === false) {
                $columns[] = (string) $column;
            }
        }

        $put  = 0;
        $gone = 0;
        // 🔐 THE TRANSACTION STARTS FIRST, AND THAT IS THE WHOLE POINT OF THE
        //    `FOR UPDATE` BELOW.
        //
        //    The first version prepared and ran that SELECT here, BEFORE
        //    begin_transaction(). No CODE in `web/_core/` touches autocommit —
        //    searching for the word now finds only these comments, so take the
        //    claim from the absence of a `->autocommit(` call, not from a
        //    grep count — so autocommit is on, the implicit transaction around a bare SELECT
        //    commits the moment the statement finishes, and every row lock it
        //    took was released before the real transaction began. The lock was
        //    decorative, and the comment above it CLAIMED it stopped two
        //    administrators reading the same rows. Found by the independent
        //    check, not by any test.
        //
        //    What the lock is for: without it the second press can read rows
        //    the first is already putting back, its INSERT then fails on the
        //    key, and the page tells it "1 could not be put back and is still
        //    being kept" about a row that IS back and is NOT being kept. No row
        //    could be duplicated either way — `tblSettings` has
        //    `uq_setting_key_site` and a code carries its own `codeID` — so
        //    this is about never saying something untrue, not about data. It is
        //    the house rule the four money writers follow (#647): every writer
        //    re-reads what it is about to change under a row lock.
        if ($this->db->begin_transaction() === false) {
            return ['success' => false, 'put_back' => 0, 'still_missing' => 0,
                    'error' => 'The database would not start a transaction.'];
        }
        // ⏳ THE LOCKING READ IS INSIDE THE try, AND MAKING THE LOCK REAL IS
        //    WHAT MADE THAT NECESSARY.
        //
        //    bootstrap.php sets mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR), so every one
        //    of these four calls THROWS rather than returning false. They sat
        //    outside the try at first, so a throw escaped this method with the
        //    transaction still open and the statement never closed.
        //
        //    And the reachable case is the one the lock exists for. Now that the
        //    lock is genuinely held, a second administrator pressing the button
        //    BLOCKS on the `FOR UPDATE`; the page has already called
        //    TimeLimit::atLeast(900), so PHP will not cut it off first, and at
        //    `innodb_lock_wait_timeout` (50 seconds by default) `execute()`
        //    throws ERROR 1205. Outside the try that gave a bare 500 instead of
        //    the honest message this method exists to give — and worse,
        //    Logger::db() hands back the SAME connection, so the `tblErrors` row
        //    was written inside the still-open transaction and rolled back when
        //    the connection closed. The fault left no record anywhere.
        $waiting = null;
        $rows    = null;
        try {
            $waiting = $this->db->prepare(
                'SELECT heldID, rowJson FROM tblRestoreHeldRows '
                . 'WHERE tableName = ? AND putBackAtUtc IS NULL ORDER BY heldID FOR UPDATE'
            );
            if ($waiting === false) {
                throw new RuntimeException('The kept rows could not be read.');
            }
            $waiting->bind_param('s', $table);
            $waiting->execute();
            $rows = $waiting->get_result();
            while ($row = $rows->fetch_assoc()) {
                $values = json_decode((string) $row['rowJson'], true);
                if (is_array($values) === false) {
                    $gone++;
                    continue;
                }
                $writing = array_values(array_filter(
                    $columns,
                    static fn (string $c): bool => array_key_exists($c, $values) === true
                ));
                if ($writing === []) {
                    $gone++;
                    continue;
                }
                $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $writing) . '`) VALUES ('
                     . implode(', ', array_fill(0, count($writing), '?')) . ')';
                $insert = $this->db->prepare($sql);
                if ($insert === false) {
                    throw new RuntimeException('The row could not be written back.');
                }
                $bind = [];
                foreach ($writing as $column) {
                    $bind[] = $values[$column];
                }
                $insert->bind_param(str_repeat('s', count($bind)), ...$bind);
                $thisOneWentBack = false;
                try {
                    $insert->execute();
                    $put++;
                    $thisOneWentBack = true;
                } catch (\mysqli_sql_exception $e) {
                    // The commonest reason, and the one worth telling somebody
                    // about: the organisation or the person this row belonged
                    // to is not in the restored database, so there is nothing
                    // for it to point at. NEVER the database's own words —
                    // they can quote the value, which here can be a setting or
                    // a hashed code.
                    $gone++;
                }
                $insert->close();
                // 🧷 THIS row, not "any row so far". Written as `$put > 0`
                //    first, which marked every row as put back the moment one
                //    succeeded — so a row that could not go back would have
                //    stopped being offered, which is the one thing this whole
                //    feature exists to prevent.
                if ($thisOneWentBack === true) {
                    // 🔒 THE COPY IS EMPTIED AS THE ROW IS MARKED, in one
                    //    statement, and this is not tidiness.
                    //
                    //    A marked row is never offered again and nothing
                    //    anywhere deletes one: `discardHeldRows()` takes only
                    //    WAITING rows, and the Backup page lists only waiting
                    //    rows. So without this the whole original row — a hash
                    //    of somebody's sign-in code, or a stored setting value —
                    //    would sit here FOR EVER, grow with every restore, and
                    //    travel into every later snapshot and every off-site
                    //    copy. That is a second copy of authentication material
                    //    outside the table erasure protects, unbounded in time,
                    //    which is the exact thing the owner was warned about
                    //    when they chose to keep the codes at all.
                    //
                    //    Once the row IS back in its own table the copy has no
                    //    purpose: it exists only so the row CAN go back. What
                    //    is worth keeping is the RECORD that it was kept and
                    //    returned — which table, whose, from which snapshot,
                    //    when — and that is every other column.
                    $mark = $this->db->prepare(
                        'UPDATE tblRestoreHeldRows SET putBackAtUtc = UTC_TIMESTAMP(), rowJson = NULL WHERE heldID = ?'
                    );
                    if ($mark !== false) {
                        $heldId = (int) $row['heldID'];
                        $mark->bind_param('i', $heldId);
                        $mark->execute();
                        $mark->close();
                    }
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            // ⛔ Never the database's own words: they can quote the value being
            //    put back, which here is a setting or a hashed code. The
            //    commonest cause now is waiting too long for another
            //    administrator's lock, and the message covers that honestly
            //    without guessing which it was.
            $this->db->rollback();

            return ['success' => false, 'put_back' => 0, 'still_missing' => 0,
                    'error' => 'Nothing was put back, and the kept rows are exactly as they were. '
                             . 'If somebody else is putting rows back at this moment, wait and try again.'];
        } finally {
            // Closed on EVERY path, including a throw: `finally` is what makes
            // that true, and the first version closed it only in the two
            // branches it happened to think of.
            if ($rows instanceof \mysqli_result) {
                $rows->free();
            }
            if ($waiting instanceof \mysqli_stmt) {
                $waiting->close();
            }
        }

        return ['success' => true, 'put_back' => $put, 'still_missing' => $gone];
    }

    /**
     * Throw away the rows kept for one table (#694).
     *
     * Deliberate, and the only way they go: nothing here removes them on a
     * timer (the owner, 31 October 2026), because they exist while somebody is
     * mid-recovery.
     *
     * @return int How many were discarded; -1 when this portal keeps no rows
     *             for that table at all; -2 when the database refused, which in
     *             practice means another administrator is putting rows back at
     *             this moment and holds the lock. Nothing was discarded in
     *             either negative case.
     */
    public function discardHeldRows(string $table): int
    {
        // -1, not 0: the page has to be able to tell "this portal does not keep
        // rows for that table" from "there was nothing waiting". Returning 0
        // for both made it answer `0 kept row(s) for <anything posted>
        // discarded. They cannot be recovered.` and write the posted name
        // into the activity log as though it were a real table.
        if (self::keepsRowsFor($table) === false) {
            return -1;
        }
        if ($this->holdingTableExists() === false) {
            return 0;
        }
        // ⏳ CAUGHT, BECAUSE MAKING THE PUT-BACK'S LOCK REAL MADE THIS BLOCK.
        //
        //    `putHeldRowsBack()` now holds row locks on exactly these rows for
        //    the length of its transaction, so this DELETE can wait on them and,
        //    at `innodb_lock_wait_timeout` (50 seconds by default), throw
        //    ERROR 1205. bootstrap.php sets
        //    mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR), and the
        //    Backup page's action handler has no try/catch of its own, so that
        //    throw gave the administrator a BARE 500 with no message at all —
        //    and TimeLimit::atLeast(900) guarantees PHP will not cut it short
        //    first. The put-back path was given this handling a round earlier
        //    and the discard path was not, which is the same half-applied fix
        //    this package has already made twice.
        //
        //    -2 rather than an exception: this method's callers word the answer,
        //    and a number keeps that true. Nothing is half done either way — one
        //    DELETE is atomic and opens no transaction of its own.
        try {
            $stmt = $this->db->prepare('DELETE FROM tblRestoreHeldRows WHERE tableName = ? AND putBackAtUtc IS NULL');
            if ($stmt === false) {
                return 0;
            }
            $stmt->bind_param('s', $table);
            $stmt->execute();
            $gone = $stmt->affected_rows;
        } catch (\Throwable $e) {
            // NEVER the database's own words: they can quote the value being
            // discarded, which here is a setting or a hashed code.
            return -2;
        } finally {
            // Outside the try on purpose. With `close()` INSIDE it, a throw from
            // close() AFTER a successful execute() would have committed the
            // deletion (one DELETE, autocommit) and still answered -2 — making
            // "Nothing was discarded, and the kept rows are exactly as they
            // were" false. Narrow, but it is the one direction that matters.
            if (isset($stmt) === true && $stmt instanceof \mysqli_stmt) {
                $stmt->close();
            }
        }

        return max(0, $gone);
    }

    /**
     * The whole sentence an administrator reads about rows a restore could not
     * put back (#694).
     *
     * WHY THIS IS A METHOD WITH EIGHT WHOLE SENTENCES IN IT, and not the
     * expression it replaced. That expression grew to ninety lines and nine
     * nested ternaries over five review rounds, and FOUR of the six wording
     * faults those rounds found were in it:
     *
     *   - it told an administrator their settings were not in their backup,
     *     in the same answer that said those tables had been left alone;
     *   - a REHEARSAL, which changes nothing, said "nothing replaced them";
     *   - "1 setting(s) ... were removed", and "1 of them is being kept";
     *   - "Any of it that existed ... if it was created since is not."
     *
     * Every one was a ternary in the middle of a clause, and none was findable
     * by any check, because nothing judges how an assembled sentence reads. So
     * the combinations are spelt out instead: eight complete sentences you can
     * read in one go, named by the three facts that choose between them. A
     * missing combination is now obvious, and a wording fault is visible
     * without evaluating anything in your head.
     *
     * READ THEM ALL before changing one, and PRINT them by calling this method
     * rather than reading the source: transcribing is how you check the sentence
     * you meant to write instead of the one that is there. Two separate facts,
     * which an earlier draft of this line wrongly joined with a "so": `php -l`
     * cannot tell you a sentence has stopped making sense, and the tests pin
     * only SUBSTRINGS of these, so they will happily pin a substring of nonsense.
     *
     * NEVER put a literal `%` in one of these tails. They go into the sprintf
     * FORMAT, and PHP 8.5 does not throw on a stray one — `sprintf('%1$d of
     * 100% of them', 3)` prints `3 of 1003f them`. A `%` inside an ARGUMENT is
     * harmless; measured both.
     *
     * @param array{parent: string, column: string, describe: string} $about
     */
    private static function cascadeWarning(
        string $table,
        array $about,
        int $howMany,
        bool $dryRun,
        bool $canKeep
    ): string {
        // 🔤 The three facts that choose the sentence. `describe` ends in "(s)",
        //    so it cannot agree with a number and the verb has to.
        $when  = $dryRun === true ? 'ahead' : 'done';
        $keep  = $canKeep === true ? 'kept' : 'lost';
        $count = $howMany === 1 ? 'one' : 'many';

        $removed = [
            'ahead.one'  => 'would be removed',
            'ahead.many' => 'would be removed',
            'done.one'   => 'was removed',
            'done.many'  => 'were removed',
        ][$when . '.' . $count];

        $replaced = [
            'ahead.one'  => 'This restore would not put %3$s back, so nothing would replace it.',
            'ahead.many' => 'This restore would not put %3$s back, so nothing would replace them.',
            'done.one'   => 'This restore did not put %3$s back, so nothing replaced it.',
            'done.many'  => 'This restore did not put %3$s back, so nothing replaced them.',
        ][$when . '.' . $count];

        // The eight tails, in full. Nothing here is stitched from words.
        $tails = [
            'ahead.kept.one'  => 'In a real restore it would be kept on the Backup page, where you could put it '
                               . 'back or discard it.',
            'ahead.kept.many' => 'In a real restore they would be kept on the Backup page, where you could put them '
                               . 'back or discard them.',
            'ahead.lost.one'  => 'It could NOT be kept, because the holding table is not there — most likely this '
                               . 'database has not had its upgrade run. Run the database upgrade and it would be '
                               . 'kept next time.',
            'ahead.lost.many' => 'They could NOT be kept, because the holding table is not there — most likely this '
                               . 'database has not had its upgrade run. Run the database upgrade and they would be '
                               . 'kept next time.',
            'done.kept.one'   => 'It is being kept for you on the Backup page: put it back there, or discard it '
                               . 'deliberately. Nothing removes it on a timer.',
            // "All %1$d are being kept", never "N of them are": a partial rescue
            // cannot happen (holdRescuedRows() throws rather than returning a
            // short count, which rolls the whole restore back), and a branch for
            // one printed "0 of them are being kept ... put them back there" — a
            // false offer in the one direction this feature exists to prevent.
            'done.kept.many'  => 'All %1$d are being kept for you on the Backup page: put them back there, or '
                               . 'discard them deliberately. Nothing removes them on a timer.',
            // ⚠️ "Any of them that existed when the snapshot was taken", never
            //    "What they were is in the snapshot". The rows a cascade removes
            //    are the LIVE rows at the moment of the restore; the snapshot
            //    holds that table as it stood when it was TAKEN, so a row created
            //    since has no copy in it at all. Reached only where NOTHING was
            //    kept, which makes it the administrator's one instruction.
            // ⚠️ WHAT THE SNAPSHOT HOLDS IS TWO CONDITIONS, NOT ONE, and these
            //    sentences have now been wrong BOTH ways round. The first version
            //    hedged on table PRESENCE ("if that snapshot holds those tables")
            //    and said nothing about currency; the second hedged on CURRENCY
            //    ("any created since are not in it") and asserted presence — which
            //    is false for the case THIS PACKAGE ADDED, a snapshot that does not
            //    contain the table at all (proof 27 section F). Both reach this
            //    tail, and getting it wrong here is wrong in the REASSURING
            //    direction: it tells somebody mid-recovery that rows they have
            //    just lost for good can be read out of their backup.
            //
            //    And the CAUSE is not asserted either. All the code knows is that
            //    the holding table is absent; a hand-dropped table or a failed
            //    query look the same, so it says "most likely".
            'done.lost.one'   => 'It has NOT been kept, because the holding table is not there — most likely this '
                               . 'database has not had its upgrade run. Whether the snapshot you are restoring FROM '
                               . 'holds an older copy depends on the snapshot: only if it covers %3$s at all, and '
                               . 'only if the row existed when it was taken.',
            'done.lost.many'  => 'They have NOT been kept, because the holding table is not there — most likely '
                               . 'this database has not had its upgrade run. Whether the snapshot you are restoring '
                               . 'FROM holds older copies depends on the snapshot: only if it covers %3$s at all, '
                               . 'and only for rows that existed when it was taken.',
        ];
        $tail = $tails[$when . '.' . $keep . '.' . $count];

        // ⚠️ "This restore did not put X back", NEVER "this snapshot does not
        //    cover X". An OLD-FORM snapshot DOES contain the table — the
        //    held-back list is built from the manifest's own list, so a table
        //    has to be in the snapshot to be held back at all — and the same
        //    answer already says those tables were left exactly as they are.
        //    Saying both told an administrator mid-recovery that their settings
        //    were not in their backup. This wording covers BOTH reasons a table
        //    is not put back: held back, or simply absent.
        return sprintf(
            '%1$d %2$s ' . $removed . ' by the database\'s own rule for the link between %3$s and %4$s. '
            . $replaced . ' ' . $tail,
            $howMany,
            $about['describe'],
            $table,
            $about['parent']
        );
    }

    /**
     * Does this portal keep rows for that table at all (#694)?
     *
     * The ONE test, asked by both writers and by the Backup page, so no caller
     * keeps a copy of the list. Membership of `CASCADE_RESCUE` is also what
     * makes the name safe to put into a statement: every identifier used by
     * this feature comes from that constant and never from a request.
     */
    public static function keepsRowsFor(string $table): bool
    {
        return isset(self::CASCADE_RESCUE[$table]) === true;
    }

    /**
     * The one thing in a kept row that may be shown: what the row IS, never
     * what it says (#694).
     *
     * For `tblSettings` that is `settingKey` — `org.address.line1` names
     * nothing secret and nobody, while `settingValue` can be anything at all,
     * a decrypted integration credential among them. For
     * `tblTotpBackupCodes` there is NOTHING showable: every column of it is
     * either the hash itself or the person it belongs to, and the Backup page
     * is read by an administrator of the INSTALLATION, who may be a different
     * person from the administrators of the organisations on it (the owner,
     * 29 October 2026). So that table answers an empty string, deliberately,
     * and a count is all the page ever shows for it.
     *
     * A table added to CASCADE_RESCUE later answers an empty string too, which
     * is the safe direction: it shows less rather than something unreviewed.
     */
    private static function nameInsideHeldRow(string $table, string $json): string
    {
        if ($table !== 'tblSettings') {
            return '';
        }
        $row = json_decode($json, true);

        return is_array($row) === true && is_string($row['settingKey'] ?? null) === true
            ? (string) $row['settingKey']
            : '';
    }

    /**
     * Is the holding table there (#694)?
     *
     * It is NOT there on a database whose upgrade has not been run: the files
     * reach a server before anybody presses "Run migrations". Every reader of
     * `tblRestoreHeldRows` asks this first, so that case degrades to what the
     * portal did before #694 — the rows are named and lost — instead of a bare
     * 500 on the Backup page or a refused restore.
     *
     * WHY `SHOW TABLES LIKE` AND NOT A try/catch: it answers "does a table by
     * this name exist" without ever failing because the table is missing — it
     * simply returns no rows. bootstrap.php sets
     * mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR), so an ordinary query on a missing
     * table THROWS; a blanket try/catch would hide a genuinely broken
     * connection behind the same "nothing is being kept" answer as the
     * harmless, expected case. The house pattern is
     * Newsletter::demoRegisterExists().
     */
    private function holdingTableExists(): bool
    {
        $result = $this->db->query("SHOW TABLES LIKE 'tblRestoreHeldRows'");
        if ($result === false) {
            return false;
        }
        $there = $result->num_rows > 0;
        $result->free();

        return $there;
    }

    /**
     * The rows a restore is about to lose to a cascade, read BEFORE anything is
     * emptied (#694).
     *
     * Only for a table that is BOTH held back from this restore AND a child,
     * with ON DELETE CASCADE, of a table this restore is going to replace. A
     * row whose pointer is already empty is not at risk and is left out.
     *
     * READ NOW, WRITTEN AFTERWARDS, and that order is the point: the holding
     * table itself may be in the snapshot, so writing the rows before the
     * restore would see them deleted again by the restore of that very table.
     * Keeping them in memory for the length of one restore sidesteps the
     * ordering question altogether, and CASCADE_RESCUE_MAX_ROWS is the price of
     * doing it that way.
     *
     * WHAT THAT PRICE REALLY IS, said plainly. The ceiling counts ROWS, not
     * bytes, and a `tblSettings` value is MEDIUMTEXT — so 20,000 rows is not a
     * bounded amount of memory. And `holdRescuedRows()` runs inside the
     * restore's transaction, so if it throws (out of memory, a value
     * json_encode() will not take) the WHOLE RESTORE rolls back: a restore that
     * used to succeed while losing the rows would now fail outright and change
     * nothing. That is the safer of the two directions — nothing is half done,
     * and the message says nothing was changed — but it IS a change in
     * behaviour, and no test reaches it.
     *
     * @param list<string> $replacing The tables this restore is going to empty —
     *                                 which, at the one call site, is the
     *                                 manifest's list with the held-back
     *                                 tables already taken out. A table that is
     *                                 NOT in it is not being put back, whatever
     *                                 the reason.
     *
     * @return array{rows: array<string, list<array<string, mixed>>>, tooMany: int, canKeep: bool}
     *         `tooMany` is the total row count when it is over the ceiling, in
     *         which case `rows` is empty: holding some of them would tell an
     *         administrator the rest had been kept too.
     *
     *         `canKeep` is false when the holding table is not there, which
     *         happens on a database whose upgrade has not been run yet — the
     *         files reach a server before anybody presses "Run migrations".
     *         The restore then goes ahead and the rows are NAMED but not kept,
     *         which is what the portal did before #694 plus an honest warning.
     *         Refusing the restore instead would block a recovery at the one
     *         moment somebody is recovering, which is worse than the loss this
     *         issue is about.
     */
    private function readRowsACascadeWouldTake(array $replacing): array
    {
        $found = [];
        $total = 0;
        $canKeep = $this->holdingTableExists();
        foreach (self::CASCADE_RESCUE as $table => $about) {
            // 🎯 THE QUESTION IS "IS THIS TABLE BEING PUT BACK", NOT "IS IT ON
            //    THE HELD-BACK LIST", and that distinction was wrong at first.
            //
            //    The first version asked whether the table was in `$heldBack`,
            //    which is built ONLY from names the manifest itself lists. So a
            //    snapshot whose manifest named `tblSites` but NOT `tblSettings`
            //    — a hand-edited one, or simply an imported snapshot that does
            //    not contain the table — restored the sites, let the cascade
            //    wipe every per-organisation settings row, rescued nothing and
            //    printed no #694 warning at all. Only the older generic count
            //    said anything. That is the same silent loss this whole feature
            //    exists to stop, one door along.
            //
            //    "Not in `$replacing`" covers both: a held-back table was taken
            //    out of that list by the block that held it back, and a table
            //    the snapshot never had was never in it.
            // Capitals ignored: see namedIn(). `in_array(..., true)` here made
            // this whole rescue inert on a server with
            // lower_case_table_names = 1 (#694, found checking #697).
            if (self::namedIn((string) $table, $replacing) === true) {
                continue;
            }
            if (self::namedIn((string) $about['parent'], $replacing) === false) {
                continue;
            }
            // The identifiers come from CASCADE_RESCUE, a constant in this
            // file, so they are not user input — but they are still checked
            // against the same pattern every other identifier here is, because
            // a typo in a constant would otherwise build SQL.
            if (preg_match(self::IDENTIFIER, $table) !== 1 || preg_match(self::IDENTIFIER, $about['column']) !== 1) {
                continue;
            }
            $result = $this->db->query(
                'SELECT * FROM `' . $table . '` WHERE `' . $about['column'] . '` IS NOT NULL'
            );
            if ($result === false) {
                continue;
            }
            $rows = [];
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
                $total++;
                if ($total > self::CASCADE_RESCUE_MAX_ROWS) {
                    $result->free();
                    // Keep counting, so the answer can say how many there were,
                    // but hold nothing.
                    while ($this->db->more_results() === true && $this->db->next_result() === true) {
                        // drain, so the connection is usable
                    }
                    $count = 0;
                    foreach (self::CASCADE_RESCUE as $t => $a) {
                        if (self::namedIn((string) $t, $replacing) === true
                            || self::namedIn((string) $a['parent'], $replacing) === false) {
                            continue;
                        }
                        // The same identifier check as the loop above, for the
                        // same reason: a typo in the constant would otherwise
                        // build SQL. It was missing here, which made the one
                        // guard that exists for that case half a guard.
                        if (preg_match(self::IDENTIFIER, $t) !== 1 || preg_match(self::IDENTIFIER, $a['column']) !== 1) {
                            continue;
                        }
                        $one = $this->db->query('SELECT COUNT(*) AS n FROM `' . $t . '` WHERE `' . $a['column'] . '` IS NOT NULL');
                        if ($one !== false) {
                            $count += (int) ($one->fetch_assoc()['n'] ?? 0);
                            $one->free();
                        }
                    }

                    return ['rows' => [], 'tooMany' => $count, 'canKeep' => $canKeep];
                }
            }
            $result->free();
            if ($rows !== []) {
                $found[$table] = $rows;
            }
        }

        return ['rows' => $found, 'tooMany' => 0, 'canKeep' => $canKeep];
    }

    /**
     * The rows still waiting to be put back, read BEFORE anything is emptied,
     * so a restore cannot write over them (#697).
     *
     * WHAT GOES WRONG WITHOUT THIS. `tblRestoreHeldRows` is an ordinary table
     * from migration 233 on, so a whole-database restore empties it and fills
     * it again from the snapshot. A snapshot taken BEFORE some rows were kept
     * holds none of them, so those rows simply vanish - and they are the only
     * copy of themselves anywhere outside the snapshot being restored FROM.
     * That is the silent loss #694 exists to stop, one door along. Measured on
     * MySQL 8.0.36: one waiting row before the restore, none after, and the
     * restore reported success with a warning nobody mid-recovery is in a
     * state to act on.
     *
     * WHY ONLY THE WAITING ONES. A row that has already been put back is
     * HISTORY: it says that a row was kept and returned, and its copy of the
     * row was emptied the moment it went home (see putHeldRowsBack()). Putting
     * a database back to an earlier moment is supposed to put its history back
     * with it. **That is a judgement, not a promise that nothing goes** - an
     * earlier version of this paragraph said "nothing is lost that the restored
     * database does not itself account for", and the `@return` note on
     * carryWaitingHeldRowsThrough() records a measured sequence in which the
     * waiting row held the LAST copy of a setting value and afterwards it was in
     * no table and in no kept row. A WAITING row is the opposite: it is work in
     * progress, and
     * the only copy of data that is no longer in its own table. The owner's
     * decision of 31 October 2026 - that a waiting row waits until somebody
     * deals with it - is what this protects.
     *
     * NO CEILING OF ITS OWN, AND THAT IS DELIBERATE. These rows are held in
     * memory for the length of the restore and a `tblSettings` value is
     * MEDIUMTEXT, so there IS a bound — but it is decided once, in
     * restoreAllWork(), before the transaction starts: past
     * CASCADE_RESCUE_MAX_ROWS waiting rows that method does not put the holding
     * table back at all, so this one is never reached with more than the
     * ceiling in front of it. The first version decided the ceiling HERE and
     * answered "carry nothing", which let the snapshot's older copy of the list
     * replace every waiting row — measured at 20,001 rows in, zero out. Two
     * places deciding one ceiling is also how a pair of readers come to
     * disagree (#668).
     *
     * THE CASCADE RESCUE'S CEILING IS THE SAME NUMBER FOR THE SAME REASON — BUT
     * NOT THE SAME BUDGET, and an earlier version of this paragraph said "it is
     * the same memory", which implies one. They are independent, so the worst
     * case the two can reach between them is TWICE the constant. Both can be
     * non-empty in one restore: a modern snapshot that contains
     * `tblRestoreHeldRows` but not `tblSettings` gives the cascade rescue work
     * to do AND needs the carry. Found by the independent check.
     *
     * @param list<string> $replacing The tables this restore is going to empty.
     *                                `tblRestoreHeldRows` not being in it means
     *                                nothing is going to write over these rows
     *                                - which is the ordinary case for an
     *                                OLD-FORM snapshot, where it is held back
     *                                by name, and for a snapshot that does not
     *                                contain it at all.
     *
     * @return array{rows: list<array<string, mixed>>, couldNotRead: bool}
     *         `couldNotRead` is true when the database would not answer at all,
     *         which must not be reported as "nothing was waiting" — see the
     *         comment on that return.
     */
    private function readWaitingHeldRows(array $replacing): array
    {
        $nothing = ['rows' => [], 'couldNotRead' => false];
        // ⚡ AN OPTIMISATION, NOT THE GUARD, and the first version of this comment
        //    read as though it were the guard. Measured by the independent check:
        //    replace this test with `false` and proofs 6, 26, 27 and 28 all stay
        //    green over 126 checks. That is not a coverage gap — with the table
        //    not being emptied, every waiting row is still at its own `heldID`
        //    and identical to itself, so each takes the "already" branch of
        //    carryWaitingHeldRowsThrough() and nothing is written. What this
        //    saves is a pointless read of every waiting row, including its
        //    MEDIUMTEXT copy, inside the restore's own transaction. Worth
        //    keeping; just not what makes anything safe.
        if (self::namedIn('tblRestoreHeldRows', $replacing) === false) {
            return $nothing;
        }
        // Not there on a database whose upgrade has not been run yet: the files
        // reach a server before anybody presses "Run migrations". SHOW TABLES,
        // never a blanket try/catch - see holdingTableExists().
        if ($this->holdingTableExists() === false) {
            return $nothing;
        }
        // 🔇 A READ THAT FAILS IS SAID OUT LOUD, NOT TREATED AS "NOTHING WAITING".
        //
        //    Both of the `=== false` returns below used to answer $nothing,
        //    which is indistinguishable from an empty holding table: the restore
        //    would go on and write over the waiting rows with no warning at all,
        //    which is the exact failure this method exists to remove. Found by
        //    the independent check.
        //
        //    They are unreachable as things stand — bootstrap.php sets
        //    mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR), and it
        //    takes BOTH for a statement error to throw rather than return false
        //    — so this is about never being able to fail silently if that ever
        //    changes, which is the house pattern elsewhere in this file.
        //
        //    `couldNotRead`, not a throw: refusing the restore would block a
        //    recovery at the one moment somebody is recovering, which is the
        //    same reasoning #694 used for a missing holding table. The restore
        //    goes ahead and SAYS the rows could not be carried.
        $result = $this->db->query(
            'SELECT * FROM `tblRestoreHeldRows` WHERE `putBackAtUtc` IS NULL ORDER BY `heldID`'
        );
        if ($result === false) {
            return ['rows' => [], 'couldNotRead' => true];
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();

        return ['rows' => $rows, 'couldNotRead' => false];
    }

    /**
     * Put the waiting rows back into the holding table, after the restore has
     * written this snapshot's own copy of it over them (#697).
     *
     * Called INSIDE the restore's transaction, so a restore that rolls back
     * takes this with it - the rows were never lost in that case.
     *
     * HOW A ROW IS RECOGNISED, because this cannot simply insert everything. If
     * the snapshot was taken AFTER a row was kept, the snapshot holds that same
     * row, with the same `heldID`, and inserting it again would offer one row
     * twice. So each carried row is looked up by its own `heldID` first:
     *
     *   - nothing there        -> written back WITH its original `heldID`, so
     *                             the numbering an administrator may already
     *                             have seen on the page does not change.
     *   - the same row there   -> left alone. The snapshot's copy IS this row.
     *   - a DIFFERENT row there -> written back under a NEW `heldID`. This is
     *                             reachable: `loadTable()` writes the
     *                             snapshot's own ids, which pushes
     *                             AUTO_INCREMENT back to just above the
     *                             snapshot's highest, and the rescue below may
     *                             then be handed an id a waiting row already
     *                             had. Nothing is lost; only the number
     *                             changes.
     *
     * "The same row" compares `tableName`, `siteID`, `userID` and `heldAtUtc`,
     * and deliberately NOT `rowJson`. Two reasons. A row already put back has
     * had its copy emptied, so comparing the copy would call it a different row
     * and re-offer something the restored database says is finished. And
     * `rowJson` is a JSON column: MySQL rewrites what it stores (keys reordered,
     * a space after each colon) while MariaDB's JSON is an alias for LONGTEXT
     * and rewrites nothing, so a text comparison means two different things on
     * the two engines. The four columns above are an identity; the copy is
     * content.
     *
     * A FAILURE HERE STOPS THE WHOLE RESTORE, by throwing. Same direction as
     * holdRescuedRows(): nothing is half done, the message says nothing was
     * changed, and the alternative is a restore that reports success while the
     * waiting rows have gone. This table has no foreign keys and nothing but
     * its primary key to clash on, so the only reachable causes are the
     * infrastructural ones - out of memory, or a database that has stopped
     * answering.
     *
     * @param list<array<string, mixed>>                                                    $rows
     * @param array<string, array{type: string, nullable: bool, generated: bool, restamped: bool}> $facts
     *        `tblRestoreHeldRows`' columns, from the restore's own
     *        `columnFacts()` read. Empty means the shape could not be read,
     *        which stops this method rather than guessing.
     *
     * @return array{carried: int, already: int, moved: int, stoppedWaiting: int}
     *         `stoppedWaiting` counts the rows the snapshot records as ALREADY
     *         PUT BACK, which are therefore no longer offered. **Do not call
     *         them "not a loss".** This docblock did, in the words the warning
     *         itself was reworded to stop using, and it was measured false: in
     *         an ordinary sequence (keep a row, put it back, delete the setting,
     *         snapshot, restore an older snapshot which brings the row back
     *         waiting, restore the newer one) the waiting row held the LAST copy
     *         of a setting value, and afterwards it was in no table and in no
     *         kept row. Nothing here looks at whether the snapshot holds the
     *         row's own table, so nothing here can say. What IS true is that the
     *         restore honours what the snapshot records, and that the rows
     *         disappear from the Backup page, so somebody has to be told.
     */
    private function carryWaitingHeldRowsThrough(array $rows, array $facts): array
    {
        $answer = ['carried' => 0, 'already' => 0, 'moved' => 0, 'stoppedWaiting' => 0];
        if ($rows === []) {
            return $answer;
        }
        // The shape is HANDED IN, from the columnFacts() the restore already
        // read. columnFacts() decides "generated" from GENERATION_EXPRESSION,
        // and its comment says at length why `EXTRA LIKE '%GENERATED%'` is
        // wrong - that exact mistake cost this package a re-stamped
        // `updatedAt`. This method asked for its own copy at first, which meant
        // a second information_schema read of every table in the database
        // inside the restore's own transaction.
        if ($facts === []) {
            throw new RuntimeException('The shape of the table that keeps rows a restore could not put '
                                     . 'back would not be read, so the rows waiting in it could not be '
                                     . 'carried through this restore.');
        }
        $columns = [];
        foreach ($facts as $column => $fact) {
            if ($fact['generated'] === false) {
                $columns[] = (string) $column;
            }
        }
        $withoutId = array_values(array_filter(
            $columns,
            static fn (string $c): bool => $c !== 'heldID'
        ));
        if ($withoutId === [] || count($withoutId) === count($columns)) {
            // No `heldID` among the writable columns at all: the table is not
            // the shape this method was written for, and guessing would be
            // worse than stopping.
            throw new RuntimeException('The table that keeps rows a restore could not put back is not the '
                                     . 'shape this portal expects, so the rows waiting in it could not be '
                                     . 'carried through this restore.');
        }

        $look  = null;
        $keep  = null;
        $fresh = null;
        try {
            // 📌 `putBackAtUtc` IS SELECTED TO COUNT, NEVER TO COMPARE. sameHeldRow()
            //    deliberately ignores it (see its docblock), and that must not change:
            //    a row the snapshot records as already put back IS the same row, and
            //    the restore honours that snapshot's own history. But until it was
            //    selected, nothing could COUNT those rows — so the one case where a
            //    waiting row deliberately stops waiting happened in SILENCE, while the
            //    Backup page said "the result always tells you what happened" and the
            //    help page listed it as something the portal "will not do silently".
            //    Measured by the independent check: waiting 1 before, 0 after, zero
            //    warnings. Counting it is what makes those two sentences true.
            $look = $this->db->prepare(
                'SELECT `tableName`, `siteID`, `userID`, `heldAtUtc`, `putBackAtUtc` '
                . 'FROM `tblRestoreHeldRows` WHERE `heldID` = ?'
            );
            $keep = $this->db->prepare(
                'INSERT INTO `tblRestoreHeldRows` (`' . implode('`, `', $columns) . '`) VALUES ('
                . implode(', ', array_fill(0, count($columns), '?')) . ')'
            );
            $fresh = $this->db->prepare(
                'INSERT INTO `tblRestoreHeldRows` (`' . implode('`, `', $withoutId) . '`) VALUES ('
                . implode(', ', array_fill(0, count($withoutId), '?')) . ')'
            );
            if ($look === false || $keep === false || $fresh === false) {
                throw new RuntimeException('The rows waiting to be put back could not be carried through '
                                         . 'this restore.');
            }
            foreach ($rows as $row) {
                $heldId = (int) ($row['heldID'] ?? 0);
                $look->bind_param('i', $heldId);
                $look->execute();
                $found   = $look->get_result();
                $takenBy = $found instanceof \mysqli_result ? $found->fetch_assoc() : null;
                if ($found instanceof \mysqli_result) {
                    $found->free();
                }
                if (is_array($takenBy) === true && self::sameHeldRow($row, $takenBy) === true) {
                    $answer['already']++;
                    // The snapshot says this row has already gone home, so it is no
                    // longer offered. Counted separately from the ordinary "the
                    // snapshot holds this row as waiting too" case, where nothing
                    // changes for anybody and there is nothing to say.
                    if (($takenBy['putBackAtUtc'] ?? null) !== null) {
                        $answer['stoppedWaiting']++;
                    }
                    continue;
                }
                $names = is_array($takenBy) === true ? $withoutId : $columns;
                $use   = is_array($takenBy) === true ? $fresh : $keep;
                $bind  = [];
                foreach ($names as $column) {
                    $bind[] = $row[$column] ?? null;
                }
                // Bound as text, as every other writer here does: the database
                // converts, and the session is strict (pinStrictness()), so a
                // value that would not fit is an error rather than a silent
                // change.
                $use->bind_param(str_repeat('s', count($bind)), ...$bind);
                $use->execute();
                $answer['carried']++;
                if (is_array($takenBy) === true) {
                    $answer['moved']++;
                }
            }
        } finally {
            // Closed on every path, a throw included. The statements are the
            // only thing this method owns; the transaction belongs to the
            // restore and is rolled back by it.
            foreach ([$look, $keep, $fresh] as $stmt) {
                if ($stmt instanceof \mysqli_stmt) {
                    $stmt->close();
                }
            }
        }

        return $answer;
    }

    /**
     * Is the row now sitting at this `heldID` the same kept row we are carrying
     * through (#697)? See carryWaitingHeldRowsThrough() for why these four
     * columns and not `rowJson`.
     *
     * @param array<string, mixed> $waiting The row read before the restore.
     * @param array<string, mixed> $found   The row at that id afterwards.
     */
    private static function sameHeldRow(array $waiting, array $found): bool
    {
        foreach (['tableName', 'siteID', 'userID', 'heldAtUtc'] as $column) {
            $before = $waiting[$column] ?? null;
            $after  = $found[$column] ?? null;
            // Empty is only equal to empty. Compared as text otherwise, because
            // one side comes back from a numeric column as a string and the
            // other may not.
            if ($before === null || $after === null) {
                if ($before !== $after) {
                    return false;
                }
                continue;
            }
            if ((string) $before !== (string) $after) {
                return false;
            }
        }

        return true;
    }

    /**
     * The whole sentence about rows carried through a restore (#697).
     *
     * FOUR COMPLETE SENTENCES, not one sentence with ternaries in the middle of
     * it. That is the lesson of cascadeWarning(): four of the six wording
     * faults six review rounds found in #694 were inside an assembled sentence,
     * not one of them findable by any check, because nothing judges how a
     * sentence reads and a test pinning a substring will pin a substring of
     * nonsense. Read them all, and PRINT them by calling this method rather
     * than transcribing.
     *
     * Never a literal `%` in one of these: they are sprintf formats.
     */
    private static function carriedWarning(int $howMany, bool $dryRun): string
    {
        // ⚠️ WHAT THESE MUST NOT SAY, because the first version said both and
        //    both were FALSE in the same reachable branch. The number here is
        //    the number of rows WRITTEN AGAIN, not the number waiting: a row the
        //    snapshot already holds takes the "already" branch and is not
        //    counted. So with four waiting and two in the snapshot the first
        //    version said "All 2 rows kept for you ... were carried through",
        //    giving an administrator with four waiting rows a number that is not
        //    the count of anything they can see — and "All" of a part. It also
        //    ASSERTED that the snapshot's copy "does not include the row", which
        //    in that same branch is untrue of half of them.
        //
        //    So neither "All" nor any claim about what the snapshot contains.
        //
        //    ⚠️ AND NO CLAIM ABOUT *EVERY* WAITING ROW EITHER. The fix for the
        //       "All" fault ended each sentence "Every row that was waiting is
        //       still waiting on the Backup page", which replaced an undercount
        //       with an ABSOLUTE promise — and sameHeldRow() ignores
        //       `putBackAtUtc` on purpose, so a row that is waiting here while
        //       the snapshot records it as already PUT BACK takes the "already"
        //       branch and stops waiting. Measured by the independent check,
        //       reached with two real snapshots and two real restores. That
        //       behaviour is right (the snapshot's own history says the row went
        //       home, and its data is in that snapshot's `tblSettings`), so what
        //       had to change was the sentence.
        //
        //    These speak ONLY about the rows this restore wrote again, which is
        //    the one thing the number counts and the one thing it can promise.
        $sentences = [
            'ahead.one'  => 'One row kept for you by an earlier restore would have to be written again after '
                          . 'this restore, because this snapshot holds its own copy of the list of kept rows. '
                          . 'It would still be waiting on the Backup page afterwards.',
            'ahead.many' => '%1$d rows kept for you by earlier restores would have to be written again after '
                          . 'this restore, because this snapshot holds its own copy of the list of kept rows. '
                          . 'They would still be waiting on the Backup page afterwards.',
            'done.one'   => 'One row kept for you by an earlier restore had to be written again after this '
                          . 'restore, because this snapshot holds its own copy of the list of kept rows. It is '
                          . 'still waiting on the Backup page.',
            'done.many'  => '%1$d rows kept for you by earlier restores had to be written again after this '
                          . 'restore, because this snapshot holds its own copy of the list of kept rows. They '
                          . 'are still waiting on the Backup page.',
        ];
        $which = ($dryRun === true ? 'ahead' : 'done') . '.' . ($howMany === 1 ? 'one' : 'many');

        return sprintf($sentences[$which], $howMany);
    }

    /**
     * The whole sentence when the snapshot records a kept row as already put
     * back, so it is no longer offered (#697). Four complete sentences, for the
     * same reason as carriedWarning().
     *
     * WHY THIS EXISTS AT ALL. It is the one case where a row an administrator
     * could see on the Backup page stops being offered, and it used to happen in
     * complete silence — measured by the independent check — while the Backup
     * page claimed "the result always tells you what happened" and the help page
     * listed it among the things the portal "will not do silently". The choice
     * was to weaken both sentences or to make them true. This makes them true.
     *
     * ⚠️ IT MUST NOT CLAIM NOTHING WAS LOST, and the first version did — "this
     *    snapshot holds the row in its own copy of the table it came from".
     *    Nothing here looks at whether the snapshot holds that table, and the
     *    independent check measured both ways it can be false: a snapshot that
     *    contains the holding table but NOT `tblSettings`, and an ordinary
     *    sequence where the waiting row held the last copy of a setting value
     *    and afterwards it was in no table and no kept row. Asserted in the
     *    REASSURING direction, which is the worst one — and the check over it
     *    pinned the untrue clause, so it held the fault in place.
     *
     *    So these say only what is known: the snapshot records the row as
     *    already put back, so it is no longer offered, and where to look.
     */
    private static function stoppedWaitingWarning(int $howMany, bool $dryRun): string
    {
        $sentences = [
            'ahead.one'  => 'One row kept for you by an earlier restore is recorded in this snapshot as having '
                          . 'already been put back, so it would no longer be offered on the Backup page '
                          . 'afterwards: this restore would honour what the snapshot says happened to it. If you '
                          . 'need what was in that row, look in the table it came from once the restore has '
                          . 'finished, and in an earlier backup if it is not there.',
            'ahead.many' => '%1$d rows kept for you by earlier restores are recorded in this snapshot as having '
                          . 'already been put back, so they would no longer be offered on the Backup page '
                          . 'afterwards: this restore would honour what the snapshot says happened to them. If '
                          . 'you need what was in those rows, look in the tables they came from once the restore '
                          . 'has finished, and in an earlier backup if they are not there.',
            'done.one'   => 'One row kept for you by an earlier restore is recorded in this snapshot as having '
                          . 'already been put back, so it is no longer offered on the Backup page: this restore '
                          . 'honoured what the snapshot says happened to it. If you need what was in that row, '
                          . 'look in the table it came from, and in an earlier backup if it is not there.',
            'done.many'  => '%1$d rows kept for you by earlier restores are recorded in this snapshot as having '
                          . 'already been put back, so they are no longer offered on the Backup page: this '
                          . 'restore honoured what the snapshot says happened to them. If you need what was in '
                          . 'those rows, look in the tables they came from, and in an earlier backup if they '
                          . 'are not there.',
        ];
        $which = ($dryRun === true ? 'ahead' : 'done') . '.' . ($howMany === 1 ? 'one' : 'many');

        return sprintf($sentences[$which], $howMany);
    }

    /**
     * The whole sentence when the number of kept rows could not be COUNTED, so
     * the holding table was left alone (#697).
     *
     * ⚠️ A SEPARATE SENTENCE FROM couldNotReadKeptRowsWarning(), AND THE TWO
     *    MUST NOT BE MERGED. They describe opposite outcomes, and sharing one
     *    sentence made it false: that one says "only the rows this snapshot
     *    holds a copy of are back", which is true when the table WAS restored
     *    and the rows could not be written again — and flatly untrue here,
     *    where the table was not restored at all and every waiting row is
     *    exactly where it was. One sentence for two outcomes is how this
     *    package collected six wording faults.
     *
     * Neither branch is reached while `MYSQLI_REPORT_STRICT |
     * MYSQLI_REPORT_ERROR` makes a failing query throw rather than return
     * false; both exist so that neither can fail silently if that changes.
     */
    private static function couldNotCountKeptRowsWarning(bool $dryRun): string
    {
        return $dryRun === true
            ? 'How many rows are being kept for you by earlier restores could not be read, so this '
              . 'rehearsal left that list alone: a real restore would not put this snapshot\'s own copy '
              . 'of it back, and every row waiting now would still be waiting afterwards. Everything '
              . 'else in the snapshot would be put back as usual.'
            : 'How many rows are being kept for you by earlier restores could not be read, so this '
              . 'restore left that list alone: this snapshot\'s own copy of it was NOT put back, and '
              . 'every row that was waiting is still waiting on the Backup page. Everything else in '
              . 'the snapshot was put back as usual.';
    }

    /**
     * The whole sentence when the kept rows themselves could not be read, after
     * the holding table has already been replaced (#697). See
     * couldNotCountKeptRowsWarning() for why these are two sentences and not
     * one.
     *
     * It says what is NOT known rather than guessing a number: the read that
     * failed is the one that would have counted them. NO TEST REACHES THIS —
     * `mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR)` makes a
     * statement error throw rather than return false, so the two returns that
     * set this flag are unreachable while that stays true. It exists so that
     * they can never fail SILENTLY if it stops being true.
     */
    private static function couldNotReadKeptRowsWarning(bool $dryRun): string
    {
        return $dryRun === true
            ? 'The list of rows kept for you by earlier restores could not be read, so this rehearsal '
              . 'cannot say whether a real restore would write them again. Look at the Backup page before '
              . 'you restore, and put any kept rows back or discard them deliberately.'
            : 'The list of rows kept for you by earlier restores could not be read, so none of them was '
              . 'written again after this restore. Whether a row is still offered on the Backup page now '
              . 'depends on this snapshot: only the rows it holds a copy of are back, and a row kept since '
              . 'it was taken has no copy in it at all. Look at the Backup page.';
    }

    /**
     * The whole sentence when there are so many kept rows that the holding
     * table is left alone instead (#697). Two complete sentences, for the same
     * reason as carriedWarning().
     *
     * NO TEST REACHES THIS by building the rows: it needs more than
     * CASCADE_RESCUE_MAX_ROWS rows waiting at once, which is 20,000 rows of a
     * MEDIUMTEXT column. The independent check reached it once, by hand, and
     * that is how the old behaviour — carry nothing, let the snapshot's copy
     * replace them all — was found to lose every waiting row while two pages
     * promised it could not.
     */
    private static function keptRowsHeldBackWarning(int $howMany, bool $dryRun): string
    {
        $sentences = [
            'ahead' => 'There are %1$d rows kept for you by earlier restores, which is more than this portal '
                     . 'will write again after one restore (the limit is %2$d). So this snapshot\'s own, older '
                     . 'copy of that list would NOT be put back, and every one of those rows would still be '
                     . 'waiting on the Backup page afterwards. Everything else in the snapshot would be put back '
                     . 'as usual. Putting kept rows back, or discarding them deliberately, is what clears this.',
            'done'  => 'There are %1$d rows kept for you by earlier restores, which is more than this portal '
                     . 'will write again after one restore (the limit is %2$d). So this snapshot\'s own, older '
                     . 'copy of that list was NOT put back, and every one of those rows is still waiting on the '
                     . 'Backup page. Everything else in the snapshot was put back as usual. Putting kept rows '
                     . 'back, or discarding them deliberately, is what clears this.',
        ];

        return sprintf($sentences[$dryRun === true ? 'ahead' : 'done'], $howMany, self::CASCADE_RESCUE_MAX_ROWS);
    }

    /**
     * Write the rescued rows into the holding table (#694).
     *
     * Called INSIDE the restore's own transaction and AFTER every table has
     * been put back, so a restore that rolls back takes these with it — the
     * rows were never lost in that case, and a holding table full of rows that
     * are still in their own table would be worse than useless.
     *
     * `siteID` and `userID` are copied out of the row where the row has them,
     * so the Backup page can say whose a row was and `GdprEraser` can reach it.
     * Everything else is kept as JSON exactly as it was read.
     *
     * @param array<string, list<array<string, mixed>>> $byTable
     *
     * @return array<string, int> How many were held, per table.
     */
    private function holdRescuedRows(array $byTable, string $snapshotName): array
    {
        if ($byTable === []) {
            return [];
        }
        $stmt = $this->db->prepare(
            'INSERT INTO `tblRestoreHeldRows` (`tableName`, `siteID`, `userID`, `rowJson`, `snapshotName`, `heldAtUtc`) '
            . 'VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        if ($stmt === false) {
            throw new RuntimeException('The rows this restore would have lost could not be kept: '
                                     . 'the holding table would not accept them.');
        }
        $held = [];
        foreach ($byTable as $table => $rows) {
            foreach ($rows as $row) {
                $siteId = isset($row['siteID']) === true && $row['siteID'] !== null ? (int) $row['siteID'] : null;
                $userId = isset($row['userID']) === true && $row['userID'] !== null ? (int) $row['userID'] : null;
                $json   = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json === false) {
                    throw new RuntimeException('A row this restore would have lost could not be written down.');
                }
                $stmt->bind_param('siiss', $table, $siteId, $userId, $json, $snapshotName);
                if ($stmt->execute() === false) {
                    $stmt->close();
                    throw new RuntimeException('The rows this restore would have lost could not be kept.');
                }
                $held[$table] = ($held[$table] ?? 0) + 1;
            }
        }
        $stmt->close();

        return $held;
    }

    /**
     * Put ONE table back from a snapshot. The table is emptied and its rows
     * written again, inside one transaction: if anything fails, the table is
     * left exactly as it was.
     *
     * Only for a table nothing else points at. Emptying a table other tables
     * point at would either be refused by the database or would silently
     * delete the linked rows in those other tables too; restoreAll() handles
     * those, in an order that keeps the links intact.
     *
     * And only for a table that MEANS something on its own: the tables on
     * WHOLE_DATABASE_ONLY are refused by name before anything is read or
     * emptied, because putting one of them back by itself leaves the rest of
     * the database out of step with it. A whole-database restore still puts
     * all of them back and is the supported way to do it.
     *
     * Unlike restoreAll(), rows the database refuses as duplicates are counted
     * and left out rather than stopping the restore (see below), which is why
     * this writes one row per statement.
     *
     * A row pointing at a row that does not exist stops this restore, whatever
     * the link's rule: unlike restoreAll(), no pointer is ever emptied here.
     *
     * The snapshot must be a FINISHED one. A folder with no manifest used to
     * be accepted here; it is a snapshot that failed part way, and the
     * manifest is also what says which form the files are in.
     *
     * Like restoreAll(), it runs in a strict session of its own (see
     * pinStrictness()): a value the database says it would have to change to
     * make it fit stops the restore, whatever the host's own settings. The
     * changes the database makes without a word (this file's header) pass
     * here as they do there.
     *
     * @return array{success: bool, rows_restored: int, error?: string,
     *               skipped_duplicates?: int, notice?: string, connection_closed?: bool}
     *         connection_closed is true when the connection had to be closed:
     *         to be sure unfinished work was undone (see undoOrClose()), or
     *         because its own settings could not be put back afterwards (see
     *         putStrictnessBack(); `notice` then says so, and `success` can
     *         still be true). The caller must not use the connection again
     *         in that request.
     */
    public function restoreTable(string $snapshotPath, string $table): array
    {
        $this->sessionNotPutBack = false;
        return $this->sayIfSessionNotPutBack($this->restoreTableWork($snapshotPath, $table), 'restore', 'notice');
    }

    /**
     * Everything restoreTable() does, except the last word on whether the
     * connection's own settings were put back afterwards (see restoreAllWork()).
     *
     * @return array<string, mixed> As restoreTable().
     */
    private function restoreTableWork(string $snapshotPath, string $table): array
    {
        $fail = static function (string $why, bool $connectionClosed = false): array {
            return ['success' => false, 'rows_restored' => 0, 'error' => $why, 'connection_closed' => $connectionClosed];
        };

        // 🛡️ Validate the table identifier FIRST — before it is ever used to
        //    build a filesystem path or put into SQL.
        if (preg_match(self::IDENTIFIER, $table) !== 1) {
            return $fail('Invalid table identifier: ' . $table);
        }

        // 🚫 Refuse every table that only makes sense put back beside the
        //    rest of the database (WHOLE_DATABASE_ONLY says why each is on the
        //    list, and what to ask before adding another).
        //
        //    THE POSITION OF THIS CHECK IS THE POINT. It is above every read of
        //    the snapshot and far above emptyTable(), so a refused table costs
        //    nothing at all: no file is opened, no transaction is started and
        //    no row is touched. Moving it below the emptying would turn the
        //    guard into the very thing it exists to stop. The check just above
        //    tests only the SHAPE of the name; it can never notice what a table
        //    means.
        if (self::isWholeDatabaseOnly($table) === true) {
            return $fail(self::wholeDatabaseOnlyReason($table));
        }

        $opened = $this->openSnapshot($snapshotPath);
        if (is_string($opened) === true) {
            return $fail($opened . ' Nothing has been changed.');
        }
        ['dir' => $dir, 'manifest' => $manifest, 'format' => $format] = $opened;
        if (is_array($manifest['tables'][$table] ?? null) === false) {
            return $fail('This snapshot does not hold ' . $table . '. Nothing has been changed.');
        }

        // 🧾 Is the file the one that was saved, and whole? Checked BEFORE
        //    anything is emptied, so a damaged file costs nothing.
        $problem = $this->tableFileProblem($dir, $table, $manifest['tables'][$table], $format);
        if ($problem !== '') {
            return $fail($problem . ' Nothing has been changed.');
        }

        // 🚫 Read the table's shape BEFORE anything is emptied: which columns
        //    the database fills in for itself (an older snapshot may still
        //    contain one, and an INSERT naming it is refused), and whether its
        //    rows point at each other.
        $graph       = $this->foreignKeyGraph();
        $facts       = [];
        $self        = [];
        $links       = [];
        $notUndoable = '';
        $packet      = 0;
        try {
            $facts       = $this->columnFacts();
            $links       = $this->links();
            $self        = $this->selfPointers($links);
            $notUndoable = $this->notUndoableProblem([$table]);
            $packet      = $this->largestMessage();
        } catch (\Throwable $e) {
            $graph = null;
        }
        if ($graph === null || isset($facts[$table]) === false) {
            return $fail('Could not read the shape of ' . $table . ' from the database, or how its tables '
                       . 'are linked together, so the restore was not started. Nothing has been changed.');
        }
        if ($notUndoable !== '') {
            return $fail($notUndoable . ' Nothing has been changed.');
        }
        $tableFacts = $facts[$table];
        $pointers   = $self[$table]['pointers'] ?? [];
        $key        = $self[$table]['key'] ?? [];
        if (isset($self[$table]) === true) {
            $problem = $this->selfPointerProblem($table, $tableFacts, $self[$table]);
            if ($problem !== '') {
                return $fail($problem . ' Nothing has been changed.');
            }
        }

        $pointedAtBy = [];
        foreach ($graph as $child => $parents) {
            foreach ($parents as $parent) {
                $pointedAtBy[$parent][] = $child;
            }
        }

        if (isset($pointedAtBy[$table]) === true) {
            $others = array_unique($pointedAtBy[$table]);
            sort($others);
            return $fail('This table cannot be put back on its own, because other '
                       . 'tables point at it: ' . implode(', ', array_slice($others, 0, 6))
                       . (count($others) > 6 ? ', and others' : '') . '. Emptying it '
                       . 'by itself would either be refused by the database, or would '
                       . 'silently delete the linked rows in those other tables too. '
                       . 'Restore the whole snapshot instead - that puts every table '
                       . 'back in an order that keeps the links intact. Nothing has '
                       . 'been changed.');
        }

        $zoneWas  = null;
        $modeWas  = null;
        $atCommit = false;
        try {
            if ($format >= 2) {
                $zoneWas = $this->pinTimeZone((string) $manifest['time_zone']);
            }
            $modeWas = $this->pinStrictness();
            if ($this->db->begin_transaction() === false) {
                throw new RuntimeException('The database would not start a transaction.');
            }
            $this->emptyTable($table, $pointers, $tableFacts);

            $rows = $this->rows($dir, $table, $format);

            // 🚫 NOTHING BELOW HERE CAN RUN TODAY, and it is kept on purpose.
            //    Since tblSettings went onto WHOLE_DATABASE_ONLY (26 October
            //    2026) this method refuses that table at its very top, so the
            //    sort can never be reached. It is kept because taking it out
            //    would silently bring back the fault it was written to fix:
            //    if tblSettings is ever taken off that list, whichever copy of
            //    a duplicate row happened to be listed first would win again.
            //    The duplicate-row handling further down is NOT dead — it
            //    applies to any table — and is proved on tblRoutes in proof 17
            //    of tools/backup-restore-selftest.php. This sort, by contrast,
            //    is not exercised by anything any more.
            //
            // 📑 A snapshot of the settings table taken before migration 187
            //    contains duplicate portal-wide rows. Only one of each can go
            //    back in, so the order they are offered in decides which one
            //    survives — and the snapshot has no order at all.
            //
            //    Sort them by the same rule migration 187 uses, so the row an
            //    administrator actually changed goes in first and the leftover
            //    seed is the one turned away. Without this, whichever copy
            //    happened to be listed first would win, which could quietly
            //    replace a real setting with an untouched default.
            //
            //    (This holds the whole settings table in memory to sort it. It
            //    is one of the smallest tables there is.)
            if ($table === 'tblSettings') {
                // Must match migration 187's rule EXACTLY, or the row kept
                // here differs from the row the migration would have kept.
                // Two details matter and are easy to get wrong:
                //
                //   * "no value at all" and "an empty value" are different
                //     things. Casting both to text would make them look the
                //     same, and a row whose value is empty while its default
                //     is absent HAS been edited.
                //   * a missing timestamp counts as the very beginning of
                //     1970, the same stand-in the migration uses, so the two
                //     agree about which row is newer.
                $looksEdited = static function (array $r): int {
                    $v = $r['settingValue'] ?? null;
                    $d = $r['defaultValue'] ?? null;
                    if ($v === null || $d === null) {
                        return ($v === $d) ? 0 : 1;
                    }
                    // Exact text, so a change of capitalisation counts.
                    return ((string) $v === (string) $d) ? 0 : 1;
                };
                $stamp = static function (array $r): string {
                    $u = $r['updatedAt'] ?? null;
                    return ($u === null || $u === '') ? '1970-01-01 00:00:00' : (string) $u;
                };
                $rows = iterator_to_array($rows, false);
                usort($rows, static function (array $a, array $b) use ($looksEdited, $stamp): int {
                    return [$looksEdited($b), $stamp($b), (int) ($b['settingID'] ?? 0)]
                       <=> [$looksEdited($a), $stamp($a), (int) ($a['settingID'] ?? 0)];
                });
            }

            $inserted          = 0;
            $skippedDuplicates = 0;
            $columns           = [];
            $statement         = null;
            try {
                foreach ($rows as $row) {
                    // 🧹 As in loadTable(): see REMOVED_COLUMNS.
                    $row = self::withoutRemovedColumns($table, $row, $tableFacts);
                    if ($statement === null) {
                        foreach (array_keys($row) as $column) {
                            if (($tableFacts[$column]['generated'] ?? false) === false) {
                                $columns[] = $column;
                            }
                        }
                        if ($columns === []) {
                            throw new RuntimeException('The saved copy of ' . $table . ' holds no column that can be written.');
                        }
                        $statement = $this->db->prepare(
                            'INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`) VALUES ('
                            . implode(', ', array_fill(0, count($columns), '?')) . ')'
                        );
                        if ($statement === false) {
                            throw new RuntimeException('Could not prepare an insert for ' . $table . '.');
                        }
                    }
                    $values  = [];
                    $rowSize = 0;
                    foreach ($columns as $column) {
                        // Pointers at rows of this same table go in empty and
                        // are filled in below; see loadTable().
                        $value    = in_array($column, $pointers, true) === true ? null : $row[$column];
                        $values[] = $value;
                        $rowSize += $value === null ? 0 : strlen($value);
                    }
                    // 📏 As in loadTable(): refused in words before it is sent.
                    $tooLarge = self::tooLargeProblem($table, $rowSize, count($columns), $packet);
                    if ($tooLarge !== '') {
                        throw new RuntimeException($tooLarge);
                    }

                    // 🔁 A snapshot taken BEFORE a "no two rows may be the same"
                    //    rule was added can contain rows that the rule now
                    //    refuses. The pre-upgrade snapshot is exactly that
                    //    case: it is taken before the migrations run, so a
                    //    settings snapshot from before migration 187 still
                    //    holds the duplicate rows that migration was written to
                    //    remove.
                    //
                    //    Refusing the whole restore would leave an
                    //    administrator with no way back at the very moment they
                    //    need one. So a row the rule rejects is counted and
                    //    skipped, and the count is reported at the end. Every
                    //    other kind of failure stops the restore, and the
                    //    rollback below then puts the table's old contents back
                    //    exactly. (A comment here used to say the rollback
                    //    could NOT do that, because the table was emptied with
                    //    TRUNCATE. That stopped being true when #472 replaced
                    //    TRUNCATE with DELETE.)
                    //
                    //    1062 is the database's number for "that would be a
                    //    duplicate"; 1586 is the same thing worded differently.
                    $rowProblem = $this->tryStatement($statement, $values);
                    if ($rowProblem === null) {
                        $inserted++;
                    } elseif ($rowProblem['code'] === 1062 || $rowProblem['code'] === 1586) {
                        $skippedDuplicates++;
                    } else {
                        throw new RuntimeException('Could not write a row to ' . $table . ': ' . self::whyRefused($rowProblem));
                    }
                }
            } finally {
                if ($statement instanceof \mysqli_stmt) {
                    $statement->close();
                }
            }

            $notCounted = null;   // null = a single-table restore: never empty a pointer
            $this->fillPointers($dir, $table, $format, $tableFacts, array_values(array_intersect($pointers, $columns)), $key, $links[$table] ?? [], $notCounted);

            // ✉️ A SAFEGUARD THAT CANNOT RUN TODAY, kept on purpose. A restore
            //    of the mail queue's table by itself never gets this far: it
            //    is refused near the top of this method, because another
            //    table (the queue's attached files) points at it, and for
            //    every other table this call does nothing. (An earlier note
            //    said only "the same as a whole restore", as if it did
            //    something.) It is left in because the refusal up there
            //    knows nothing about mail: if that rule is ever relaxed,
            //    this is what stops mail that came back waiting from being
            //    sent a second time. No test can reach it while the refusal
            //    stands.
            $heldMail = $this->holdRestoredMail([$table], false);

            // 📱 This one CAN run: the table of text messages may be put
            //    back by itself, because no other table points at it. A text
            //    that came back held for quiet hours is recorded as not
            //    sent (#610): see holdRestoredTexts().
            $heldTexts = $this->holdRestoredTexts([$table], false);

            $atCommit = true;
            if ($this->db->commit() === false) {
                throw new RuntimeException('The database would not make the restore permanent.');
            }

            $result = ['success' => true, 'rows_restored' => $inserted];
            if ($skippedDuplicates > 0) {
                $result['skipped_duplicates'] = $skippedDuplicates;
                $result['notice'] = $skippedDuplicates . ' row(s) in this snapshot '
                    . 'were left out because the database no longer allows two rows '
                    . 'the same. This is expected when restoring a snapshot taken '
                    . 'before that rule was introduced — the duplicates were never '
                    . 'meant to be there. Everything else was restored.';
            }
            if ($heldMail !== '') {
                // In `notice`, which the page already shows: no new key for a page to forget.
                $result['notice'] = trim(($result['notice'] ?? '') . ' ' . $heldMail);
            }
            if ($heldTexts !== '') {
                $result['notice'] = trim(($result['notice'] ?? '') . ' ' . $heldTexts);
            }

            return $result;
        } catch (\Throwable $e) {
            // Confirmed, not assumed: see undoOrClose(), and restoreAll() for
            // why a failure at the commit itself is worded differently.
            if ($this->undoOrClose() === true) {
                return $fail('The table was left exactly as it was. The reason the restore stopped: ' . $e->getMessage());
            }
            $zoneWas = null;
            $modeWas = null;
            if ($atCommit === true) {
                return $fail(
                    'The connection to the database failed at the very moment the restore of this table was being '
                    . 'made permanent, so it cannot be told from here whether it took effect. The table is either '
                    . 'wholly as it was or wholly put back, never a part. Reload the page and look at it before '
                    . 'doing anything else. The connection to the database was closed. What the database said: '
                    . $e->getMessage(),
                    true
                );
            }
            return $fail(
                'The restore of this table was stopped. The database did not confirm that the unfinished work was '
                . 'undone, so the connection to the database was closed, which makes the database undo it. The '
                . 'table was left exactly as it was. Reload the page before doing anything else. The reason it '
                . 'stopped: ' . $e->getMessage(),
                true
            );
        } finally {
            $this->putStrictnessBack($modeWas);
            $this->putTimeZoneBack($zoneWas);
        }
    }

    /**
     * @internal Remove a folder and everything in it. A link is removed as a
     *           link and never followed: following one would delete whatever
     *           it points at, outside the backups folder.
     */
    private function rmdirRecursive(string $dir): bool
    {
        if (is_link($dir) === true) {
            return unlink($dir);
        }
        if (is_dir($dir) === false) {
            return true;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return false;
        }
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $e;
            if (is_dir($path) === true && is_link($path) === false) {
                if ($this->rmdirRecursive($path) === false) {
                    return false;
                }
            } else {
                if (unlink($path) === false) {
                    return false;
                }
            }
        }
        return rmdir($dir);
    }
}
