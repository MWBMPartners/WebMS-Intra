<?php
// Path: install/upgrade.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — Upgrade Handler
 * -----------------------------------------------------------------------------
 * Web-based upgrade process for existing installations. Detects the current
 * schema version by checking tblMigrations, then runs any pending SQL
 * migrations in order.
 *
 * This file bootstraps the portal normally (requires a working config) and
 * uses the existing Migrator class to run pending migrations.
 *
 * Access: UMBRELLA administrators only. This reshapes the database for the
 *         whole installation, so a site administrator is not enough.
 *
 * @package   Portal\Install
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.2.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/84
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// Load the full portal bootstrap (requires working config)
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\FeedAdmin;
use Portal\Core\Migrator;
use Portal\Core\Router;
use Portal\Core\Site;

// Require authentication and admin access
Auth::requireLogin();

// 🔐 UMBRELLA ADMINISTRATORS ONLY - not simply "an administrator".
//
//    This page changes the shape of the database for the WHOLE installation.
//    On a portal shared by several organisations, an administrator of one of
//    them has no business reshaping the database underneath all the others.
//
//    App::isAdmin() would have allowed exactly that: it returns true for a site
//    administrator, and for the older plain "admin" flag, as well as for an
//    umbrella administrator. App::isUmbrellaAdmin() is the narrow check, and it
//    is the right one here.
//
//    On the ordinary single-organisation install the owner IS the umbrella
//    administrator, so nothing is taken away from anybody in practice.
if (App::isUmbrellaAdmin() === false) {
    Router::renderError(403);
    return;
}

$migrator = new Migrator($mysqli);
$pending  = $migrator->pending();
$results  = [];
$error    = '';
$ranMigrations = false;
// 🚩 Set when this run went ahead although its own snapshot cannot be put back
//    whole (see "A SNAPSHOT THAT CANNOT BE PUT BACK WHOLE" below): the words to
//    show beside the results.
$ranWithoutWayBack = '';
$unplacedAfterUpgrade = 0; // #533 — only computed for real after a run; see below
$apiNotice = ['calendars' => 0, 'organisations' => 0]; // #514 part 8 — see below

// Handle POST to run migrations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        // 🔁 Back to the page the visitor is actually on.
        //
        //    This used to send them to /install/upgrade.php, which is wrong
        //    twice over. The folder is _install, not install - and no address
        //    ending in .php is served at all, because .htaccess answers 404 for
        //    every one of them. So a mistyped or expired form token sent the
        //    administrator to a "page not found" instead of back to the form,
        //    in the middle of upgrading their database.
        //
        //    This file is reached at /admin/upgrade, through a one-line proxy
        //    at _apps/admin/upgrade.php. That is the address to return to.
        header('Location: /admin/upgrade');
        exit();
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);

    // 🚧 Maintenance gate: lock public access for the duration of the
    //    migration run. Non-admin requests will see the maintenance page;
    //    admins (and the routes on the allow-list) can still reach the
    //    upgrader / backup UI. Clearing happens at the end of this block,
    //    AND we update portal.installed_version on success so the
    //    version-drift check also stops firing.
    \Portal\Core\Maintenance::setActive(
        true,
        'Applying database migrations — please wait.'
    );

    // 📦 A snapshot is taken before any migration runs, one file per table,
    //    in web/_backups/upgrade-YYYYMMDD-HHMMSS/. See DbBackup.php. It is
    //    taken unless the portal.upgrade.backup.enabled setting is 0
    //    (default: on).
    //
    //    IF THE SNAPSHOT FAILS, THE UPGRADE STOPS. Nothing is migrated, the
    //    portal is reopened, and the reason is stored and shown at the top
    //    of this page when it is drawn again (see "Show, once, the message"
    //    further down). Until October 2026 the reason was stored and NEVER
    //    shown: the page simply came back with the migration still waiting.
    //    (This comment also used to say a failed backup did not block the
    //    upgrade. The code below has always blocked it, and that is the
    //    right way round: an upgrade with no way back is the thing the
    //    snapshot exists to prevent.)
    //
    //    ⏱️ A snapshot of a large database takes longer than PHP's ordinary
    //    limit of 30 seconds, and until #579 nothing here asked for more.
    //    TimeLimit never lowers a limit. What it cannot do is stop the HOST
    //    ending the request; if that happens no migration has run, and the
    //    unfinished snapshot folder is listed as unfinished and removed by a
    //    later upgrade once it is a day old.
    $backupSetting = (string) (\Portal\Core\App::settings()['portal']['upgrade']['backup']['enabled'] ?? '1');
    $backupResult  = null;
    if ($backupSetting === '1') {
        \Portal\Core\TimeLimit::atLeast(900);
        $backup = new \Portal\Core\DbBackup($mysqli);
        $backupResult = $backup->snapshot('pre-upgrade-' . PORTAL_VERSION);
        if ($backupResult['success'] === false) {
            // 💬 What went wrong, and what to do about the CAUSE. This used
            //    to end by naming the setting that skips the backup. That
            //    invited the one thing the backup is there to prevent, an
            //    upgrade with no way back, at the very moment somebody was
            //    stuck and in a hurry. The recovery guide is where that
            //    switch is described, with when it is acceptable to use it.
            //    (The reason can name a folder on the server. Only a global
            //    administrator reaches this page, and they need it.)
            $reason = rtrim((string) ($backupResult['error'] ?? 'The backup gave no reason.'));
            if (in_array(substr($reason, -1), ['.', '!', '?'], true) === false) {
                $reason .= '.';
            }
            $_SESSION['flash_msg']  = 'Backup failed, so the upgrade was not started. Nothing has been changed. '
                                    . 'The reason: ' . $reason . ' What to do: put that right, then press the '
                                    . 'button again. If the reason names a folder, check that the folder exists, '
                                    . 'that the portal is allowed to write to it, and that the disk is not full. '
                                    . 'The recovery guide covers the other causes.';
            $_SESSION['flash_type'] = 'danger';
            \Portal\Core\Maintenance::setActive(false);
            header('Location: /admin/upgrade');
            exit();
        }

        // 🚩 A SNAPSHOT THAT CANNOT BE PUT BACK WHOLE STOPS THE UPGRADE TOO.
        //
        //    The snapshot was taken, but it holds a value that is not on its
        //    column's list of choices, and a restore refuses such a value
        //    (DbBackup::unlistedWarning() explains). So if a migration went
        //    wrong there would be NO way back, which is the one thing this
        //    snapshot is taken to prevent, and until October 2026 nobody
        //    would have known until they tried to put it back. The upgrade
        //    stops here, nothing is migrated, and the page says which table
        //    and column, how many values, and what to do.
        //
        //    A global administrator may go on all the same, deliberately: the
        //    page then offers a second button that sends
        //    `go_on_without_way_back=1`. That is for somebody who has another
        //    backup they trust (the host's own, say) and cannot mend the rows
        //    first. It is written to the activity log and said on the page.
        //    (What is acted on is that field in a request carrying a valid
        //    form token from a global administrator, not the session mark
        //    that makes the button appear: the mark only decides what the
        //    page shows.)
        //    Carrying on with only a warning was considered and not done: a
        //    warning shown AFTER the migrations have run is too late to act
        //    on. Refusing with no way forward was not done either: the mend
        //    needs a database tool some customers do not have to hand.
        if ($backupResult['warnings'] !== []) {
            $cannotGoBack = implode(' ', $backupResult['warnings']);
            $snapshotName = basename((string) $backupResult['directory']);
            if ((string) (is_string($_POST['go_on_without_way_back'] ?? null) === true ? $_POST['go_on_without_way_back'] : '') !== '1') {
                $_SESSION['flash_msg']  = 'The upgrade was NOT started, and nothing has been changed. The snapshot taken '
                                        . 'just now (' . $snapshotName . ') is meant to be the way back if the upgrade goes '
                                        . 'wrong, and it could not be used for that. ' . $cannotGoBack . ' Once the rows '
                                        . 'are mended, press the upgrade button again: it takes a new snapshot of its own. '
                                        . 'If you have another backup of the database that you trust, you may go on '
                                        . 'without this one: use the second button below.';
                $_SESSION['flash_type'] = 'danger';
                // Shown once, like the message: the second button appears
                // only on the page this refusal leads to.
                $_SESSION['upgrade_may_go_on'] = true;
                \Portal\Core\Maintenance::setActive(false);
                header('Location: /admin/upgrade');
                exit();
            }
            // Going on, deliberately. Recorded first, so that the record does
            // not depend on how the migrations end; a failure to record must
            // not be what stops (or seems to stop) the upgrade.
            $ranWithoutWayBack = 'This upgrade was run WITHOUT a way back, because a global administrator chose to go on. '
                               . 'Its snapshot (' . $snapshotName . ') was taken, but: ' . $cannotGoBack;
            try {
                \Portal\Core\Logger::activity(
                    'UpgradeWithoutWayBack',
                    'The upgrade was run although its own snapshot (' . $snapshotName . ') cannot be put back whole. '
                    . $cannotGoBack,
                    $userId
                );
            } catch (\Throwable $ignored) {
                unset($ignored);
            }
        } else {
            // 🗄️ Keep the newest N FINISHED snapshots and remove older ones.
            //    The one just taken is the newest, so it is always kept.
            //    Unfinished folders are not counted and are removed only once
            //    nothing in them has changed for 24 hours (see DbBackup::prune()).
            //
            //    NOT done when the new snapshot cannot be put back whole (the
            //    branch above): an older snapshot must never be removed to
            //    make room for one that is no use as a way back.
            $keepN = (int) (\Portal\Core\App::settings()['portal']['upgrade']['backup']['keep_last_n'] ?? 10);
            $backup->prune($keepN);
        }
    }

    $results = $migrator->runAll($userId);
    $ranMigrations = true;
    $pending = $migrator->pending(); // Refresh pending list

    // 🛡️ #533: how many accounts, across the WHOLE installation, still
    //    belong to no organisation after migration 199 has had its chance
    //    to run. On a single-organisation portal this is 0 unless 199
    //    itself failed — which is exactly when this card should show, so
    //    no extra "did 199 succeed" test is needed here. Nothing is
    //    guessed here either: this only counts and reports, it never
    //    places anybody — that is what /admin/users/unplaced is for.
    $unplacedAfterUpgrade = 0;
    $unplacedResult = $mysqli->query(
        'SELECT COUNT(*) AS cnt FROM tblUsers u '
        . 'WHERE NOT EXISTS (SELECT 1 FROM tblUserSites us WHERE us.userID = u.userID)'
    );
    if ($unplacedResult !== false) {
        $unplacedAfterUpgrade = (int) ($unplacedResult->fetch_assoc()['cnt'] ?? 0);
    }

    // 🔑 #514 part 8 (the plan's section 7.1, part 7's B7): the run that
    //    applied migration 206 changed what API keys receive — events from an
    //    outside calendar set to Everyone now reach a key exactly as a
    //    signed-out visitor sees them. Counted only when THIS run applied 206
    //    successfully, by the Migrator's own key names (`filename`,
    //    `success` — #561 fixed the page to read those; the plan's first
    //    draft read `file`, which does not exist). The count is wrapped so
    //    that the upgrade page can never break over a notice: a failure here
    //    simply shows no card.
    foreach ($results as $r) {
        if (($r['filename'] ?? '') === '206_external_calendar_choices_rules_approvals.sql' && ($r['success'] ?? false) === true) {
            try {
                $apiNotice = FeedAdmin::apiUpgradeNotice($mysqli);
            } catch (\Throwable $e) {
                $apiNotice = ['calendars' => 0, 'organisations' => 0];
            }
            break;
        }
    }

    // 🪞 Mark every migration as successful → record current version and
    //    release maintenance. If any migration failed, keep maintenance
    //    on so the admin's next request still routes here.
    $allOk = true;
    foreach ($results as $r) {
        if (($r['success'] ?? false) === false) {
            $allOk = false;
            break;
        }
    }
    if ($allOk === true) {
        try {
            $stmt = $mysqli->prepare(
                "INSERT INTO `tblSettings` "
                . "(`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) "
                . "VALUES (NULL, 'portal.installed_version', ?, '', 0) "
                . "ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`)"
            );
            if ($stmt !== false) {
                $v = (string) PORTAL_VERSION;
                $stmt->bind_param('s', $v);
                $stmt->execute();
                $stmt->close();
            }
        } catch (\mysqli_sql_exception $e) {
            // Non-fatal — the gate will fire again until this gets fixed.
        }
        \Portal\Core\Maintenance::setActive(false);
    }
}

// Get all executed migrations for display.
// 🛡️ Wrapped in try/catch so a bad tblMigrations (corrupt, missing,
//    permission issue) shows the admin a useful message inline on the
//    upgrade page instead of relying on the global 500 handler. Mirrors
//    the pattern PR #170 established for the installer step-3 fix.
$executed     = [];
$executedErr  = '';
try {
    $execStmt = $mysqli->prepare(
        'SELECT filename, executedAt FROM tblMigrations ORDER BY filename ASC'
    );
    if ($execStmt !== false) {
        $execStmt->execute();
        $execResult = $execStmt->get_result();
        while ($row = $execResult->fetch_assoc()) {
            $executed[] = $row;
        }
        $execStmt->close();
    }
} catch (\mysqli_sql_exception $e) {
    $executedErr = 'Could not read executed-migrations list: ' . $e->getMessage();
}

// Page metadata
$pageTitle   = 'Upgrade Portal';
$pageSection = 'admin';
// #508: a breadcrumb trail is [label => address, …] — a flat map, not a
// list of small arrays. This page used to pass the wrong shape
// ([['label' => …, 'url' => …], …]), so header.php's loop handed an ARRAY
// to htmlspecialchars() and the page died half drawn, ten error rows for
// one mistake. header.php now guards against the wrong shape too (see its
// own comment), but the fix belongs here: the trail should be right in
// the first place.
$breadcrumbs = ['Admin' => '/admin', 'Upgrade' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-4">
    <h1 class="h3 mb-4">Upgrade Portal</h1>

    <?php
    // 💬 Show, once, the message a redirect left behind: a backup that failed,
    //    or a form token that had expired. It used to be stored and never
    //    shown by anything, so a refused upgrade looked exactly as if the
    //    button had done nothing. It is removed as it is read, so it does not
    //    come back on the next visit.
    $flashMsg  = is_string($_SESSION['flash_msg'] ?? null) === true ? $_SESSION['flash_msg'] : '';
    $flashType = in_array($_SESSION['flash_type'] ?? '', ['danger', 'warning', 'success', 'info'], true) === true
        ? (string) $_SESSION['flash_type']
        : 'info';
    // 🚩 Was this page reached from "the snapshot cannot be put back whole"?
    //    Then, and only then, the second button is offered (see the Pending
    //    migrations card). Read once and removed, like the message.
    $mayGoOn = ($_SESSION['upgrade_may_go_on'] ?? false) === true;
    unset($_SESSION['flash_msg'], $_SESSION['flash_type'], $_SESSION['upgrade_may_go_on']);
    ?>
    <?php if ($flashMsg !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-8">

            <!-- Current status -->
            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="h6 mb-0">Current Version</h2>
                </div>
                <div class="card-body">
                    <p class="mb-1"><strong>Portal version:</strong> <?php echo htmlspecialchars(App::version(), ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="mb-1"><strong>Executed migrations:</strong> <?php echo count($executed); ?></p>
                    <p class="mb-0"><strong>Pending migrations:</strong>
                        <?php if (count($pending) === 0): ?>
                            <span class="text-success">None — schema is up to date</span>
                        <?php else: ?>
                            <span class="text-warning"><?php echo count($pending); ?> pending</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <?php if ($ranWithoutWayBack !== ''): ?>
                <!-- 🚩 This run went ahead although its snapshot cannot be put back whole -->
                <div class="alert alert-warning" id="upgrade-without-way-back"><?php echo htmlspecialchars($ranWithoutWayBack, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <!-- Migration results (if just ran) -->
            <?php if ($ranMigrations === true && count($results) > 0): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 mb-0">Migration Results</h2>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr><th>Migration</th><th>Status</th><th>Details</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $r): ?>
                                    <?php
                                    // 🐛 #561: Portal\Core\Migrator::runOne()/runAll() return each row
                                    //    with the keys "filename", "success" and "error" — never "file"
                                    //    or "message". This used to read $r['file'] / $r['message'],
                                    //    which do not exist, so htmlspecialchars() was handed null for
                                    //    the file column and PHP's strict_types=1 threw a TypeError on
                                    //    the first row of every upgrade that ran anything. The page had
                                    //    already drawn its top part, so the administrator saw that, then
                                    //    the portal's "Something Went Wrong" page inside the results
                                    //    table, and nothing below it: no result rows, no #533 card, no
                                    //    history. The Details column would have shown nothing anyway,
                                    //    since 'message' was never set. As of 25 September 2026 the
                                    //    main and alpha branches still read the same two wrong keys,
                                    //    but there the page never gets this far: it stops earlier, at
                                    //    the breadcrumb trail (#508, fixed alongside this on
                                    //    claude/alpha-wip). The two fixes have to be released together:
                                    //    without #508's the page still dies at the trail, and #508's
                                    //    alone would only move the crash down to this table. See web/_core/Migrator.php ~215-372;
                                    //    web/_apps/admin/migrations/index.php already read the correct
                                    //    keys, so only this page had the fault.
                                    ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($r['filename'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                        <td>
                                            <?php if ($r['success'] === true): ?>
                                                <span class="badge bg-success">OK</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Failed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted small"><?php echo htmlspecialchars($r['error'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- #533: accounts left for a global administrator to place by hand -->
            <?php if ($ranMigrations === true && $unplacedAfterUpgrade > 0): ?>
                <div class="card mb-4 border-warning">
                    <div class="card-header bg-warning-subtle">
                        <h2 class="h6 mb-0">Accounts with no organisation</h2>
                    </div>
                    <div class="card-body">
                        <p class="mb-2">
                            <?php echo (int) $unplacedAfterUpgrade; ?> account<?php echo $unplacedAfterUpgrade !== 1 ? 's' : ''; ?>
                            still belong<?php echo $unplacedAfterUpgrade === 1 ? 's' : ''; ?> to no organisation. Nothing was
                            guessed &mdash; this portal has more than one organisation, so this migration could not
                            safely decide which one each account belongs to.
                        </p>
                        <a href="<?php echo htmlspecialchars(Site::url('admin/users/unplaced'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-warning">
                            Place them
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 🔑 #514 part 8: outside calendars that now send their public events to API keys -->
            <?php if ($ranMigrations === true && $apiNotice['calendars'] > 0): ?>
                <div class="card mb-4 border-info" id="upgrade-api-notice">
                    <div class="card-header bg-info-subtle">
                        <h2 class="h6 mb-0">Outside calendars and API keys</h2>
                    </div>
                    <div class="card-body">
                        <p class="mb-2">
                            <?php echo $apiNotice['calendars'] === 1
                                ? '1 outside calendar now sends its public events to API keys.'
                                : (int) $apiNotice['calendars'] . ' outside calendars now send their public events to API keys.'; ?>
                            If you have given a key to a website and do not want that, tick 'Don't show via API' on each calendar.
                            <?php if ($apiNotice['organisations'] > 1): ?>
                                These calendars belong to <?php echo (int) $apiNotice['organisations']; ?> organisations; open Outside calendars in each one.
                            <?php endif; ?>
                        </p>
                        <a href="<?php echo htmlspecialchars(Site::url('admin/calendar/feeds'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-info">
                            Open Outside calendars
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Pending migrations -->
            <?php if (count($pending) > 0): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 mb-0">Pending Migrations</h2>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">The following migrations will be executed in order:</p>
                        <ol class="mb-3">
                            <?php foreach ($pending as $p): ?>
                                <li><code><?php echo htmlspecialchars($p, ENT_QUOTES, 'UTF-8'); ?></code></li>
                            <?php endforeach; ?>
                        </ol>

                        <div class="alert alert-warning">
                            <strong>Important:</strong> Back up your database before running migrations.
                            Migrations cannot be automatically rolled back.
                        </div>

                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="btn btn-warning">
                                Run <?php echo count($pending); ?> Pending Migration<?php echo count($pending) > 1 ? 's' : ''; ?>
                            </button>
                        </form>

                        <?php if ($mayGoOn === true): ?>
                            <?php // 🚩 Offered only straight after the refusal above, and worded so that nobody presses it by habit. ?>
                            <form method="post" class="mt-3" id="upgrade-go-on-anyway" data-confirm="Run the upgrade WITHOUT a way back? The snapshot the portal has just taken cannot be put back whole, so if a migration goes wrong the portal will not be able to undo it. Only go on if you have another backup of the database that you trust." data-confirm-destructive="true" data-confirm-confirm-label="Upgrade without a way back">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="go_on_without_way_back" value="1">
                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                    Upgrade anyway, without a way back
                                </button>
                                <span class="text-muted small ms-2">Only if you have another backup of the database that you trust.</span>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Executed migrations history -->
            <div class="card">
                <div class="card-header">
                    <h2 class="h6 mb-0">Migration History</h2>
                </div>
                <div class="card-body p-0">
                    <?php if ($executedErr !== ''): ?>
                        <div class="alert alert-danger m-3 mb-0">
                            <?php echo htmlspecialchars($executedErr, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php elseif (count($executed) === 0): ?>
                        <p class="text-muted p-3 mb-0">No migrations have been executed yet.</p>
                    <?php else: ?>
                        <table class="table table-sm table-striped mb-0">
                            <thead>
                                <tr><th>#</th><th>Filename</th><th>Executed At</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($executed as $i => $m): ?>
                                    <tr>
                                        <td><?php echo $i + 1; ?></td>
                                        <td><code><?php echo htmlspecialchars($m['filename'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                        <td class="text-muted"><?php echo htmlspecialchars($m['executedAt'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h2 class="h6 mb-0">Upgrade Guide</h2>
                </div>
                <div class="card-body">
                    <ol class="small">
                        <li class="mb-2"><strong>Back up</strong> your database before upgrading</li>
                        <li class="mb-2"><strong>Upload</strong> the new portal files to your server (FTP sync)</li>
                        <li class="mb-2"><strong>Run migrations</strong> using this page</li>
                        <li class="mb-2"><strong>Verify</strong> the portal is working correctly</li>
                        <li><strong>Clear</strong> your browser cache if styles look wrong</li>
                    </ol>

                    <hr>

                    <p class="small text-muted mb-1"><strong>Upgrade process:</strong></p>
                    <p class="small text-muted">
                        SQL migrations are numbered sequentially (000, 001, ...).
                        Each migration runs once and is recorded in tblMigrations.
                        The full_schema.sql file is for fresh installs only &mdash;
                        upgrades always use incremental migrations.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
