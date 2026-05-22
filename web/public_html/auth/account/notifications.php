<?php
// Path: public_html/auth/account/notifications.php
/**
 * -----------------------------------------------------------------------------
 * Account — Notification preferences UI 📬
 * -----------------------------------------------------------------------------
 * Lets each user opt in/out of the various notification channels:
 *
 *   • Weekly email digest
 *   • Per-category event reminders (calendar)
 *   • Expense workflow updates (approver decisions, treasury, withdrawal)
 *   • Prayer-request moderation (for moderators)
 *   • Announcement notifications
 *
 * Stored in tblUsers.notifyPrefs (JSON column from migration 026).
 *
 * Delivery gate: when notifications.deliveryReady === 'false' the page
 * shows a banner explaining that preferences are saved but emails aren't
 * being sent yet. Admins flip the setting once Mailer + the digest cron
 * are wired up.
 *
 * @package   Portal\Auth
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();

$pageTitle   = 'Notification preferences';
$pageSection = 'auth';
$breadcrumbs = ['Dashboard' => '/', 'Account' => '/account', 'Notifications' => ''];

$user   = App::user();
$userId = (int) ($user['userID'] ?? 0);

// 🚦 Delivery gate — informational only; we still save preferences.
$deliveryReady = (App::settings('notifications.deliveryReady') ?? 'false') === 'true';
$siteDigestOn  = (App::settings('notifications.digestEnabled')  ?? 'true')  === 'true';
$digestDay     = (string) (App::settings('notifications.digestDay') ?? 'monday');

// 📋 Read the user's current prefs JSON
$prefsJson = '';
$stmt = $mysqli->prepare('SELECT notifyPrefs FROM tblUsers WHERE userID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row !== null && $row['notifyPrefs'] !== null) {
        $prefsJson = (string) $row['notifyPrefs'];
    }
    $stmt->close();
}
$prefs = json_decode($prefsJson, true);
if (is_array($prefs) === false) {
    $prefs = [];
}

// 🎛️ Defaults — opt-in for digest, opt-in for transactional updates that affect the user directly.
$defaults = [
    'emailDigest'           => true,
    'eventReminders'        => true,
    'eventRsvpConfirmation' => true,
    'expenseStatusUpdates'  => true,
    'expenseApproverNudges' => true,
    'announcementsNew'      => true,
    'prayerModeration'      => true,
    'accountSecurity'       => true,
];
foreach ($defaults as $k => $v) {
    if (array_key_exists($k, $prefs) === false) {
        $prefs[$k] = $v;
    }
}

$flashMsg  = $_SESSION['notifications_flash'] ?? '';
$flashType = $_SESSION['notifications_flash_type'] ?? '';
unset($_SESSION['notifications_flash'], $_SESSION['notifications_flash_type']);

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

/**
 * 🎨 Helper — render a single switch row
 */
$switchRow = static function (string $key, string $label, string $helpText) use ($prefs): string {
    $checked = (bool) ($prefs[$key] ?? false) === true ? 'checked' : '';
    $id = 'np-' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
    return '<div class="form-check form-switch py-2 border-bottom">'
         . '<input class="form-check-input" type="checkbox" role="switch" '
         . 'name="prefs[' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . ']" value="1" '
         . 'id="' . $id . '" ' . $checked . '>'
         . '<label class="form-check-label" for="' . $id . '">'
         . '<strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</strong>'
         . '<div class="small text-muted">' . htmlspecialchars($helpText, ENT_QUOTES, 'UTF-8') . '</div>'
         . '</label></div>';
};
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-bell me-2"></i>Notification preferences</h1>
    <a href="/account" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back to Account
    </a>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($deliveryReady === false): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-1"></i>
        <strong>Notifications are not yet being sent on this site.</strong>
        Your preferences below are saved and will take effect once delivery is enabled.
        Site admins control this via the <code>notifications.deliveryReady</code> setting.
    </div>
<?php endif; ?>

<form method="post" action="/account/notifications/save">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

    <div class="card shadow-sm mb-3">
        <div class="card-header"><h2 class="h6 mb-0">Email digest</h2></div>
        <div class="card-body">
            <?php if ($siteDigestOn === false): ?>
                <p class="small text-muted mb-2">
                    The site admin has disabled email digests for this site
                    (<code>notifications.digestEnabled</code>). Your preference below is
                    remembered for when it's re-enabled.
                </p>
            <?php else: ?>
                <p class="small text-muted mb-2">
                    Weekly summary of upcoming events, pending tasks, and any actions awaiting you.
                    Sent on <strong><?php echo htmlspecialchars(ucfirst($digestDay), ENT_QUOTES, 'UTF-8'); ?></strong>.
                </p>
            <?php endif; ?>
            <?php echo $switchRow('emailDigest', 'Send me the weekly digest', 'One email per week, summarising the things that matter to you.'); ?>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header"><h2 class="h6 mb-0">Events &amp; Calendar</h2></div>
        <div class="card-body">
            <?php echo $switchRow('eventReminders',        'Event reminders',                'Get notified ahead of events you have RSVP\'d to.'); ?>
            <?php echo $switchRow('eventRsvpConfirmation', 'RSVP confirmations',            'Confirmation email when you RSVP to an event.'); ?>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header"><h2 class="h6 mb-0">Expenses</h2></div>
        <div class="card-body">
            <?php echo $switchRow('expenseStatusUpdates',  'Status updates on my claims', 'When an approver decides on a claim you submitted.'); ?>
            <?php echo $switchRow('expenseApproverNudges', 'Approver nudges',             'For approvers: when a claim is waiting on you.'); ?>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header"><h2 class="h6 mb-0">Other</h2></div>
        <div class="card-body">
            <?php echo $switchRow('announcementsNew', 'New announcements',           'Site-wide notices when admins post a new announcement.'); ?>
            <?php echo $switchRow('prayerModeration', 'Prayer-request moderation', 'For moderators: new submissions awaiting review.'); ?>
            <?php echo $switchRow('accountSecurity',  'Security alerts',           'Sign-ins from new devices, password changes, 2FA enrolments. Strongly recommended — leave on.'); ?>
        </div>
    </div>

    <button type="submit" class="btn btn-success">
        <i class="fa-solid fa-save me-1"></i> Save preferences
    </button>
    <a href="/account" class="btn btn-outline-secondary">Cancel</a>
</form>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
