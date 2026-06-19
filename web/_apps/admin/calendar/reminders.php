<?php
// Path: _apps/admin/calendar/reminders.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Event reminder cron status (#329)
 * -----------------------------------------------------------------------------
 * Shows the configured cron token + last-run timestamps + the URL to hit.
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/329
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Settings;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) { http_response_code(403); exit('Forbidden'); }

$enabled = (string) Settings::get('reminders.enabled', '1');
$token   = (string) Settings::get('reminders.cron_token', '');

// 📋 Recent runs (any reminderType).
$recent = [];
$result = $mysqli->query('SELECT eventID, reminderType, recipientCount, sentAt FROM tblEventReminderLog ORDER BY sentAt DESC LIMIT 30');
while ($r = $result->fetch_assoc()) { $recent[] = $r; }

// 📅 Upcoming events with pending reminders.
$upcoming = [];
$result = $mysqli->query(
    'SELECT e.eventID, e.eventName, e.startDateTime, '
    . '  (SELECT COUNT(*) FROM tblEventReminderLog WHERE eventID = e.eventID) AS sentCount '
    . 'FROM tblEvents e '
    . 'WHERE e.isDeleted = 0 AND e.status = "published" '
    . '  AND e.startDateTime BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 48 HOUR) '
    . 'ORDER BY e.startDateTime ASC LIMIT 20'
);
while ($r = $result->fetch_assoc()) { $upcoming[] = $r; }

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$cronUrl = $scheme . '://' . $host . '/cron/event-reminders?key=<YOUR_TOKEN>';

$pageTitle = 'Event Reminder Cron';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:880px;">
    <h1 class="h4 mb-3"><i class="fa-solid fa-bell me-2 text-primary"></i>Event Reminder Cron</h1>

    <div class="card mb-4">
        <div class="card-header">Configuration</div>
        <div class="card-body small">
            <p class="mb-1">Status: <span class="badge <?php echo $enabled === '1' ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $enabled === '1' ? 'Enabled' : 'Disabled'; ?></span> (toggle via <code>reminders.enabled</code> in <a href="/admin/settings">/admin/settings</a>)</p>
            <p class="mb-1">Cron URL: <code><?php echo htmlspecialchars($cronUrl, ENT_QUOTES, 'UTF-8'); ?></code></p>
            <p class="mb-0">Token: <?php echo $token === '' ? '<span class="text-danger">NOT SET — generate one in <a href="/admin/settings">/admin/settings</a> under <code>reminders.cron_token</code></span>' : '<span class="text-success">configured (' . strlen($token) . ' chars)</span>'; ?></p>
            <hr>
            <p class="text-muted mb-0">Schedule recommendation: every 15 minutes. DreamHost cron tab → <code>0,15,30,45 * * * * curl -s "<?php echo htmlspecialchars($cronUrl, ENT_QUOTES, 'UTF-8'); ?>" &gt; /dev/null</code></p>
        </div>
    </div>

    <h2 class="h6">Upcoming events (next 48h)</h2>
    <?php if (count($upcoming) === 0): ?>
        <p class="text-muted small">No events in the next 48 hours.</p>
    <?php else: ?>
        <div class="portal-data-list mb-4">
        <?php foreach ($upcoming as $u): ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main">
                    <strong><?php echo htmlspecialchars((string) $u['eventName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <div class="text-muted small"><?php echo htmlspecialchars(date('l j M, H:i', strtotime((string) $u['startDateTime'])), ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="portal-data-row-aside">
                    <span class="badge bg-info text-dark"><?php echo (int) $u['sentCount']; ?>/3 reminders sent</span>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2 class="h6">Recent sends</h2>
    <?php if (count($recent) === 0): ?>
        <p class="text-muted small">No reminder log entries yet.</p>
    <?php else: ?>
        <div class="portal-data-list">
        <?php foreach ($recent as $r): ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main small">
                    Event #<?php echo (int) $r['eventID']; ?>
                    <span class="badge bg-secondary ms-1"><?php echo htmlspecialchars((string) $r['reminderType'], ENT_QUOTES, 'UTF-8'); ?></span>
                    &middot; <?php echo (int) $r['recipientCount']; ?> recipient<?php echo (int) $r['recipientCount'] === 1 ? '' : 's'; ?>
                </div>
                <div class="portal-data-row-aside small text-muted"><?php echo htmlspecialchars((string) $r['sentAt'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
