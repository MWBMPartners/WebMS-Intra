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
 * Access: Admin only — uses the standard App::isAdmin() check.
 *
 * @package   Portal\Install
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.1
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/84
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// Load the full portal bootstrap (requires working config)
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Migrator;
use Portal\Core\Router;

// Require authentication and admin access
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$migrator = new Migrator($mysqli);
$pending  = $migrator->pending();
$results  = [];
$error    = '';
$ranMigrations = false;

// Handle POST to run migrations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /install/upgrade.php');
        exit();
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $results = $migrator->runAll($userId);
    $ranMigrations = true;
    $pending = $migrator->pending(); // Refresh pending list
}

// Get all executed migrations for display
$executed = [];
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

// Page metadata
$pageTitle   = 'Upgrade Portal';
$pageSection = 'admin';
$breadcrumbs = [
    ['label' => 'Admin', 'url' => '/admin'],
    ['label' => 'Upgrade', 'url' => ''],
];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-4">
    <h1 class="h3 mb-4">Upgrade Portal</h1>

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
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($r['file'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                        <td>
                                            <?php if ($r['success'] === true): ?>
                                                <span class="badge bg-success">OK</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Failed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted small"><?php echo htmlspecialchars($r['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
                    </div>
                </div>
            <?php endif; ?>

            <!-- Executed migrations history -->
            <div class="card">
                <div class="card-header">
                    <h2 class="h6 mb-0">Migration History</h2>
                </div>
                <div class="card-body p-0">
                    <?php if (count($executed) === 0): ?>
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
