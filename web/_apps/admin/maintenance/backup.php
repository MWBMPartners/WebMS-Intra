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
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\BackupArchive;
use Portal\Core\DbBackup;
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
        $record = static function (string $type, string $what): void {
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
            $record(
                $result['success'] === true ? 'BackupTableRestored' : 'BackupRestoreFailed',
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
            $record(
                $result['success'] === true ? 'BackupRestored' : 'BackupRestoreFailed',
                $result['success'] === true
                    ? trim(sprintf('Snapshot %s put back in full: %d tables, %d rows. %s', $name, $result['tables_restored'], $result['rows_restored'], $notes))
                    : sprintf('Putting snapshot %s back failed; nothing was changed. %s', $name, $result['error'])
            );

            if ($result['success'] === true) {
                $flash = trim(sprintf(
                    'Snapshot put back in full: %d tables, %d rows. %s',
                    $result['tables_restored'],
                    $result['rows_restored'],
                    $notes
                ));
                $flashType = $notes === '' ? 'success' : 'warning';
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
        }
    }
}

$snapshots = $backup->listSnapshots();

// 🔍 Inspect mode. The same name test as every action above, and the same
//    door into the folder: DbBackup::manifestOf() refuses a link that leads
//    out of the backups folder, as a check or a restore does. This page used
//    to read the manifest file itself, followed such a link, and showed the
//    outside folder's manifest with its Restore buttons.
$inspect = $_GET['inspect'] ?? '';
$inspectManifest = null;
$inspectUnlisted = '';
if (DbBackup::isSnapshotName($inspect) === true) {
    $opened = $backup->manifestOf($inspect);
    if (is_array($opened) === true) {
        $inspectManifest = $opened;
        // 🚩 Said on the snapshot's own page, above its buttons.
        $inspectUnlisted = DbBackup::unlistedWarning(DbBackup::unlistedIn($opened));
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
$scheduleFrequency = (string) ($SETTINGS['backup']['schedule']['frequency'] ?? 'off');
$scheduleKeep      = (string) ($SETTINGS['backup']['schedule']['keep'] ?? '7');
$frequencyChoices  = [
    'off'     => 'Never — only when somebody presses the button',
    'daily'   => 'Every day',
    'weekly'  => 'Every week',
    'monthly' => 'Every four weeks',
];
?>
<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 card-title"><i class="fa-solid fa-clock me-2"></i>Backups taken automatically</h2>

        <div class="alert alert-info">
            <i class="fa-solid fa-circle-info me-2"></i>
            <strong>This only happens if your hosting company&rsquo;s scheduler is calling the portal.</strong>
            Set it to call <code>/cron/backup-run?token=&lt;your token&gt;</code> <strong>once a day</strong>,
            whatever you choose below &mdash; how often a backup is actually taken is decided here, not by
            how often the scheduler calls. Your token is on the Settings page.
        </div>

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
            <form method="post" class="mb-3" data-confirm="Restore ALL tables from this snapshot? Every table in it is emptied and filled again from the snapshot, so anything saved since the snapshot was taken is lost. A table that is not in the snapshot is not put back, but rows in it that are linked to rows being replaced can be deleted or lose their links: the result names every such table, and the Check button shows it beforehand. If anything goes wrong, nothing is changed. The portal is closed to everyone else while it runs." data-confirm-destructive="true" data-confirm-confirm-label="Restore all">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="restore_full">
                <input type="hidden" name="name" value="<?php echo htmlspecialchars($inspect, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="btn btn-danger btn-sm">
                    <i class="fa-solid fa-rotate-left me-1"></i> Restore ALL tables
                </button>
            </form>
            <h3 class="h6">Per-table</h3>
            <?php // 📣 Said once, above the list, rather than on each of the three rows: an administrator needs to know WHY the button is missing and what to press instead, and three copies of that sentence in a long list would be read by nobody. ?>
            <p class="text-muted small mb-2">
                A few tables can only be put back by &ldquo;Restore ALL tables&rdquo; above:
                <?php echo htmlspecialchars(implode(', ', array_keys(DbBackup::WHOLE_DATABASE_ONLY)), ENT_QUOTES, 'UTF-8'); ?>.
                They keep track of things the rest of the database has moved on from &mdash; which
                database upgrades have run, which sign-in codes have been used &mdash; so putting
                one of them back on its own would leave the rest of the database out of step with
                it. They are still saved in the snapshot, and a whole-snapshot restore puts them
                back.
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
