<?php
// Path: public_html/admin/maintenance/backup-check.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Backup Freshness Check + Alert 🔔
 * -----------------------------------------------------------------------------
 * Reads the web/_backups/ directory via DbBackup::listSnapshots(), determines
 * the age of the most-recent snapshot, and alerts (email) the configured
 * admin recipients if that age exceeds the `portal.backups.max_age_hours`
 * threshold.
 *
 * This file is the staff page only: an administrator visits
 * /admin/maintenance/backup-check and sees the current state inline. Viewing
 * it never sends an email.
 *
 * The daily scheduled check, which also sends the alert email, is at
 * /cron/backup-check?token=… (web/_apps/cron/backup-check.php). Until
 * 14 September 2026 it was a "?cron=1" mode of this page; the comment above
 * the sign-in check below says why it moved.
 *
 * Settings:
 *
 *   portal.backups.max_age_hours       (default 36)
 *   portal.backups.alert_recipients    (default '' — comma-separated emails)
 *   maintenance.cronToken              (used by /cron/backup-check; the same
 *                                       token as /cron/retention-sweep)
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/142
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

// 🔐 Staff only. The Router already sends a signed-out visitor to sign in,
//    because this address is seeded as protected; these checks stay as well so
//    the page is still safe if it is ever reached some other way.
//
//    This page used to have a "?cron=1&token=" mode for the daily scheduled
//    check, handled here before any sign-in, which also sent the alert email.
//    It was removed on 14 September 2026 (issue #497): with the Router
//    enforcing sign-in, a scheduler with a token and no session would only ever
//    be redirected, and no alert would be sent. The job, and the alert email,
//    are now at /cron/backup-check.
Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

// 📦 The verdict is shared with /cron/backup-check, so this page and the alert
//    always agree about whether the backups are fresh. Viewing this page never
//    sends an email.
require_once PORTAL_APPS . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . '_backup-freshness.php';
$check          = backup_freshness_check();
$snapshots      = $check['snapshots'];
$thresholdHours = $check['thresholdHours'];
$recipients     = $check['recipients'];
$state          = $check['state'];
$message        = $check['message'];

$pageTitle   = 'Backup Freshness Check';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Maintenance' => '/admin/maintenance', 'Backups' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

$badgeClass = match ($state) {
    'ok'       => 'success',
    'stale'    => 'warning',
    'critical' => 'danger',
    default    => 'secondary',
};
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-box-archive me-2"></i>Backup Freshness</h1>
        <p class="text-secondary mb-0">Current state of the JSON snapshot store in <code>web/_backups/</code>.</p>
    </div>
    <a href="/admin/maintenance" class="btn btn-outline-secondary btn-sm">&larr; Maintenance</a>
</div>

<div class="alert alert-<?php echo $badgeClass; ?>">
    <strong><?php echo strtoupper($state); ?></strong> — <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5">Snapshots on disk</h2>
        <p class="text-muted small">Showing the 10 most recent.</p>
        <?php if (count($snapshots) === 0): ?>
            <p class="mb-0">No snapshots present.</p>
        <?php else: ?>
            <div class="portal-data-list">
                <?php foreach (array_slice($snapshots, 0, 10) as $snap): ?>
                    <div class="row py-2 border-bottom">
                        <div class="col-md-4"><code><?php echo htmlspecialchars($snap['name'], ENT_QUOTES, 'UTF-8'); ?></code></div>
                        <div class="col-md-3"><?php echo htmlspecialchars($snap['created_at'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-2"><?php echo (int) $snap['tables']; ?> tables</div>
                        <div class="col-md-3"><?php echo number_format((int) $snap['rows']); ?> rows</div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 class="h5">Cron configuration</h2>
        <p>To enable automated alerting, add the following to your cron schedule:</p>
        <pre class="bg-body-tertiary p-2 rounded"><code>0 9 * * * curl -fsS "https://<?php echo htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'portal', ENT_QUOTES, 'UTF-8'); ?>/cron/backup-check?token=YOUR_TOKEN" &gt; /dev/null</code></pre>
        <p class="small text-muted mb-2">
            Use the token saved in <code>maintenance.cronToken</code>. The old address, this page with <code>?cron=1</code> added,
            no longer works: this page now always asks for a sign-in, and no alert would be sent.
        </p>
        <p class="small text-muted mb-0">
            Threshold: <strong><?php echo $thresholdHours; ?> hours</strong> (setting: <code>portal.backups.max_age_hours</code>)<br>
            Recipients: <strong><?php echo count($recipients); ?> address(es)</strong> (setting: <code>portal.backups.alert_recipients</code>)
        </p>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
