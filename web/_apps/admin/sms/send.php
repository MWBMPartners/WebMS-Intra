<?php
// Path: public_html/admin/sms/send.php
/**
 * Admin — broadcast SMS to a category. GET shows compose form; POST
 * dispatches via Sms::sendCategory().
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/272
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\Sms;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        http_response_code(400);
        exit('Bad request');
    }
    $category = (string) ($_POST['category'] ?? 'emergency_comms');
    if (in_array($category, Sms::CATEGORIES, true) === false) {
        $category = 'emergency_comms';
    }
    $body = trim((string) ($_POST['body'] ?? ''));
    if ($body === '') {
        $_SESSION['flash_msg']  = 'Message body is required.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/sms/send');
        exit();
    }
    if (mb_strlen($body) > 480) {
        $body = mb_substr($body, 0, 480);
    }
    $result = Sms::sendCategory($siteId, $category, $body);
    Logger::activity('SmsBroadcast', 'Broadcast to ' . $category . ': ' . $result['sent'] . ' sent', $userId);
    $_SESSION['flash_msg']  = sprintf('Broadcast complete: %d sent, %d skipped.', (int) $result['sent'], (int) $result['skipped']);
    $_SESSION['flash_type'] = 'success';
    header('Location: /admin/sms');
    exit();
}

$csrf = Auth::csrfToken();

$pageTitle   = 'Broadcast SMS';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'SMS' => '/admin/sms', 'Broadcast' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><i class="fa-solid fa-bullhorn me-2"></i>Broadcast SMS</h1>
<p class="text-secondary">Sends to every verified, opted-in subscriber for the selected category. Sabbath quiet hours defer non-critical messages.</p>

<form method="post" class="card">
    <div class="card-body row g-3">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="col-md-4">
            <label class="form-label">Category</label>
            <select class="form-select" name="category">
                <?php foreach (Sms::CATEGORIES as $c): ?>
                    <option value="<?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $c === 'emergency_comms' ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(str_replace('_', ' ', $c), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12">
            <label class="form-label">Message (max 480 chars — multi-part will be billed accordingly)</label>
            <textarea class="form-control" name="body" rows="4" maxlength="480" required></textarea>
        </div>
        <div class="col-12">
            <button class="btn btn-danger" type="submit" data-confirm="Send SMS to all opted-in subscribers in this category?">
                <i class="fa-solid fa-paper-plane me-1"></i>Send broadcast
            </button>
            <a href="/admin/sms" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>
</form>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
