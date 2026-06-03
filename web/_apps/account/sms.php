<?php
// Path: public_html/account/sms.php
/**
 * Account — user manages their phone number + category opt-ins.
 *
 * @package   Portal\Account
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/272
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;
use Portal\Core\Sms;

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
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_number') {
        $raw = trim((string) ($_POST['phoneNumber'] ?? ''));
        $num = Sms::normaliseNumber($raw);
        if ($num === '') {
            $_SESSION['flash_msg']  = 'Invalid phone number.';
            $_SESSION['flash_type'] = 'danger';
        } elseif (Sms::startVerification($siteId, $userId, $num) === true) {
            $_SESSION['flash_msg']  = 'Verification code sent to ' . $num . ' — enter it below.';
            $_SESSION['flash_type'] = 'info';
        } else {
            $_SESSION['flash_msg']  = 'Could not send verification code — admin may need to enable SMS.';
            $_SESSION['flash_type'] = 'danger';
        }
    } elseif ($action === 'save_categories') {
        $cats = [];
        foreach (Sms::CATEGORIES as $c) {
            if (isset($_POST['cat_' . $c]) === true) {
                $cats[] = $c;
            }
        }
        $csv = implode(',', $cats);
        $stmt = $db->prepare('UPDATE tblUserSmsPreference SET categories = ? WHERE siteID = ? AND userID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('sii', $csv, $siteId, $userId);
            $stmt->execute();
            $stmt->close();
        }
        $_SESSION['flash_msg']  = 'Notification categories updated.';
        $_SESSION['flash_type'] = 'success';
    }
    header('Location: /account/sms');
    exit();
}

$pref = null;
$stmt = $db->prepare('SELECT phoneNumber, isVerified, categories, verificationExpires FROM tblUserSmsPreference WHERE siteID = ? AND userID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('ii', $siteId, $userId);
    $stmt->execute();
    $pref = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();
$currentCats = $pref !== null ? array_filter(array_map('trim', explode(',', (string) $pref['categories']))) : ['critical_alerts'];

$pageTitle   = 'SMS notifications';
$pageSection = 'account';
$breadcrumbs = ['Dashboard' => '/', 'My Account' => '/account', 'SMS' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-comment-sms me-2"></i>SMS notifications</h1>

<div class="card mb-3">
    <div class="card-header"><strong>Phone number</strong></div>
    <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="save_number">
            <div class="col-md-6">
                <label class="form-label small">Mobile number</label>
                <input type="tel" class="form-control" name="phoneNumber" value="<?php echo htmlspecialchars((string) ($pref['phoneNumber'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="+44 7700 000000">
            </div>
            <div class="col-md-3">
                <?php if ($pref !== null && (int) $pref['isVerified'] === 1): ?>
                    <span class="badge bg-success"><i class="fa-solid fa-check-circle me-1"></i>Verified</span>
                <?php elseif ($pref !== null): ?>
                    <span class="badge bg-warning">Unverified</span>
                <?php endif; ?>
            </div>
            <div class="col-md-3">
                <button class="btn btn-primary w-100" type="submit">Send verification code</button>
            </div>
        </form>
        <?php if ($pref !== null && (int) $pref['isVerified'] === 0): ?>
            <form method="post" action="/account/sms/verify" class="row g-2 align-items-end mt-3 border-top pt-3">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="col-md-6">
                    <label class="form-label small">Enter 6-digit code</label>
                    <input type="text" class="form-control" name="code" pattern="[0-9]{6}" maxlength="6" required>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-outline-primary w-100">Verify</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($pref !== null && (int) $pref['isVerified'] === 1): ?>
    <div class="card">
        <div class="card-header"><strong>Categories</strong></div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="save_categories">
                <?php foreach (Sms::CATEGORIES as $c): ?>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="cat_<?php echo $c; ?>" name="cat_<?php echo $c; ?>" value="1" <?php echo in_array($c, $currentCats, true) === true ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="cat_<?php echo $c; ?>"><?php echo htmlspecialchars(str_replace('_', ' ', $c), ENT_QUOTES, 'UTF-8'); ?></label>
                    </div>
                <?php endforeach; ?>
                <p class="small text-muted mt-2">Critical alerts ignore quiet hours; other categories defer during your Sabbath window.</p>
                <button class="btn btn-primary btn-sm mt-2" type="submit">Save preferences</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
