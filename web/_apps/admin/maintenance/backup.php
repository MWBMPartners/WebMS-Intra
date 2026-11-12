<?php
// Path: public_html/admin/maintenance/backup.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Backup Management UI 📦
 * -----------------------------------------------------------------------------
 * List / inspect / run / check / restore / delete the snapshots made by
 * Portal\Core\DbBackup. A restore of one table empties it and writes its rows
 * again; a restore of the whole snapshot does that for every table. Either
 * way it is one transaction: if anything fails, nothing changes. (This header
 * and the two confirmation boxes used to say the tables were emptied with
 * TRUNCATE. That stopped being true with #472, and TRUNCATE is precisely what
 * must never be used here: it cannot be undone.)
 *
 * Actions:
 *   GET  /admin/maintenance/backup                     — list
 *   GET  /admin/maintenance/backup?inspect=NAME        — show manifest detail (a link
 *                                                        leading out of the backups folder
 *                                                        is refused, as for every action)
 *   POST action=snapshot_now                           — DbBackup::snapshot('manual')
 *   POST action=check_snapshot name=NAME               — rehearse a restore, change nothing
 *   POST action=restore_table  name=NAME table=TABLE   — per-table restore
 *   POST action=restore_full   name=NAME               — restore all tables
 *   POST action=delete         name=NAME               — remove that one folder
 *   POST action=put_held_back  table=TABLE              — put the rows a restore could
 *                                                        not put back into TABLE (#694)
 *   POST action=discard_held   table=TABLE              — throw those kept rows away
 *
 * TABLE on the two #694 actions is NOT tested by isTableName(): it has to be a
 * key of DbBackup::CASCADE_RESCUE, which DbBackup::keepsRowsFor() answers and
 * both writers ask. That allow-list is the stronger test, and it is what makes
 * the name safe to put into a statement at all.
 *
 * NAME is tested once, by DbBackup::isSnapshotName(), for every action that
 * takes one, before anything is closed, changed or deleted. Each action that
 * closes the portal puts it back as it found it: reopened if it was open,
 * still closed (with the same message) if an administrator had closed it.
 *
 * HOW LONG THESE TAKE, AND WHAT THE PAGE CAN AND CANNOT DO ABOUT IT (#579)
 * A snapshot, a check and a restore each ask PHP for at least 15 minutes, and
 * a check or a restore carries on if the browser gives up waiting. Afterwards
 * one line goes in the activity log saying what happened, so an administrator
 * whose page timed out can find out whether it finished. What the page cannot
 * do is survive the HOST ending the request. If that happens during a check or
 * a restore, nothing has changed (the database undoes unfinished work), but
 * the portal is still closed and has to be reopened; the recovery guide says
 * how, and how to run the restore from a command line instead.
 *
 * WHO MAY USE THIS PAGE, AND WHY IT IS A GLOBAL ADMINISTRATOR ONLY
 * -----------------------------------------------------------------------------
 * A snapshot is a copy of the WHOLE database: every organisation on the
 * installation, their members, their giving records, password hashes, and the
 * portal-wide settings including payment and other service credentials. So
 * every action here works on all organisations at once, not only the one the
 * administrator belongs to:
 *
 *   restore_table / restore_full  replace live data for every organisation with
 *                                 older copies. Restoring tblSettings alone puts
 *                                 back old portal-wide settings and credentials.
 *   check_snapshot                changes nothing, but closes the portal to every
 *                                 organisation while it runs and makes their
 *                                 writes wait.
 *   delete                        removes a recovery point every organisation
 *                                 depends on, including the automatic copy taken
 *                                 before each upgrade.
 *   snapshot_now                  reads every organisation's data and writes a
 *                                 full copy onto the shared server disk.
 *   list / inspect                show row counts for the whole installation,
 *                                 table by table, and offer the restore buttons.
 *
 * Until 13 September 2026 the only check was App::isAdmin(), which is true for
 * an administrator of a SINGLE organisation as well as for a global
 * administrator (see web/_core/App.php). So an administrator of one
 * organisation could restore tblSettings from an old snapshot and quietly put
 * back old payment credentials and other settings that the rest of the portal
 * now reserves for a global administrator (issue #495) — a way round those
 * pages' refusals. They could also replace every other organisation's data
 * with an old copy, or delete the backups.
 *
 * This matches the upgrade page (web/_install/upgrade.php), which already
 * refuses anybody but the global administrator for the same reason: it acts on
 * the database underneath every organisation. On an ordinary
 * single-organisation installation the owner IS the global administrator, so
 * nothing is taken away in practice.
 *
 * The whole page is refused, not just the buttons, because nothing on it is
 * about one organisation. An administrator of one organisation is told why, in
 * words, and where to see how recent the last backup is (the Health page, which
 * shows that without any of the above).
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.1
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/227
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/495
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/578
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/579
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/943
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\BackupArchive;
use Portal\Core\DbBackup;
use Portal\Core\InstallationSettings;
use Portal\Core\Logger;
use Portal\Core\Maintenance;
use Portal\Core\Site;
use Portal\Core\TimeLimit;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

// 🚧 Global administrators only — see "WHO MAY USE THIS PAGE" above. This sits
//    BEFORE the action handler and before any snapshot is listed, so nothing
//    below runs for anybody else: no snapshot, no restore, no delete, and no
//    list of the whole installation's row counts.
if (App::isRootAdmin() === false) {
    // 📝 A refused ACTION is recorded, so a global administrator can see that
    //    somebody tried to restore or delete. Only when the form token is
    //    valid, though: otherwise a forged request from another website could
    //    fill the activity log with refusals. Simply opening the page is not
    //    recorded. The token is checked here once and the request ends below,
    //    so it is never checked a second time.
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && Auth::verifyCsrf($_POST['csrf_token'] ?? '') === true
    ) {
        Logger::activity(
            'BackupActionRefused',
            sprintf(
                'Refused: backup action "%s" may only be used by a global administrator',
                substr(preg_replace('/[^a-z_]/', '', (string) ($_POST['action'] ?? '')) ?? '', 0, 30)
            ),
            $_SESSION['user_id'] ?? null
        );
    }

    http_response_code(403);
    $pageTitle   = 'Backup Management';
    $pageSection = 'admin';
    // 'Maintenance' has no page of its own, so it is shown without a link (#612). It used to link to
    // 'admin/maintenance', which is not an address anyone answers.
    $breadcrumbs = ['Dashboard' => Site::url(''), 'Admin' => Site::url('admin'), 'Maintenance' => '', 'Backups' => ''];
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
    ?>
    <h1 class="mb-3"><i class="fa-solid fa-database me-2"></i>Backup Management</h1>
    <div class="alert alert-danger">
        A backup is a copy of the whole installation: every organisation on it,
        not only yours. Taking, restoring or deleting one affects all of them,
        so only a global administrator can use this page. Nothing has been
        changed. To see how recent the latest backup is, open the
        <a href="<?php echo htmlspecialchars(Site::url('admin/maintenance/health'), ENT_QUOTES, 'UTF-8'); ?>">Health page</a>.
    </div>
    <?php
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
    return;
}

$db     = App::db();
$backup = new DbBackup($db);
$flash  = '';
$flashType = 'info';

// 🚧 How the portal stood BEFORE this request did anything: closed or open,
//    and with what closing message. Every action below that closes the portal
//    puts it back to exactly this afterwards. (The two Restore buttons used to
//    reopen a portal an administrator had closed, and overwrite their message;
//    only the Check button left it alone.)
$closedBefore  = (string) (App::settings()['portal']['maintenance']['active'] ?? '0') === '1';
$messageBefore = (string) (App::settings()['portal']['maintenance']['message'] ?? '');

/**
 * 🚧 Run $work with the portal closed, then put the portal back as it was found.
 *
 * The putting back is in a `finally`, so it happens whatever $work does: it
 * returns, it refuses, or it throws. Nothing here may leave the portal closed
 * because something went wrong. (A name holding a zero byte used to: the
 * portal was closed, the path lookup threw, and nothing reopened it.)
 *
 * After a restore the settings table holds whatever the SNAPSHOT held, which
 * includes "is the portal closed" as it stood then. So "as it was found" is
 * written again afterwards in both directions: reopened if it was open, and
 * closed again, with the administrator's own message, if they had closed it.
 *
 * THE ONE EXCEPTION: when the backup class reports that it had to close the
 * database connection (`connection_closed`), nothing may touch the database
 * again in this request, so the portal stays closed and the caller says so.
 *
 * WHAT THIS CANNOT DO: run if PHP is killed or runs out of memory, or if the
 * host ends the request. The portal is then left closed; the recovery guide
 * says how to reopen it.
 *
 * @return array{0: array<string, mixed>, 1: bool} The work's own result, and
 *         whether the portal is back as it was found.
 */
$whileClosed = static function (string $closingMessage, callable $work) use ($closedBefore, $messageBefore): array {
    if ($closedBefore === false) {
        Maintenance::setActive(true, $closingMessage);
    }
    $result  = null;
    $asFound = false;
    try {
        $result = $work();
    } finally {
        if (is_array($result) === false || ($result['connection_closed'] ?? false) === false) {
            try {
                $asFound = Maintenance::setActive($closedBefore, $messageBefore) === true;
            } catch (\Throwable $ignored) {
                // The database would not take it. $asFound stays false and
                // the message on the page says the portal is still closed.
                unset($ignored);
            }
        }
    }
    return [$result, $asFound];
};

/**
 * 🧱 The backup class closed the database connection to be sure unfinished
 * work was undone. Show its own words on a bare page and stop.
 *
 * NOTHING HERE TOUCHES THE DATABASE: no activity-log line, no reopening the
 * portal, and not the portal's usual header and footer, which read settings.
 * This branch used to carry on regardless, and the first thing it did (reopen
 * the portal) failed on the closed connection: the administrator got a blank
 * "Server Error" page with none of the class's explanation, and no word that
 * the portal was still closed.
 *
 * $settingsPutBack is true in ONE case: a restore that FINISHED and put the
 * settings table back, after which the connection had to be closed (its own
 * settings could not be put back; see DbBackup::putStrictnessBack()). Whether
 * the portal is open or closed is then whatever the snapshot held, which this
 * page cannot read any more, so it says exactly that instead of "still closed".
 */
$stopOnClosedConnection = static function (string $words, bool $settingsPutBack = false) use ($closedBefore): never {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    $portal = $closedBefore === true
        ? 'The portal is closed to everyone else, as it was before this began.'
        : 'The portal is STILL CLOSED to everyone else. This page closed it and could not reopen it, because '
          . 'reopening needs the database connection that had to be closed.';
    if ($settingsPutBack === true) {
        $portal = 'The restore put the settings table back as well, so whether the portal is open or closed to '
                . 'everyone else is now as the snapshot held it. This page could not look, because that needs the '
                . 'database connection that had to be closed. Look at the setting below before letting people back in.';
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Backup Management</title>
</head>
<body>
    <h1>Backup Management</h1>
    <p><?php echo htmlspecialchars($words, ENT_QUOTES, 'UTF-8'); ?></p>
    <p><?php echo htmlspecialchars($portal, ENT_QUOTES, 'UTF-8'); ?></p>
    <p>
        To reopen the portal, go to <a href="<?php echo htmlspecialchars(Site::url('admin/settings/maintenance'), ENT_QUOTES, 'UTF-8'); ?>">Admin, Settings, Maintenance mode</a>,
        switch &ldquo;Maintenance mode active&rdquo; off and save. If that page will not load, the recovery
        guide gives the one database statement that does the same.
    </p>
    <p><a href="<?php echo htmlspecialchars(Site::url('admin/maintenance/backup'), ENT_QUOTES, 'UTF-8'); ?>">Back to Backup Management</a></p>
</body>
</html>
    <?php
    exit();
};

// 🛠️ Action handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A browser can send any field as a list (`name[]=x`). Every field is
    // taken as text or not at all.
    $posted = static fn (string $field): ?string => is_string($_POST[$field] ?? null) === true ? $_POST[$field] : null;

    if (Auth::verifyCsrf($posted('csrf_token') ?? '') === false) {
        $flash = 'Invalid CSRF token.';
        $flashType = 'danger';
    } else {
        $action = $posted('action') ?? '';
        $name   = $posted('name');
        $table  = $posted('table');

        // 🛡️ THE NAME IS TESTED HERE, ONCE, FOR EVERY ACTION THAT TAKES ONE,
        //    before anything is closed, changed or deleted. See
        //    DbBackup::isSnapshotName() for the test and for what each
        //    action's own weaker test used to let through.
        if (in_array($action, ['check_snapshot', 'restore_table', 'restore_full', 'delete'], true) === true
            && DbBackup::isSnapshotName($name) === false
        ) {
            $flash     = 'Missing or invalid snapshot name. Nothing was changed.';
            $flashType = 'danger';
            $action    = '';
        } elseif ($action === 'restore_table' && DbBackup::isTableName($table) === false) {
            $flash     = 'Missing or invalid table name. Nothing was changed.';
            $flashType = 'danger';
            $action    = '';
        } elseif ($action === 'restore_table' && DbBackup::isWholeDatabaseOnly($table) === true) {
            // 🚫 One of the tables that only make sense put back beside the
            //    rest of the database. DbBackup::restoreTable() refuses it
            //    too, and that refusal is the real guard — this one is here so
            //    the portal is not CLOSED to everybody for an action that was
            //    never going to run. (The buttons below leave these tables
            //    out, so reaching here means a posted request, not a press.)
            //
            //    Both the words and the list come from DbBackup: the page
            //    keeps no copy of either, or the two could drift apart and the
            //    page would promise something the class does not do.
            $flash     = (string) DbBackup::wholeDatabaseOnlyReason((string) $table);
            $flashType = 'danger';
            $action    = '';
        }

        // ⏱️ Every action below can run for minutes on a large database.
        //    Without this the page was cut off at PHP's ordinary limit,
        //    usually 30 seconds. TimeLimit never lowers a limit, and says
        //    nothing if the host will not allow a change.
        if (in_array($action, ['snapshot_now', 'check_snapshot', 'restore_table', 'restore_full'], true) === true) {
            TimeLimit::atLeast(900);
        }
        // 🔌 A check or a restore must not stop because the browser stopped
        //    waiting: half way through is the worst place to stop. (Stopping
        //    there is still safe, the database undoes it, but the portal would
        //    be left closed.)
        if (in_array($action, ['check_snapshot', 'restore_table', 'restore_full'], true) === true) {
            ignore_user_abort(true);
        }

        // 📝 One line in the activity log once a check or a restore has
        //    returned, saying who ran it and how it ended. It is written AFTER
        //    a restore, so it lands in the restored activity log and is not
        //    wiped by it.
        //
        //    `$namesNobody` (#922): after a whole restore the person who pressed
        //    it may not be IN the restored database. Their number in the line's
        //    account column would then be refused by its link to tblUsers and
        //    the line would reach only PHP's error log - so the restore's
        //    result was lost. Such a line goes through
        //    Logger::activityNamingNobody() (#988's method, whose gate lists
        //    this file for exactly one call) and says in its own words which
        //    account ran it. That method also leaves out the network address,
        //    the browser and the session's details: intended here, for a line
        //    about an account that no longer exists - the number in the words
        //    is all that is kept of who pressed the button.
        $record = static function (string $type, string $what, bool $namesNobody = false): void {
            if ($namesNobody === true) {
                Logger::activityNamingNobody($type, $what);

                return;
            }
            Logger::activity($type, $what, isset($_SESSION['user_id']) === true ? (int) $_SESSION['user_id'] : null);
        };
        // Added to the message when the portal could not be put back as found.
        $stillClosed = ' The portal could not be reopened automatically and is still closed to everyone '
                     . 'else: the recovery guide says how to reopen it.';

        if ($action === 'save_schedule') {
            // ⏰ How often a backup is taken by itself, and how many are kept.
            //    Installation-wide rows (siteID NULL), because one database
            //    serves every organisation: there is nothing here for an
            //    organisation to choose.
            //
            //    A GLOBAL administrator only. The page is already gated on that
            //    for every action (see the top of this file), so this is not a
            //    second gate — it is the same one.
            $frequency = $posted('frequency') ?? '';
            $keepRaw   = trim($posted('keep') ?? '');

            if (in_array($frequency, ['off', 'daily', 'weekly', 'monthly'], true) === false) {
                $flash     = 'That is not one of the choices for how often. Nothing was changed.';
                $flashType = 'danger';
            } elseif (preg_match('/^\d{1,4}$/', $keepRaw) !== 1 || (int) $keepRaw < 1 || (int) $keepRaw > 365) {
                // Refused rather than quietly corrected: somebody who typed 0
                // meant something, and keeping none would delete the snapshot
                // that had just been taken.
                $flash     = 'How many to keep has to be a whole number between 1 and 365. Nothing was changed.';
                $flashType = 'danger';
            } else {
                $keep = (int) $keepRaw;
                // Two installation-wide rows. `ON DUPLICATE KEY UPDATE` is
                // right for a NULL siteID because migration 187 added
                // `siteScope` (COALESCE(siteID, -1)) and a unique key on it, so
                // a NULL collapses to one value and DOES match.
                $save = $mysqli->prepare(
                    'INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive, updatedAt) '
                    . 'VALUES (NULL, ?, ?, ?, 0, NOW()) '
                    . 'ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue), updatedAt = NOW()'
                );
                if ($save === false) {
                    $flash     = 'The schedule could not be saved.';
                    $flashType = 'danger';
                } else {
                    foreach ([
                        'backup.schedule.frequency' => $frequency,
                        'backup.schedule.keep'      => (string) $keep,
                    ] as $key => $value) {
                        $save->bind_param('sss', $key, $value, $value);
                        $save->execute();
                    }
                    $save->close();
                    // 🪞 The boxes below are drawn from what is stored, and start-up
                    //    read that before this save; so what was just written is laid
                    //    over it, or the page would show the old values beside "Saved".
                    $scheduleJustSaved = ['frequency' => $frequency, 'keep' => (string) $keep];
                    Logger::activity(
                        'BackupScheduleSaved',
                        'Scheduled backups set to ' . $frequency . ', keeping ' . $keep,
                        isset($_SESSION['user_id']) === true ? (int) $_SESSION['user_id'] : null
                    );
                    $flash = $frequency === 'off'
                        ? 'Scheduled backups are now switched off. Backups can still be taken with the '
                          . 'button above.'
                        : 'Saved. A backup will be taken ' . $frequency . ', keeping ' . $keep
                          . ' — as long as your hosting company\'s scheduler is calling /cron/backup-run.';
                    $flashType = 'success';
                }
            }
        } elseif ($action === 'snapshot_now') {
            $result = $backup->snapshot('manual');
            if ($result['success'] === true) {
                $flash = sprintf(
                    'Snapshot created: %s (%d tables, %d rows).',
                    basename($result['directory']),
                    $result['tables'],
                    $result['rows']
                );
                $flashType = 'success';
                // 🚩 A snapshot can be taken and still not be one that can be
                //    put back whole (a value not on its column's list of
                //    choices; see DbBackup::unlistedWarning()). Said here, at
                //    once and in yellow, not left for the day it is needed.
                if ($result['warnings'] !== []) {
                    $flash    .= ' ' . implode(' ', $result['warnings']);
                    $flashType = 'warning';
                }
            } else {
                $flash = 'Snapshot failed: ' . ($result['error'] ?? 'unknown');
                $flashType = 'danger';
            }
        } elseif ($action === 'restore_table') {
            [$result, $asFound] = $whileClosed(
                sprintf('Restoring table %s from snapshot %s.', $table, $name),
                static fn (): array => $backup->restoreTable($backup->pathOf($name), $table)
            );
            if (($result['connection_closed'] ?? false) === true) {
                // The connection can be closed after a restore that WORKED
                // (see $stopOnClosedConnection); the words then say what was
                // done as well as why the connection was closed.
                $stopOnClosedConnection(
                    $result['success'] === true
                        ? trim(sprintf('Restored %s: %d rows put back. %s', $table, $result['rows_restored'], (string) ($result['notice'] ?? '')))
                        : (string) ($result['error'] ?? ''),
                    $result['success'] === true && $table === 'tblSettings'
                );
            }
            // 🏷️ A ONE-table failure has its own type (#922 round 2). It
            //    shared `BackupRestoreFailed` with a failed WHOLE restore, and
            //    the backup page's "last whole restore" box reads that type, so
            //    a one-table restore refused a minute later replaced the whole
            //    restore's result under the wrong heading (measured).
            $record(
                $result['success'] === true ? 'BackupTableRestored' : 'BackupTableRestoreFailed',
                $result['success'] === true
                    ? sprintf('Table %s put back from snapshot %s: %d rows.', $table, $name, $result['rows_restored'])
                    : sprintf('Putting table %s back from snapshot %s failed; nothing was changed. %s', $table, $name, (string) ($result['error'] ?? ''))
            );
            if ($result['success'] === true) {
                $flash = sprintf(
                    'Restored %s — %d rows reinserted.',
                    $table,
                    $result['rows_restored']
                );
                $flashType = 'success';
                // ⚠️ Say so if any rows were left out. Reporting "success"
                //    while quietly dropping rows would be worse than saying
                //    nothing at all.
                if (($result['skipped_duplicates'] ?? 0) > 0) {
                    $flash .= ' ' . ($result['notice'] ?? '');
                    $flashType = 'warning';
                }
            } else {
                $flash = 'Restore failed: ' . ($result['error'] ?? 'unknown');
                $flashType = 'danger';
            }
            if ($asFound === false) {
                $flash    .= $stillClosed;
                $flashType = 'danger';
            }
        } elseif ($action === 'restore_full') {
            // 🔄 ONE operation for the whole snapshot, not a loop over tables.
            //
            //    This used to walk the manifest and restore each table on
            //    its own. That could not work, for two reasons:
            //
            //      * Each table finished before the next began. If the
            //        twentieth failed, the first nineteen were already done
            //        and could not be taken back - leaving a database that
            //        was part one thing and part another.
            //
            //      * Sixty-nine of the tables are pointed at by other
            //        tables, and a table cannot be emptied on its own while
            //        something still points at it. Those had never been
            //        restorable at all.
            //
            //    DbBackup::restoreAll() checks every file first, works out
            //    an order from how the database is actually put together,
            //    and does the whole thing inside one transaction. If
            //    anything fails, nothing changes.
            [$result, $asFound] = $whileClosed(
                sprintf('Putting snapshot %s back. The portal is closed while this runs.', $name),
                static fn (): array => $backup->restoreAll($backup->pathOf($name))
            );
            if ($result['connection_closed'] === true) {
                $stopOnClosedConnection(
                    $result['success'] === true
                        ? trim(sprintf('Snapshot put back in full: %d tables, %d rows. %s', $result['tables_restored'], $result['rows_restored'], implode(' ', $result['warnings'])))
                        : $result['error'],
                    $result['success'] === true
                );
            }
            // ⚠️ A restore can succeed and still have something to say: a
            //    pointer at a row that is not in the snapshot is emptied
            //    where the link's own rule allows it, and a table that is
            //    not in the snapshot can lose rows linked to ones that are.
            //    Both are counted and named, here and in the activity log,
            //    never done quietly.
            $notes = implode(' ', $result['warnings']);
            // 🔢 #922: the restore has just put every account's number, switch
            //    and very existence back as the snapshot held them, so the
            //    person who pressed it may be signed out by their next click.
            //    Asked once, here, through the same test the sign-in check
            //    makes on that next request (Auth::signInStateNow()).
            $restorerNow = $result['success'] === true ? Auth::signInStateNow() : null;
            $restorerGone = $restorerNow === Auth::SIGN_IN_NO_ACCOUNT;
            $record(
                $result['success'] === true ? 'BackupRestored' : 'BackupRestoreFailed',
                ($result['success'] === true
                    ? trim(sprintf('Snapshot %s put back in full: %d tables, %d rows. %s', $name, $result['tables_restored'], $result['rows_restored'], $notes))
                    : sprintf('Putting snapshot %s back failed; nothing was changed. %s', $name, $result['error']))
                . ($restorerGone === true
                    ? sprintf(' It was run by account #%d, which is not in the restored database, so this line names no account.', (int) ($_SESSION['user_id'] ?? 0))
                    : ''),
                $restorerGone
            );

            if ($result['success'] === true) {
                $flash = trim(sprintf(
                    'Snapshot put back in full: %d tables, %d rows. %s',
                    $result['tables_restored'],
                    $result['rows_restored'],
                    $notes
                ));
                $flashType = $notes === '' ? 'success' : 'warning';
                // The three ways the restorer's own sign-in can end, each its
                // own whole sentence, because what they can do next differs
                // (#922 rounds 2-3): a moved number must sign in again and only
                // a password the account had then will work; an account
                // switched off cannot sign in until switched back on; an
                // account not there cannot sign in with it. The result is kept
                // at the top of this page for seven days (the "last whole
                // restore" box below) in every case, read from the line just
                // written - which for a missing account names nobody, so it is
                // still written - but only a GLOBAL administrator can open this
                // page, so somebody who is not one in the restored database is
                // not told the result "stays" for them.
                $restorerGlobal = false;
                if ($restorerNow === Auth::SIGN_IN_NOT_CURRENT) {
                    $globalStmt = $db->prepare('SELECT `isRootAdmin` FROM `tblUsers` WHERE `userID` = ? LIMIT 1');
                    if ($globalStmt !== false) {
                        $restorerId = (int) ($_SESSION['user_id'] ?? 0);
                        $globalStmt->bind_param('i', $restorerId);
                        $globalStmt->execute();
                        $globalRow      = $globalStmt->get_result()->fetch_assoc();
                        $restorerGlobal = $globalRow !== null && (int) $globalRow['isRootAdmin'] === 1;
                        $globalStmt->close();
                    }
                }
                $restorerWords = [
                    Auth::SIGN_IN_NOT_CURRENT  => ' Your own sign-in is not current in the restored database: its sign-in '
                        . 'number differs from the one you signed in under (usually because your password was reset, '
                        . 'changed or set after this snapshot was taken). The next page you open will ask you to sign in '
                        . 'again, and only a password your account had when the snapshot was taken will work.'
                        . ($restorerGlobal === true
                            ? ' This result stays at the top of this page for seven days.'
                            : ' Your account is not a global administrator in the restored database, so you will not be '
                              . 'able to open this page again; this result is kept here for seven days for any global '
                              . 'administrator.'),
                    Auth::SIGN_IN_SWITCHED_OFF => ' Your own account is switched off in the restored database, so the next page '
                        . 'you open will sign you out, and you cannot sign in until an administrator switches it back on. '
                        . 'This result is kept at the top of this page for seven days for any global administrator who '
                        . 'signs in.',
                    Auth::SIGN_IN_NO_ACCOUNT   => ' Your own account is not in the restored database, so the next page you open '
                        . 'will sign you out and you cannot sign in with it again. This result has been recorded in the '
                        . 'activity log naming no account, because yours is not there, and is kept at the top of this page '
                        . 'for seven days for any global administrator who signs in.',
                ];
                if (isset($restorerWords[$restorerNow]) === true) {
                    $flash    .= $restorerWords[$restorerNow];
                    $flashType = 'warning';
                }
            } else {
                // The message already explains, in plain words, that nothing
                // was changed. Show it as it is rather than wrapping it in
                // something vaguer.
                $flash = $result['error'];
                $flashType = 'danger';
            }
            if ($asFound === false) {
                $flash    .= $stillClosed;
                $flashType = 'danger';
            }
        } elseif ($action === 'check_snapshot') {
            // 🎭 "Will this backup work?" Answered by a full REHEARSAL: every
            //    row of the snapshot is really written, inside a transaction
            //    that is then undone, so the answer is the restore's own
            //    answer. (The owner chose this over a quick check of the files
            //    on 12 October 2026: the quick check said "fine" about
            //    snapshots that could never be put back, #578.)
            //
            //    WHAT IT CANNOT DO: foresee a failure at the very last step of
            //    a real restore, the moment it is made permanent.
            //
            // 🚧 The portal is closed while the rehearsal runs, exactly as
            //    for a restore: its transaction holds every table, so
            //    anybody saving something would simply be left waiting.
            [$result, $asFound] = $whileClosed(
                sprintf('Checking that snapshot %s can be restored. The portal is closed while this runs.', $name),
                static fn (): array => $backup->restoreAll($backup->pathOf($name), true)
            );
            if ($result['connection_closed'] === true) {
                $stopOnClosedConnection(
                    $result['success'] === true
                        ? trim('This snapshot can be restored. ' . implode(' ', $result['warnings']))
                        : $result['error']
                );
            }
            // The first warning is the class's own "this was a rehearsal"
            // sentence, which the message below already says. Anything
            // after it is something a real restore would do that the
            // administrator should know about first.
            $notes = implode(' ', array_slice($result['warnings'], 1));

            if ($result['success'] === true) {
                $flash = trim(sprintf(
                    'This snapshot can be restored. The check wrote all %d rows of its %d tables into the '
                    . 'database and then took every one of them out again. Nothing was changed. %s',
                    $result['rows_restored'],
                    $result['tables_restored'],
                    $notes
                ));
                $flashType = $notes === '' ? 'success' : 'warning';
            } else {
                // The reason already says, in plain words, that nothing
                // was changed.
                $flash = 'This snapshot cannot be restored. ' . $result['error'];
                $flashType = 'danger';
            }
            if ($asFound === false) {
                $flash    .= $stillClosed;
                $flashType = 'danger';
            }
            $record(
                'BackupSnapshotChecked',
                $result['success'] === true
                    ? trim(sprintf('Snapshot %s was checked by a full rehearsal: it can be restored (%d tables, %d rows). Nothing was changed. %s', $name, $result['tables_restored'], $result['rows_restored'], $notes))
                    : sprintf('Snapshot %s was checked by a full rehearsal: it cannot be restored. %s', $name, $result['error'])
            );
        } elseif ($action === 'delete') {
            // 🗑️ The class does the deleting: a link is removed as a link and
            //    never followed, and a loose file is never touched. This page
            //    used to have a loop of its own here that did neither.
            if ($backup->deleteSnapshot($name) === true) {
                $flash = sprintf('Snapshot %s deleted.', $name);
                $flashType = 'success';
            } else {
                $flash = sprintf('Could not delete %s.', $name);
                $flashType = 'danger';
            }
        } elseif ($action === 'put_held_back' || $action === 'discard_held') {
            // 🧷 The rows a restore was about to lose to a cascade (#694).
            //
            //    The table name needs no test here. DbBackup::putHeldRowsBack()
            //    and discardHeldRows() both refuse any name that is not a key
            //    of DbBackup::CASCADE_RESCUE, a constant in that file, and that
            //    allow-list IS the guard: it is what makes the name safe to put
            //    into a statement at all. A test on this page would be a second
            //    copy of a list that must not drift.
            //
            //    Not behind $whileClosed: this writes rows INTO two tables
            //    inside one transaction and empties nothing, so there is no
            //    half-way state for a visitor to see. It can still be slow if
            //    a great many rows are waiting, hence the time limit.
            $heldTable = (string) ($table ?? '');
            TimeLimit::atLeast(900);
            if ($action === 'put_held_back') {
                $putBack = $backup->putHeldRowsBack($heldTable);
                if ($putBack['success'] === true) {
                    // ⚠️ A row that could not go back STAYS WAITING, and the
                    //    message says so rather than quietly dropping it. The
                    //    likeliest reason is that the organisation or the
                    //    person it belonged to is not in the restored
                    //    database, so there is nothing for it to point at —
                    //    but that is said as the likeliest reason, not as the
                    //    answer, because a row already present fails the same
                    //    way and the two cannot be told apart from here.
                    $flash = sprintf(
                        '%d kept row(s) put back into %s.',
                        $putBack['put_back'],
                        $heldTable
                    );
                    if ($putBack['still_missing'] > 0) {
                        $flash .= sprintf(
                            ' %d could not be put back and are still being kept, so nothing has been lost.'
                            . ' The likeliest reason is that the organisation or person they belonged to is'
                            . ' not in the restored database.',
                            $putBack['still_missing']
                        );
                    }
                    $flashType = $putBack['still_missing'] > 0 ? 'warning' : 'success';
                } else {
                    $flash     = (string) ($putBack['error'] ?? 'Nothing was put back.');
                    $flashType = 'danger';
                }
                // 📝 A posted name that is not a table this portal keeps rows for
                //    is a REFUSAL, recorded without the name. Writing it in gave
                //    the log a line reading "Putting kept rows back into
                //    <anything you posted> failed", under an event type saying a
                //    put-back had been attempted — the harm the discard branch
                //    was just fixed for, one action along.
                if (DbBackup::keepsRowsFor($heldTable) === false) {
                    $record('BackupActionRefused', 'A request to put kept rows back named a table this portal does not keep rows for.');
                } else {
                    $record(
                        $putBack['success'] === true ? 'BackupHeldRowsPutBack' : 'BackupHeldRowsPutBackFailed',
                        $putBack['success'] === true
                            ? sprintf('Kept rows put back into %s: %d written, %d still kept.', $heldTable, $putBack['put_back'], $putBack['still_missing'])
                            : sprintf('Putting kept rows back into %s failed; nothing was changed.', $heldTable)
                    );
                }
            } else {
                // 🗑️ Deliberate, and the only way a WAITING row goes: nothing
                //    removes one on a timer (the owner, 31 October 2026).
                $discarded = $backup->discardHeldRows($heldTable);
                if ($discarded === -2) {
                    // ⏳ The database refused, which in practice means another
                    //    administrator is putting rows back and holds the lock.
                    //    Said honestly: nothing was discarded, and nothing is
                    //    claimed about why beyond the likeliest cause.
                    $flash     = 'Nothing was discarded, and the kept rows are exactly as they were. '
                               . 'If somebody else is putting rows back at this moment, wait and try again.';
                    $flashType = 'danger';
                    // 📝 ITS OWN EVENT TYPE. Recorded as `BackupHeldRowsDiscarded` it
                    //    counted, to anybody searching the log by type, as a run in
                    //    which rows WERE discarded. The put-back branch a few lines
                    //    above separates its success from its failure for exactly
                    //    this reason, and its comment names the harm — so this was
                    //    the same half-applied fix a fourth time.
                    $record('BackupHeldRowsDiscardFailed', sprintf('Discarding kept rows for %s was refused by the database; nothing was discarded.', $heldTable));
                } elseif ($discarded < 0) {
                    // ⚠️ -1 means "this portal keeps no rows for that table",
                    //    which is NOT the same as "none was waiting". It
                    //    answered 0 for both at first, so a posted name — any
                    //    name — was told `0 kept row(s) for X discarded. They
                    //    cannot be recovered.` and written into the activity
                    //    log as though X were a real table.
                    $flash     = sprintf('This portal does not keep rows for %s.', $heldTable);
                    $flashType = 'danger';
                    $record('BackupActionRefused', 'A request to discard kept rows named a table this portal does not keep rows for.');
                } else {
                    $flash     = sprintf('%d kept row(s) for %s discarded. They cannot be recovered.', $discarded, $heldTable);
                    $flashType = $discarded > 0 ? 'success' : 'warning';
                    $record('BackupHeldRowsDiscarded', sprintf('Kept rows for %s discarded: %d.', $heldTable, $discarded));
                }
            }
        }
    }
}

$snapshots = $backup->listSnapshots();

// 🧾 ------------------------------------------------------------------------
//    THE LAST WHOLE RESTORE, for seven days (#922). A restore can sign out the
//    very administrator who ran it (see the note at the restore above), and
//    then the result they were shown is gone with their session; until #922
//    it survived only in the activity log. Its line there is written AFTER
//    the restore, so it outlives the restore itself, and this box reads it
//    back on every ordinary visit: whoever signs in again and comes back here
//    finds what happened. Two look-ups, one per kind of line, each served by
//    the activity log's index on (activityType, timestamp), so the cost does
//    not grow with the log. Not drawn on the answer to a press, which shows
//    its own result.
//
//    WHAT IT CANNOT SAY: anything the line does not hold. It is the same
//    sentence the activity log keeps, with who pressed it and when, in the
//    database's own clock as the activity log shows it.
$lastRestore = null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        foreach (['BackupRestored', 'BackupRestoreFailed'] as $restoreType) {
            $stmt = $db->prepare(
                'SELECT `l`.`logID`, `l`.`activityType`, `l`.`activityDescription`, `l`.`timestamp`, `u`.`fullName` '
                . 'FROM `tblActivityLogs` `l` LEFT JOIN `tblUsers` `u` ON `u`.`userID` = `l`.`userID` '
                . 'WHERE `l`.`activityType` = ? AND `l`.`timestamp` >= (NOW() - INTERVAL 7 DAY) '
                . 'ORDER BY `l`.`timestamp` DESC, `l`.`logID` DESC LIMIT 1'
            );
            if ($stmt === false) {
                continue;
            }
            $stmt->bind_param('s', $restoreType);
            $stmt->execute();
            $found = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($found !== null && ($lastRestore === null || (int) $found['logID'] > (int) $lastRestore['logID'])) {
                $lastRestore = $found;
            }
        }
    } catch (\Throwable $ignored) {
        // The box is a convenience; the page must still open without it.
        $lastRestore = null;
        unset($ignored);
    }
}

// 🛟 ------------------------------------------------------------------------
//    The rows a restore was about to lose to a cascade, kept instead of lost
//    (#694). Grouped by table, because that is what the two buttons act on:
//    DbBackup::putHeldRowsBack() and discardHeldRows() each take one table.
//
//    An empty answer draws nothing at all, so the page looks exactly as it did
//    for the ordinary case — and that is also what a database whose upgrade has
//    not been run gives back (DbBackup asks whether the holding table is there
//    before it reads it, so this cannot be what breaks the page).
$heldByTable = [];
foreach ($backup->rowsWaitingToBePutBack() as $heldRow) {
    $forTable = $heldRow['tableName'];
    if (isset($heldByTable[$forTable]) === false) {
        $heldByTable[$forTable] = [
            'describe'  => $heldRow['describe'],
            'count'     => 0,
            'snapshots' => [],
            'people'    => [],
            'orgs'      => [],
            'names'     => [],
            'oldest'    => $heldRow['heldAtUtc'],
        ];
    }
    $heldByTable[$forTable]['count']++;
    $heldByTable[$forTable]['snapshots'][$heldRow['snapshotName']] = true;
    if ($heldRow['userID'] !== null) {
        $heldByTable[$forTable]['people'][$heldRow['userID']] = true;
    }
    if ($heldRow['siteID'] !== null) {
        $heldByTable[$forTable]['orgs'][$heldRow['siteID']] = true;
    }
    // 🏷️ What each row IS, where the class can say so without saying what it
    //    HOLDS. For a settings row that is its key; for a sign-in code there is
    //    nothing showable and `names` is empty, so only a count appears.
    //
    //    This matters because `describe` CANNOT be exact: the rescue takes every
    //    per-organisation settings row whatever its key, so the sentence can only
    //    say "its own choices, such as…". An administrator judging what they have
    //    lost needs the actual keys, and the keys name nothing secret.
    if (($heldRow['names'] ?? '') !== '') {
        $heldByTable[$forTable]['names'][$heldRow['names']] = true;
    }
    // The list comes back newest first, so each row seen is older than the last.
    $heldByTable[$forTable]['oldest'] = $heldRow['heldAtUtc'];
}

// 🔍 Inspect mode. The same name test as every action above, and the same
//    door into the folder: DbBackup::manifestOf() refuses a link that leads
//    out of the backups folder, as a check or a restore does. This page used
//    to read the manifest file itself, followed such a link, and showed the
//    outside folder's manifest with its Restore buttons.
$inspect = $_GET['inspect'] ?? '';
$inspectManifest  = null;
$inspectUnlisted  = '';
$inspectOneMoment = '';
$inspectSignIns   = '';
if (DbBackup::isSnapshotName($inspect) === true) {
    $opened = $backup->manifestOf($inspect);
    if (is_array($opened) === true) {
        $inspectManifest = $opened;
        // 🚩 Said on the snapshot's own page, above its buttons.
        $inspectUnlisted = DbBackup::unlistedWarning(DbBackup::unlistedIn($opened));
        // 🧊 And whether it was read at one moment in time (#581). The words
        //    are the RESTORING ones, because this is the page somebody reads
        //    with their finger over the Restore button — what they can do
        //    about it differs from what the person who took it could.
        $inspectOneMoment = DbBackup::oneMomentWarning(DbBackup::oneMomentIn($opened), true);
        // 🔢 #922: whose sign-in this restore would end, said BEFORE the
        //    button is pressed. DbBackup counts; the words are here.
        $signIns = $backup->signInsEndedByRestoring($inspect, (int) ($_SESSION['user_id'] ?? 0));
        // Whole sentences per case, never a clause assembled from ternaries
        // (#694's lesson): what an account can do next depends on WHY it is
        // signed out, so the three groups are said apart (#922 rounds 2-3).
        // Every sentence was checked against what the next request really does:
        // - a moved number is NOT always a changed password (an earlier restore
        //   can put an older number back), so the sentence says the number
        //   differs and gives the usual cause as usual;
        // - "signs in with the password it had" was untrue for an account that
        //   had none then, so it says only that no OTHER password will work;
        // - a switched-off account can sign in once switched back on, so it is
        //   never also told it cannot sign in "at all";
        // - the result is kept on THIS page, which only a global administrator
        //   opens, so somebody who is not one in the snapshot is told so.
        $accounts = static fn (int $n, string $one, string $many): string => $n === 1 ? '1 account ' . $one : $n . ' accounts ' . $many;
        if ($signIns === null) {
            $inspectSignIns = 'How many accounts putting this snapshot back would sign out could not be worked out. Put back '
                . 'whole, it signs out every account that is not in it or is switched off in it, and every account whose '
                . 'sign-in number differs from the snapshot\'s (usually because its password was reset, changed or set '
                . 'since); such an account must sign in again, and only a password it had when the snapshot was taken '
                . 'will work. Yours may be one of them.';
        } elseif ($signIns['changed'] + $signIns['switchedOff'] + $signIns['missing'] > 0) {
            $total          = $signIns['changed'] + $signIns['switchedOff'] + $signIns['missing'];
            $inspectSignIns = 'Putting this snapshot back whole signs out ' . ($total === 1 ? '1 account' : $total . ' accounts')
                . ' on their next page.';
            if ($signIns['changed'] > 0) {
                $inspectSignIns .= ' ' . ucfirst($accounts(
                    $signIns['changed'],
                    'has a sign-in number that differs from the snapshot\'s (usually because its password was reset, '
                        . 'changed or set after the snapshot was taken); it must sign in again, and only a password it had '
                        . 'when the snapshot was taken will work.',
                    'have a sign-in number that differs from the snapshot\'s (usually because a password was reset, '
                        . 'changed or set after the snapshot was taken); they must sign in again, and only a password each '
                        . 'had when the snapshot was taken will work.'
                ));
            }
            if ($signIns['switchedOff'] > 0) {
                $inspectSignIns .= ' ' . ucfirst($accounts(
                    $signIns['switchedOff'],
                    'is switched off in this snapshot; it cannot sign in until an administrator switches it back on.',
                    'are switched off in this snapshot; they cannot sign in until an administrator switches them back on.'
                ));
            }
            if ($signIns['missing'] > 0) {
                $inspectSignIns .= ' ' . ucfirst($accounts(
                    $signIns['missing'],
                    'is not in this snapshot, and cannot be used to sign in afterwards.',
                    'are not in this snapshot, and cannot be used to sign in afterwards.'
                ));
            }
            $keptForYou = $signIns['youGlobalAfter'] === true
                ? ' Its result stays at the top of this page for seven days.'
                : ' Your account is not a global administrator in this snapshot, so you will not be able to open this '
                  . 'page afterwards; its result is kept here for seven days for any global administrator.';
            $inspectSignIns .= match ($signIns['you']) {
                'changed'      => ' Yours is one of them: you will be asked to sign in as soon as the restore has '
                    . 'finished, and only a password your account had when the snapshot was taken will work.' . $keptForYou,
                'switched_off' => ' Yours is one of them: your account is switched off in this snapshot, so once it is '
                    . 'put back you cannot sign in until an administrator switches it back on. Its result is kept at the '
                    . 'top of this page for seven days for any global administrator who signs in.',
                'missing'      => ' Yours is one of them: your account is not in this snapshot, so once it is put back '
                    . 'you cannot sign in with it. Its result is kept at the top of this page for seven days for any '
                    . 'global administrator who signs in.',
                default        => '',
            };
        }
    } elseif (is_dir($backup->pathOf($inspect)) === true || is_link($backup->pathOf($inspect)) === true) {
        // Something by that name IS there and was not opened: say why. (A
        // name with nothing behind it draws the plain list, as before.)
        $flash     = trim($flash . ' ' . $inspect . ' was not opened. ' . $opened);
        $flashType = 'danger';
    }
} else {
    $inspect = '';
}

$pageTitle   = 'Backup Management';
$pageSection = 'admin';
// 'Maintenance' has no page of its own, so it is shown without a link (#612). It used to link to
// 'admin/maintenance', which is not an address anyone answers.
$breadcrumbs = ['Dashboard' => Site::url(''), 'Admin' => Site::url('admin'), 'Maintenance' => '', 'Backups' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-database me-2"></i>Backup Management</h1>
        <p class="text-secondary mb-0">Snapshots of every <code>tbl*</code> table, kept in <code>web/_backups/</code>.</p>
    </div>
    <div>
        <a href="<?php echo htmlspecialchars(Site::url('admin/maintenance/backup-check'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm me-1">Freshness check</a>
        <a href="<?php echo htmlspecialchars(Site::url('admin'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm">&larr; Admin</a>
    </div>
</div>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<?php if ($lastRestore !== null): ?>
    <?php // 🧾 #922: the last whole restore, from its activity-log line. See where $lastRestore is read. ?>
    <div class="alert alert-<?php echo $lastRestore['activityType'] === 'BackupRestored' ? 'info' : 'danger'; ?>" id="last-whole-restore" role="status">
        <strong>The last whole restore</strong>
        (<?php echo htmlspecialchars((string) $lastRestore['timestamp'], ENT_QUOTES, 'UTF-8'); ?><?php if (($lastRestore['fullName'] ?? '') !== ''): ?>, by <?php echo htmlspecialchars((string) $lastRestore['fullName'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>):
        <?php echo htmlspecialchars((string) $lastRestore['activityDescription'], ENT_QUOTES, 'UTF-8'); ?>
        <?php // A FAILED restore changed nothing and signed nobody out, so it is never given the signing-out sentence (#922 round 3). ?>
        <?php if ($lastRestore['activityType'] === 'BackupRestored'): ?>
            <span class="d-block small mt-1">A whole restore signs out every account whose sign-in number differs from its snapshot's, and every account that is not in the snapshot or is switched off in it, which is why this is kept here for seven days. It is also in the activity log.</span>
        <?php else: ?>
            <span class="d-block small mt-1">Nothing was changed by it, so nobody was signed out. It is kept here for seven days, and is also in the activity log.</span>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
// 🛟 ------------------------------------------------------------------------
//    ROWS BEING KEPT FOR YOU (#694). FIRST on the page, above everything else,
//    because somebody reading it is part way through a recovery and this is the
//    only thing on the page that is waiting on a decision. Nothing is drawn
//    when nothing is waiting.
//
//    There is no timer: these rows wait until an administrator puts them back
//    or discards them (the owner, 31 October 2026). What stops them being
//    forgotten is this card, not an expiry — so it has to be impossible to
//    miss.
?>
<?php if ($heldByTable !== []): ?>
    <div class="card border-warning mb-4">
        <div class="card-body">
            <h2 class="h5 card-title text-warning-emphasis">
                <i class="fa-solid fa-life-ring me-2"></i>Rows being kept for you
            </h2>
            <p class="mb-3">
                <?php // ⚠️ NOT "tables the backup does not cover", which this said first and
                      //    which is FALSE in the commonest case: an older backup DOES contain
                      //    these tables, and the restore deliberately leaves them alone because
                      //    each records what has already happened. Telling somebody mid-recovery
                      //    that their settings are not in their backup, when they are, is the
                      //    worst moment to be wrong. ?>
                Putting a backup back emptied and refilled the tables it put back. These rows were in tables
                the restore <strong>could not</strong> put back &mdash; either because each one records what
                has already happened and only makes sense restored beside the rest of the database, or because
                the backup simply did not contain it. The database&rsquo;s own rules removed them along the
                way, so nothing put them back. They are being kept here instead of being lost.
                <strong>Nothing removes them on a timer</strong> &mdash; they wait until you put them back or
                discard them.
            </p>
            <?php // 🛟 #697. Said here because this is where somebody sees the waiting rows,
                  //    and because until 31 October 2026 the opposite was true: a later restore
                  //    replaced this list with the backup's own older copy and the rows simply
                  //    went. The restore now reads them before it empties anything and writes
                  //    them again at the end, and says how many it wrote again.
                  //
                  //    ⚠️ ONE case really does end with a row no longer waiting, and this
                  //       comment said TWO: a backup that already records the row as put back,
                  //       where the restore honours that backup's own history. The other one it
                  //       named - more than 20,000 kept rows - leaves the whole list ALONE, so
                  //       every row is still waiting; the same sentence said so and contradicted
                  //       itself. Measured by the independent check at 20,001 rows in and
                  //       20,001 out.
                  //
                  //       And "the result always tells you" was false until the same round: that
                  //       one case happened in SILENCE, measured at zero warnings. It is
                  //       reported now (DbBackup::stoppedWaitingWarning()), which is what makes
                  //       this sentence true rather than merely softened. ?>
            <p class="mb-3 small">
                Putting another backup back does <strong>not</strong> throw these away. They are read
                before anything is emptied and written again at the end, and the result says how many
                were written again. Putting back the one table they are kept in would lose them, so the
                portal refuses to do that. The result always tells you what happened &mdash; if a backup
                already records one of these rows as put back, the portal honours that backup rather
                than offering the row again.
            </p>
            <div class="portal-data-list">
                <?php foreach ($heldByTable as $heldTable => $about): ?>
                    <div class="row py-2 align-items-center border-bottom">
                        <div class="col-md-6">
                            <strong><?php echo htmlspecialchars((string) $about['count'] . ' ' . $about['describe'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            <div class="small text-muted">
                                <code><?php echo htmlspecialchars((string) $heldTable, ENT_QUOTES, 'UTF-8'); ?></code>
                                &middot; kept while putting back
                                <?php echo htmlspecialchars(implode(', ', array_keys($about['snapshots'])), ENT_QUOTES, 'UTF-8'); ?>
                                &middot; oldest <?php echo htmlspecialchars($about['oldest'], ENT_QUOTES, 'UTF-8'); ?> UTC
                            </div>
                            <?php if ($about['names'] !== []): ?>
                                <div class="small">
                                    <?php
                                    // The keys themselves, sorted so the list reads the
                                    // same way twice. Never a stored value: a key names
                                    // what a setting is, a value can be anything at all.
                                    // The label is tied to the ONE table that answers a name
                                    // (DbBackup::nameInsideHeldRow()), so a table added to
                                    // CASCADE_RESCUE later cannot be labelled "Settings:" by
                                    // accident: it answers an empty string and draws nothing.
                                    $heldNames = array_keys($about['names']);
                                    sort($heldNames);
                                    echo htmlspecialchars(
                                        ($heldTable === 'tblSettings' ? 'Settings: ' : 'Which ones: ')
                                        . implode(', ', $heldNames),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    );
                                    ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($about['orgs'] !== [] || $about['people'] !== []): ?>
                                <div class="small text-muted">
                                    <?php
                                    // 👤 A COUNT, never a name or an e-mail address: this page is
                                    //    read by a global administrator of the installation, who may be
                                    //    a different person from the administrators of the organisations
                                    //    on it (the owner, 29 October 2026). The count is what they need
                                    //    to judge the size of the loss.
                                    $whose = [];
                                    if ($about['orgs'] !== []) {
                                        $whose[] = count($about['orgs']) . ' organisation(s)';
                                    }
                                    if ($about['people'] !== []) {
                                        $whose[] = count($about['people']) . ' ' . (count($about['people']) === 1 ? 'person' : 'people');
                                    }
                                    echo htmlspecialchars('Belonging to ' . implode(' and ', $whose) . '.', ENT_QUOTES, 'UTF-8');
                                    ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 text-md-end mt-2 mt-md-0">
                            <form method="post" class="d-inline"
                                  data-confirm="Put these rows back into <?php echo htmlspecialchars((string) $heldTable, ENT_QUOTES, 'UTF-8'); ?>? Each row is written back exactly as it was. A row whose organisation or person is not in the restored database cannot go back, and that row stays here rather than being lost."
                                  data-confirm-confirm-label="Put them back">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="put_held_back">
                                <input type="hidden" name="table" value="<?php echo htmlspecialchars((string) $heldTable, ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="btn btn-primary btn-sm">Put them back</button>
                            </form>
                            <form method="post" class="d-inline"
                                  data-confirm="Discard the <?php echo htmlspecialchars((string) $about['count'], ENT_QUOTES, 'UTF-8'); ?> kept row(s) for <?php echo htmlspecialchars((string) $heldTable, ENT_QUOTES, 'UTF-8'); ?>? This cannot be undone. These are the only copy the portal has: whether the backup you restored from holds an older one depends on that backup &mdash; only if it covers this table at all, and only for rows that existed when it was taken."
                                  data-confirm-destructive="true"
                                  data-confirm-confirm-label="Discard them">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="discard_held">
                                <input type="hidden" name="table" value="<?php echo htmlspecialchars((string) $heldTable, ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm">Discard</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2 align-items-center mb-4">
    <form method="post" class="d-inline-block mb-0">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="snapshot_now">
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-camera me-1"></i> Run snapshot now
        </button>
    </form>
    <a href="<?php echo htmlspecialchars(Site::url('admin/maintenance/backup/import'), ENT_QUOTES, 'UTF-8'); ?>"
       class="btn btn-outline-secondary">
        <i class="fa-solid fa-file-import me-1"></i> Import a backup file
    </a>
</div>

<?php
// ⏰ ------------------------------------------------------------------------
//    Scheduled backups. Read from the settings this request already loaded.
//    `frequency` is a word rather than a number of hours so that the choices
//    are the choices; `cron/backup-run.php` is the ONE place it is turned into
//    a period.
// ⚠️ 'off' when the row is absent, which is what `cron/backup-run.php` also
//    falls back to. The page used to say 'daily' here, so in the window between
//    deploying the files and running the upgrade it would have shown "Every
//    day" while the job was doing nothing — the page telling somebody something
//    untrue about their backups. The seeded default IS daily; this is only
//    about the row not existing yet.
//
// 🏢 FROM THE INSTALLATION'S OWN ROWS, never the merged settings (#943, #674). The
//    "save_schedule" action above writes both rows installation-wide (`siteID
//    IS NULL`), so the two boxes show THAT row. WHAT WAS WRONG: they were read from
//    `$SETTINGS`, where the open organisation's own row has already won, so a global
//    administrator who opened this page inside an organisation with its own schedule
//    saw ITS schedule, pressed Save untouched, and made it the installation's. The
//    check found it only once it read a page that is its own saver (#936).
$scheduleJustSaved = $scheduleJustSaved ?? null;
$scheduleFrequency = (string) ($scheduleJustSaved['frequency'] ?? InstallationSettings::value('backup.schedule.frequency') ?? 'off');
$scheduleKeep      = (string) ($scheduleJustSaved['keep'] ?? InstallationSettings::value('backup.schedule.keep') ?? '7');
$installWideDiffering = InstallationSettings::differsHere('backup.schedule');
$frequencyChoices  = [
    'off'     => 'Never — only when somebody presses the button',
    'daily'   => 'Every day',
    'weekly'  => 'Every week',
    'monthly' => 'Every four weeks',
];

// ⏰ IS ANYTHING ACTUALLY CALLING THE JOB? (#581)
//
//    The sentence below used to be the only thing said about this, and it is
//    conditional: "this only happens if your hosting company's scheduler is
//    calling the portal". True, and no use to somebody deciding whether their
//    data is safe — the setting ships as `daily`, so a new installation showed
//    "Every day" chosen in a box while nothing was calling the job.
//
//    `DbBackup::scheduleWords()` is the ONE place that decides what to say;
//    the page does not work any of it out, and the moment is passed in rather
//    than read inside the class. A schedule nobody set up must not look like a
//    schedule that is running.
$scheduleLastSeen = $backup->scheduledCallLastSeen();
$scheduleNow      = time();
$scheduleRunning  = DbBackup::scheduleWords($scheduleFrequency, $scheduleLastSeen, $scheduleNow);
// 🎨 The colour comes from the SAME ONE test the sentence comes from
//    (DbBackup::scheduleIsRunning()), never from reading the sentence. The
//    first version chose the colour by looking at how the words started, which
//    would have gone wrong the first time anybody improved the wording — and
//    would have drawn "your scheduler is calling but backups are switched off"
//    in green.
$scheduleIsCalled = DbBackup::scheduleIsRunning($scheduleLastSeen, $scheduleNow);
$scheduleBadge    = $scheduleFrequency === 'off'
    ? 'alert-secondary'
    : ($scheduleIsCalled === true ? 'alert-success' : 'alert-danger');
?>
<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 card-title"><i class="fa-solid fa-clock me-2"></i>Backups taken automatically</h2>

        <?php // ⏰ WHAT IS REALLY HAPPENING, before the instructions. One sentence from DbBackup::scheduleWords(), which is the only thing that decides it (#581). ?>
        <div class="alert <?php echo htmlspecialchars($scheduleBadge, ENT_QUOTES, 'UTF-8'); ?>">
            <i class="fa-solid <?php echo $scheduleIsCalled === true ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?> me-2"></i>
            <?php echo htmlspecialchars($scheduleRunning, ENT_QUOTES, 'UTF-8'); ?>
        </div>

        <div class="alert alert-info">
            <i class="fa-solid fa-circle-info me-2"></i>
            <strong>This only happens if your hosting company&rsquo;s scheduler is calling the portal.</strong>
            Set it to call <code>/cron/backup-run?token=&lt;your token&gt;</code> <strong>once a day</strong>,
            whatever you choose below &mdash; how often a backup is actually taken is decided here, not by
            how often the scheduler calls. Your token is on the Settings page.
        </div>

        <?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'install-wide-notice.php'; ?>

        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="save_schedule">
            <div class="col-md-6">
                <label for="frequency" class="form-label">How often</label>
                <select class="form-select" id="frequency" name="frequency">
                    <?php foreach ($frequencyChoices as $value => $words): ?>
                        <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"
                            <?php echo $scheduleFrequency === $value ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($words, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="keep" class="form-label">How many to keep</label>
                <input type="number" class="form-control" id="keep" name="keep" min="1" max="365"
                       value="<?php echo htmlspecialchars($scheduleKeep, ENT_QUOTES, 'UTF-8'); ?>" required>
                <div class="form-text">Older ones are removed. Imported backups are never removed.</div>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk me-1"></i>Save schedule
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 📥 Download a copy to keep somewhere else. -->
<?php $completeSnapshots = array_values(array_filter($snapshots, static fn (array $s): bool => $s['complete'] === true)); ?>
<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 card-title"><i class="fa-solid fa-download me-2"></i>Download a copy to keep</h2>
        <p class="text-secondary small mb-2">
            Every snapshot lives on this server. If the hosting account is lost, they go with it. A
            download is how you keep a copy somewhere else.
        </p>
        <div class="alert alert-warning">
            <i class="fa-solid fa-triangle-exclamation me-2"></i>
            <strong>The file holds every name, address, telephone number, giving record and care note in
            the database.</strong> It is always encrypted, with a key you choose here &mdash; there is no
            unencrypted option, and <strong>the key is not stored anywhere</strong>. If you lose it, the
            file cannot be opened by anybody, including us. Keep it somewhere separate from the file.
        </div>

        <?php if ($completeSnapshots === []): ?>
            <p class="text-muted mb-0"><em>There is no finished snapshot to download yet.</em></p>
        <?php else: ?>
        <form method="post"
              action="<?php echo htmlspecialchars(Site::url('admin/maintenance/backup/download'), ENT_QUOTES, 'UTF-8'); ?>"
              class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="col-md-6">
                <label for="downloadName" class="form-label">Which snapshot</label>
                <select class="form-select" id="downloadName" name="name">
                    <?php foreach ($completeSnapshots as $snap): ?>
                        <option value="<?php echo htmlspecialchars($snap['name'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($snap['name'] . ' — ' . $snap['created_at'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label for="keyMode" class="form-label">How to protect it</label>
                <select class="form-select" id="keyMode" name="keyMode">
                    <option value="<?php echo htmlspecialchars(BackupArchive::KEY_PASSPHRASE, ENT_QUOTES, 'UTF-8'); ?>" selected>
                        A key I choose (recommended)
                    </option>
                    <option value="<?php echo htmlspecialchars(BackupArchive::KEY_BOTH, ENT_QUOTES, 'UTF-8'); ?>">
                        A key I choose AND this installation&rsquo;s key file
                    </option>
                </select>
                <div class="form-text">
                    Including the key file means the file is useless to anybody who does not also have
                    that file &mdash; <strong>and useless to you if this server is gone</strong>, which is
                    the very situation a backup is for. Choose it only if you keep the key file somewhere
                    else as well.
                </div>
            </div>
            <div class="col-md-6">
                <label for="passphrase" class="form-label">The key (at least <?php echo (int) BackupArchive::MIN_PASSPHRASE; ?> characters)</label>
                <input type="password" class="form-control" id="passphrase" name="passphrase"
                       autocomplete="new-password" minlength="<?php echo (int) BackupArchive::MIN_PASSPHRASE; ?>" required>
            </div>
            <div class="col-md-6">
                <label for="passphraseAgain" class="form-label">The key again</label>
                <input type="password" class="form-control" id="passphraseAgain" name="passphraseAgain"
                       autocomplete="new-password" minlength="<?php echo (int) BackupArchive::MIN_PASSPHRASE; ?>" required>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-download me-1"></i>Pack and download
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($inspectManifest !== null): ?>
    <div class="card mb-4 border-primary">
        <div class="card-body">
            <h2 class="h5"><i class="fa-solid fa-magnifying-glass me-1"></i>Inspecting: <?php echo htmlspecialchars($inspect, ENT_QUOTES, 'UTF-8'); ?></h2>
            <p class="text-muted small">Created <?php echo htmlspecialchars((string) ($inspectManifest['created_at'] ?? '?'), ENT_QUOTES, 'UTF-8'); ?> · reason: <?php echo htmlspecialchars((string) ($inspectManifest['reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
            <?php if ($inspectUnlisted !== ''): ?>
                <?php // 🚩 Above the buttons on purpose: "Restore ALL tables" below will be refused for this snapshot, and this says why and what to do. ?>
                <div class="alert alert-warning" id="snapshot-unlisted-warning"><?php echo htmlspecialchars($inspectUnlisted, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($inspectOneMoment !== ''): ?>
                <?php // 🧊 Also above the buttons (#581). Unlike the warning above it, this does NOT mean the restore will be refused — it means it MIGHT be, and the sentence says which message to look for. Nothing is said at all when the snapshot's answer is "yes". ?>
                <div class="alert alert-secondary" id="snapshot-one-moment-warning"><?php echo htmlspecialchars($inspectOneMoment, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($inspectSignIns !== ''): ?>
                <?php // 🔢 #922: above the buttons, like the two warnings before it. Nothing is drawn when nobody would be signed out. ?>
                <div class="alert alert-warning" id="snapshot-sign-ins-warning"><?php echo htmlspecialchars($inspectSignIns, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php // 🎭 The check comes first and looks harmless on purpose: it is the thing to press before the dangerous button below it. ?>
            <form method="post" class="mb-2" data-confirm="Check that this snapshot can be restored? This is a full rehearsal: every row is written into the database and then taken out again, so nothing is changed. It takes as long as a real restore, and the portal is closed to everyone else while it runs. Stay on this page until it finishes." data-confirm-confirm-label="Run the check">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="check_snapshot">
                <input type="hidden" name="name" value="<?php echo htmlspecialchars($inspect, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="btn btn-outline-primary btn-sm">
                    <i class="fa-solid fa-clipboard-check me-1"></i> Check this snapshot
                </button>
                <span class="text-muted small ms-2">Rehearses the whole restore and changes nothing.</span>
            </form>
            <form method="post" class="mb-3" data-confirm="Restore ALL tables from this snapshot? Every table in it is emptied and filled again from the snapshot, so anything saved since the snapshot was taken is lost. A table that is not in the snapshot is not put back, but rows in it that are linked to rows being replaced can be deleted or lose their links: the result names exactly what went, and the Check button shows it beforehand. Where the portal can keep such rows it does, and this page then offers them back. If anything goes wrong, nothing is changed. The portal is closed to everyone else while it runs. Every account whose sign-in number differs from the snapshot's (usually a password changed since) is signed out, yours too if so; an account not in the snapshot cannot sign in afterwards, and one switched off in it cannot until it is switched back on." data-confirm-destructive="true" data-confirm-confirm-label="Restore all">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="restore_full">
                <input type="hidden" name="name" value="<?php echo htmlspecialchars($inspect, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="btn btn-danger btn-sm">
                    <i class="fa-solid fa-rotate-left me-1"></i> Restore ALL tables
                </button>
            </form>
            <h3 class="h6">Per-table</h3>
            <?php // 📣 Said once, above the list, rather than on each of the rows it applies to: an administrator needs to know WHY the button is missing and what to press instead, and a copy of that sentence against every such row in a long list would be read by nobody. ?>
            <p class="text-muted small mb-2">
                A few tables can only be put back by &ldquo;Restore ALL tables&rdquo; above:
                <?php echo htmlspecialchars(implode(', ', array_keys(DbBackup::WHOLE_DATABASE_ONLY)), ENT_QUOTES, 'UTF-8'); ?>.
                <?php // 🗒️ THE EXAMPLES ARE EXAMPLES, and they used to name two of the
                      //    tables on a list of three. A fourth joined for #697, so an
                      //    administrator was given no reason at all why that one has no
                      //    button. The general sentence covers whatever the list holds; the
                      //    exact reason for each name is in WHOLE_DATABASE_ONLY and is what
                      //    the refusal says if somebody posts past the missing button. ?>
                Each of them keeps track of something the rest of the database has moved on from
                &mdash; which database upgrades have run, which sign-in codes have been used, which
                rows an earlier restore kept for you &mdash; so putting one back on its own would
                leave the rest of the database out of step with it. They are still saved in the
                snapshot, and a whole-snapshot restore puts them back.
            </p>
            <div class="portal-data-list">
                <?php foreach ((array) ($inspectManifest['tables'] ?? []) as $tableName => $meta): ?>
                    <div class="row py-1 align-items-center border-bottom">
                        <div class="col-md-4"><code><?php echo htmlspecialchars((string) $tableName, ENT_QUOTES, 'UTF-8'); ?></code></div>
                        <div class="col-md-2"><?php echo (int) ($meta['rows'] ?? 0); ?> rows</div>
                        <div class="col-md-3 small text-muted"><?php echo htmlspecialchars(substr((string) ($meta['sha256'] ?? ''), 0, 12), ENT_QUOTES, 'UTF-8'); ?>…</div>
                        <div class="col-md-3 text-end">
                            <?php // 🚫 No "Restore" button for a table that only makes sense put back beside the rest of the database: DbBackup::restoreTable() would refuse it, and offering a button that always fails is a trap. The row STAYS in the list, with its rows and checksum, because the table IS in the snapshot and hiding it would read as "not saved". The list of names is DbBackup's, never a copy kept here. ?>
                            <?php if (DbBackup::isWholeDatabaseOnly((string) $tableName) === true): ?>
                                <span class="text-muted small">Whole-snapshot restore only — see above</span>
                            <?php else: ?>
                            <form method="post" class="d-inline"
      data-confirm="Restore only <?php echo htmlspecialchars((string) $tableName, ENT_QUOTES, 'UTF-8'); ?>? The table is emptied and filled again from the snapshot. If anything goes wrong, it is left as it was."
      data-confirm-destructive="true">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="restore_table">
                                <input type="hidden" name="name" value="<?php echo htmlspecialchars($inspect, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="table" value="<?php echo htmlspecialchars((string) $tableName, ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="btn btn-outline-warning btn-sm">Restore</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <h2 class="h5">Snapshots</h2>
        <?php if (count($snapshots) === 0): ?>
            <p class="text-muted mb-0">No snapshots yet. Click "Run snapshot now" to create the first one.</p>
        <?php else: ?>
            <div class="portal-data-list">
                <?php foreach ($snapshots as $snap): ?>
                    <div class="row py-2 align-items-center border-bottom">
                        <div class="col-md-4"><code><?php echo htmlspecialchars($snap['name'], ENT_QUOTES, 'UTF-8'); ?></code></div>
                        <div class="col-md-3"><?php echo htmlspecialchars($snap['created_at'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php if ($snap['complete'] === true): ?>
                            <div class="col-md-1"><?php echo (int) $snap['tables']; ?> tbl</div>
                            <div class="col-md-2">
                                <?php echo number_format((int) $snap['rows']); ?> rows
                                <?php if ($snap['unlisted_values'] !== []): ?>
                                    <?php // 🚩 See DbBackup::unlistedWarning(). The snapshot's own page (Inspect) says which values and what to do. ?>
                                    <span class="badge text-bg-warning">Cannot be put back whole</span>
                                <?php endif; ?>
                                <?php // 🧊 Only when the answer is NOT "yes" (#581). A badge on every snapshot saying "one moment" would be a reassurance printed so often that nobody reads the one that matters; the Inspect page carries the whole sentence. ?>
                                <?php if ($snap['one_moment'] === DbBackup::ONE_MOMENT_NO): ?>
                                    <span class="badge text-bg-warning">Not one moment in time</span>
                                <?php elseif ($snap['one_moment'] === DbBackup::ONE_MOMENT_CANNOT_TELL): ?>
                                    <span class="badge text-bg-secondary">One moment: not known</span>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <?php // 🚧 A folder with no readable manifest: a snapshot that failed, was cut off, or is being taken right now. It must never be mistaken for a backup. ?>
                            <div class="col-md-3"><span class="badge text-bg-warning">Unfinished</span> <span class="small text-muted">not a backup</span></div>
                        <?php endif; ?>
                        <div class="col-md-2 text-end">
                            <?php if ($snap['complete'] === true): ?>
                                <a href="?inspect=<?php echo urlencode($snap['name']); ?>" class="btn btn-outline-primary btn-sm">Inspect</a>
                            <?php endif; ?>
                            <form method="post" class="d-inline" data-confirm="Delete this snapshot? Cannot be undone." data-confirm-destructive="true">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="name" value="<?php echo htmlspecialchars($snap['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
