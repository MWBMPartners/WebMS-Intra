<?php
// Path: public_html/calendar/account-feed.php
/**
 * Account-level page: user generates / regenerates / revokes their
 * personal iCal token + sees the subscription URL.
 *
 * @package   Portal\Calendar
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/271
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Ical;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$newToken = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf($_POST['csrf_token'] ?? '') === true) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'regenerate') {
        $newToken = Ical::ensureUserToken($userId);
    } elseif ($action === 'revoke') {
        $stmt = $db->prepare('UPDATE tblUsers SET calendarToken = NULL WHERE userID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

// Has any token been generated previously?
$hasToken = false;
$stmt = $db->prepare('SELECT calendarToken FROM tblUsers WHERE userID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $hasToken = $row !== null && $row['calendarToken'] !== null && $row['calendarToken'] !== '';
}

$pageTitle   = 'Calendar feed';
$pageSection = 'auth';
$breadcrumbs = ['Dashboard' => '/', 'Account' => '/account', 'Calendar feed' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
$host = $_SERVER['HTTP_HOST'] ?? 'portal';
?>

<h1 class="mb-3"><i class="fa-solid fa-calendar-arrow-down me-2"></i>Calendar feed</h1>
<p class="text-muted">Subscribe to a personal calendar feed in Google Calendar, Apple Calendar, or Outlook. The feed includes published portal events + your rota duties.</p>

<?php if ($newToken !== null): ?>
    <div class="alert alert-success">
        <strong>Your feed URL (save this — it won't be shown again):</strong>
        <div class="mt-2">
            <code style="word-break:break-all;">
                https://<?php echo htmlspecialchars($host, ENT_QUOTES, 'UTF-8'); ?>/calendar.ics?token=<?php echo htmlspecialchars($newToken, ENT_QUOTES, 'UTF-8'); ?>
            </code>
        </div>
        <div class="row g-3 mt-2">
            <div class="col-md-8">
                <p class="small mb-0">
                    <strong>To use it:</strong> copy the URL above, then in your calendar app add a new subscription / iCal feed using this URL.
                </p>
            </div>
            <div class="col-md-4 text-center">
                <?php
                $feedUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'portal') . '/calendar.ics?token=' . $newToken;
                ?>
                <p class="small text-muted mb-1">Scan on a phone:</p>
                <object data="/qr?content=<?php echo urlencode($feedUrl); ?>&size=160" type="image/svg+xml" style="max-width:160px"></object>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <h2 class="h5">Your feed</h2>
        <?php if ($hasToken === true && $newToken === null): ?>
            <p>You have a feed URL active. If you've lost it, regenerate (this will invalidate the old one).</p>
        <?php else: ?>
            <p>You don't have an active feed URL.</p>
        <?php endif; ?>
        <form method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="regenerate">
            <button type="submit" class="btn btn-primary btn-sm">
                <?php echo $hasToken === true ? 'Regenerate feed URL' : 'Generate feed URL'; ?>
            </button>
        </form>
        <?php if ($hasToken === true): ?>
            <form method="post" class="d-inline ms-2">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="revoke">
                <button type="submit" class="btn btn-outline-danger btn-sm"
                        data-confirm="Revoke your calendar feed URL? Any calendar app subscribed to it will stop syncing." data-confirm-destructive="true">
                    Revoke
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-3">
    <div class="card-body">
        <h2 class="h6">How to add the feed</h2>
        <ul class="small text-muted mb-0">
            <li><strong>Google Calendar:</strong> Settings → Add calendar → "From URL" → paste.</li>
            <li><strong>Apple Calendar (Mac):</strong> File → New Calendar Subscription → paste.</li>
            <li><strong>Outlook:</strong> Add calendar → Subscribe from web → paste.</li>
            <li>Calendars refresh every few hours — events show up shortly after they're added in the portal.</li>
        </ul>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
