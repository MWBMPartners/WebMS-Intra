<?php
// Path: apps/auth/account/index.php
/**
 * -----------------------------------------------------------------------------
 * My Account Page 👤
 * -----------------------------------------------------------------------------
 * Displays the current user's profile information with three card sections:
 *   1. Profile Info   — editable full name, email, phone number
 *   2. Change Password — current + new password with policy requirements
 *   3. Account Info   — read-only created date, last login, roles
 *
 * Uses header.php / footer.php templates (protected route).
 * -----------------------------------------------------------------------------
 * @package    Portal\Auth
 * @author     MWBM Partners Ltd (t/a MWservices)
 * @copyright  2025-2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version    0.2.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Avatar;
use Portal\Core\WebAuthn;

/**
 * 🏷️ Convert provider key to display label.
 */
function providerLabel(string $provider): string
{
    $labels = [
        'ms365'  => 'Microsoft 365',
        'google' => 'Google',
        'local'  => 'Local',
    ];
    return $labels[$provider] ?? ucfirst($provider);
}

/**
 * 🎨 Provider icon class for Font Awesome.
 */
function providerIcon(string $provider): string
{
    $icons = [
        'ms365'  => 'fa-brands fa-microsoft',
        'google' => 'fa-brands fa-google',
        'local'  => 'fa-solid fa-key',
    ];
    return $icons[$provider] ?? 'fa-solid fa-link';
}

// -----------------------------------------------------------------------------
// 1. 🔒 Require authentication
// -----------------------------------------------------------------------------

Auth::requireLogin();

// -----------------------------------------------------------------------------
// 2. 📊 Load user data
// -----------------------------------------------------------------------------

$user   = App::user();
$userId = (int) $_SESSION['user_id'];

// 🕐 Fetch last login from tblLocalAccounts
$lastLogin = null;
$stmt = $mysqli->prepare(
    'SELECT lastLogin FROM tblLocalAccounts WHERE userID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $localRow = $result->fetch_assoc();
    $stmt->close();
    if ($localRow !== null && ($localRow['lastLogin'] ?? '') !== '') {
        $lastLogin = $localRow['lastLogin'];
    }
}

// 🏷️ Fetch user roles
$roles = [];
$roleStmt = $mysqli->prepare(
    'SELECT R.roleName FROM tblUserRoles UR '
    . 'JOIN tblRoles R ON R.roleID = UR.roleID '
    . 'WHERE UR.userID = ? ORDER BY R.roleName'
);
if ($roleStmt !== false) {
    $roleStmt->bind_param('i', $userId);
    $roleStmt->execute();
    $roleResult = $roleStmt->get_result();
    while ($roleRow = $roleResult->fetch_assoc()) {
        $roles[] = $roleRow['roleName'];
    }
    $roleStmt->close();
}

// 🔗 Fetch linked accounts
$linkedAccounts = Auth::getLinkedAccounts($userId, $mysqli);

// 🔐 Fetch WebAuthn credentials
$webauthnCreds = [];
$waStmt = $mysqli->prepare(
    'SELECT credID, friendlyName, createdAt, lastUsedAt FROM tblWebAuthnCredentials WHERE userID = ? ORDER BY createdAt ASC'
);
if ($waStmt !== false) {
    $waStmt->bind_param('i', $userId);
    $waStmt->execute();
    $webauthnCreds = $waStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $waStmt->close();
}

// 🔢 Count total login methods (for safety check display)
$loginMethodCount = Auth::countLoginMethods($userId, $mysqli);

// 📋 Check if local account exists
$hasLocalAccount = false;
$laCheck = $mysqli->prepare('SELECT 1 FROM tblLocalAccounts WHERE userID = ? LIMIT 1');
if ($laCheck !== false) {
    $laCheck->bind_param('i', $userId);
    $laCheck->execute();
    $hasLocalAccount = ($laCheck->get_result()->num_rows > 0);
    $laCheck->close();
}

// -----------------------------------------------------------------------------
// 3. 📋 Build password policy description
// -----------------------------------------------------------------------------

$minLength      = (int) (App::settings('auth.password.minLength') ?? '8');
$requireUpper   = (App::settings('auth.password.requireUppercase') ?? 'true') === 'true';
$requireNumber  = (App::settings('auth.password.requireNumber') ?? 'true') === 'true';
$requireSpecial = (App::settings('auth.password.requireSpecial') ?? 'true') === 'true';

$policyItems = [];
$policyItems[] = 'At least ' . $minLength . ' characters';
if ($requireUpper === true) {
    $policyItems[] = 'Upper and lowercase letters';
}
if ($requireNumber === true) {
    $policyItems[] = 'At least one number';
}
if ($requireSpecial === true) {
    $policyItems[] = 'At least one special character';
}

// -----------------------------------------------------------------------------
// 4. 📨 Flash messages from save handlers
// -----------------------------------------------------------------------------

$successMsg = '';
$errorMsg   = '';

if (isset($_GET['success']) === true && $_GET['success'] === '1') {
    $successMsg = 'Your profile has been updated.';
}
if (isset($_GET['pwchanged']) === true && $_GET['pwchanged'] === '1') {
    $successMsg = 'Your password has been changed.';
}
if (isset($_GET['unlinked']) === true && $_GET['unlinked'] === '1') {
    $successMsg = 'Account has been unlinked successfully.';
}
if (isset($_GET['wa_deleted']) === true && $_GET['wa_deleted'] === '1') {
    $successMsg = 'Passkey has been removed.';
}
if (isset($_GET['wa_registered']) === true && $_GET['wa_registered'] === '1') {
    $successMsg = 'Passkey registered successfully.';
}
if (isset($_GET['error']) === true) {
    $errorMap = [
        'csrf'         => 'Invalid session token. Please try again.',
        'name'         => 'Full name is required.',
        'email'        => 'A valid email address is required.',
        'email_taken'  => 'That email address is already in use by another account.',
        'pw_current'   => 'Current password is incorrect.',
        'pw_match'     => 'New passwords do not match.',
        'pw_policy'    => 'New password does not meet the requirements.',
        'pw_empty'     => 'All password fields are required.',
        'db'           => 'A database error occurred. Please try again.',
        'unlink_fail'  => 'Unable to unlink that account. You must keep at least one login method.',
        'unlink_csrf'  => 'Invalid session token. Please try again.',
        'wa_delete'    => 'Unable to remove that passkey.',
        'wa_csrf'      => 'Invalid session token. Please try again.',
    ];
    $errorCode = $_GET['error'];
    $errorMsg  = $errorMap[$errorCode] ?? 'An error occurred.';
}

// -----------------------------------------------------------------------------
// 5. 🎨 Render page
// -----------------------------------------------------------------------------

$pageTitle   = 'My Account';
$pageSection = 'account';
$breadcrumbs = ['Dashboard' => '/', 'My Account' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- ✅ Success message -->
<?php if ($successMsg !== ''): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-1"></i>
        <?php echo htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- ❌ Error message -->
<?php if ($errorMsg !== ''): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-exclamation me-1"></i>
        <?php echo htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row g-4">

    <!-- ========================================================== -->
    <!-- 📝 Profile Information Card                                -->
    <!-- ========================================================== -->
    <div class="col-12 col-lg-6">
        <div class="card portal-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fa-solid fa-user me-1"></i> Profile Information
                </h5>
            </div>
            <div class="card-body">
                <!-- 🖼️ Avatar display -->
                <div class="text-center mb-3">
                    <?php
                    if ($user !== null) {
                        echo Avatar::img($user, 96, 'portal-avatar portal-avatar-xl');
                    } else {
                        echo '<img src="/assets/images/avatar-placeholder.svg" class="portal-avatar portal-avatar-xl" alt="" width="96" height="96">';
                    }
                    ?>
                </div>

                <form method="post" action="/account/save" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="mb-3">
                        <label for="fullName" class="form-label">Full Name</label>
                        <input type="text" class="form-control" id="fullName" name="fullName"
                               value="<?php echo htmlspecialchars($user['fullName'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                               required>
                    </div>

                    <div class="mb-3">
                        <label for="emailAddress" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="emailAddress" name="emailAddress"
                               value="<?php echo htmlspecialchars($user['emailAddress'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                               required>
                    </div>

                    <div class="mb-3">
                        <label for="phoneNumber" class="form-label">Phone Number <span class="text-muted small">(optional)</span></label>
                        <input type="tel" class="form-control" id="phoneNumber" name="phoneNumber"
                               value="<?php echo htmlspecialchars($user['phoneNumber'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <button type="submit" class="btn btn-success w-100">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Save Changes
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ========================================================== -->
    <!-- 🔐 Change Password Card                                    -->
    <!-- ========================================================== -->
    <div class="col-12 col-lg-6">
        <div class="card portal-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fa-solid fa-lock me-1"></i> Change Password
                </h5>
            </div>
            <div class="card-body">
                <form method="post" action="/account/change-password" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="mb-3">
                        <label for="current_password" class="form-label">Current Password</label>
                        <input type="password" class="form-control" id="current_password" name="current_password"
                               autocomplete="current-password" required>
                    </div>

                    <div class="mb-3">
                        <label for="new_password" class="form-label">New Password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password"
                               autocomplete="new-password" required>
                    </div>

                    <div class="mb-2">
                        <label for="new_password_confirm" class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" id="new_password_confirm" name="new_password_confirm"
                               autocomplete="new-password" required>
                    </div>

                    <!-- 📋 Password requirements -->
                    <div class="small text-muted mb-3">
                        <strong>Password requirements:</strong>
                        <ul class="mb-0 ps-3">
                            <?php foreach ($policyItems as $item): ?>
                                <li><?php echo htmlspecialchars($item, ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <button type="submit" class="btn btn-warning w-100">
                        <i class="fa-solid fa-key me-1"></i> Update Password
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ========================================================== -->
    <!-- ℹ️ Account Information Card (read-only)                    -->
    <!-- ========================================================== -->
    <div class="col-12">
        <div class="card portal-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fa-solid fa-circle-info me-1"></i> Account Information
                </h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <div class="small text-muted mb-1">Account Created</div>
                        <div><?php echo htmlspecialchars($user['createdAt'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="small text-muted mb-1">Last Login</div>
                        <div><?php echo htmlspecialchars($lastLogin ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="small text-muted mb-1">Account Type</div>
                        <div>
                            <?php if ($hasLocalAccount === true): ?>
                                <span class="badge bg-secondary me-1">Local Account</span>
                            <?php endif; ?>
                            <?php foreach ($linkedAccounts as $la): ?>
                                <span class="badge bg-info me-1"><?php echo htmlspecialchars(providerLabel($la['provider']), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endforeach; ?>
                            <?php if ($hasLocalAccount === false && count($linkedAccounts) === 0): ?>
                                <span class="badge bg-secondary">Unknown</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (count($roles) > 0): ?>
                    <div class="col-12">
                        <div class="small text-muted mb-1">Roles</div>
                        <div>
                            <?php foreach ($roles as $role): ?>
                                <span class="badge bg-primary me-1"><?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (($user['isAdmin'] ?? '0') === '1' || ($user['isRootAdmin'] ?? '0') === '1'): ?>
                    <div class="col-12">
                        <div class="small text-muted mb-1">Privileges</div>
                        <div>
                            <?php if (($user['isRootAdmin'] ?? '0') === '1'): ?>
                                <span class="badge bg-danger me-1">Root Admin</span>
                            <?php elseif (($user['isAdmin'] ?? '0') === '1'): ?>
                                <span class="badge bg-warning text-dark me-1">Admin</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================== -->
    <!-- 🔗 Linked Accounts Card                                    -->
    <!-- ========================================================== -->
    <div class="col-12 col-lg-6">
        <div class="card portal-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fa-solid fa-link me-1"></i> Linked Accounts
                </h5>
            </div>
            <div class="card-body">
                <?php if (count($linkedAccounts) === 0 && $hasLocalAccount === false): ?>
                    <p class="text-muted small mb-0">No linked accounts found.</p>
                <?php else: ?>
                    <div class="portal-data-list">
                        <?php if ($hasLocalAccount === true): ?>
                            <div class="portal-data-row align-items-center">
                                <div class="col">
                                    <i class="fa-solid fa-key me-2 text-secondary"></i>
                                    <strong>Local Account</strong>
                                    <div class="small text-muted">Password-based login</div>
                                </div>
                                <div class="col-auto">
                                    <span class="badge bg-success">Active</span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php foreach ($linkedAccounts as $la): ?>
                            <div class="portal-data-row align-items-center">
                                <div class="col">
                                    <i class="<?php echo htmlspecialchars(providerIcon($la['provider']), ENT_QUOTES, 'UTF-8'); ?> me-2"></i>
                                    <strong><?php echo htmlspecialchars(providerLabel($la['provider']), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <?php if (($la['providerEmail'] ?? '') !== ''): ?>
                                        <div class="small text-muted"><?php echo htmlspecialchars($la['providerEmail'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                    <div class="small text-muted">Linked <?php echo \Portal\Core\I18n::formatDate($la['linkedAt']); ?></div>
                                </div>
                                <div class="col-auto">
                                    <?php if ($loginMethodCount > 1): ?>
                                        <form method="post" action="/account/unlink" class="d-inline"
                                              onsubmit="return confirm('Unlink this <?php echo htmlspecialchars(providerLabel($la['provider']), ENT_QUOTES, 'UTF-8'); ?> account?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="linkID" value="<?php echo (int) $la['linkID']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fa-solid fa-unlink me-1"></i> Unlink
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge bg-secondary" title="Cannot unlink your only login method">Only method</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- 🔗 Link new account buttons -->
                <div class="mt-3">
                    <?php
                    // 📋 Determine which providers are already linked
                    $linkedProviders = array_column($linkedAccounts, 'provider');
                    ?>
                    <?php if (Auth::isGoogleConfigured() === true && in_array('google', $linkedProviders, true) === false): ?>
                        <a href="/login/google" class="btn btn-sm btn-outline-danger me-1">
                            <i class="fa-brands fa-google me-1"></i> Link Google
                        </a>
                    <?php endif; ?>
                    <?php if (Auth::isMS365Configured() === true && in_array('ms365', $linkedProviders, true) === false): ?>
                        <a href="/login/ms365" class="btn btn-sm btn-outline-primary me-1">
                            <i class="fa-brands fa-microsoft me-1"></i> Link Microsoft 365
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================== -->
    <!-- 🔐 PassKeys / WebAuthn Card                                -->
    <!-- ========================================================== -->
    <div class="col-12 col-lg-6">
        <div class="card portal-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fa-solid fa-fingerprint me-1"></i> Passkeys
                </h5>
            </div>
            <div class="card-body">
                <?php if (count($webauthnCreds) === 0): ?>
                    <p class="text-muted small">No passkeys registered yet. Passkeys let you sign in with your fingerprint, face, or security key.</p>
                <?php else: ?>
                    <div class="portal-data-list mb-3">
                        <?php foreach ($webauthnCreds as $cred): ?>
                            <div class="portal-data-row align-items-center">
                                <div class="col">
                                    <i class="fa-solid fa-key me-2 text-primary"></i>
                                    <strong><?php echo htmlspecialchars($cred['friendlyName'] ?? 'Passkey', ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <div class="small text-muted">
                                        Registered <?php echo \Portal\Core\I18n::formatDate($cred['createdAt']); ?>
                                        <?php if (($cred['lastUsedAt'] ?? '') !== ''): ?>
                                            &bull; Last used <?php echo \Portal\Core\I18n::formatDateTime($cred['lastUsedAt']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <?php if ($loginMethodCount > 1): ?>
                                        <form method="post" action="/account/webauthn/delete" class="d-inline"
                                              onsubmit="return confirm('Remove this passkey?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="credID" value="<?php echo (int) $cred['credID']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fa-solid fa-trash me-1"></i> Remove
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge bg-secondary" title="Cannot remove your only login method">Only method</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- ➕ Register new passkey button -->
                <button type="button" class="btn btn-sm btn-outline-primary" id="btnRegisterPasskey">
                    <i class="fa-solid fa-plus me-1"></i> Register New Passkey
                </button>
                <noscript>
                    <p class="text-muted small mt-2"><i class="fa-solid fa-circle-info me-1"></i>Passkey registration requires JavaScript and a compatible browser. Enable JavaScript to register passkeys.</p>
                </noscript>

                <!-- 🔐 Passkey registration modal -->
                <div class="modal fade" id="passkeyModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title"><i class="fa-solid fa-fingerprint me-1"></i> Register Passkey</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label for="passkeyName" class="form-label">Passkey Name</label>
                                    <input type="text" class="form-control" id="passkeyName" placeholder="e.g. YubiKey 5C, MacBook Touch ID">
                                </div>
                                <div id="passkeyStatus" class="small"></div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-primary" id="btnStartRegistration">
                                    <i class="fa-solid fa-fingerprint me-1"></i> Register
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- 📦 WebAuthn registration script -->
<script>
(function() {
    'use strict';

    const registerBtn = document.getElementById('btnRegisterPasskey');
    const startBtn    = document.getElementById('btnStartRegistration');
    const statusEl    = document.getElementById('passkeyStatus');
    const nameInput   = document.getElementById('passkeyName');
    const modal       = new bootstrap.Modal(document.getElementById('passkeyModal'));

    if (!window.PublicKeyCredential) {
        registerBtn.disabled = true;
        registerBtn.title    = 'WebAuthn is not supported in this browser';
        return;
    }

    registerBtn.addEventListener('click', function() {
        statusEl.textContent = '';
        nameInput.value      = '';
        modal.show();
    });

    startBtn.addEventListener('click', async function() {
        const friendlyName = nameInput.value.trim() || 'Passkey';
        statusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Requesting registration options...';
        startBtn.disabled  = true;

        try {
            // 📋 Step 1: Get registration options from server
            const optResp = await fetch('/account/webauthn', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=register_options&csrf_token=' + encodeURIComponent(document.querySelector('[name=csrf_token]').value)
            });
            const optData = await optResp.json();
            if (!optData.success) {
                throw new Error(optData.error || 'Failed to get registration options');
            }

            const options = optData.options;

            // 🔐 Convert base64url strings to ArrayBuffers
            options.challenge = base64urlToBuffer(options.challenge);
            options.user.id   = base64urlToBuffer(options.user.id);
            if (options.excludeCredentials) {
                options.excludeCredentials = options.excludeCredentials.map(function(c) {
                    c.id = base64urlToBuffer(c.id);
                    return c;
                });
            }

            statusEl.innerHTML = '<i class="fa-solid fa-fingerprint fa-beat me-1"></i> Waiting for authenticator...';

            // 🔐 Step 2: Create credential via WebAuthn API
            const credential = await navigator.credentials.create({publicKey: options});

            statusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Verifying...';

            // 📤 Step 3: Send credential to server for verification and storage
            const attestation = {
                id:       credential.id,
                rawId:    bufferToBase64url(credential.rawId),
                type:     credential.type,
                response: {
                    clientDataJSON:    bufferToBase64url(credential.response.clientDataJSON),
                    attestationObject: bufferToBase64url(credential.response.attestationObject)
                }
            };

            if (credential.response.getTransports) {
                attestation.transports = credential.response.getTransports();
            }

            const verifyResp = await fetch('/account/webauthn', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action:       'register_verify',
                    csrf_token:   document.querySelector('[name=csrf_token]').value,
                    credential:   attestation,
                    friendlyName: friendlyName
                })
            });
            const verifyData = await verifyResp.json();

            if (verifyData.success) {
                modal.hide();
                window.location.href = '/account?wa_registered=1';
            } else {
                throw new Error(verifyData.error || 'Verification failed');
            }
        } catch (err) {
            if (err.name === 'NotAllowedError') {
                statusEl.innerHTML = '<span class="text-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i> Registration was cancelled.</span>';
            } else {
                statusEl.innerHTML = '<span class="text-danger"><i class="fa-solid fa-circle-exclamation me-1"></i> ' + escapeHtml(err.message) + '</span>';
            }
        } finally {
            startBtn.disabled = false;
        }
    });

    // 🔧 Utility: base64url → ArrayBuffer
    function base64urlToBuffer(b64url) {
        var b64 = b64url.replace(/-/g, '+').replace(/_/g, '/');
        while (b64.length % 4 !== 0) b64 += '=';
        var bin  = atob(b64);
        var buf  = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
        return buf.buffer;
    }

    // 🔧 Utility: ArrayBuffer → base64url
    function bufferToBase64url(buffer) {
        var bytes = new Uint8Array(buffer);
        var bin   = '';
        for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
    }

    // 🔧 Utility: HTML escape
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
})();
</script>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
