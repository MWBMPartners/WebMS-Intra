<?php
// Path: public_html/admin/maintenance/retention.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Audit-Log Retention Sweeper 🧹
 * -----------------------------------------------------------------------------
 * Hard-deletes rows from tblActivityLogs + tblErrors older than the configured
 * retention window. Without this sweeper, both tables grow forever — disk
 * usage on shared hosting becomes a real problem after a year or two.
 *
 * Two modes:
 *
 *   1. Web UI (this file) — admin clicks a button, sees a per-table preview
 *      of how many rows would be deleted, then confirms.
 *
 *   2. CRON-style endpoint at /admin/maintenance/retention?cron=1&token=…
 *      — for scheduled execution. Auth is via the
 *      `maintenance.cronToken` setting (matching token in the query string)
 *      instead of session auth, so a wget/curl from cron works.
 *
 * Settings:
 *
 *   audit.retentionDays        (default 365) — activity logs
 *   errors.retentionDays       (default 365) — error logs
 *   maintenance.cronToken      ('' by default — empty value disables cron mode)
 *
 * Deletions are hard (no soft-delete column on these tables). Output is a
 * JSON document under cron mode and an HTML report under web mode.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;

// -----------------------------------------------------------------------------
// 🤖 Cron mode — no session auth, token-gated
// -----------------------------------------------------------------------------
$isCron = isset($_GET['cron']) === true && $_GET['cron'] === '1';
if ($isCron === true) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $configured = (string) (App::settings('maintenance.cronToken') ?? '');
    $provided   = (string) ($_GET['token'] ?? '');

    if ($configured === '' || hash_equals($configured, $provided) === false) {
        http_response_code(403);
        echo json_encode(['status' => 'forbidden']);
        exit();
    }

    $result = run_retention_sweep();
    Logger::activity('AuditRetentionSweep', 'Cron sweep deleted ' . $result['totalDeleted'] . ' rows');
    echo json_encode(['status' => 'ok'] + $result);
    exit();
}

// -----------------------------------------------------------------------------
// 🛡️ Web mode — admin-only
// -----------------------------------------------------------------------------
$pageTitle   = 'Audit Retention Sweeper';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Maintenance' => '', 'Retention' => ''];

Auth::ensureSession();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$activityDays = (int) (App::settings('audit.retentionDays')  ?? '365');
$errorDays    = (int) (App::settings('errors.retentionDays') ?? '365');
if ($activityDays < 1) { $activityDays = 365; }
if ($errorDays    < 1) { $errorDays    = 365; }

$flashMsg  = '';
$flashType = '';

// -----------------------------------------------------------------------------
// 🚀 Execute on POST (with CSRF)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $flashMsg  = 'Invalid or expired form token. Please try again.';
        $flashType = 'danger';
    } else {
        $result = run_retention_sweep();
        Logger::activity('AuditRetentionSweep', 'Admin sweep deleted ' . $result['totalDeleted'] . ' rows');
        $flashMsg  = 'Sweep complete. Deleted ' . $result['activityDeleted']
                   . ' activity log row(s) and ' . $result['errorsDeleted'] . ' error row(s).';
        $flashType = 'success';
    }
}

// -----------------------------------------------------------------------------
// 📊 Preview counts (what WOULD be deleted right now)
// -----------------------------------------------------------------------------
$preview = preview_retention_counts($activityDays, $errorDays);

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-broom me-2"></i>Audit Retention Sweeper</h1>
        <p class="text-secondary mb-0">Hard-delete activity logs and error rows older than the configured window.</p>
    </div>
    <a href="/admin" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back to Admin
    </a>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h6"><i class="fa-solid fa-list-check me-1"></i>Activity Logs (<code>tblActivityLogs</code>)</h2>
                <p class="text-muted small mb-2">Retention: <strong><?php echo $activityDays; ?> days</strong> (setting: <code>audit.retentionDays</code>)</p>
                <p class="mb-0">Rows that would be deleted now: <strong><?php echo number_format($preview['activity']); ?></strong></p>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h6"><i class="fa-solid fa-triangle-exclamation me-1"></i>Error Log (<code>tblErrors</code>)</h2>
                <p class="text-muted small mb-2">Retention: <strong><?php echo $errorDays; ?> days</strong> (setting: <code>errors.retentionDays</code>)</p>
                <p class="mb-0">Rows that would be deleted now: <strong><?php echo number_format($preview['errors']); ?></strong></p>
            </div>
        </div>
    </div>
</div>

<form method="post" action="/admin/maintenance/retention" onsubmit="return confirm('This will hard-delete <?php echo number_format($preview['activity'] + $preview['errors']); ?> rows. Continue?');">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <button type="submit" class="btn btn-warning" <?php echo ($preview['activity'] + $preview['errors']) === 0 ? 'disabled' : ''; ?>>
        <i class="fa-solid fa-broom me-1"></i> Run Sweep Now
    </button>
    <a href="/settings" class="btn btn-outline-secondary">Adjust retention settings</a>
</form>

<div class="card mt-4 border-0 bg-body-tertiary">
    <div class="card-body">
        <h2 class="h6 mb-2"><i class="fa-solid fa-clock me-1"></i>Cron-style execution</h2>
        <p class="small mb-2">
            For scheduled execution (every night, say), set
            <code>maintenance.cronToken</code> in Settings to a long random string, then have your
            cron / scheduled task hit:
        </p>
        <pre class="small mb-0"><code>curl -s "https://&lt;your-portal&gt;/admin/maintenance/retention?cron=1&amp;token=&lt;TOKEN&gt;"</code></pre>
        <p class="small text-muted mb-0 mt-2">
            The token is checked with <code>hash_equals</code> (constant-time compare). Empty token disables cron mode.
        </p>
    </div>
</div>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';

// -----------------------------------------------------------------------------
// 🔧 Helpers (defined at the bottom so they're available regardless of
//     which entry path executed — file is procedural)
// -----------------------------------------------------------------------------

/**
 * Count rows that WOULD be deleted at the current window.
 *
 * @return array{activity:int,errors:int}
 */
function preview_retention_counts(int $activityDays, int $errorDays): array
{
    $db = App::db();
    $out = ['activity' => 0, 'errors' => 0];

    $stmt = $db->prepare(
        'SELECT COUNT(*) AS cnt FROM tblActivityLogs '
        . 'WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $activityDays);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $out['activity'] = (int) ($row['cnt'] ?? 0);
        $stmt->close();
    }

    $stmt = $db->prepare(
        'SELECT COUNT(*) AS cnt FROM tblErrors '
        . 'WHERE createdAt < DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $errorDays);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $out['errors'] = (int) ($row['cnt'] ?? 0);
        $stmt->close();
    }

    return $out;
}

/**
 * Perform the actual delete. Returns counts per table.
 *
 * @return array{activityDeleted:int,errorsDeleted:int,totalDeleted:int}
 */
function run_retention_sweep(): array
{
    $activityDays = (int) (App::settings('audit.retentionDays')  ?? '365');
    $errorDays    = (int) (App::settings('errors.retentionDays') ?? '365');
    if ($activityDays < 1) { $activityDays = 365; }
    if ($errorDays    < 1) { $errorDays    = 365; }

    $db = App::db();
    $activityDeleted = 0;
    $errorsDeleted   = 0;

    $stmt = $db->prepare(
        'DELETE FROM tblActivityLogs WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $activityDays);
        $stmt->execute();
        $activityDeleted = (int) $stmt->affected_rows;
        $stmt->close();
    }

    $stmt = $db->prepare(
        'DELETE FROM tblErrors WHERE createdAt < DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $errorDays);
        $stmt->execute();
        $errorsDeleted = (int) $stmt->affected_rows;
        $stmt->close();
    }

    return [
        'activityDeleted' => $activityDeleted,
        'errorsDeleted'   => $errorsDeleted,
        'totalDeleted'    => $activityDeleted + $errorsDeleted,
    ];
}
