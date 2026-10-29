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
    ];

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
        foreach (self::REMOVED_COLUMNS[$table] ?? [] as $gone) {
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
     * Take a snapshot of every `tbl*` table.
     *
     * A snapshot that fails removes its own folder, so a failure can never be
     * mistaken for a backup. (PHP cannot do that when the process is killed or
     * runs out of memory; prune() removes such a folder once it has sat
     * unchanged for 24 hours, and until then it is listed as unfinished.)
     *
     * A snapshot that SUCCEEDS can still carry a warning: see
     * unlistedWarning(). `warnings` is then not empty, and the caller must
     * show it.
     *
     * @param string $reason Short label kept in the manifest
     *                       (e.g. 'pre-upgrade-1.4.0').
     *
     * @return array{success: bool, directory: string, tables: int, rows: int, error?: string,
     *               warnings: array<int, string>,
     *               unlisted_values: array<int, array{table: string, column: string, cells: int}>}
     */
    public function snapshot(string $reason = ''): array
    {
        $fail = static function (string $why): array {
            return ['success' => false, 'directory' => '', 'tables' => 0, 'rows' => 0, 'error' => $why,
                    'warnings' => [], 'unlisted_values' => []];
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
            return [
                'success'         => true,
                'directory'       => $dir,
                'tables'          => count($tables),
                'rows'            => $totalRows,
                'warnings'        => $warning === '' ? [] : [$warning],
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
        // Three tables may be put back only as part of a whole restore
        // (WHOLE_DATABASE_ONLY): each holds a "this has already happened" mark,
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

        // 🧾 SAY what was held back, if anything. A restore that quietly skips
        //    a table is the silent kind of failure this portal keeps being
        //    caught by, so this goes in the answer the administrator reads.
        if ($heldBack !== []) {
            $warnings[] = 'This snapshot does not say that it covers the whole database, so '
                        . implode(' and ', $heldBack) . ' ' . (count($heldBack) === 1 ? 'was' : 'were')
                        . ' left exactly as ' . (count($heldBack) === 1 ? 'it is' : 'they are')
                        . '. Each of those records what has already happened — which migrations have run, '
                        . 'which backup codes have been used, what the settings now say — and putting one '
                        . 'back without the rest of the database loses data. Everything else in the snapshot '
                        . ($dryRun === true ? 'would be' : 'was') . ' put back. Snapshots taken by this '
                        . 'portal from 27 October 2026 onwards say that they cover the whole database, and '
                        . 'for those nothing is held back.';
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
        if (in_array('tblMailQueue', $restored, true) === false) {
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
        if (in_array('tblSmsMessage', $restored, true) === false) {
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

        // 🚫 Refuse the three tables that only make sense put back beside the
        //    rest of the database (WHOLE_DATABASE_ONLY says why each is on the
        //    list, and what to ask before adding a fourth).
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
