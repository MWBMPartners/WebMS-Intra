<?php
// Path: public_html/account/erasure-request.php
/**
 * Account — file a GDPR Article 17 erasure request. Sends a confirmation
 * email; click-through flips status to pending_review.
 *
 * @package   Portal\Account
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/235
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\GdprEraser;
use Portal\Core\Mailer;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$allowDelete = (App::settings('privacy.allowAccountDelete') ?? 'true') === 'true';
if ($allowDelete === false) {
    http_response_code(403);
    exit('Account erasure is disabled on this portal.');
}

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// Pull user info for the snapshot.
$user = null;
$stmt = $db->prepare('SELECT fullName, emailAddress FROM tblUsers WHERE userID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($user === null) {
    http_response_code(404);
    exit('User not found');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        http_response_code(400);
        exit('Bad request');
    }

    $token = bin2hex(random_bytes(32));
    $email = (string) $user['emailAddress'];
    $name  = (string) $user['fullName'];

    $stmt = $db->prepare(
        'INSERT INTO tblErasureRequest (siteID, userID, subjectEmail, subjectName, confirmToken, status, dueBy) '
        . 'VALUES (?, ?, ?, ?, ?, "pending_confirmation", DATE_ADD(NOW(), INTERVAL 1 MONTH))'
    );
    if ($stmt !== false) {
        $stmt->bind_param('iisss', $siteId, $userId, $email, $name, $token);
        $stmt->execute();
        $stmt->close();
    }

    $scheme = (($_SERVER['HTTPS'] ?? '') !== '' && (string) ($_SERVER['HTTPS'] ?? '') !== 'off') ? 'https' : 'http';
    $confirmUrl = $scheme . '://' . ((string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) . '/account/erasure-confirm?token=' . $token;
    $body = '<p>Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
        . '<p>We received a request to erase your personal data on the portal.</p>'
        . '<p>If this was you, click below to confirm. The link expires in 24 hours.</p>'
        . '<p><a href="' . htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8') . '">Confirm erasure request</a></p>'
        . '<p>If you didn\'t make this request you can safely ignore this email.</p>';
    try {
        Mailer::send($email, 'Confirm your data erasure request', $body);
    } catch (\Throwable $ignored) {
        // Email failure shouldn't block the workflow — admin can find the request directly.
    }

    $_SESSION['flash_msg']  = 'A confirmation email has been sent to ' . $email . '. Click the link to file the request.';
    $_SESSION['flash_type'] = 'info';
    header('Location: /account/my-data');
    exit();
}

$inventory = GdprEraser::inventory($userId);
$csrf = Auth::csrfToken();

$pageTitle   = 'Request erasure';
$pageSection = 'account';
$breadcrumbs = ['Dashboard' => '/', 'My Account' => '/account', 'My data' => '/account/my-data', 'Erasure request' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><i class="fa-solid fa-trash me-2"></i>Request data erasure</h1>

<div class="alert alert-warning">
    <strong>This action cannot be undone.</strong> Records below will be deleted or anonymised within one month (UK GDPR SLA).
</div>

<div class="card mb-3"><div class="card-body">
    <h5>What will happen to your data:</h5>
    <ul>
        <?php foreach ($inventory as $i): ?>
            <li>
                <code><?php echo htmlspecialchars((string) $i['table'], ENT_QUOTES, 'UTF-8'); ?></code>
                — <strong><?php echo (int) $i['rows']; ?></strong> row(s) will be
                <strong><?php echo htmlspecialchars((string) $i['action'], ENT_QUOTES, 'UTF-8'); ?></strong>d.
                <?php if (($i['reason'] ?? '') !== ''): ?>
                    <span class="text-muted">— <?php echo htmlspecialchars((string) $i['reason'], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div></div>

<form method="post" class="card"><div class="card-body">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
    <p>You will receive a confirmation email at <strong><?php echo htmlspecialchars((string) $user['emailAddress'], ENT_QUOTES, 'UTF-8'); ?></strong>. Click the link to file the request.</p>
    <button class="btn btn-danger" type="submit" data-confirm="Send the erasure confirmation email?">
        <i class="fa-solid fa-envelope me-1"></i>Send confirmation email
    </button>
    <a class="btn btn-outline-secondary" href="/account/my-data">Cancel</a>
</div></form>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
