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
 * Two modes:
 *
 *   1. Web UI — admin visits /admin/maintenance/backup-check and sees the
 *      current state inline.
 *
 *   2. Cron-style endpoint — /admin/maintenance/backup-check?cron=1&token=…
 *      matched against `maintenance.cronToken`. Returns JSON. Designed for
 *      a daily wget/curl from DreamHost's cron scheduler.
 *
 * Settings:
 *
 *   portal.backups.max_age_hours       (default 36)
 *   portal.backups.alert_recipients    (default '' — comma-separated emails)
 *   maintenance.cronToken              (same token as retention.php)
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
use Portal\Core\DbBackup;
use Portal\Core\Mailer;

$cronMode = isset($_GET['cron']) === true && $_GET['cron'] === '1';

// 🔐 Auth
if ($cronMode === true) {
    $expected = (string) (App::settings()['maintenance']['cronToken'] ?? '');
    $supplied = (string) ($_GET['token'] ?? '');
    if ($expected === '' || hash_equals($expected, $supplied) === false) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'invalid_token']);
        exit();
    }
} else {
    Auth::ensureSession();
    Auth::requireLogin();
    if (App::isAdmin() === false) {
        http_response_code(403);
        exit('Forbidden');
    }
}

// 📦 Probe snapshots
$db        = App::db();
$backup    = new DbBackup($db);
$snapshots = $backup->listSnapshots();

$thresholdHours = (int) (App::settings()['portal']['backups']['max_age_hours'] ?? 36);
$recipientsRaw  = (string) (App::settings()['portal']['backups']['alert_recipients'] ?? '');
$recipients     = array_filter(array_map('trim', explode(',', $recipientsRaw)));

$mostRecent = $snapshots[0] ?? null;
$ageHours   = null;
$state      = 'ok';
$message    = '';

if ($mostRecent === null) {
    $state   = 'critical';
    $message = 'No snapshots found in web/_backups/.';
} else {
    $createdAt = strtotime((string) $mostRecent['created_at']);
    if ($createdAt === false) {
        $state   = 'critical';
        $message = 'Most recent snapshot has unparseable created_at timestamp.';
    } else {
        $ageHours = (int) round((time() - $createdAt) / 3600);
        if ($ageHours > $thresholdHours) {
            $state   = 'stale';
            $message = sprintf(
                'Most recent backup is %d hours old (threshold: %d hours).',
                $ageHours,
                $thresholdHours
            );
        } else {
            $state   = 'ok';
            $message = sprintf(
                'Most recent backup is %d hours old (within %d-hour threshold).',
                $ageHours,
                $thresholdHours
            );
        }
    }
}

// 🚨 Alert dispatch (only if stale or critical AND we have recipients AND cron mode)
//    Web-mode visits just show the status; alerts fire only from the
//    scheduled cron call to avoid double-emailing the admin who's
//    actively looking at the page.
$alertsSent = 0;
if ($cronMode === true && $state !== 'ok' && count($recipients) > 0) {
    $portalName = (string) (App::settings()['site']['name'] ?? 'WebMS Intra');
    $subject    = sprintf('[%s] Backup freshness alert: %s', $portalName, $state);
    $body       = $message . "\n\n"
                . sprintf("Snapshot count: %d\n", count($snapshots))
                . sprintf("Threshold: %d hours\n", $thresholdHours)
                . "\nReview: " . (string) (App::settings()['site']['url'] ?? '')
                . "/admin/maintenance/backup-check\n";
    foreach ($recipients as $to) {
        try {
            if (Mailer::send($to, $subject, $body) === true) {
                $alertsSent++;
            }
        } catch (\Throwable $e) {
            // 🛡️ Mail-send failure is non-fatal — we still return the
            //    status JSON so the cron can decide what to do next.
        }
    }
}

// 🖼️ Render
if ($cronMode === true) {
    header('Content-Type: application/json');
    echo json_encode([
        'state'           => $state,
        'message'         => $message,
        'age_hours'       => $ageHours,
        'threshold_hours' => $thresholdHours,
        'snapshot_count'  => count($snapshots),
        'recipients'      => count($recipients),
        'alerts_sent'     => $alertsSent,
        'checked_at'      => date('c'),
    ]);
    exit();
}

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
        <pre class="bg-body-tertiary p-2 rounded"><code>0 9 * * * curl -fsS "https://<?php echo htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'portal', ENT_QUOTES, 'UTF-8'); ?>/admin/maintenance/backup-check?cron=1&amp;token=YOUR_TOKEN" &gt; /dev/null</code></pre>
        <p class="small text-muted mb-0">
            Threshold: <strong><?php echo $thresholdHours; ?> hours</strong> (setting: <code>portal.backups.max_age_hours</code>)<br>
            Recipients: <strong><?php echo count($recipients); ?> address(es)</strong> (setting: <code>portal.backups.alert_recipients</code>)
        </p>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
