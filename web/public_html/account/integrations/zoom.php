<?php
// Path: public_html/account/integrations/zoom.php
/**
 * User — connect / disconnect personal Zoom account (only when zoom.mode = user).
 *
 * @package   Portal\Account
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/274
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db      = App::db();
$siteId  = Site::id();
$userId  = (int) ($_SESSION['user_id'] ?? 0);
$settings = App::settings()['zoom'] ?? [];
$mode    = (string) ($settings['mode'] ?? 'org');
$enabled = (string) ($settings['enabled'] ?? '0') === '1';

$account = null;
$stmt = $db->prepare('SELECT accountID, zoomAccountEmail, accessTokenExpiresAt, updatedAt FROM tblZoomAccount WHERE siteID = ? AND userID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('ii', $siteId, $userId);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Zoom — My Account';
$pageSection = 'account';
$breadcrumbs = ['Dashboard' => '/', 'My Account' => '/account', 'Zoom' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-video me-2"></i>Zoom — My Account</h1>

<?php if ($enabled === false): ?>
    <div class="alert alert-info">Zoom integration is not enabled on this site.</div>
<?php elseif ($mode !== 'user'): ?>
    <div class="alert alert-info">This site uses a single org-level Zoom account — managed by an admin.</div>
<?php elseif ($account !== null): ?>
    <div class="card">
        <div class="card-body">
            <p class="mb-2"><i class="fa-solid fa-check-circle text-success me-1"></i>Connected: <strong><?php echo htmlspecialchars((string) ($account['zoomAccountEmail'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></p>
            <p class="small text-muted">Last refresh: <?php echo htmlspecialchars((string) $account['updatedAt'], ENT_QUOTES, 'UTF-8'); ?></p>
            <form method="post" action="/admin/integrations/zoom/disconnect" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="accountID" value="<?php echo (int) $account['accountID']; ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Disconnect your Zoom account?">Disconnect</button>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <p>Connect your Zoom account to create meetings from calendar events.</p>
            <a href="/admin/integrations/zoom/connect?mode=user" class="btn btn-primary"><i class="fa-solid fa-link me-1"></i>Connect Zoom</a>
        </div>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
