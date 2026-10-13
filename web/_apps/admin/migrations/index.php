<?php
// Path: public_html/admin/migrations/index.php
/**
 * -----------------------------------------------------------------------------
 * SQL Migration Runner UI 🔄
 * -----------------------------------------------------------------------------
 * Web-based interface for running database migrations. Lists all SQL files
 * from sql/, shows which have been applied, and allows running pending ones.
 * Uses the Migrator class for execution.
 *
 * NOT A ROAD FOR AN UPGRADE (#499). When the portal's files are newer than
 * the version the database is recorded at, this page runs nothing and points
 * at the Upgrade page instead. Until 13 October 2026 it ran the waiting
 * migrations like any others: with no snapshot first, without the check of
 * stored secrets, and without recording the version, so the portal stayed on
 * its holding page afterwards with nothing left to press.
 *
 * WHAT THAT CANNOT DO: it goes by the version number. A migration that
 * arrives WITHOUT a new version number can still be run here, as before,
 * with no snapshot first and without the check of stored secrets.
 *
 * FOR A GLOBAL ADMINISTRATOR ONLY, as the Upgrade page is. Until 13 October
 * 2026 any administrator got in, an administrator of ONE organisation too:
 * with the versions equal and an upgrade file waiting, one of them ran it
 * for the whole installation (a check did exactly that). Anybody else is
 * now refused in words, and the refusal is written to the activity log.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.3.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Maintenance;
use Portal\Core\Migrator;
use Portal\Core\Router;
use Portal\Core\Site;

// 📌 Page metadata
$pageTitle   = 'Database Migrations';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Admin' => Site::url('admin'), 'Migrations' => ''];

// 🛡️ A GLOBAL administrator only: see the top of this file. This page
//    reshapes the database for the WHOLE installation, so an administrator
//    of one organisation has no business here, any more than on the Upgrade
//    page (App::isAdmin() let them in). Refused in words, before anything is
//    read or run, and written down.
if (App::isUmbrellaAdmin() === false) {
    Logger::activity(
        'MigrationsRefused',
        'Refused: Admin, Migrations is for a global administrator only',
        isset($_SESSION['user_id']) === true ? (int) $_SESSION['user_id'] : null
    );
    http_response_code(403);
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
    ?>
    <h1 class="mb-4"><i class="fa-solid fa-database me-2"></i>Database Migrations</h1>
    <div class="alert alert-danger" id="migrations-global-only">
        <p class="mb-0">
            <strong>This page is for a global administrator only.</strong>
            It runs database upgrades for the whole installation, every organisation on it included, so an administrator
            of one organisation cannot open it. Nothing was run. If the portal needs upgrading, ask a global administrator.
        </p>
    </div>
    <?php
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
    return;
}

// 🧭 An upgrade is waiting to be finished: point at the Upgrade page and stop,
//    before anything on this page can run. A form sent here gets the same
//    answer. A signpost and not a redirect, so that the reason is read
//    before the next page is opened.
if (Maintenance::versionIsBehind() === true) {
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
    ?>
    <h1 class="mb-4"><i class="fa-solid fa-database me-2"></i>Database Migrations</h1>
    <div class="alert alert-warning" id="migrations-use-upgrade">
        <p><strong>Nothing can be run here just now: the portal is part way through an upgrade.</strong></p>
        <p>
            Its files are newer than the version the database is recorded at. Finish the upgrade on the Upgrade page.
            When a database upgrade is waiting, that page takes a snapshot of the database first, so there is a way
            back, and then runs it. Then, waiting or not, it stores encrypted any saved secret still kept as readable
            text and records the new version, which is what ends the upgrade. This page does none of those except run
            the upgrades.
        </p>
        <p class="mb-0">
            <a class="btn btn-warning" href="<?php echo htmlspecialchars(Site::url('admin/upgrade'), ENT_QUOTES, 'UTF-8'); ?>">Open the Upgrade page</a>
        </p>
    </div>
    <?php
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
    return;
}

// 📦 Initialise migrator
$migrator     = new Migrator($mysqli);
$allFiles     = $migrator->allFiles();
$executedList = $migrator->executed();
$pendingList  = $migrator->pending();
$results      = [];

// -----------------------------------------------------------------------------
// 🚀 Handle migration execution (POST)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin'));
        exit();
    }

    $runAction = $_POST['run'] ?? '';
    $userId    = $_SESSION['user_id'] ?? null;

    if ($runAction === 'all') {
        // 🚀 Run all pending migrations
        $results = $migrator->runAll($userId);
        Logger::activity('MigrationsRunAll', 'Ran ' . count($results) . ' migrations', $userId);
    } elseif ($runAction === 'single' && isset($_POST['filename']) === true) {
        // 🚀 Run a single migration
        $filename = basename($_POST['filename']); // 🛡️ Prevent path traversal
        $results  = [$migrator->runOne($filename, $userId)];
        Logger::activity('MigrationRunSingle', 'Ran migration: ' . $filename, $userId);
    }

    // 🔄 Refresh lists after execution
    $executedList = $migrator->executed();
    $pendingList  = $migrator->pending();
}

// 📊 Get execution details from tblMigrations
$executionDetails = [];
$stmt = $mysqli->prepare(
    'SELECT m.filename, m.executedAt, m.executedByID, u.fullName AS executedBy '
    . 'FROM tblMigrations m '
    . 'LEFT JOIN tblUsers u ON u.userID = m.executedByID '
    . 'ORDER BY m.filename'
);
if ($stmt !== false) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $executionDetails[$r['filename']] = $r;
    }
    $stmt->close();
}

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 🔄 Migration Runner -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-database me-2"></i>Database Migrations</h1>
    <?php if (count($pendingList) > 0): ?>
        <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/migrations'), ENT_QUOTES, 'UTF-8'); ?>" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="run" value="all">
            <button type="submit" class="btn btn-warning"
                    data-confirm="Run all <?php echo count($pendingList); ?> pending migration(s)?">
                <i class="fa-solid fa-play me-1"></i> Run All Pending (<?php echo count($pendingList); ?>)
            </button>
        </form>
    <?php endif; ?>
</div>

<!-- 📊 Summary badges -->
<div class="mb-4">
    <span class="badge bg-secondary"><?php echo count($allFiles); ?> total</span>
    <span class="badge bg-success"><?php echo count($executedList); ?> applied</span>
    <span class="badge bg-warning text-dark"><?php echo count($pendingList); ?> pending</span>
</div>

<!-- 📋 Migration results (if any) -->
<?php if (count($results) > 0): ?>
    <div class="mb-4">
        <h5>Execution Results</h5>
        <?php foreach ($results as $r): ?>
            <?php if ($r['success'] === true): ?>
                <div class="alert alert-success py-2">
                    <i class="fa-solid fa-check me-1"></i>
                    <strong><?php echo htmlspecialchars($r['filename'], ENT_QUOTES, 'UTF-8'); ?></strong> — applied successfully
                </div>
            <?php else: ?>
                <div class="alert alert-danger py-2">
                    <i class="fa-solid fa-xmark me-1"></i>
                    <strong><?php echo htmlspecialchars($r['filename'], ENT_QUOTES, 'UTF-8'); ?></strong> — FAILED:
                    <?php echo htmlspecialchars($r['error'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- 📋 Migration file list -->
<div class="portal-data-list">
    <!-- 🏷️ Header row -->
    <div class="portal-data-row portal-data-header d-none d-md-flex">
        <div class="col-md-4">Migration File</div>
        <div class="col-md-2">Status</div>
        <div class="col-md-3">Applied</div>
        <div class="col-md-3 text-end">Actions</div>
    </div>

    <?php foreach ($allFiles as $file): ?>
        <?php
        $isApplied = in_array($file, $executedList, true);
        $detail    = $executionDetails[$file] ?? null;
        ?>
        <div class="portal-data-row">
            <div class="col-12 col-md-4">
                <span class="d-md-none fw-semibold">File: </span>
                <code class="small"><?php echo htmlspecialchars($file, ENT_QUOTES, 'UTF-8'); ?></code>
            </div>
            <div class="col-12 col-md-2">
                <span class="d-md-none fw-semibold">Status: </span>
                <?php if ($isApplied === true): ?>
                    <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>Applied</span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark"><i class="fa-solid fa-clock me-1"></i>Pending</span>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-3">
                <span class="d-md-none fw-semibold">Applied: </span>
                <?php if ($detail !== null): ?>
                    <small>
                        <?php echo htmlspecialchars($detail['executedAt'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ($detail['executedBy'] !== null): ?>
                            <br>by <?php echo htmlspecialchars($detail['executedBy'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </small>
                <?php else: ?>
                    <span class="text-muted small">—</span>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-3 text-md-end mt-2 mt-md-0">
                <?php if ($isApplied === false): ?>
                    <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/migrations'), ENT_QUOTES, 'UTF-8'); ?>" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="run" value="single">
                        <input type="hidden" name="filename" value="<?php echo htmlspecialchars($file, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" class="btn btn-sm btn-outline-warning"
                                data-confirm="Run migration: <?php echo htmlspecialchars($file, ENT_QUOTES, 'UTF-8'); ?>?">
                            <i class="fa-solid fa-play me-1"></i> Run
                        </button>
                    </form>
                <?php else: ?>
                    <span class="text-muted small"><i class="fa-solid fa-lock me-1"></i>Already applied</span>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
