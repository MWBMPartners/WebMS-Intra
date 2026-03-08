<?php
// Path: public_html/auth/2fa/setup.php
/**
 * -----------------------------------------------------------------------------
 * Auth — TOTP 2FA Setup
 * -----------------------------------------------------------------------------
 * Allows authenticated users to enable TOTP two-factor authentication.
 * Shows QR code for authenticator app scanning and backup codes.
 *
 * @package   Portal\Auth
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.2
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/92
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;
use Portal\Core\Totp;

Auth::ensureSession();
Auth::requireLogin();

$userId = (int) ($_SESSION['user_id'] ?? 0);
$siteId = Site::id();

// 🔍 Check if already enabled
$userStmt = $mysqli->prepare('SELECT totpEnabled, emailAddress FROM tblUsers WHERE userID = ? LIMIT 1');
if ($userStmt === false) {
    $_SESSION['flash_msg']  = 'Database error.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /auth/account');
    exit();
}
$userStmt->bind_param('i', $userId);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if ((int) ($user['totpEnabled'] ?? 0) === 1) {
    $_SESSION['flash_msg']  = 'Two-factor authentication is already enabled.';
    $_SESSION['flash_type'] = 'info';
    header('Location: /auth/account');
    exit();
}

// 📋 Handle POST (confirm setup)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /auth/2fa/setup');
        exit();
    }

    $secret = $_SESSION['totp_setup_secret'] ?? '';
    $code   = trim($_POST['code'] ?? '');

    if ($secret === '' || $code === '') {
        $_SESSION['flash_msg']  = 'Please enter the verification code.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /auth/2fa/setup');
        exit();
    }

    // 🔍 Verify the code
    if (Totp::verify($secret, $code) === false) {
        $_SESSION['flash_msg']  = 'Invalid code. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /auth/2fa/setup');
        exit();
    }

    // 📋 Enable TOTP
    $encSecret = Auth::encrypt($secret);
    $stmt = $mysqli->prepare('UPDATE tblUsers SET totpSecret = ?, totpEnabled = 1 WHERE userID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('si', $encSecret, $userId);
        $stmt->execute();
        $stmt->close();
    }

    // 📋 Generate and store backup codes
    $backupCodes = Totp::generateBackupCodes(8);
    $bcStmt = $mysqli->prepare(
        'INSERT INTO tblTotpBackupCodes (userID, codeHash) VALUES (?, ?)'
    );
    if ($bcStmt !== false) {
        foreach ($backupCodes as $bc) {
            $hash = password_hash($bc, PASSWORD_BCRYPT);
            $bcStmt->bind_param('is', $userId, $hash);
            $bcStmt->execute();
        }
        $bcStmt->close();
    }

    // 📋 Store backup codes in session for one-time display
    $_SESSION['totp_backup_codes'] = $backupCodes;
    unset($_SESSION['totp_setup_secret']);

    Logger::activity('TotpEnabled', 'Enabled two-factor authentication', $userId);
    $_SESSION['flash_msg']  = 'Two-factor authentication enabled! Save your backup codes.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /auth/2fa/setup?complete=1');
    exit();
}

// 📋 Generate new secret for setup
if (isset($_SESSION['totp_setup_secret']) === false) {
    $_SESSION['totp_setup_secret'] = Totp::generateSecret();
}

$secret  = $_SESSION['totp_setup_secret'];
$issuer  = App::settings('auth.totpIssuer') ?? 'WebMS Portal';
$email   = $user['emailAddress'] ?? '';
$uri     = Totp::getUri($secret, $email, $issuer);
$qrUrl   = Totp::getQrUrl($uri, 250);

$complete    = isset($_GET['complete']) === true;
$backupCodes = $_SESSION['totp_backup_codes'] ?? [];

// 📌 Page metadata
$pageTitle   = 'Setup Two-Factor Authentication';
$pageSection = 'account';
$breadcrumbs = ['Dashboard' => '/', 'Account' => '/auth/account', '2FA Setup' => ''];

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 🔐 TOTP Setup -->
<h1 class="mb-4"><i class="fa-solid fa-shield-halved me-2"></i>Two-Factor Authentication</h1>

<?php if ($complete === true && count($backupCodes) > 0): ?>
    <!-- ✅ Setup Complete — Show Backup Codes -->
    <div class="alert alert-success">
        <i class="fa-solid fa-check-circle me-2"></i>Two-factor authentication is now enabled.
    </div>

    <div class="card mb-4">
        <div class="card-header bg-warning text-dark">
            <h5 class="mb-0"><i class="fa-solid fa-key me-2"></i>Backup Codes — Save These Now!</h5>
        </div>
        <div class="card-body">
            <p class="text-danger fw-bold">These codes will only be shown once. Store them securely.</p>
            <div class="row g-2">
                <?php foreach ($backupCodes as $bc): ?>
                    <div class="col-6 col-md-3">
                        <code class="d-block p-2 bg-light text-center rounded fs-5"><?php echo htmlspecialchars($bc, ENT_QUOTES, 'UTF-8'); ?></code>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <a href="/auth/account" class="btn btn-primary">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to Account
    </a>
    <?php unset($_SESSION['totp_backup_codes']); ?>

<?php else: ?>
    <!-- 📱 Setup Steps -->
    <div class="row g-4">
        <div class="col-12 col-md-6">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Step 1: Scan QR Code</h5></div>
                <div class="card-body text-center">
                    <p>Scan this QR code with your authenticator app (Google Authenticator, Authy, etc.):</p>
                    <img src="<?php echo htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8'); ?>"
                         alt="TOTP QR Code" class="img-fluid mb-3" width="250" height="250">
                    <p class="small text-muted">Or enter this key manually:</p>
                    <code class="d-block p-2 bg-light rounded user-select-all"><?php echo htmlspecialchars($secret, ENT_QUOTES, 'UTF-8'); ?></code>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Step 2: Verify Code</h5></div>
                <div class="card-body">
                    <p>Enter the 6-digit code from your authenticator app to confirm setup:</p>
                    <form method="post" action="/auth/2fa/setup">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="mb-3">
                            <label for="code" class="form-label">Verification Code</label>
                            <input type="text" class="form-control form-control-lg text-center" id="code" name="code"
                                   maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fa-solid fa-check me-1"></i>Enable 2FA
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
