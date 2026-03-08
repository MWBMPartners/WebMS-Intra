<?php
// Path: apps/auth/account/save.php
/**
 * -----------------------------------------------------------------------------
 * Account Profile – Save Handler 💾
 * -----------------------------------------------------------------------------
 * Updates the current user's profile information (full name, email, phone) in
 * tblUsers.  Validates email uniqueness (excluding the current user) and
 * refreshes the session variables to reflect the changes immediately.
 * -----------------------------------------------------------------------------
 * @package    Portal\Auth
 * @author     MWBM Partners Ltd (t/a MWservices)
 * @copyright  2025-2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version    0.2.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;

// -----------------------------------------------------------------------------
// 1. 🛡️ Only accept POST from authenticated users
// -----------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /account', true, 302);
    exit();
}

Auth::requireLogin();

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    header('Location: /account?error=csrf', true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 1b. 🔔 Handle notification preference updates
// -----------------------------------------------------------------------------

$action = $_POST['action'] ?? 'update_profile';

if ($action === 'update_notifications') {
    $userId = (int) $_SESSION['user_id'];
    $prefs = json_encode([
        'emailDigest'    => isset($_POST['emailDigest']) === true,
        'expenseUpdates' => isset($_POST['expenseUpdates']) === true,
        'eventReminders' => isset($_POST['eventReminders']) === true,
    ], JSON_THROW_ON_ERROR);

    $npStmt = $mysqli->prepare('UPDATE tblUsers SET notifyPrefs = ? WHERE userID = ?');
    if ($npStmt !== false) {
        $npStmt->bind_param('si', $prefs, $userId);
        $npStmt->execute();
        $npStmt->close();
    }

    Logger::activity('NotificationPrefsUpdate', 'Updated notification preferences');
    $_SESSION['flash_msg']  = 'Notification preferences saved.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /account', true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 2. 📝 Validate fields
// -----------------------------------------------------------------------------

$userId       = (int) $_SESSION['user_id'];
$fullName     = trim($_POST['fullName'] ?? '');
$emailAddress = strtolower(trim($_POST['emailAddress'] ?? ''));
$phoneNumber  = trim($_POST['phoneNumber'] ?? '');

if ($fullName === '') {
    header('Location: /account?error=name', true, 302);
    exit();
}

if ($emailAddress === '' || filter_var($emailAddress, FILTER_VALIDATE_EMAIL) === false) {
    header('Location: /account?error=email', true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 3. 🔍 Check email uniqueness (excluding current user)
// -----------------------------------------------------------------------------

$checkStmt = $mysqli->prepare(
    'SELECT userID FROM tblUsers WHERE emailAddress = ? AND userID != ? LIMIT 1'
);

if ($checkStmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'PROFILE_PREP_FAIL', $mysqli->error, '');
    header('Location: /account?error=db', true, 302);
    exit();
}

$checkStmt->bind_param('si', $emailAddress, $userId);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();
$existing    = $checkResult->fetch_assoc();
$checkStmt->close();

if ($existing !== null) {
    header('Location: /account?error=email_taken', true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 4. 💾 Update tblUsers
// -----------------------------------------------------------------------------

$updateStmt = $mysqli->prepare(
    'UPDATE tblUsers SET fullName = ?, emailAddress = ?, phoneNumber = ? WHERE userID = ?'
);

if ($updateStmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'PROFILE_UPDATE_FAIL', $mysqli->error, '');
    header('Location: /account?error=db', true, 302);
    exit();
}

$updateStmt->bind_param('sssi', $fullName, $emailAddress, $phoneNumber, $userId);
$updateStmt->execute();
$updateStmt->close();

// -----------------------------------------------------------------------------
// 5. 🔄 Update session variables and reset App user cache
// -----------------------------------------------------------------------------

$_SESSION['user_name']  = $fullName;
$_SESSION['user_email'] = $emailAddress;
App::resetUser();

// 📝 Log the profile update
Logger::activity('ProfileUpdate', 'User updated profile information');

// -----------------------------------------------------------------------------
// 6. 🔀 Redirect with success message
// -----------------------------------------------------------------------------

header('Location: /account?success=1', true, 302);
exit();
