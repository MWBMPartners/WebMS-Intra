<?php
// Path: public_html/account/notifications.php
/**
 * User — manage newsletter opt-in / opt-out.
 *
 * @package   Portal\Account
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/269
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        http_response_code(400);
        exit('Bad request');
    }
    $optIn = isset($_POST['optedIn']) === true ? 1 : 0;
    $fresh = bin2hex(random_bytes(20));
    $stmt = $db->prepare(
        'INSERT INTO tblNewsletterSubscription (siteID, userID, optedIn, unsubToken) VALUES (?, ?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE optedIn = VALUES(optedIn)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('iiis', $siteId, $userId, $optIn, $fresh);
        $stmt->execute();
        $stmt->close();
    }
    $_SESSION['flash_msg']  = 'Notification preferences saved.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /account/notifications');
    exit();
}

$sub = null;
$stmt = $db->prepare('SELECT optedIn FROM tblNewsletterSubscription WHERE siteID = ? AND userID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('ii', $siteId, $userId);
    $stmt->execute();
    $sub = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
$optedIn = $sub === null ? true : (int) $sub['optedIn'] === 1;

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Notification preferences';
$pageSection = 'account';
$breadcrumbs = ['Dashboard' => '/', 'My Account' => '/account', 'Notifications' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-bell me-2"></i>Notification preferences</h1>

<form method="post" class="card">
    <div class="card-body">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="form-check">
            <input type="checkbox" class="form-check-input" id="optedIn" name="optedIn" value="1" <?php echo $optedIn === true ? 'checked' : ''; ?>>
            <label class="form-check-label" for="optedIn">Receive newsletters from this portal</label>
        </div>
        <p class="small text-muted mt-2">You can also unsubscribe directly from the link at the bottom of any newsletter.</p>
        <button class="btn btn-primary btn-sm mt-2" type="submit">Save</button>
    </div>
</form>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
