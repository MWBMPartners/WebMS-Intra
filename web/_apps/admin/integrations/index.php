<?php
// Path: public_html/admin/integrations/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Integration Diagnostics 🔌
 * -----------------------------------------------------------------------------
 * Provides live connectivity tests for Microsoft 365 OAuth login,
 * Microsoft Graph email sending (shared mailbox / SendAs), and
 * Google OAuth configuration. Admin-only.
 *
 * Tests:
 *   1. MS365 OAuth Login — checks required settings are configured
 *   2. MS365 Graph Token — attempts client-credentials token acquisition
 *   3. MS365 Graph Email — sends a test email from the shared mailbox
 *   4. Google OAuth — checks configuration status
 *   5. Google Email — checks Gmail API service account config and sends test
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.9.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/48
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Mailer;
use Portal\Core\MailerGoogle;
use Portal\Core\Router;
use Portal\Core\Site;

// 📌 Page metadata
$pageTitle   = 'Integration Diagnostics';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Integrations' => ''];

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// ============================================================================
// 📧 Handle test-email POST
// ============================================================================
$emailResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) === true && $_POST['action'] === 'send_test_email') {
    // 🛡️ CSRF verification
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/integrations');
        exit();
    }

    $testRecipient = trim($_POST['test_recipient'] ?? '');

    if (filter_var($testRecipient, FILTER_VALIDATE_EMAIL) === false) {
        $emailResult = ['success' => false, 'message' => 'Invalid email address.', 'details' => ''];
    } else {
        $emailResult = sendTestEmail($testRecipient);
    }
}

// ============================================================================
// 🔍 Handle token-test POST
// ============================================================================
$tokenResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) === true && $_POST['action'] === 'test_token') {
    // 🛡️ CSRF verification
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/integrations');
        exit();
    }

    $tokenResult = testGraphToken();
}

// ============================================================================
// 📧 Handle Google test-email POST
// ============================================================================
$googleEmailResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) === true && $_POST['action'] === 'send_google_test_email') {
    // 🛡️ CSRF verification
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/integrations');
        exit();
    }

    $testRecipient = trim($_POST['test_recipient'] ?? '');

    if (filter_var($testRecipient, FILTER_VALIDATE_EMAIL) === false) {
        $googleEmailResult = ['success' => false, 'message' => 'Invalid email address.', 'details' => ''];
    } else {
        $googleEmailResult = sendGoogleTestEmail($testRecipient);
    }
}

// ============================================================================
// 📋 Gather configuration status
// ============================================================================

// -- MS365 OAuth (end-user login) settings --
$ms365OAuthSettings = [
    'auth.ms365.enduser.clientID'     => 'Client ID',
    'auth.ms365.enduser.clientSecret' => 'Client Secret',
    'auth.ms365.enduser.redirectURI'  => 'Redirect URI',
    'auth.ms365.tenantID'             => 'Tenant ID',
];

$ms365OAuthStatus = [];
$ms365OAuthAllSet = true;
foreach ($ms365OAuthSettings as $key => $label) {
    $val = App::settings($key) ?? '';
    $isSet = ($val !== '');
    $ms365OAuthStatus[] = ['key' => $key, 'label' => $label, 'configured' => $isSet];
    if ($isSet === false) {
        $ms365OAuthAllSet = false;
    }
}

// -- MS365 Graph API (app-wide / mail) settings --
$ms365GraphSettings = [
    'auth.ms365.appwide.clientID'     => 'App Client ID',
    'auth.ms365.appwide.clientSecret' => 'App Client Secret',
    'auth.ms365.tenantID'             => 'Tenant ID (shared)',
    'mail.defaultFromAddress'         => 'From Address (Shared Mailbox)',
    'mail.defaultFromName'            => 'From Display Name',
];

$ms365GraphStatus = [];
$ms365GraphAllSet = true;
foreach ($ms365GraphSettings as $key => $label) {
    $val = App::settings($key) ?? '';
    $isSet = ($val !== '');
    // 🔒 Show non-sensitive values for verification; mask secrets
    $displayVal = '';
    if ($isSet === true) {
        if (strpos($key, 'Secret') !== false || strpos($key, 'secret') !== false) {
            $displayVal = '••••••••';
        } elseif ($key === 'mail.defaultFromAddress' || $key === 'mail.defaultFromName' || $key === 'auth.ms365.enduser.redirectURI') {
            $displayVal = $val;
        } else {
            // Show first 8 chars of IDs/tenant for verification
            $displayVal = substr($val, 0, 8) . '…';
        }
    }
    $ms365GraphStatus[] = ['key' => $key, 'label' => $label, 'configured' => $isSet, 'display' => $displayVal];
    if ($isSet === false) {
        $ms365GraphAllSet = false;
    }
}

// -- Google OAuth settings --
$googleSettings = [
    'auth.google.clientID'      => 'Client ID',
    'auth.google.clientSecret'  => 'Client Secret',
    'auth.google.redirectURI'   => 'Redirect URI',
    'auth.google.hostedDomain'  => 'Hosted Domain (optional)',
];

$googleStatus = [];
$googleAllSet = true;
foreach ($googleSettings as $key => $label) {
    $val = App::settings($key) ?? '';
    $isSet = ($val !== '');
    // hostedDomain is optional, don't count it as missing
    $isRequired = ($key !== 'auth.google.hostedDomain');
    $googleStatus[] = ['key' => $key, 'label' => $label, 'configured' => $isSet, 'required' => $isRequired];
    if ($isSet === false && $isRequired === true) {
        $googleAllSet = false;
    }
}

// -- Mail provider & Google email settings --
$mailProvider = App::settings('mail.provider') ?? 'ms365';

$googleMailSettings = [
    'mail.provider'                     => 'Mail Provider',
    'mail.google.serviceAccountKeyFile' => 'Service Account Key File',
    'mail.google.delegateUser'          => 'Delegate User (Sender)',
    'mail.defaultFromName'              => 'From Display Name (shared)',
];

$googleMailStatus = [];
$googleMailAllSet = true;
foreach ($googleMailSettings as $key => $label) {
    $val   = App::settings($key) ?? '';
    $isSet = ($val !== '');
    // 🔍 mail.provider and defaultFromName are always set, not critical for Google check
    $isRequired = ($key !== 'mail.provider' && $key !== 'mail.defaultFromName');
    $displayVal = '';
    if ($isSet === true) {
        if (strpos($key, 'KeyFile') !== false) {
            $displayVal = $val; // Show filename (not secret — it's just the filename)
        } elseif ($key === 'mail.provider') {
            $displayVal = strtoupper($val);
        } else {
            $displayVal = $val;
        }
    }
    $googleMailStatus[] = ['key' => $key, 'label' => $label, 'configured' => $isSet, 'required' => $isRequired, 'display' => $displayVal];
    if ($isSet === false && $isRequired === true) {
        $googleMailAllSet = false;
    }
}

// 🔍 Check if the key file actually exists on disk
$keyFileExists = false;
$keyFileName   = App::settings('mail.google.serviceAccountKeyFile') ?? '';
if ($keyFileName !== '') {
    $keyFilePath = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . $keyFileName;
    $keyFileExists = is_readable($keyFilePath);
}

// -- Webhooks (#324) — count configured for the Webhooks card badge --
$webhookCount = 0;
$webhookSiteId = Site::id();
$stmt = $mysqli->prepare('SELECT COUNT(*) AS webhookTotal FROM tblWebhooks WHERE siteID = ?');
if ($stmt !== false) {
    $stmt->bind_param('i', $webhookSiteId);
    $stmt->execute();
    $webhookRow = $stmt->get_result()->fetch_assoc();
    $webhookCount = (int) ($webhookRow['webhookTotal'] ?? 0);
    $stmt->close();
}

// 📄 Include header
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 🔌 Integration Diagnostics -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-plug-circle-check me-2" aria-hidden="true"></i>Integration Diagnostics</h1>
        <p class="text-secondary mb-0">Test Microsoft 365 OAuth, Graph API email, and Google OAuth integrations.</p>
    </div>
    <a href="/admin" class="btn btn-outline-secondary mt-2 mt-md-0">
        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Admin Dashboard
    </a>
</div>

<!-- ====================================================================== -->
<!-- 1️⃣ MS365 OAuth Login Configuration                                     -->
<!-- ====================================================================== -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fa-brands fa-microsoft me-2" aria-hidden="true"></i>MS365 OAuth Login</h5>
        <?php if ($ms365OAuthAllSet === true): ?>
            <span class="badge bg-success"><i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>Configured</span>
        <?php else: ?>
            <span class="badge bg-danger"><i class="fa-solid fa-circle-xmark me-1" aria-hidden="true"></i>Incomplete</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <p class="text-secondary small mb-3">
            These settings control end-user login via Microsoft 365. Configure them in
            <a href="/admin/settings">Admin Settings</a> or <code>tblSettings</code>.
        </p>
        <div class="portal-data-list">
            <?php foreach ($ms365OAuthStatus as $item): ?>
                <div class="portal-data-row">
                    <div class="col-12 col-md-4 fw-semibold"><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-12 col-md-4 text-muted small font-monospace"><?php echo htmlspecialchars($item['key'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-12 col-md-4">
                        <?php if ($item['configured'] === true): ?>
                            <span class="badge bg-success"><i class="fa-solid fa-check me-1" aria-hidden="true"></i>Set</span>
                            <?php
                            // Show redirect URI value for easy Azure AD verification
                            if ($item['key'] === 'auth.ms365.enduser.redirectURI') {
                                $redirectVal = App::settings($item['key']) ?? '';
                                echo ' <code class="ms-2 small">' . htmlspecialchars($redirectVal, ENT_QUOTES, 'UTF-8') . '</code>';
                            }
                            ?>
                        <?php else: ?>
                            <span class="badge bg-danger"><i class="fa-solid fa-xmark me-1" aria-hidden="true"></i>Missing</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ====================================================================== -->
<!-- 2️⃣ MS365 Graph API — Configuration & Token Test                        -->
<!-- ====================================================================== -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fa-solid fa-key me-2" aria-hidden="true"></i>MS365 Graph API — Token &amp; Mail Config</h5>
        <?php if ($ms365GraphAllSet === true): ?>
            <span class="badge bg-success"><i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>Configured</span>
        <?php else: ?>
            <span class="badge bg-danger"><i class="fa-solid fa-circle-xmark me-1" aria-hidden="true"></i>Incomplete</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <p class="text-secondary small mb-3">
            These settings are used by the <strong>app-level</strong> (client credentials) Graph API integration
            for sending email from a shared mailbox via <code>SendAs</code> / delegate access.
        </p>
        <div class="portal-data-list mb-4">
            <?php foreach ($ms365GraphStatus as $item): ?>
                <div class="portal-data-row">
                    <div class="col-12 col-md-3 fw-semibold"><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-12 col-md-4 text-muted small font-monospace"><?php echo htmlspecialchars($item['key'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-12 col-md-5">
                        <?php if ($item['configured'] === true): ?>
                            <span class="badge bg-success"><i class="fa-solid fa-check me-1" aria-hidden="true"></i>Set</span>
                            <?php if ($item['display'] !== '' && $item['display'] !== '••••••••'): ?>
                                <code class="ms-2 small"><?php echo htmlspecialchars($item['display'], ENT_QUOTES, 'UTF-8'); ?></code>
                            <?php elseif ($item['display'] === '••••••••'): ?>
                                <span class="ms-2 text-muted small"><?php echo htmlspecialchars($item['display'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-danger"><i class="fa-solid fa-xmark me-1" aria-hidden="true"></i>Missing</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- 🔑 Token Acquisition Test -->
        <h6 class="border-bottom pb-2 mb-3"><i class="fa-solid fa-vial me-2" aria-hidden="true"></i>Token Acquisition Test</h6>

        <?php if ($ms365GraphAllSet === false): ?>
            <div class="alert alert-warning small mb-0">
                <i class="fa-solid fa-triangle-exclamation me-2" aria-hidden="true"></i>
                Cannot test token acquisition — one or more Graph API settings are missing.
            </div>
        <?php else: ?>
            <?php if ($tokenResult !== null): ?>
                <div class="alert alert-<?php echo ($tokenResult['success'] === true) ? 'success' : 'danger'; ?> small" role="alert" aria-live="polite">
                    <i class="fa-solid fa-<?php echo ($tokenResult['success'] === true) ? 'circle-check' : 'circle-xmark'; ?> me-2" aria-hidden="true"></i>
                    <strong><?php echo ($tokenResult['success'] === true) ? 'Token Acquired' : 'Token Failed'; ?></strong>
                    — <?php echo htmlspecialchars($tokenResult['message'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($tokenResult['details'] !== ''): ?>
                        <pre class="mt-2 mb-0 p-2 bg-body-tertiary rounded small" style="white-space:pre-wrap;"><?php echo htmlspecialchars($tokenResult['details'], ENT_QUOTES, 'UTF-8'); ?></pre>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="/admin/integrations" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="test_token">
                <button type="submit" class="btn btn-outline-primary btn-sm">
                    <i class="fa-solid fa-key me-1" aria-hidden="true"></i>Test Token Acquisition
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- ====================================================================== -->
<!-- 3️⃣ MS365 Graph API — Send Test Email                                   -->
<!-- ====================================================================== -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fa-solid fa-envelope me-2" aria-hidden="true"></i>MS365 Graph API — Send Test Email</h5>
    </div>
    <div class="card-body">
        <p class="text-secondary small mb-3">
            Send a test email from the configured shared mailbox
            (<strong><?php echo htmlspecialchars(App::settings('mail.defaultFromAddress') ?? 'not set', ENT_QUOTES, 'UTF-8'); ?></strong>)
            via Microsoft Graph <code>sendMail</code> API. This verifies SendAs / delegate permissions.
        </p>

        <?php if ($ms365GraphAllSet === false): ?>
            <div class="alert alert-warning small mb-0">
                <i class="fa-solid fa-triangle-exclamation me-2" aria-hidden="true"></i>
                Cannot send test email — Graph API settings are incomplete.
            </div>
        <?php else: ?>
            <?php if ($emailResult !== null): ?>
                <div class="alert alert-<?php echo ($emailResult['success'] === true) ? 'success' : 'danger'; ?> small" role="alert" aria-live="polite">
                    <i class="fa-solid fa-<?php echo ($emailResult['success'] === true) ? 'circle-check' : 'circle-xmark'; ?> me-2" aria-hidden="true"></i>
                    <strong><?php echo ($emailResult['success'] === true) ? 'Email Sent' : 'Email Failed'; ?></strong>
                    — <?php echo htmlspecialchars($emailResult['message'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($emailResult['details'] !== ''): ?>
                        <pre class="mt-2 mb-0 p-2 bg-body-tertiary rounded small" style="white-space:pre-wrap;"><?php echo htmlspecialchars($emailResult['details'], ENT_QUOTES, 'UTF-8'); ?></pre>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="/admin/integrations" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="send_test_email">
                <div class="col-12 col-md-6 col-lg-4">
                    <label for="test_recipient" class="form-label small fw-semibold">Recipient Email</label>
                    <input type="email" class="form-control form-control-sm" id="test_recipient" name="test_recipient"
                           placeholder="you@example.com" required
                           value="<?php echo htmlspecialchars($_SESSION['user_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-12 col-md-auto">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fa-solid fa-paper-plane me-1" aria-hidden="true"></i>Send Test Email
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- ====================================================================== -->
<!-- 4️⃣ Google OAuth Configuration                                          -->
<!-- ====================================================================== -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fa-brands fa-google me-2" aria-hidden="true"></i>Google OAuth</h5>
        <?php if ($googleAllSet === true): ?>
            <span class="badge bg-success"><i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>Configured</span>
        <?php else: ?>
            <span class="badge bg-danger"><i class="fa-solid fa-circle-xmark me-1" aria-hidden="true"></i>Incomplete</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <p class="text-secondary small mb-3">
            Google Workspace OAuth settings for user login. Configure in
            <a href="/admin/settings">Admin Settings</a>.
        </p>
        <div class="portal-data-list">
            <?php foreach ($googleStatus as $item): ?>
                <div class="portal-data-row">
                    <div class="col-12 col-md-4 fw-semibold">
                        <?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="col-12 col-md-4 text-muted small font-monospace"><?php echo htmlspecialchars($item['key'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-12 col-md-4">
                        <?php if ($item['configured'] === true): ?>
                            <span class="badge bg-success"><i class="fa-solid fa-check me-1" aria-hidden="true"></i>Set</span>
                        <?php elseif ($item['required'] === false): ?>
                            <span class="badge bg-secondary"><i class="fa-solid fa-minus me-1" aria-hidden="true"></i>Optional</span>
                        <?php else: ?>
                            <span class="badge bg-danger"><i class="fa-solid fa-xmark me-1" aria-hidden="true"></i>Missing</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ====================================================================== -->
<!-- 5️⃣ Google Email — Gmail API Configuration & Test                        -->
<!-- ====================================================================== -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fa-solid fa-envelope me-2" aria-hidden="true"></i>Google Email — Gmail API</h5>
        <?php if ($googleMailAllSet === true && $keyFileExists === true): ?>
            <span class="badge bg-success"><i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>Configured</span>
        <?php else: ?>
            <span class="badge bg-danger"><i class="fa-solid fa-circle-xmark me-1" aria-hidden="true"></i>Incomplete</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <p class="text-secondary small mb-3">
            Google Workspace email sending via the Gmail API using a service account with domain-wide delegation.
            The service account impersonates the delegate user to send from a shared/generic mailbox.
        </p>

        <!-- 📋 Settings status -->
        <div class="portal-data-list mb-4">
            <?php foreach ($googleMailStatus as $item): ?>
                <div class="portal-data-row">
                    <div class="col-12 col-md-3 fw-semibold"><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-12 col-md-4 text-muted small font-monospace"><?php echo htmlspecialchars($item['key'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-12 col-md-5">
                        <?php if ($item['configured'] === true): ?>
                            <span class="badge bg-success"><i class="fa-solid fa-check me-1" aria-hidden="true"></i>Set</span>
                            <?php if ($item['display'] !== ''): ?>
                                <code class="ms-2 small"><?php echo htmlspecialchars($item['display'], ENT_QUOTES, 'UTF-8'); ?></code>
                            <?php endif; ?>
                        <?php elseif ($item['required'] === false): ?>
                            <span class="badge bg-secondary"><i class="fa-solid fa-minus me-1" aria-hidden="true"></i>Optional</span>
                        <?php else: ?>
                            <span class="badge bg-danger"><i class="fa-solid fa-xmark me-1" aria-hidden="true"></i>Missing</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- 📂 Key file existence check -->
            <?php if ($keyFileName !== ''): ?>
                <div class="portal-data-row">
                    <div class="col-12 col-md-3 fw-semibold">Key File on Disk</div>
                    <div class="col-12 col-md-4 text-muted small font-monospace">_auth_keys/<?php echo htmlspecialchars($keyFileName, ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-12 col-md-5">
                        <?php if ($keyFileExists === true): ?>
                            <span class="badge bg-success"><i class="fa-solid fa-check me-1" aria-hidden="true"></i>Found</span>
                        <?php else: ?>
                            <span class="badge bg-danger"><i class="fa-solid fa-xmark me-1" aria-hidden="true"></i>Not Found</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- 📧 Active provider indicator -->
        <div class="alert alert-<?php echo ($mailProvider === 'google') ? 'primary' : 'secondary'; ?> small mb-3">
            <i class="fa-solid fa-toggle-<?php echo ($mailProvider === 'google') ? 'on' : 'off'; ?> me-2" aria-hidden="true"></i>
            <strong>Active mail provider:</strong>
            <code><?php echo htmlspecialchars(strtoupper($mailProvider), ENT_QUOTES, 'UTF-8'); ?></code>
            <?php if ($mailProvider !== 'google'): ?>
                — Set <code>mail.provider = google</code> in Settings to use Gmail API.
            <?php else: ?>
                — Gmail API is the active email backend.
            <?php endif; ?>
        </div>

        <!-- 📧 Send Test Email -->
        <h6 class="border-bottom pb-2 mb-3"><i class="fa-solid fa-vial me-2" aria-hidden="true"></i>Send Test Email via Gmail API</h6>

        <?php if ($googleMailAllSet === false || $keyFileExists === false): ?>
            <div class="alert alert-warning small mb-0">
                <i class="fa-solid fa-triangle-exclamation me-2" aria-hidden="true"></i>
                Cannot send test email — Google email settings are incomplete or key file is missing.
            </div>
        <?php else: ?>
            <?php if ($googleEmailResult !== null): ?>
                <div class="alert alert-<?php echo ($googleEmailResult['success'] === true) ? 'success' : 'danger'; ?> small" role="alert" aria-live="polite">
                    <i class="fa-solid fa-<?php echo ($googleEmailResult['success'] === true) ? 'circle-check' : 'circle-xmark'; ?> me-2" aria-hidden="true"></i>
                    <strong><?php echo ($googleEmailResult['success'] === true) ? 'Email Sent' : 'Email Failed'; ?></strong>
                    — <?php echo htmlspecialchars($googleEmailResult['message'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($googleEmailResult['details'] !== ''): ?>
                        <pre class="mt-2 mb-0 p-2 bg-body-tertiary rounded small" style="white-space:pre-wrap;"><?php echo htmlspecialchars($googleEmailResult['details'], ENT_QUOTES, 'UTF-8'); ?></pre>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="/admin/integrations" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="send_google_test_email">
                <div class="col-12 col-md-6 col-lg-4">
                    <label for="google_test_recipient" class="form-label small fw-semibold">Recipient Email</label>
                    <input type="email" class="form-control form-control-sm" id="google_test_recipient" name="test_recipient"
                           placeholder="you@example.com" required
                           value="<?php echo htmlspecialchars($_SESSION['user_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-12 col-md-auto">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fa-brands fa-google me-1" aria-hidden="true"></i>Send Test Email via Gmail
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- ====================================================================== -->
<!-- 6️⃣ Webhooks — Outbound Event Notifications (#324)                      -->
<!-- ====================================================================== -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fa-solid fa-tower-broadcast me-2" aria-hidden="true"></i>Webhooks</h5>
        <?php if ($webhookCount > 0): ?>
            <span class="badge bg-success"><i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i><?php echo (int) $webhookCount; ?> configured</span>
        <?php else: ?>
            <span class="badge bg-secondary"><i class="fa-solid fa-circle-minus me-1" aria-hidden="true"></i>None configured</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <p class="text-secondary small mb-3">
            POST a signed JSON payload to an external URL whenever a subscribed portal event fires
            (e.g. <code>prayer-requests.created</code>, <code>expenses.approved</code>, or <code>all</code>).
            Create, pause, and delete webhooks — the signing secret is shown once at creation.
        </p>
        <a href="/admin/integrations/webhooks" class="btn btn-outline-primary btn-sm">
            <i class="fa-solid fa-arrow-right me-1" aria-hidden="true"></i>Manage Webhooks
        </a>
    </div>
</div>

<!-- 📋 Permissions Reference -->
<div class="card border-0 bg-body-tertiary">
    <div class="card-body">
        <h6><i class="fa-solid fa-info-circle me-2" aria-hidden="true"></i>Azure AD &amp; Google Workspace Permissions Reference</h6>
        <div class="small text-secondary">
            <p class="mb-2">For <strong>end-user OAuth login</strong>, the Azure AD app registration needs:</p>
            <ul class="mb-3">
                <li>API Permissions: <code>openid</code>, <code>email</code>, <code>profile</code>, <code>offline_access</code>, <code>User.Read</code> (Delegated)</li>
                <li>Redirect URI configured to match <code>auth.ms365.enduser.redirectURI</code></li>
            </ul>
            <p class="mb-2">For <strong>app-level Graph API email</strong> (shared mailbox / SendAs), the Azure AD app needs:</p>
            <ul class="mb-0">
                <li>API Permissions: <code>Mail.Send</code> (Application — not Delegated)</li>
                <li>Admin consent granted for the <code>Mail.Send</code> permission</li>
                <li>The shared mailbox must grant SendAs or Full Access to the app, or use an
                    <a href="https://learn.microsoft.com/en-us/graph/auth-limit-mailbox-access" target="_blank" rel="noopener">
                        Application Access Policy</a> scoped to the mailbox</li>
            </ul>
            <p class="mb-2">For <strong>Google Workspace email sending</strong> (Gmail API via service account):</p>
            <ul class="mb-0">
                <li>Create a service account in Google Cloud Console with a JSON key</li>
                <li>Enable the Gmail API in the project</li>
                <li>Enable domain-wide delegation on the service account</li>
                <li>In Google Workspace Admin Console, authorise the service account client ID with scope: <code>https://www.googleapis.com/auth/gmail.send</code></li>
                <li>Upload the JSON key file to <code>_auth_keys/</code> on the server</li>
                <li>Set <code>mail.google.serviceAccountKeyFile</code> to the filename and <code>mail.google.delegateUser</code> to the sender address</li>
            </ul>
        </div>
    </div>
</div>

<?php
// 📄 Include footer
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';

// ============================================================================
// 🔧 Helper functions (defined after output to keep template clean)
// ============================================================================

/**
 * 🔑 Test acquiring an app-level access token from Microsoft Graph.
 *
 * Uses the client-credentials flow (same as Mailer::accessToken) but returns
 * diagnostic information instead of throwing on failure.
 *
 * @return array{success: bool, message: string, details: string}
 */
function testGraphToken(): array
{
    global $SETTINGS;

    $cid    = $SETTINGS['auth']['ms365']['appwide']['clientID'] ?? '';
    $sec    = $SETTINGS['auth']['ms365']['appwide']['clientSecret'] ?? '';
    $tenant = $SETTINGS['auth']['ms365']['tenantID'] ?? '';

    if ($cid === '' || $sec === '' || $tenant === '') {
        return ['success' => false, 'message' => 'Required settings missing.', 'details' => ''];
    }

    $url  = 'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/token';
    $post = [
        'client_id'     => $cid,
        'client_secret' => $sec,
        'grant_type'    => 'client_credentials',
        'scope'         => 'https://graph.microsoft.com/.default',
    ];

    $ch = curl_init($url);
    if ($ch === false) {
        Logger::activity('IntegrationTest', 'Graph token test failed — curl_init() returned false');
        return ['success' => false, 'message' => 'curl_init() failed.', 'details' => ''];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($post),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $resp     = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        Logger::activity('IntegrationTest', 'Graph token test failed — cURL error: ' . $curlErr);
        return ['success' => false, 'message' => 'cURL request failed.', 'details' => $curlErr];
    }

    $data = json_decode((string) $resp, true);

    if ($httpCode >= 200 && $httpCode < 300 && isset($data['access_token']) === true) {
        $expiresIn = (int) ($data['expires_in'] ?? 0);
        $expiryStr = ($expiresIn > 0) ? gmdate('H:i:s', $expiresIn) : 'unknown';
        Logger::activity('IntegrationTest', 'Graph token test succeeded (expires in ' . $expiresIn . 's)');
        return [
            'success' => true,
            'message' => 'Token acquired successfully. Expires in ' . $expiryStr . ' (' . $expiresIn . ' seconds).',
            'details' => 'Token type: ' . ($data['token_type'] ?? 'unknown'),
        ];
    }

    // 🔍 Extract error details from the response
    $errCode = $data['error'] ?? 'unknown';
    $errDesc = $data['error_description'] ?? 'No description provided.';
    Logger::activity('IntegrationTest', 'Graph token test failed — HTTP ' . $httpCode . ': ' . $errCode);

    return [
        'success' => false,
        'message' => 'HTTP ' . $httpCode . ' — ' . $errCode,
        'details' => $errDesc,
    ];
}

/**
 * 📧 Send a test email via Microsoft Graph using the shared mailbox.
 *
 * Performs the full flow: token acquisition → sendMail API call.
 * Returns diagnostic details rather than a simple bool.
 *
 * @param string $recipient The email address to send the test to
 *
 * @return array{success: bool, message: string, details: string}
 */
function sendTestEmail(string $recipient): array
{
    global $SETTINGS;

    $fromAddr = $SETTINGS['mail']['defaultFromAddress'] ?? '';
    $fromName = $SETTINGS['mail']['defaultFromName']    ?? 'Portal';

    if ($fromAddr === '') {
        return ['success' => false, 'message' => 'From address not configured.', 'details' => ''];
    }

    // 🔑 Step 1: Acquire token
    $cid    = $SETTINGS['auth']['ms365']['appwide']['clientID'] ?? '';
    $sec    = $SETTINGS['auth']['ms365']['appwide']['clientSecret'] ?? '';
    $tenant = $SETTINGS['auth']['ms365']['tenantID'] ?? '';

    if ($cid === '' || $sec === '' || $tenant === '') {
        return ['success' => false, 'message' => 'Graph API credentials missing.', 'details' => ''];
    }

    $tokenUrl  = 'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/token';
    $tokenPost = [
        'client_id'     => $cid,
        'client_secret' => $sec,
        'grant_type'    => 'client_credentials',
        'scope'         => 'https://graph.microsoft.com/.default',
    ];

    $ch = curl_init($tokenUrl);
    if ($ch === false) {
        return ['success' => false, 'message' => 'curl_init() failed for token request.', 'details' => ''];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($tokenPost),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $tokenResp = curl_exec($ch);
    $tokenCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($tokenResp === false || $tokenCode < 200 || $tokenCode >= 300) {
        Logger::activity('IntegrationTest', 'Test email failed — token acquisition returned HTTP ' . $tokenCode);
        return ['success' => false, 'message' => 'Token acquisition failed (HTTP ' . $tokenCode . ').', 'details' => (string) $tokenResp];
    }

    $tokenData = json_decode((string) $tokenResp, true);
    if (isset($tokenData['access_token']) === false) {
        return ['success' => false, 'message' => 'Token response did not contain access_token.', 'details' => (string) $tokenResp];
    }

    $accessToken = $tokenData['access_token'];

    // 📧 Step 2: Send the test email
    $siteName  = htmlspecialchars($SETTINGS['site']['name'] ?? 'Portal', ENT_QUOTES, 'UTF-8');
    $timestamp = date('Y-m-d H:i:s T');

    $htmlBody = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"></head>'
        . '<body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-size:14px;color:#212529;background:#f8f9fa;margin:0;padding:20px;">'
        . '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.1);">'
        . '<div style="background:#0d6efd;color:#fff;padding:16px 24px;">'
        . '<h2 style="margin:0;font-size:18px;">Integration Test Email</h2>'
        . '</div>'
        . '<div style="padding:24px;">'
        . '<p>This is a <strong>test email</strong> sent from the ' . $siteName . ' Integration Diagnostics page.</p>'
        . '<table style="border-collapse:collapse;margin:1em 0;width:100%;max-width:500px;">'
        . '<tr><td style="padding:6px 12px;border:1px solid #dee2e6;font-weight:bold;">From (Shared Mailbox)</td>'
        . '<td style="padding:6px 12px;border:1px solid #dee2e6;">' . htmlspecialchars($fromAddr, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td style="padding:6px 12px;border:1px solid #dee2e6;font-weight:bold;">Display Name</td>'
        . '<td style="padding:6px 12px;border:1px solid #dee2e6;">' . htmlspecialchars($fromName, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td style="padding:6px 12px;border:1px solid #dee2e6;font-weight:bold;">Sent At</td>'
        . '<td style="padding:6px 12px;border:1px solid #dee2e6;">' . $timestamp . '</td></tr>'
        . '<tr><td style="padding:6px 12px;border:1px solid #dee2e6;font-weight:bold;">API Method</td>'
        . '<td style="padding:6px 12px;border:1px solid #dee2e6;">Graph API — client_credentials + /users/{mailbox}/sendMail</td></tr>'
        . '</table>'
        . '<p style="color:#6c757d;font-size:12px;">If you received this email, Microsoft Graph SendAs from the shared mailbox is working correctly.</p>'
        . '</div>'
        . '<div style="padding:16px 24px;background:#f8f9fa;border-top:1px solid #dee2e6;font-size:12px;color:#6c757d;text-align:center;">'
        . 'Sent by ' . $siteName . ' Integration Diagnostics &mdash; ' . $timestamp
        . '</div>'
        . '</div></body></html>';

    $msg = [
        'message' => [
            'subject'      => '[' . ($SETTINGS['site']['name'] ?? 'Portal') . '] Integration Test Email — ' . $timestamp,
            'body'         => ['contentType' => 'HTML', 'content' => $htmlBody],
            'toRecipients' => [['emailAddress' => ['address' => $recipient]]],
        ],
    ];

    $sendUrl = 'https://graph.microsoft.com/v1.0/users/' . urlencode($fromAddr) . '/sendMail';

    $ch = curl_init($sendUrl);
    if ($ch === false) {
        return ['success' => false, 'message' => 'curl_init() failed for sendMail.', 'details' => ''];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($msg),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $sendResp = curl_exec($ch);
    $sendCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($sendResp === false) {
        Logger::activity('IntegrationTest', 'Test email cURL error: ' . $curlErr);
        return ['success' => false, 'message' => 'cURL request to sendMail failed.', 'details' => $curlErr];
    }

    if ($sendCode >= 200 && $sendCode < 300) {
        Logger::activity('IntegrationTest', 'Test email sent successfully to ' . $recipient . ' from ' . $fromAddr);
        return [
            'success' => true,
            'message' => 'Email sent to ' . $recipient . ' from shared mailbox ' . $fromAddr . ' (HTTP ' . $sendCode . ').',
            'details' => '',
        ];
    }

    // 🔍 Parse Graph API error response
    $errData = json_decode((string) $sendResp, true);
    $errCode = $errData['error']['code'] ?? 'unknown';
    $errMsg  = $errData['error']['message'] ?? 'No error message provided.';

    Logger::activity('IntegrationTest', 'Test email failed — HTTP ' . $sendCode . ': ' . $errCode . ' — to: ' . $recipient);

    return [
        'success' => false,
        'message' => 'HTTP ' . $sendCode . ' — ' . $errCode,
        'details' => $errMsg,
    ];
}

/**
 * 📧 Send a test email via the Gmail API using service account delegation.
 *
 * @param string $recipient The email address to send the test to
 *
 * @return array{success: bool, message: string, details: string}
 */
function sendGoogleTestEmail(string $recipient): array
{
    global $SETTINGS;

    $delegateUser = $SETTINGS['mail']['google']['delegateUser'] ?? '';
    $fromName     = $SETTINGS['mail']['defaultFromName'] ?? 'Portal';
    $siteName     = htmlspecialchars($SETTINGS['site']['name'] ?? 'Portal', ENT_QUOTES, 'UTF-8');
    $timestamp    = date('Y-m-d H:i:s T');

    if ($delegateUser === '') {
        return ['success' => false, 'message' => 'Delegate user not configured.', 'details' => ''];
    }

    $htmlBody = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"></head>'
        . '<body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-size:14px;color:#212529;background:#f8f9fa;margin:0;padding:20px;">'
        . '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.1);">'
        . '<div style="background:#ea4335;color:#fff;padding:16px 24px;">'
        . '<h2 style="margin:0;font-size:18px;">Google Email Integration Test</h2>'
        . '</div>'
        . '<div style="padding:24px;">'
        . '<p>This is a <strong>test email</strong> sent from the ' . $siteName . ' Integration Diagnostics page.</p>'
        . '<table style="border-collapse:collapse;margin:1em 0;width:100%;max-width:500px;">'
        . '<tr><td style="padding:6px 12px;border:1px solid #dee2e6;font-weight:bold;">From (Delegate User)</td>'
        . '<td style="padding:6px 12px;border:1px solid #dee2e6;">' . htmlspecialchars($delegateUser, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td style="padding:6px 12px;border:1px solid #dee2e6;font-weight:bold;">Display Name</td>'
        . '<td style="padding:6px 12px;border:1px solid #dee2e6;">' . htmlspecialchars($fromName, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td style="padding:6px 12px;border:1px solid #dee2e6;font-weight:bold;">Sent At</td>'
        . '<td style="padding:6px 12px;border:1px solid #dee2e6;">' . $timestamp . '</td></tr>'
        . '<tr><td style="padding:6px 12px;border:1px solid #dee2e6;font-weight:bold;">API Method</td>'
        . '<td style="padding:6px 12px;border:1px solid #dee2e6;">Gmail API — service account delegation + users.messages.send</td></tr>'
        . '</table>'
        . '<p style="color:#6c757d;font-size:12px;">If you received this email, Gmail API service account delegation is working correctly.</p>'
        . '</div>'
        . '<div style="padding:16px 24px;background:#f8f9fa;border-top:1px solid #dee2e6;font-size:12px;color:#6c757d;text-align:center;">'
        . 'Sent by ' . $siteName . ' Integration Diagnostics &mdash; ' . $timestamp
        . '</div>'
        . '</div></body></html>';

    $subject = '[' . ($SETTINGS['site']['name'] ?? 'Portal') . '] Google Email Integration Test — ' . $timestamp;

    try {
        $result = MailerGoogle::send([$recipient], $subject, $htmlBody);

        if ($result === true) {
            Logger::activity('IntegrationTest', 'Google test email sent successfully to ' . $recipient . ' from ' . $delegateUser);
            return [
                'success' => true,
                'message' => 'Email sent to ' . $recipient . ' from delegate user ' . $delegateUser . '.',
                'details' => '',
            ];
        }

        return [
            'success' => false,
            'message' => 'Gmail API returned failure.',
            'details' => 'Check the error log for details.',
        ];
    } catch (\RuntimeException $ex) {
        Logger::activity('IntegrationTest', 'Google test email failed: ' . $ex->getMessage());
        return [
            'success' => false,
            'message' => 'Exception: ' . $ex->getMessage(),
            'details' => '',
        ];
    }
}
?>
