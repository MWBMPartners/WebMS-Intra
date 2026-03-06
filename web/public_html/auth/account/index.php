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
 * @license    MIT
 * @version    0.2.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Avatar;

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
                        <div><span class="badge bg-secondary">Local Account</span></div>
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

</div>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
