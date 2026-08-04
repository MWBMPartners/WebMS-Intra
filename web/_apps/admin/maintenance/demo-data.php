<?php
// Path: public_html/admin/maintenance/demo-data.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Demo Data Management 🧪
 * -----------------------------------------------------------------------------
 * Loads / wipes a realistic demo dataset for training and demonstration.
 * Demo rows carry an isDemo or known-id sentinel so wipe targets only the
 * demo data; real records are untouched.
 *
 * Gated by `portal.demo_mode.enabled` (default 0). Must be enabled in
 * /admin/settings before this page is reachable.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/242
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

$demoEnabled = (string) (App::settings()['portal']['demo_mode']['enabled'] ?? '0') === '1';

$flash = '';
$flashType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && Auth::verifyCsrf($_POST['csrf_token'] ?? '') === true
    && $demoEnabled === true
) {
    $action = (string) ($_POST['action'] ?? '');
    $db = App::db();
    $demoSqlPath = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_sql'
                 . DIRECTORY_SEPARATOR . 'demo_data.sql';

    if ($action === 'load') {
        if (is_readable($demoSqlPath) === false) {
            $flash = 'demo_data.sql not found. Expected at ' . $demoSqlPath;
            $flashType = 'danger';
        } else {
            $sql = (string) file_get_contents($demoSqlPath);
            try {
                if ($db->multi_query($sql)) {
                    do {
                        if ($r = $db->store_result()) {
                            $r->free();
                        }
                    } while ($db->more_results() && $db->next_result());
                    $flash = 'Demo data loaded.';
                    $flashType = 'success';
                } else {
                    $flash = 'Load failed: ' . $db->error;
                    $flashType = 'danger';
                }
            } catch (\Throwable $e) {
                $flash = 'Load failed: ' . $e->getMessage();
                $flashType = 'danger';
            }
        }
    } elseif ($action === 'wipe') {
        try {
            // 🪦 Wipe demo rows. Demo data is tagged with isDemo column where
            //    present, OR by user IDs >= 9000. Conservative: only delete
            //    where we have a clear marker.
            $db->begin_transaction();
            $db->query('DELETE FROM tblAnnouncements WHERE createdByID >= 9000');
            $db->query('DELETE FROM tblEvents WHERE createdByID >= 9000');
            $db->query('DELETE FROM tblExpenseClaims WHERE userID >= 9000');
            $db->query('DELETE FROM tblTasks WHERE assignedToID >= 9000');
            $db->query('DELETE FROM tblUsers WHERE userID >= 9000');
            $db->commit();
            $flash = 'Demo data wiped.';
            $flashType = 'success';
        } catch (\Throwable $e) {
            $db->rollback();
            $flash = 'Wipe failed: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
}

$pageTitle   = 'Demo Data';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Maintenance' => '/admin/maintenance', 'Demo Data' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-flask me-2"></i>Demo Data</h1>
        <p class="text-secondary mb-0">Load a realistic demo dataset for training, then wipe it cleanly.</p>
    </div>
    <a href="/admin/maintenance" class="btn btn-outline-secondary btn-sm">&larr; Maintenance</a>
</div>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<?php if ($demoEnabled === false): ?>
    <div class="alert alert-warning">
        Demo mode is disabled. Enable <code>portal.demo_mode.enabled = 1</code> in
        <a href="/admin/settings">/admin/settings</a> before using this page.
    </div>
<?php else: ?>
    <div class="card mb-3 border-warning">
        <div class="card-body">
            <h2 class="h5"><i class="fa-solid fa-triangle-exclamation me-1 text-warning"></i>This affects the LIVE database</h2>
            <p>Demo data INSERTs into the same tables your real data lives in. The wipe action deletes by sentinel (userID ≥ 9000), so real records are preserved — but always take a backup first.</p>
            <a href="/admin/maintenance/backup" class="btn btn-outline-primary btn-sm">Take a snapshot first</a>
        </div>
    </div>
    <div class="row g-3">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h5">Load demo data</h2>
                    <p class="small text-muted">INSERTs ~25 users (IDs 9000-9024), demo announcements, calendar events, expenses, tasks. Idempotent: skips rows already present.</p>
                    <form method="post" data-confirm="Load demo data into the live database?">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="load">
                        <button type="submit" class="btn btn-primary">Load demo data</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100 border-danger">
                <div class="card-body">
                    <h2 class="h5 text-danger">Wipe demo data</h2>
                    <p class="small text-muted">DELETE rows where userID ≥ 9000 (or equivalent sentinel). Real records untouched.</p>
                    <form method="post" data-confirm="Delete all demo rows from the live database? Real data with userID < 9000 will be preserved." data-confirm-destructive="true">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="wipe">
                        <button type="submit" class="btn btn-outline-danger">Wipe demo data</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
