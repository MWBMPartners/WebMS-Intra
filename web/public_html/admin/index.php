<?php
// Path: public_html/admin/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin Dashboard 🛡️
 * -----------------------------------------------------------------------------
 * Central admin hub showing summary cards for key system metrics:
 *   - Recent errors count
 *   - Active users count
 *   - Recent activity entries
 *   - Pending migrations
 *   - System information (PHP version, DB version, environment)
 *
 * Only accessible to users with isAdmin=1 or isRootAdmin=1.
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
use Portal\Core\Router;

// 📌 Page metadata for the template system
$pageTitle   = 'Admin Dashboard';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => ''];

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// -----------------------------------------------------------------------------
// 📊 Gather summary data for dashboard cards
// -----------------------------------------------------------------------------

// 🔴 Recent errors (last 24 hours)
$errorCount24h = 0;
$stmt = $mysqli->prepare(
    'SELECT COUNT(*) AS cnt FROM tblErrors WHERE createdAt >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
);
if ($stmt !== false) {
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $errorCount24h = (int) ($row['cnt'] ?? 0);
    $stmt->close();
}

// 🔴 Total errors
$errorCountTotal = 0;
$stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM tblErrors');
if ($stmt !== false) {
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $errorCountTotal = (int) ($row['cnt'] ?? 0);
    $stmt->close();
}

// 👥 Active users (total enabled users)
$userCountActive = 0;
$stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM tblUsers WHERE isActive = 1');
if ($stmt !== false) {
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $userCountActive = (int) ($row['cnt'] ?? 0);
    $stmt->close();
}

// 👥 Total users
$userCountTotal = 0;
$stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM tblUsers');
if ($stmt !== false) {
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $userCountTotal = (int) ($row['cnt'] ?? 0);
    $stmt->close();
}

// 📋 Recent activity (last 24 hours)
$activityCount24h = 0;
$stmt = $mysqli->prepare(
    'SELECT COUNT(*) AS cnt FROM tblActivityLogs WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
);
if ($stmt !== false) {
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $activityCount24h = (int) ($row['cnt'] ?? 0);
    $stmt->close();
}

// 🔧 Pending migrations count
$pendingMigrations = 0;
$appliedMigrations = [];
$stmt = $mysqli->prepare('SELECT filename AS migrationFile FROM tblMigrations');
if ($stmt !== false) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $appliedMigrations[] = $r['migrationFile'];
    }
    $stmt->close();
}

// 📂 Count SQL files in the migrations directory
$sqlDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_sql';
$sqlFiles = [];
if (is_dir($sqlDir) === true) {
    $scan = scandir($sqlDir);
    if ($scan !== false) {
        foreach ($scan as $file) {
            if (str_ends_with($file, '.sql') === true && $file !== 'full_schema.sql') {
                $sqlFiles[] = $file;
            }
        }
    }
}
$pendingMigrations = 0;
foreach ($sqlFiles as $file) {
    if (in_array($file, $appliedMigrations, true) === false) {
        $pendingMigrations++;
    }
}

// 🖥️ System info
$phpVersion = PHP_VERSION;
$dbVersion  = $mysqli->server_info ?? 'Unknown';
$portalEnv  = PORTAL_ENV ?? 'production';

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 🛡️ Admin Dashboard -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-shield-halved me-2"></i>Admin Dashboard</h1>
</div>

<!-- 📊 Summary Cards -->
<div class="row g-4 mb-4">
    <!-- 🔴 Errors Card -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card border-danger h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="card-subtitle mb-2 text-danger">Errors (24h)</h6>
                        <h2 class="card-title mb-0"><?php echo $errorCount24h; ?></h2>
                        <small class="text-muted"><?php echo number_format($errorCountTotal); ?> total</small>
                    </div>
                    <div class="fs-1 text-danger opacity-25">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-transparent border-danger">
                <a href="/admin/errors" class="text-danger text-decoration-none small">
                    View Error Log <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- 👥 Users Card -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card border-primary h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="card-subtitle mb-2 text-primary">Active Users</h6>
                        <h2 class="card-title mb-0"><?php echo $userCountActive; ?></h2>
                        <small class="text-muted"><?php echo number_format($userCountTotal); ?> total</small>
                    </div>
                    <div class="fs-1 text-primary opacity-25">
                        <i class="fa-solid fa-users"></i>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-transparent border-primary">
                <a href="/admin/users" class="text-primary text-decoration-none small">
                    Manage Users <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- 📋 Activity Card -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card border-success h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="card-subtitle mb-2 text-success">Activity (24h)</h6>
                        <h2 class="card-title mb-0"><?php echo number_format($activityCount24h); ?></h2>
                        <small class="text-muted">audit log entries</small>
                    </div>
                    <div class="fs-1 text-success opacity-25">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-transparent border-success">
                <a href="/admin/activity" class="text-success text-decoration-none small">
                    View Activity Log <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- 🔧 Migrations Card -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card border-warning h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="card-subtitle mb-2 text-warning">Pending Migrations</h6>
                        <h2 class="card-title mb-0"><?php echo $pendingMigrations; ?></h2>
                        <small class="text-muted"><?php echo count($appliedMigrations); ?> applied</small>
                    </div>
                    <div class="fs-1 text-warning opacity-25">
                        <i class="fa-solid fa-database"></i>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-transparent border-warning">
                <a href="/admin/migrations" class="text-warning text-decoration-none small">
                    Run Migrations <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- 🖥️ System Information -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fa-solid fa-server me-2"></i>System Information</h5>
    </div>
    <div class="card-body">
        <div class="portal-data-list">
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold">Environment</div>
                <div class="col-12 col-md-8">
                    <span class="badge bg-<?php echo ($portalEnv === 'production') ? 'success' : (($portalEnv === 'dev') ? 'warning' : 'info'); ?>">
                        <?php echo htmlspecialchars($portalEnv, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
            </div>
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold">PHP Version</div>
                <div class="col-12 col-md-8"><?php echo htmlspecialchars($phpVersion, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold">MySQL Version</div>
                <div class="col-12 col-md-8"><?php echo htmlspecialchars($dbVersion, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold">Portal Version</div>
                <div class="col-12 col-md-8"><?php echo htmlspecialchars(App::version(), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold">Server Time</div>
                <div class="col-12 col-md-8"><?php echo htmlspecialchars(date('Y-m-d H:i:s T'), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- 🔗 Quick Links -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><i class="fa-solid fa-link me-2"></i>Admin Quick Links</h5>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/errors" class="btn btn-outline-danger w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-triangle-exclamation fa-lg"></i>
                    <span class="small">Error Log</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/activity" class="btn btn-outline-success w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-clock-rotate-left fa-lg"></i>
                    <span class="small">Activity Log</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/users" class="btn btn-outline-primary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-users fa-lg"></i>
                    <span class="small">Users</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/migrations" class="btn btn-outline-warning w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-database fa-lg"></i>
                    <span class="small">Migrations</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/settings" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-gear fa-lg"></i>
                    <span class="small">Settings</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/integrations" class="btn btn-outline-info w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-plug-circle-check fa-lg"></i>
                    <span class="small">Integrations</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/captcha" class="btn btn-outline-warning w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-robot fa-lg"></i>
                    <span class="small">Captcha</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/release-notes" class="btn btn-outline-info w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-clipboard-list fa-lg"></i>
                    <span class="small">Release Notes</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/email-templates" class="btn btn-outline-primary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-envelope fa-lg"></i>
                    <span class="small">Email Templates</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/maintenance/retention" class="btn btn-outline-warning w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-broom fa-lg"></i>
                    <span class="small">Retention</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/api-docs" class="btn btn-outline-info w-100 d-flex flex-column align-items-center gap-1 py-3" target="_blank" rel="noopener">
                    <i class="fa-solid fa-book fa-lg"></i>
                    <span class="small">API Docs</span>
                </a>
            </div>
            <?php if (App::isUmbrellaAdmin() === true): ?>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/sites" class="btn btn-outline-dark w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-sitemap fa-lg"></i>
                    <span class="small">Sites</span>
                </a>
            </div>
            <?php endif; ?>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/settings" class="btn btn-outline-info w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-sliders fa-lg"></i>
                    <span class="small">Old Settings</span>
                </a>
            </div>
        </div>
    </div>
</div>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
